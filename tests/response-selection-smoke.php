<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$userType = (new class extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['name']; public string $name; })::class;
$addressType = (new class extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['city']; public string $city; })::class;
$process = proc_open([PHP_BINARY, __DIR__ . '/response-selection-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public string $userType;
        public string $addressType;
        public function fetch(int $status, bool $post = false, bool $fallback = false): mixed {
            $body = new class((object) ['name' => 'request']) extends \Dmorenof\SdkRuntime\DTO { public string $name; };
            return $this->executeOperation(['method' => $post ? 'POST' : 'GET', 'path' => $fallback ? '/fallback' : '/responses',
                'success' => $fallback ? ['default'] : ['2XX'],
                'responseTypes' => $fallback ? ['default' => ['type' => $this->addressType, 'schemaNullable' => false]] : [
                    201 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false],
                    204 => ['type' => null, 'schemaNullable' => false], '2XX' => ['type' => $this->userType, 'schemaNullable' => false]],
                'errors' => [400 => null, '4XX' => $this->userType, 'default' => $this->addressType]],
                queryParameters: ['status' => $status, 'body' => 'query body'], headerParameters: ['X-Trace' => 'trace'], body: $post ? $body : null);
        }
    };
    $client->userType = $userType; $client->addressType = $addressType;
    foreach ([200, 201, 204, 299, 400, 404, 500] as $status) {
        try {
            $result = $client->fetch($status);
            if ($status >= 400) { throw new RuntimeException('Error status accepted.'); }
            if ($status === 201) {
                if (!$result instanceof \Psr\Http\Message\ResponseInterface || $result->getBody()->tell() !== 0 || (string) $result->getBody() !== "\x00\xffbinary" || $result->getHeaderLine('X-Result') !== 'preserved') { throw new RuntimeException('Binary stream changed.'); }
            } elseif ($status === 204 ? $result !== null : !$result instanceof $userType) { throw new RuntimeException('Wrong selected result.'); }
        } catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || ($status === 400 ? $error->error !== null : !($error->error instanceof ($status === 404 ? $userType : $addressType)))) { throw new RuntimeException('Wrong error selection.'); }
        }
    }
    if (!$client->fetch(201, true) instanceof \Psr\Http\Message\ResponseInterface || !$client->fetch(202, fallback: true) instanceof $addressType) { throw new RuntimeException('POST or default success changed.'); }
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'RESPONSE_SELECTION_RUNTIME_HTTP_OK', PHP_EOL;
