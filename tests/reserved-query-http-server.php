<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
if ($address === false) { throw new RuntimeException('Missing address.'); }
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 2; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing HTTP request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)][] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $parts = explode(' ', $line); $target = explode('?', $parts[1] ?? '', 2); $query = [];
    foreach (explode('&', $target[1] ?? '') as $pair) { [$name, $value] = explode('=', $pair, 2); $query[$name][] = $value; }
    $expected = ['q' => ['a/b%26c?x'], 'body' => ['query/body'], 'labels' => ['a/b', 'c?d'], 'filter%5Bcode%5D' => ['x/y'], 'plain' => ['a%2Fb'], 'k' => ['%252F']];
    ksort($query); ksort($expected);
    $valid = ($parts[0] ?? '') === ($i === 0 ? 'GET' : 'POST') && $target[0] === '/items' && $query === $expected
        && ($headers['x-trace'] ?? []) === ['h/x'] && ($headers['cookie'] ?? []) === ['theme=a%2Fb']
        && ($i === 0 ? $body === '' : $body === '{"name":"body\/x"}');
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Reserved expansion changed query shape, credentials, headers or body.'); }
}
fclose($socket);
