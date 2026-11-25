<?php
declare(strict_types=1);

/**
 * A scripted HTTP/1.1 server for tests that need real bytes on a real socket.
 *
 * usage: php scripted-server.php <script file> <request log file>
 *
 * It listens on 127.0.0.1 on a free port, prints the port on its first line and then answers each connection with the next entry of
 * the script, which it reads from the file at every request, so a test can write it once the ports of all its servers are known.
 * Entries: {"status": 200, "headers": {"Name": "value"}, "body": "text", "bodyBase64": "...", "delayMs": 0}; or, to answer in pieces that
 * arrive over time (an event stream), "chunks": [{"data": "text", "delayMs": 0}, ...] sent with chunked transfer coding. After the last
 * entry every request gets a 599. Every request received is appended to the log as one JSON line: method, target, headers (lower-case
 * names, each a list) and body. The connection is closed after each answer. The server stops after 30 seconds without a connection.
 */
$script = $argv[1] ?? throw new RuntimeException('Missing script file.');
$log = $argv[2] ?? throw new RuntimeException('Missing log file.');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
if ($address === false) {
    throw new RuntimeException('No server address.');
}
echo substr($address, strrpos($address, ':') + 1), PHP_EOL;
flush();

$served = 0;
while (($connection = @stream_socket_accept($socket, 30)) !== false) {
    stream_set_timeout($connection, 15);
    $line = fgets($connection);
    if ($line === false) {
        fclose($connection);
        continue;
    }
    [$method, $target] = array_pad(explode(' ', trim($line), 3), 2, '');
    $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$name, $value] = explode(':', $header, 2);
        $headers[strtolower($name)][] = trim($value);
    }
    $body = '';
    $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) {
        $chunk = fread($connection, $remaining);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
        $remaining -= strlen($chunk);
    }
    file_put_contents($log, json_encode(['method' => $method, 'target' => $target, 'headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

    $entries = is_file($script) ? json_decode((string) file_get_contents($script), true, 512, JSON_THROW_ON_ERROR) : [];
    $entry = $entries[$served] ?? ['status' => 599, 'body' => 'script exhausted'];
    ++$served;
    if (($entry['delayMs'] ?? 0) > 0) {
        usleep((int) $entry['delayMs'] * 1000);
    }
    $out = 'HTTP/1.1 ' . (int) $entry['status'] . ' ' . ($entry['reason'] ?? 'Scripted') . "\r\n";
    foreach ($entry['headers'] ?? [] as $name => $value) {
        $out .= $name . ': ' . $value . "\r\n";
    }
    if (isset($entry['chunks'])) {
        @fwrite($connection, $out . "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
        foreach ($entry['chunks'] as $piece) {
            if (($piece['delayMs'] ?? 0) > 0) {
                usleep((int) $piece['delayMs'] * 1000);
            }
            if (@fwrite($connection, dechex(strlen($piece['data'])) . "\r\n" . $piece['data'] . "\r\n") === false) {
                break;
            }
        }
        @fwrite($connection, "0\r\n\r\n");
        @fclose($connection);
        continue;
    }
    $payload = isset($entry['bodyBase64']) ? (string) base64_decode($entry['bodyBase64'], true) : (string) ($entry['body'] ?? '');
    $out .= 'Content-Length: ' . strlen($payload) . "\r\nConnection: close\r\n\r\n";
    @fwrite($connection, $out . $payload);
    @fclose($connection);
}
