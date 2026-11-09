<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/delimited-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public function send(string $method): void {
            $this->executeOperation(['method' => $method, 'path' => '/items/{spaces}/{tabs}/{pipes}', 'success' => [204],
                'pathSerialization' => ['spaces' => 'ssv', 'tabs' => 'tsv', 'pipes' => 'pipes'],
                'headerSerialization' => ['X-Spaces' => 'ssv', 'X-Tabs' => 'tsv', 'X-Pipes' => 'pipes']],
                pathParameters: array_fill_keys(['spaces', 'tabs', 'pipes'], ['a/b', '%2F', 'ñ']),
                queryParameters: ['body' => 'query body'], headerParameters: array_fill_keys(['X-Spaces', 'X-Tabs', 'X-Pipes'], ['alpha', 'beta']),
                body: $method === 'POST' ? new class((object) ['name' => 'Ada']) extends \Dmorenof\SdkRuntime\DTO { public string $name; } : null);
        }
    };
    $client->send('GET'); $client->send('POST');
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'DELIMITED_PARAMETER_RUNTIME_HTTP_OK', PHP_EOL;
