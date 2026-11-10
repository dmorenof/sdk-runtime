<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false); echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
foreach (['/text', '/optional', '/optional', '/binary/7?body=separate', '/mixed', '/mixed', '/mixed', '/mixed'] as $index => $target) {
    $connection = stream_socket_accept($socket, 20); if ($connection === false) { throw new RuntimeException('Missing raw request.'); }
    stream_set_timeout($connection, 20); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $remaining = (int) ($headers['content-length'] ?? 0); $length = $remaining; $hash = hash_init('sha256'); $body = '';
    while ($remaining > 0) { $chunk = fread($connection, min(8192, $remaining)); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete raw body.'); } hash_update($hash, $chunk); if ($index !== 3) { $body .= $chunk; } $remaining -= strlen($chunk); }
    $valid = $line === ($index < 4 ? 'POST ' : 'GET ') . $target . ' HTTP/1.1';
    $valid = $valid && ($headers['accept'] ?? '') === ($index === 0 ? 'text/plain' : ($index < 4 ? 'application/json' : 'application/json, text/plain, application/octet-stream'));
    if ($index === 0) { $valid = $valid && $body === "ñ text\n" && ($headers['content-type'] ?? '') === 'text/plain'; }
    elseif ($index === 2) { $valid = $valid && $body === '' && ($headers['content-type'] ?? '') === 'text/plain'; }
    elseif ($index === 3) {
        $expectedHash = hash_init('sha256'); for ($n = 0; $n < 4096; ++$n) { hash_update($expectedHash, str_repeat('x', 8192)); }
        $valid = $valid && $length === 33554432 && hash_final($hash) === hash_final($expectedHash) && ($headers['content-type'] ?? '') === 'application/octet-stream' && ($headers['x-test'] ?? '') === 'header';
    } else { $valid = $valid && $body === '' && !isset($headers['content-type']); }
    $status = match ($index) { 0, 4 => 200, 5 => 201, 6 => 202, default => 204 };
    $response = match ($index) { 0 => "sin JSON ñ\n", 4 => 'plain response', 5 => '7', 6 => "\0\xffbin", default => '' };
    $media = match ($index) { 0, 4 => 'text/plain', 6 => 'application/octet-stream', default => 'application/json' };
    fwrite($connection, 'HTTP/1.1 ' . ($valid ? $status . ' OK' : '400 Bad Request') . "\r\nContent-Type: $media\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
    fclose($connection); if (!$valid) { throw new RuntimeException('Raw request bytes, streaming or codec selection changed at ' . $index); }
}
fclose($socket);
