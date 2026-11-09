<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/cookie-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port, headers: ['COOKIE' => 'default=base'], securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Session' => 'a+/b=='])) extends \Dmorenof\SdkRuntime\ApiClient {
        protected const array SECURITY_SCHEMES = ['Session' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'cookie', 'parameterName' => 'session']];
        public function call(string $method, \Dmorenof\SdkRuntime\DTO $params): void { $this->executeArguments(['method' => $method, 'path' => '/items', 'success' => [204], 'security' => [['Session' => []]]], payload: $params); }
    };
    $params = new class((object) ['theme' => 'a/b,ñ', 'enabled' => false, 'count' => 0, 'empty' => '', 'queryBody' => 'query body', 'raw' => 'caller=ok']) extends \Dmorenof\SdkRuntime\DTO {
        public const bool OPERATION_BODY = true;
        public const array OPERATION_FIELDS = ['cookie' => ['theme' => 'theme', 'enabled' => 'enabled', 'count' => 'count', 'empty' => 'empty'], 'query' => ['body' => 'queryBody'], 'header' => ['cookie' => 'raw']];
        public string $theme; public bool $enabled; public int $count; public string $empty; public string $queryBody; public string $raw; public \Dmorenof\SdkRuntime\DTO $body;
    };
    $client->call('GET', $params); $params->body = new class((object) ['name' => 'body name']) extends \Dmorenof\SdkRuntime\DTO { public string $name; }; $client->call('POST', $params);
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'COOKIE_RUNTIME_HTTP_OK', PHP_EOL;
