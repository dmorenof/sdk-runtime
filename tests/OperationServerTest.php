<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class ServerRoutingClient extends ApiClient
{
    public function root(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/root']);
    }

    public function routed(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/items/{id}',
            'serverUrl' => 'https://platform.example.test/v2/'],
            pathParameters: ['id' => 'a/b'], queryParameters: ['q' => 'a b']);
    }

    public function invalid(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/items', 'serverUrl' => 'https://{tenant}.example.test']);
    }
}

final class OperationServerTest extends TestCase
{
    public function testDeclaredOperationServerBuildsExactUrlIncludingPrefixAndEncoding(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        $client = ServerRoutingClient::connect('https://root.test/v1', transport: $transport);
        $client->routed();
        $client->root();
        self::assertSame('https://platform.example.test/v2/items/a%2Fb?q=a%20b', $transport->sent[0]['url']);
        self::assertSame('https://root.test/v1/root', $transport->sent[1]['url']);
        self::assertSame('https://platform.example.test/v2/', Operation::fromArray([
            'method' => 'GET', 'path' => '/items', 'serverUrl' => 'https://platform.example.test/v2/',
        ])->serverUrl);
    }

    public function testOverridesAreSharedWithResourcesAndIsolatedBetweenConnections(): void
    {
        $first = new FakeTransport(new HttpResponse(200, ''));
        $second = new FakeTransport(new HttpResponse(200, ''));
        $client = ServerRoutingClient::connect('https://root.test', transport: $first,
            serverOverrides: ['https://platform.example.test/v2' => 'http://127.0.0.1:9090/mock/']);
        $other = ServerRoutingClient::connect('https://root.test', transport: $second,
            serverOverrides: ['https://platform.example.test/v2/' => 'https://staging.test/api']);
        $client->resource(ServerRoutingClient::class)->routed();
        $other->resource(ServerRoutingClient::class)->routed();
        self::assertSame('http://127.0.0.1:9090/mock/items/a%2Fb?q=a%20b', $first->sent[0]['url']);
        self::assertSame('https://staging.test/api/items/a%2Fb?q=a%20b', $second->sent[0]['url']);
    }

    public function testInvalidOperationServerFailsBeforeTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        $client = ServerRoutingClient::connect('https://root.test', transport: $transport);
        try {
            $client->invalid();
            self::fail('Expected unsupported server failure.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->sent);
        }
    }

    public function testInvalidEnvironmentOverrideIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServerRoutingClient::connect('https://root.test', serverOverrides: [
            'https://platform.example.test/v2' => 'https://example.test/api?tenant=1',
        ]);
    }
}
