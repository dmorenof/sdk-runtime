<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
if ($address === false) { throw new RuntimeException('Missing address.'); }
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 4; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing HTTP request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)][] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'][0] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    if ($i === 2) {
        $valid = $line === 'GET /scalar/%22Cat%22 HTTP/1.1' && $body === '' && ($headers['cookie'] ?? []) === ['default=base; auth=token+/=='];
    } else {
        $parts = explode(' ', $line); $target = explode('?', $parts[1] ?? '', 2); $query = [];
        foreach (explode('&', $target[1] ?? '') as $pair) { [$key, $value] = explode('=', $pair, 2); $query[$key][] = $value; }
        $expected = ['body' => [rawurlencode('{"name":"query\/x"}')], 'records' => [rawurlencode($i === 3 ? '[{"name":"second"}]' : '[{"name":"row\/x"}]')], 'empty' => ['%5B%5D'], 'scalar' => ['%22%22'], 'active' => ['false'], 'values' => [rawurlencode('{"0":"zero"}')], 'plain' => ['100%25%20%2F%20%C3%B1']];
        ksort($query); ksort($expected);
        $cookies = explode('; ', $headers['cookie'][0] ?? ''); $expectedCookies = ['default=base', 'session=a+/b==', 'theme=a%2Fb', 'auth=token+/==']; sort($cookies); sort($expectedCookies);
        $valid = ($parts[0] ?? '') === ($i === 0 ? 'GET' : 'POST') && $target[0] === '/items/' . rawurlencode('{"code":"path\/x","flag":false}') . '/a%2Fb%25'
            && $query === $expected && ($headers['x-json'] ?? []) === ['{"code":"header\/x","flag":false}'] && ($headers['x-trace'] ?? []) === ['h,/=x']
            && count($headers['cookie'] ?? []) === 1 && $cookies === $expectedCookies && ($i === 0 ? $body === '' : $body === '{"name":"body\/x"}');
    }
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Parameter content changed target, JSON direction, cookies or body.'); }
}
fclose($socket);
