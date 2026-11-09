<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$user = new class extends \Dmorenof\SdkRuntime\DTO {
    public const array REQUIRED_FIELDS = ['name'];
    public string $name;
};
$created = new class extends \Dmorenof\SdkRuntime\DTO {
    public const array REQUIRED_FIELDS = ['name', 'ticket'];
    public string $name;
    public string $ticket;
};
$types = [200 => ['type' => $user::class, 'schemaNullable' => false],
    201 => ['type' => $created::class, 'schemaNullable' => false],
    202 => ['type' => $user::class, 'schemaNullable' => true],
    204 => ['type' => null, 'schemaNullable' => false]];
$http = new class implements \Psr\Http\Client\ClientInterface {
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $scenario = (int) ($query['scenario'] ?? 0);
        [$status, $body] = match ($scenario) {
            200 => [200, '{"name":"Ada"}'], 201 => [201, '{"name":"Ada","ticket":"created"}'],
            202 => [202, 'null'], 204 => [204, ''], 400 => [400, 'error'],
            default => [200, ''],
        };
        return new \GuzzleHttp\Psr7\Response($status, [], $body);
    }
};
$client = new class('https://example.test', httpClient: $http) extends \Dmorenof\SdkRuntime\ApiClient {
    public function fetch(int $scenario, array $types): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/objects', 'success' => [200, 201, 202, 204],
            'responseTypes' => $types], queryParameters: ['scenario' => $scenario]);
    }
};
if ($client->fetch(200, $types)::class !== $user::class || $client->fetch(201, $types)::class !== $created::class
    || $client->fetch(202, $types) !== null || $client->fetch(204, $types) !== null) {
    throw new RuntimeException('Status-specific hydration failed.');
}
try { $client->fetch(0, $types); throw new RuntimeException('Empty 200 accepted because 204 exists.'); }
catch (JsonException) {}
try { $client->fetch(400, $types); throw new RuntimeException('Unexpected status accepted.'); }
catch (\Dmorenof\SdkRuntime\ApiException $e) {
    if ($e->response->status !== 400 || $e->response->body !== 'error') { throw new RuntimeException('Error contract changed.'); }
}
echo 'STATUS_RESPONSE_RUNTIME_OK', PHP_EOL;
