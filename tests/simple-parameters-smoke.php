<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$http = new class implements \Psr\Http\Client\ClientInterface {
    public int $calls = 0;
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        ++$this->calls;
        if ((string) $request->getUri() === 'https://example.test/numeric/a%2Cb,c%2Fd') {
            if ($request->getHeaderLine('0') !== 'alpha,beta') { throw new RuntimeException('Numeric header name lost.'); }
            return new \GuzzleHttp\Psr7\Response(204);
        }
        if ((string) $request->getUri() !== 'https://example.test/items/a%2Cb,c%2Fd,%252F,%C3%B1?body=query%20body&q=2&q=false'
            || $request->getHeaderLine('X-Codes') !== 'alpha,beta' || (string) $request->getBody() !== '{"name":"Ada"}') {
            throw new RuntimeException('Simple lists changed path, header, query or JSON body.');
        }
        return new \GuzzleHttp\Psr7\Response(204);
    }
};
$client = new class('https://example.test', httpClient: $http) extends \Dmorenof\SdkRuntime\ApiClient {
    public function numeric(): void {
        $this->executeOperation(['method' => 'GET', 'path' => '/numeric/{0}', 'success' => [204],
            'pathSerialization' => ['0' => 'csv'], 'headerSerialization' => ['0' => 'csv']],
            pathParameters: ['0' => ['a,b', 'c/d']], headerParameters: ['0' => ['alpha', 'beta']]);
    }
    public function submit(\Dmorenof\SdkRuntime\DTO $path, \Dmorenof\SdkRuntime\DTO $payload): void {
        $this->executeArguments(['method' => 'POST', 'path' => '/items/{ids}', 'success' => [204],
            'pathSerialization' => ['ids' => 'csv'], 'headerSerialization' => ['X-Codes' => 'csv'],
            'querySerialization' => ['q' => 'multi']], identifier: $path, payload: $payload);
    }
};
$path = new class((object) ['ids' => ['a,b', 'c/d', '%2F', 'ñ']]) extends \Dmorenof\SdkRuntime\DTO {
    public const array REQUIRED_FIELDS = ['ids'];
    public const array OPERATION_FIELDS = ['path' => ['ids' => 'ids'], 'query' => [], 'header' => []];
    protected const array VALIDATION = ['ids' => ['minItems' => 1, 'items' => ['type' => 'string', 'rules' => []]]];
    public array $ids;
};
$form = new class((object) ['name' => 'Ada']) extends \Dmorenof\SdkRuntime\DTO { public string $name; };
$payload = new class((object) ['queryBody' => 'query body', 'q' => [2, false], 'codes' => ['alpha', 'beta']]) extends \Dmorenof\SdkRuntime\DTO {
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['path' => [], 'query' => ['body' => 'queryBody', 'q' => 'q'], 'header' => ['X-Codes' => 'codes']];
    public string $queryBody;
    public array $q;
    public array $codes;
    public \Dmorenof\SdkRuntime\DTO $body;
};
$payload->body = $form;
$client->submit($path, $payload);
$payload->codes = ['a,b'];
try { $client->submit($path, $payload); throw new RuntimeException('Ambiguous header accepted.'); }
catch (UnexpectedValueException) {}
$payload->codes = ['alpha', 'beta']; $path->ids = [];
try { $client->submit($path, $payload); throw new RuntimeException('Path mutation accepted.'); }
catch (UnexpectedValueException) {}
if ($http->calls !== 1) { throw new RuntimeException('Invalid mutation reached HTTP.'); }
$client->numeric();
echo 'SIMPLE_PARAMETER_RUNTIME_OK', PHP_EOL;
