<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
echo substr((string) $address, strrpos((string) $address, ':') + 1), PHP_EOL; flush();
foreach (['GET', 'POST'] as $method) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15);
    $line = rtrim((string) fgets($connection), "\r\n"); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value);
    }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $valid = $line === $method . ' /items/a%2Fb%20%252F%20%C3%B1/a%2Fb%09%252F%09%C3%B1/a%2Fb%7C%252F%7C%C3%B1?body=query%20body HTTP/1.1'
        && ($headers['x-spaces'] ?? '') === 'alpha beta' && ($headers['x-tabs'] ?? '') === "alpha\tbeta" && ($headers['x-pipes'] ?? '') === 'alpha|beta'
        && ($method === 'GET' ? $body === '' : ($body === '{"name":"Ada"}' && ($headers['content-type'] ?? '') === 'application/json'));
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
    if (!$valid) { throw new RuntimeException('Delimited parameters changed path, headers, query or body.'); }
}
fclose($socket);
