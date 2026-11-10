<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false); echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$cases = [[400, 'all'], [401, 'all'], [402, 'all'], [403, 'all'], [404, 'all'], [405, 'all'], [406, 'all'], [407, 'all'], [409, 'all'], [422, 'all'], [500, 'all'], [400, 'short'], [401, 'json']];
foreach ($cases as [$status, $trace]) {
    $connection = stream_socket_accept($socket, 15); if ($connection === false) { throw new RuntimeException('Missing error request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $valid = $line === "GET /errors/$status?trace=$trace HTTP/1.1" && ($headers['accept'] ?? '') === 'application/json, text/plain, application/octet-stream'
        && ($headers['x-test'] ?? '') === 'header' && (int) ($headers['content-length'] ?? 0) === 0;
    $body = match ($status) { 400 => $trace === 'short' ? '"x"' : '"issue"', 401 => $trace === 'json' ? '{' : '[1,2]', 402 => '{"one":1}', 403 => 'null', 404 => 'ignored raw body', 405 => '"denied"', 406 => 'plain error', 407 => "\0\xffbin", 409 => '"many"', 422 => '7', default => '{"code":500,"message":"failed"}' };
    $media = match ($status) { 406 => 'text/plain', 407 => 'application/octet-stream', default => 'application/json' };
    fwrite($connection, 'HTTP/1.1 ' . ($valid ? $status . ' Error' : '599 Wrong Request') . "\r\nContent-Type: $media\r\nX-Trace: $trace\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($connection); if (!$valid) { throw new RuntimeException('Error request routing, parameters or Accept changed.'); }
}
fclose($socket);
