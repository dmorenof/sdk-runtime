<?php
declare(strict_types=1);

/**
 * Large bodies on a real socket: a 32 MiB multipart upload (one file, then a list of two) and a 32 MiB download, each in a PHP process
 * limited to 16 MiB, against a local server that never holds more than a chunk (Support/streaming-server.php). The fake-client runs of
 * streaming-smoke.php and download-smoke.php (ci/run-tests.sh) prove the runtime hands the stream on; this proves the bytes really cross.
 *
 * usage: php tests/real-streaming-smoke.php <path to vendor/autoload.php>
 */
$autoload = $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$process = proc_open([PHP_BINARY, __DIR__ . '/Support/streaming-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start the streaming server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('The streaming server printed no port.');
    }
    $url = "http://127.0.0.1:$port";
    $runs = [
        ['streaming-smoke.php', [$autoload, $url], 'STREAMING_32M_UNDER_16M_OK'],
        ['streaming-smoke.php', [$autoload, $url, 'list'], 'STREAMING_FILE_LIST_32M_UNDER_16M_OK'],
        ['download-smoke.php', [$autoload, $url], 'DOWNLOAD_32M_UNDER_16M_OK'],
    ];
    foreach ($runs as [$script, $arguments, $marker]) {
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'memory_limit=16M', __DIR__ . '/' . $script, ...$arguments]));
        exec($command . ' 2>&1', $output, $status);
        $text = implode("\n", $output);
        $output = [];
        if ($status !== 0 || !str_contains($text, $marker)) {
            throw new RuntimeException("$script on a real socket failed (exit $status): $text");
        }
        echo 'REAL_', $marker, PHP_EOL;
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);
}
