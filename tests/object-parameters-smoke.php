<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$http = new class implements \Psr\Http\Client\ClientInterface {
    public int $calls = 0;
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        ++$this->calls;
        if ((string) $request->getUri() !== 'https://example.test/items/code,a%2Cb,active,false?filter=code,safe,active,false&tag=x%20y&body=query%20body'
            || $request->getHeaderLine('X-Filter') !== 'code=safe,active=false') { throw new RuntimeException('Object parameter contract changed.'); }
        return new \GuzzleHttp\Psr7\Response(204);
    }
};
$client = new class('https://example.test', httpClient: $http) extends \Dmorenof\SdkRuntime\ApiClient {
    public function submit(\Dmorenof\SdkRuntime\DTO $path, \Dmorenof\SdkRuntime\DTO $payload): void {
        $this->executeArguments(['method' => 'GET', 'path' => '/items/{selector}', 'success' => [204],
            'pathSerialization' => ['selector' => 'object'], 'headerSerialization' => ['X-Filter' => 'object-explode'],
            'querySerialization' => ['filter' => 'form-object', 'labels' => 'form-object-explode']], identifier: $path, payload: $payload);
    }
};
$selector = new class((object) ['code' => 'a,b', 'active' => false]) extends \Dmorenof\SdkRuntime\DTO { public string $code; public bool $active; };
$path = new class extends \Dmorenof\SdkRuntime\DTO {
    public const array OPERATION_FIELDS = ['path' => ['selector' => 'selector'], 'query' => [], 'header' => []];
    public \Dmorenof\SdkRuntime\DTO $selector;
};
$path->selector = $selector;
$filter = new class((object) ['code' => 'safe', 'active' => false]) extends \Dmorenof\SdkRuntime\DTO { public string $code; public bool $active; };
$labels = new class((object) ['tag' => 'x y']) extends \Dmorenof\SdkRuntime\Map { protected const string VALUE_TYPE = 'string'; };
$payload = new class extends \Dmorenof\SdkRuntime\DTO {
    public const array OPERATION_FIELDS = ['path' => [], 'query' => ['filter' => 'filter', 'labels' => 'labels', 'body' => 'queryBody'], 'header' => ['X-Filter' => 'filter']];
    public \Dmorenof\SdkRuntime\DTO $filter;
    public \Dmorenof\SdkRuntime\Map $labels;
    public string $queryBody = 'query body';
};
$payload->filter = $filter; $payload->labels = $labels;
$client->submit($path, $payload);
$labels['body'] = 'collision';
try { $client->submit($path, $payload); throw new RuntimeException('Query collision accepted.'); }
catch (UnexpectedValueException) {}
if ($http->calls !== 1) { throw new RuntimeException('Collision reached transport.'); }
echo 'OBJECT_PARAMETER_RUNTIME_OK', PHP_EOL;
