<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
if ($address === false) { throw new RuntimeException('No server address.'); }
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 2; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15);
    $line = fgets($connection); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$key, $value] = explode(':', $header, 2);
        $headers[strtolower($key)][] = trim($value);
    }
    $body = ''; $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Truncated body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $expected = ($i === 0 ? 'GET' : 'POST') . ' /items?body=query%20body HTTP/1.1';
    $actualCookies = explode('; ', $headers['cookie'][0] ?? '');
    $expectedCookies = ['default=base', 'caller=ok', 'theme=a%2Fb%2C%C3%B1', 'enabled=false', 'count=0', 'empty=', 'session=a+/b=='];
    sort($actualCookies); sort($expectedCookies);
    if (trim((string) $line) !== $expected || count($headers['cookie'] ?? []) !== 1 || $actualCookies !== $expectedCookies
        || ($i === 0 ? $body !== '' : $body !== '{"name":"body name"}')) {
        fwrite($connection, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        throw new RuntimeException('Request cookies, target or body changed.');
    }
    fwrite($connection, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"); fclose($connection);
}
fclose($socket);
