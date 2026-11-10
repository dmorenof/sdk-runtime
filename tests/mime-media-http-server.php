<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false); echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$paths = ['/csv', '/xml', '/image/7?body=separate', '/pdf', '/pdf', '/problem', '/mixed', '/mixed', '/mixed', '/mixed', '/mixed', '/mixed', '/pdf', '/image/7?body=separate', '/problem'];
foreach ($paths as $index => $target) {
    $connection = stream_socket_accept($socket, 20); if ($connection === false) { throw new RuntimeException('Missing MIME request.'); }
    stream_set_timeout($connection, 20); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $length = (int) ($headers['content-length'] ?? 0); $remaining = $length; $hash = hash_init('sha256'); $body = '';
    while ($remaining > 0) { $chunk = fread($connection, min(8192, $remaining)); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete MIME body.'); } hash_update($hash, $chunk); if ($index !== 2) { $body .= $chunk; } $remaining -= strlen($chunk); }
    $method = in_array($index, [0, 1, 2, 5, 13, 14], true) ? 'POST' : 'GET';
    $accept = match (true) { $index === 0 => 'text/csv; charset=utf-8', $index === 1 => 'application/xml', in_array($index, [2, 13], true) => 'application/problem+json, image/png', in_array($index, [3, 4, 12], true) => 'application/pdf, image/svg+xml, application/xml', in_array($index, [5, 14], true) => 'application/problem+json', default => 'application/problem+json, application/xml, application/pdf, text/csv, image/png' };
    $valid = $line === "$method $target HTTP/1.1" && ($headers['accept'] ?? '') === $accept;
    if ($index === 0) { $valid = $valid && $body === "a,b\n1,2\n" && ($headers['content-type'] ?? '') === 'text/csv; charset=utf-8'; }
    elseif ($index === 1) { $valid = $valid && $body === '<input>ñ</input>' && ($headers['content-type'] ?? '') === 'application/xml'; }
    elseif (in_array($index, [2, 13], true)) {
        $valid = $valid && ($headers['content-type'] ?? '') === 'image/png' && ($headers['x-test'] ?? '') === 'header';
        if ($index === 2) { $expected = hash_init('sha256'); for ($n = 0; $n < 4096; ++$n) { hash_update($expected, str_repeat('x', 8192)); } $valid = $valid && $length === 33554432 && hash_final($hash) === hash_final($expected); }
        else { $valid = $valid && $body === 'small'; }
    } elseif (in_array($index, [5, 14], true)) { $valid = $valid && $body === '{"value":7}' && ($headers['content-type'] ?? '') === 'application/vnd.example+json'; }
    else { $valid = $valid && $body === '' && !isset($headers['content-type']); }
    $status = match ($index) { 2, 4, 7 => 201, 8 => 202, 9 => 204, 10 => 406, 11 => 409, 12 => 404, 13, 14 => 400, default => 200 };
    $response = match ($index) { 0 => "a,b\n3,4\n", 1 => '<reply/>', 2 => "\0\xffpng", 4 => '<svg/>', 5 => '{"ok":true}', 6 => '<xml/>', 7 => '7', 8 => '%PDF bytes', 9 => '', 10 => "error,csv\n", 11 => "\0\xfferror", 12 => '<error/>', 13 => '{"code":400}', 14 => '"problem"', default => '' };
    $media = match ($index) { 0 => 'text/csv; charset=utf-8', 1, 6, 12 => 'application/xml', 2, 11 => 'image/png', 3, 8 => 'application/pdf', 4 => 'image/svg+xml', 10 => 'text/csv', default => 'application/problem+json' };
    $size = $index === 3 ? 33554432 : strlen($response);
    fwrite($connection, 'HTTP/1.1 ' . ($valid ? $status . ' Result' : '599 Wrong Request') . "\r\nContent-Type: $media\r\nContent-Length: $size\r\nConnection: close\r\n\r\n");
    if ($index === 3) {
        $chunk = str_repeat('z', 8192);
        for ($n = 0; $n < 4096; ++$n) { $sent = 0; while ($sent < strlen($chunk)) { $written = fwrite($connection, substr($chunk, $sent)); if ($written === false || $written === 0) { throw new RuntimeException('Incomplete PDF response.'); } $sent += $written; } }
    } else { fwrite($connection, $response); }
    fclose($connection); if (!$valid) { throw new RuntimeException('MIME bytes, stream or headers changed at ' . $index); }
}
fclose($socket);
