<?php
declare(strict_types=1);

/**
 * A server for large bodies, on 127.0.0.1: it reads an upload and serves a download without ever holding more than one chunk, so the
 * runtime's streaming can be proved on a real socket (tests/real-streaming-smoke.php).
 *
 *   POST /uploads   reads the whole body, answers 204 when it is at least `min` bytes (query `min`, default 33554432), else 400
 *   GET  /download  answers 32 MiB of zero bytes with Content-Length and a Content-Disposition
 *
 * Prints its port on the first line and stops after 60 seconds without a connection.
 */
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
echo substr((string) $address, (int) strrpos((string) $address, ':') + 1), PHP_EOL;
flush();

const CHUNK = 65536;
const DOWNLOAD_BYTES = 32 * 1024 * 1024;

while (($connection = @stream_socket_accept($socket, 60)) !== false) {
    stream_set_timeout($connection, 30);
    $line = fgets($connection);
    if ($line === false) {
        fclose($connection);
        continue;
    }
    [$method, $target] = array_pad(explode(' ', trim($line), 3), 2, '');
    $headers = [];
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
        [$name, $value] = explode(':', $header, 2);
        $headers[strtolower($name)] = trim($value);
    }
    $path = (string) parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    if ($method === 'POST' && $path === '/uploads') {
        if (strtolower($headers['expect'] ?? '') === '100-continue') {
            fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
        }
        $received = 0;
        if (isset($headers['content-length'])) {
            for ($remaining = (int) $headers['content-length']; $remaining > 0;) {
                $chunk = fread($connection, min(CHUNK, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $received += strlen($chunk);
                $remaining -= strlen($chunk);
            }
        } else {
            // chunked transfer coding: size line, data, CRLF, until a zero size
            while (($sizeLine = fgets($connection)) !== false) {
                $size = (int) hexdec(trim($sizeLine));
                if ($size === 0) {
                    fgets($connection);
                    break;
                }
                for ($remaining = $size; $remaining > 0;) {
                    $chunk = fread($connection, min(CHUNK, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break 2;
                    }
                    $received += strlen($chunk);
                    $remaining -= strlen($chunk);
                }
                fgets($connection);
            }
        }
        $ok = $received >= (int) ($query['min'] ?? 32 * 1024 * 1024);
        fwrite($connection, ($ok ? "HTTP/1.1 204 No Content\r\n" : "HTTP/1.1 400 Bad Request\r\n") . "X-Received: $received\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } elseif ($method === 'GET' && $path === '/download') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: " . DOWNLOAD_BYTES
            . "\r\nContent-Disposition: attachment; filename=\"large.bin\"\r\nConnection: close\r\n\r\n");
        $block = str_repeat("\0", CHUNK);
        for ($sent = 0; $sent < DOWNLOAD_BYTES; $sent += CHUNK) {
            if (@fwrite($connection, $block) === false) {
                break;
            }
        }
    } else {
        fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
    @fclose($connection);
}
