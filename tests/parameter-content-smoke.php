<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/parameter-content-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $payload = static fn (string $name) => new class((object) ['id' => 7, 'name' => $name]) extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['name']; protected const array READ_ONLY_FIELDS = ['id']; public int $id; public string $name; };
    $path = new class extends \Dmorenof\SdkRuntime\DTO { public const array OPERATION_FIELDS = ['path' => ['selector' => 'selector', 'token' => 'token']]; public \Dmorenof\SdkRuntime\DTO $selector; public string $token; };
    $path->selector = new class((object) ['code' => 'path/x', 'flag' => false]) extends \Dmorenof\SdkRuntime\DTO { public string $code; public bool $flag; }; $path->token = 'a/b%';
    $params = new class extends \Dmorenof\SdkRuntime\DTO {
        public const bool OPERATION_BODY = true;
        public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody', 'records' => 'records', 'empty' => 'empty', 'scalar' => 'scalar', 'active' => 'active', 'values' => 'values', 'plain' => 'plain', 'optional' => 'optional'], 'header' => ['X-JSON' => 'filter', 'X-Trace' => 'trace'], 'cookie' => ['session' => 'session', 'theme' => 'theme']];
        public \Dmorenof\SdkRuntime\DTO $queryBody; public \Dmorenof\SdkRuntime\TypedArray $records; public \Dmorenof\SdkRuntime\TypedArray $empty; public string $scalar; public bool $active; public \Dmorenof\SdkRuntime\Map $values; public string $plain; public ?string $optional; public \Dmorenof\SdkRuntime\DTO $filter; public string $trace; public string $session; public string $theme; public \Dmorenof\SdkRuntime\DTO $body;
    };
    $params->queryBody = $payload('query/x'); $params->records = new class([$payload('row/x')]) extends \Dmorenof\SdkRuntime\TypedArray { protected string $expected_type = \Dmorenof\SdkRuntime\DTO::class; }; $params->empty = new class([]) extends \Dmorenof\SdkRuntime\TypedArray { protected string $expected_type = 'string'; };
    $params->scalar = ''; $params->active = false; $params->values = new \Dmorenof\SdkRuntime\Map((object) ['0' => 'zero']); $params->plain = '100% / ñ'; $params->optional = null;
    $params->filter = new class((object) ['code' => 'header/x', 'flag' => false]) extends \Dmorenof\SdkRuntime\DTO { public string $code; public bool $flag; }; $params->trace = 'h,/=x'; $params->session = 'a+/b=='; $params->theme = 'a/b';
    $client = new class('http://127.0.0.1:' . $port, headers: ['Cookie' => 'default=base'], securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => 'token+/=='])) extends \Dmorenof\SdkRuntime\ApiClient {
        protected const array SECURITY_SCHEMES = ['Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'auth']];
        public function call(string $method, \Dmorenof\SdkRuntime\DTO $path, \Dmorenof\SdkRuntime\DTO $params): void {
            $this->executeArguments(['method' => $method, 'path' => '/items/{selector}/{token}', 'success' => [204], 'security' => [['Key' => []]], 'parameterContent' => ['path' => ['selector' => 'application/json', 'token' => 'text/plain'], 'query' => ['body' => 'application/json', 'records' => 'application/json', 'empty' => 'application/json', 'scalar' => 'application/json', 'active' => 'application/json', 'values' => 'application/json', 'plain' => 'text/plain', 'optional' => 'text/plain'], 'header' => ['X-JSON' => 'application/json', 'X-Trace' => 'text/plain'], 'cookie' => ['session' => 'text/plain']]], $path, payload: $params);
        }
        public function scalar(): void { $this->executeOperation(['method' => 'GET', 'path' => '/scalar/{id}', 'success' => [204], 'security' => [['Key' => []]], 'parameterContent' => ['path' => ['id' => 'application/json']]], pathParameters: ['id' => 'Cat']); }
    };
    $client->call('GET', $path, $params); $params->body = $payload('body/x'); $client->call('POST', $path, $params); $client->scalar();
    $params->records[] = $payload('second'); unset($params->records[0]); $client->call('POST', $path, $params);
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PARAMETER_CONTENT_RUNTIME_HTTP_OK', PHP_EOL;
