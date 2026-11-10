<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false);
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 9; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15);
    $line = rtrim((string) fgets($connection), "\r\n"); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value);
    }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    [$method, $target] = explode(' ', $line); $url = parse_url($target); parse_str($url['query'] ?? '', $query);
    $status = (int) ($query['status'] ?? 0); $fallback = ($url['path'] ?? '') === '/fallback';
    $valid = ($query['body'] ?? '') === 'query body' && ($headers['x-trace'] ?? '') === 'trace'
        && ($headers['accept'] ?? '') === ($fallback ? 'application/json' : 'application/json, application/octet-stream')
        && ($method === 'POST' ? $body === '{"name":"request"}' : $body === '');
    if (!$valid) { throw new RuntimeException('Response selection changed request fields or Accept.'); }
    $bytes = match ($status) { 201 => "\x00\xffbinary", 204, 400 => '', 500 => '{"city":"Madrid"}', default => $fallback ? '{"city":"Madrid"}' : '{"name":"value"}' };
    $media = $status === 201 ? 'application/octet-stream' : 'application/json';
    fwrite($connection, 'HTTP/1.1 ' . $status . " Test\r\nContent-Type: " . $media . "\r\nX-Result: preserved\r\nContent-Length: " . strlen($bytes) . "\r\nConnection: close\r\n\r\n" . $bytes);
    fclose($connection);
}
fclose($socket);
