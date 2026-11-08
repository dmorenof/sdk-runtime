<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class SharedBody extends DTO
{
    public const array REQUIRED_FIELDS = ['name'];
    protected const array VALIDATION = ['name' => ['minLength' => 1]];
    public string $name;
}

class SharedParameters extends DTO
{
    public const bool OPERATION_BODY = true;
    public const array OPERATION_FIELDS = ['path' => [], 'query' => ['body' => 'queryBody'], 'header' => ['X-Trace' => 'trace']];
    public string $queryBody;
    public string $trace;
    public SharedBody $body;
}

class SharedProbe extends ApiClient
{
    public function submit(SharedParameters $params): void
    {
        $this->executeArguments(['method' => 'POST', 'path' => '/items/{id}', 'success' => [204],
            'requestMediaType' => 'application/x-www-form-urlencoded'], 7, 'id', $params);
    }
}

final class SharedExecutionTest extends TestCase
{
    public function testInheritedExecutorKeepsLocationAndBodyNamesSeparate(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $params = new SharedParameters((object) ['queryBody' => 'query +', 'trace' => 'trace', 'body' => (object) ['name' => 'body name']]);
        SharedProbe::connect('https://api.example.test/v1', transport: $transport)->submit($params);
        self::assertSame('https://api.example.test/v1/items/7?body=query%20%2B', $transport->sent[0]['url']);
        self::assertSame('name=body+name', $transport->sent[0]['body']);
        self::assertSame('trace', $transport->sent[0]['headers']['X-Trace']);
        self::assertSame('application/x-www-form-urlencoded', $transport->sent[0]['headers']['Content-Type']);
        $params->body->name = '';
        try {
            SharedProbe::connect('https://api.example.test/v1', transport: $transport)->submit($params);
            self::fail('Invalid inherited body mutation reached HTTP.');
        } catch (\UnexpectedValueException) {
            self::assertCount(1, $transport->sent);
        }
    }

    public function testDerivedConfigurationKeepsDefaultsAndInstancesIndependent(): void
    {
        $a = new class extends Configuration { public string $baseUrl = 'https://sdk.example.test'; };
        $b = new Configuration();
        $a->headers['X-Trace'] = 'one';
        self::assertSame('https://sdk.example.test', $a->baseUrl);
        self::assertSame('', $b->baseUrl);
        self::assertSame([], $b->headers);
        self::assertSame([], $b->middleware);
    }
}
