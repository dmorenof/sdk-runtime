<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false);
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$record = '{"id":1.0,"value":"valid","open":{"nested":[true,null]},"nullOnly":null,"items":[1,"text",null],"empty":[]}';
foreach (['/native', '/native', '/value', '/any', '/never', '/items', '/param/1', '/param/1'] as $index => $path) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    [$method, $target] = explode(' ', $line); $url = parse_url($target);
    $valid = $method === ($index === 1 ? 'POST' : 'GET') && ($url['path'] ?? '') === $path && ($headers['accept'] ?? '') === 'application/json';
    if ($index === 1) {
        $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode($record, true, 512, JSON_THROW_ON_ERROR); $expected['id'] = 1;
        ksort($value); ksort($expected); $valid = $valid && $value === $expected;
    } else { $valid = $valid && $body === ''; }
    if ($index >= 6) {
        parse_str($url['query'] ?? '', $query); $expected = ['json' => '1', 'plain' => 'hello'];
        if ($index === 6) { $expected['value'] = 'false'; }
        ksort($query); ksort($expected);
        $valid = $valid && $query === $expected && ($headers['x-alias'] ?? '') === 'ab' && ($headers['cookie'] ?? '') === 'session=ab';
    }
    $response = match ($path) { '/native' => $record, '/value' => 'false', '/any' => '{"nested":[1,null]}', '/never' => 'null', '/items' => '[1,"x",null]', default => '"result"' };
    fwrite($connection, ($valid ? 'HTTP/1.1 200 OK' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
    fclose($connection); if (!$valid) { throw new RuntimeException('Native schema types or parameter wire values changed.'); }
}
fclose($socket);
