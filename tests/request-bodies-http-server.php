<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false);
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
$requests = [['/scalar', '"valid"'], ['/optional', null], ['/optional', 'null'], ['/optional', '0'], ['/optional', '"text"'], ['/null', 'null'], ['/list/7?body=separate', '[{"name":"Alice"}]'], ['/map', '{"one":1}'], ['/loose', '[1,"x",null]'], ['/open', '{"nested":[false,null]}'], ['/never', null]];
foreach ($requests as [$target, $expected]) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $valid = $line === 'POST ' . $target . ' HTTP/1.1' && $body === ($expected ?? '') && ($headers['accept'] ?? '') === 'application/json';
    $valid = $valid && ($expected === null ? !isset($headers['content-type']) : ($headers['content-type'] ?? '') === 'application/json');
    if (str_starts_with($target, '/list/')) { $valid = $valid && ($headers['x-test'] ?? '') === 'header'; }
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Request body bytes, presence or parameter separation changed: ' . $target); }
}
fclose($socket);
