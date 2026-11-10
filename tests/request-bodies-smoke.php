<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/request-bodies-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public function post(string $path, mixed $value, string $type = 'mixed', bool $present = true, ?\Dmorenof\SdkRuntime\DTO $params = null): mixed {
            return $this->executeBody(['method' => 'POST', 'path' => $path, 'success' => [204],
                'requestBody' => ['type' => $type, 'required' => false, 'nullable' => true]], $value, $present, 7, 'id', $params);
        }
    };
    $client->post('/scalar', 'valid', 'string');
    $client->post('/optional', null, present: false); $client->post('/optional', null);
    $client->post('/optional', 0); $client->post('/optional', 'text'); $client->post('/null', null);
    $user = new class((object) ['name' => 'Alice', 'readOnlyId' => 7]) extends \Dmorenof\SdkRuntime\DTO {
        public const array REQUIRED_FIELDS = ['name']; protected const array READ_ONLY_FIELDS = ['readOnlyId'];
        protected const array VALIDATION = ['name' => ['minLength' => 2]];
        public string $name; public int $readOnlyId;
    };
    $params = new class((object) ['queryBody' => 'separate', 'header' => 'header']) extends \Dmorenof\SdkRuntime\DTO {
        public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody'], 'header' => ['X-Test' => 'header']];
        public const bool OPERATION_BODY = true;
        public ?string $queryBody; public ?string $header; public array $body;
    };
    $params->body = [$user]; $client->post('/list/{id}', null, 'array', params: $params);
    $client->post('/map', (object) ['one' => 1]); $client->post('/loose', [1, 'x', null], 'array');
    $client->post('/open', (object) ['nested' => [false, null]]); $client->post('/never', null, present: false);
    $user->name = 'x';
    try { $client->post('/list/{id}', null, 'array', params: $params); throw new RuntimeException('Invalid nested mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'REQUEST_BODIES_RUNTIME_HTTP_OK', PHP_EOL;
