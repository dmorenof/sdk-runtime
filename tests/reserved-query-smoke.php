<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/reserved-query-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing HTTP port.'); }
    $client = new class('http://127.0.0.1:' . $port, securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => '%2F'])) extends \Dmorenof\SdkRuntime\ApiClient {
        protected const array SECURITY_SCHEMES = ['Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'query', 'parameterName' => 'k']];
        public function call(string $method, ?\Dmorenof\SdkRuntime\DTO $body = null): void {
            $this->executeOperation(['method' => $method, 'path' => '/items', 'success' => [204], 'security' => [['Key' => []]], 'queryAllowReserved' => ['q', 'body', 'labels', 'filter', 'k'], 'querySerialization' => ['labels' => 'multi', 'filter' => 'deepObject']],
                queryParameters: ['q' => 'a/b%26c?x', 'body' => 'query/body', 'labels' => ['a/b', 'c?d'], 'filter' => (object) ['code' => 'x/y'], 'plain' => 'a/b', 'k' => 'ignored/value'], headerParameters: ['X-Trace' => 'h/x', 'Cookie' => 'theme=a%2Fb'], body: $body);
        }
    };
    $client->call('GET');
    $client->call('POST', new class((object) ['name' => 'body/x']) extends \Dmorenof\SdkRuntime\DTO { public string $name; });
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'RESERVED_QUERY_RUNTIME_HTTP_OK', PHP_EOL;
