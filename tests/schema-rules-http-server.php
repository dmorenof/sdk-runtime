<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false);
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
foreach (['/rules?amount=0.3', '/scalar', '/scalar-bad', '/collection'] as $target) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete body.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $post = str_starts_with($target, '/rules');
    $valid = $line === ($post ? 'POST' : 'GET') . ' ' . $target . ' HTTP/1.1' && ($headers['accept'] ?? '') === 'application/json';
    if ($post) {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $valid = $valid && count($data) === 5 && !isset($data['id']) && ($data['amount'] ?? 0) === 0.3
            && ($data['rows'] ?? []) === [1, 2] && ($data['tag'] ?? null) === 7 && ($data['profile'] ?? null) === ['only' => 1] && ($data['label'] ?? '') === 'ñ';
        $result = $body;
    } else { $valid = $valid && $body === ''; $result = match ($target) { '/scalar' => '0.3', '/scalar-bad' => '0.31', default => '[1,2]' }; }
    fwrite($connection, ($valid ? 'HTTP/1.1 200 OK' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($result) . "\r\nConnection: close\r\n\r\n" . $result);
    fclose($connection); if (!$valid) { throw new RuntimeException('Schema rule HTTP request changed.'); }
}
fclose($socket);
