<?php
declare(strict_types=1);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) { throw new RuntimeException($error); }
$address = (string) stream_socket_get_name($socket, false);
echo substr($address, strrpos($address, ':') + 1), PHP_EOL; flush();
for ($i = 0; $i < 3; ++$i) {
    $connection = stream_socket_accept($socket, 15);
    if ($connection === false) { throw new RuntimeException('Missing request.'); }
    stream_set_timeout($connection, 15); $line = trim((string) fgets($connection)); $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') { [$key, $value] = explode(':', $header, 2); $headers[strtolower($key)] = trim($value); }
    $body = ''; $remaining = (int) ($headers['content-length'] ?? 0);
    while ($remaining > 0) { $chunk = fread($connection, $remaining); if ($chunk === false || $chunk === '') { throw new RuntimeException('Incomplete request.'); } $body .= $chunk; $remaining -= strlen($chunk); }
    $paths = ['/form', '/multipart', '/swagger'];
    $valid = $line === 'POST ' . $paths[$i] . '?body=query%20body HTTP/1.1' && ($headers['x-trace'] ?? '') === 'trace';
    if ($i !== 1) {
        $expected = $i === 0
            ? ['body=form+body', 'city=Madrid', 'deep%5Bname%5D=ni%C3%B1o', 'filter=code,a%2Fb,flag,false', 'pipe=a%2Fb%7C%252F', 'space=one+two', 'zip=0']
            : ['csv=1,2', 'multi=x', 'multi=y', 'pipes=a%2Fb%7C%252F', 'ssv=one+two', 'tsv=one%09two'];
        $actual = explode('&', $body); sort($expected); sort($actual);
        $valid = $valid && $actual === $expected && ($headers['content-type'] ?? '') === 'application/x-www-form-urlencoded';
    } else {
        $matched = preg_match('/^multipart\/form-data; boundary=(.+)$/D', $headers['content-type'] ?? '', $match);
        $valid = $valid && $matched === 1; $parts = [];
        foreach (explode('--' . ($match[1] ?? 'invalid'), $body) as $part) {
            if (!str_contains($part, "\r\n\r\n")) { continue; }
            [$partHeaders, $bytes] = explode("\r\n\r\n", $part, 2); $bytes = substr($bytes, 0, -2);
            if (preg_match('/name="([^"]+)"/', $partHeaders, $name) !== 1 || preg_match('/Content-Type: ([^\r\n]+)/i', $partHeaders, $media) !== 1) { throw new RuntimeException('Missing MIME metadata.'); }
            $parts[$name[1]][] = ['media' => $media[1], 'bytes' => $bytes];
        }
        $valid = $valid && count($parts) === 5 && count($parts['batch'] ?? []) === 2
            && ($parts['document'][0]['bytes'] ?? '') === "\x00\xfffile" && ($parts['count'][0]['bytes'] ?? '') === '0'
            && ($parts['asJson'][0]['bytes'] ?? '') === '"quoted"';
        foreach (array_merge($parts['profile'] ?? [], $parts['batch'] ?? []) as $part) {
            $value = json_decode($part['bytes'], true, 512, JSON_THROW_ON_ERROR);
            $valid = $valid && count($value) === 2 && ($value['code'] ?? '') === 'a/b' && ($value['nested']['flag'] ?? null) === false && !isset($value['id']);
        }
        $valid = $valid && ($parts['profile'][0]['media'] ?? '') === 'application/json'
            && ($parts['batch'][0]['media'] ?? '') === 'application/vnd.test+json';
    }
    fwrite($connection, ($valid ? 'HTTP/1.1 204 No Content' : 'HTTP/1.1 400 Bad Request') . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection); if (!$valid) { throw new RuntimeException('Form block changed encoding, JSON direction, multipart bytes or query separation.'); }
}
fclose($socket);
