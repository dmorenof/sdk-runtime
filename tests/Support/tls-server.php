<?php
declare(strict_types=1);

/**
 * A TLS server on 127.0.0.1 that requires a client certificate signed by a given CA (mutual TLS), for tests on a real socket.
 *
 * usage: php tls-server.php <server certificate and key, PEM> <CA certificate, PEM> <request log file>
 *
 * Prints its port on the first line. Every request that completes the handshake is answered 204 and appended to the log as one JSON
 * line: method, target and the subject common name of the client certificate it presented. A client without a certificate, or with one
 * the CA did not sign, fails the handshake and never reaches the log. Stops after 30 seconds without a connection.
 */
$certificate = $argv[1] ?? throw new RuntimeException('Missing server certificate.');
$ca = $argv[2] ?? throw new RuntimeException('Missing CA certificate.');
$log = $argv[3] ?? throw new RuntimeException('Missing log file.');
$context = stream_context_create(['ssl' => [
    'local_cert' => $certificate,
    'cafile' => $ca,
    'verify_peer' => true,
    'verify_peer_name' => false,
    'capture_peer_cert' => true,
    'allow_self_signed' => false,
]]);
$socket = stream_socket_server('ssl://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
echo substr((string) $address, (int) strrpos((string) $address, ':') + 1), PHP_EOL;
flush();

while (true) {
    $waitingSince = microtime(true);
    $connection = @stream_socket_accept($socket, 30);
    if ($connection === false) {
        if (microtime(true) - $waitingSince >= 29) {
            break; // nobody came
        }
        continue; // a failed handshake: the next client
    }
    stream_set_timeout($connection, 10);
    $line = fgets($connection);
    while (($header = fgets($connection)) !== false && trim($header) !== '') {
    }
    $peer = stream_context_get_options($connection)['ssl']['peer_certificate'] ?? null;
    $subject = $peer !== null ? (openssl_x509_parse($peer)['subject']['CN'] ?? null) : null;
    [$method, $target] = array_pad(explode(' ', trim((string) $line), 3), 2, '');
    file_put_contents($log, json_encode(['method' => $method, 'target' => $target, 'clientCn' => $subject], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    @fwrite($connection, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    @fclose($connection);
}
