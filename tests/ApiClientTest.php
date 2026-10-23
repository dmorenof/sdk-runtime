<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\Address;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    private function client(int $status, string $body): array
    {
        $transport = new FakeTransport(new HttpResponse($status, $body));
        $client = TestClient::connect(
            'https://api.example.com/',
            securityCredentials: new SecurityCredentials(['Bearer' => 'secret']),
            transport: $transport,
        );
        return [$client, $transport];
    }

    public function testBuildsRequestAndHydratesResponse(): void
    {
        [$client, $transport] = $this->client(200, '{"id":1,"name":"Grace"}');

        $user = $client->getUser(1, true);

        self::assertSame('Grace', $user->name);
        self::assertSame('GET', $transport->sent[0]['method']);
        self::assertSame('https://api.example.com/users/1?verbose=true', $transport->sent[0]['url']);
        self::assertSame('Bearer secret', $transport->sent[0]['headers']['Authorization']);
    }

    public function testRejectsInvalidParameterBeforeSending(): void
    {
        [$client, $transport] = $this->client(200, '{}');

        try {
            $client->getUser(0);
            self::fail('Expected validation failure.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
    }

    public function testRefusesToSendWithoutRequiredCredentials(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{}'));
        $client = TestClient::connect('https://api.example.com', transport: $transport);

        try {
            $client->getUser(1);
            self::fail('Expected missing credentials failure.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->sent);
        }
    }

    public function testThrowsTypedApiExceptionForUnexpectedStatus(): void
    {
        [$client] = $this->client(404, '{"city":"Nowhere"}');

        try {
            $client->getUser(1);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->response->status);
            self::assertInstanceOf(Address::class, $exception->error);
            self::assertSame('Nowhere', $exception->error->city);
        }
    }

    public function testKeepsRawResponseWhenErrorBodyCannotBeDecoded(): void
    {
        [$client] = $this->client(404, 'not json');

        try {
            $client->getUser(1);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertNull($exception->error);
            self::assertSame('not json', $exception->response->body);
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testSerializesRequestBodyWithoutReadOnlyFields(): void
    {
        [$client, $transport] = $this->client(200, '{"name":"Grace"}');

        $client->createUser(new User((object) ['id' => 9, 'name' => 'Grace']));

        self::assertSame('{"name":"Grace"}', $transport->sent[0]['body']);
        self::assertSame('application/json', $transport->sent[0]['headers']['Content-Type']);
    }

    public function testOperationDeclaringOnlyMethodAndPathInheritsDefaults(): void
    {
        [$client, $transport] = $this->client(200, '');

        $client->ping();

        self::assertSame('https://api.example.com/ping', $transport->sent[0]['url']);
        self::assertArrayNotHasKey('Authorization', $transport->sent[0]['headers']);
    }

    public function testNoContentSuccessReturnsNull(): void
    {
        [$client] = $this->client(204, '');

        self::assertNull($client->deleteUser(1));
    }

    public function testDefaultLabelNamesMethodAndRouteInErrors(): void
    {
        [$client] = $this->client(500, '');

        try {
            $client->ping();
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertStringContainsString('GET /ping', $exception->getMessage());
        }
    }

    public function testResourceSharesTransportAndCredentials(): void
    {
        [$client, $transport] = $this->client(200, '{"name":"Grace"}');

        $client->resource(TestClient::class)->getUser(1);

        self::assertCount(1, $transport->sent);
        self::assertSame('Bearer secret', $transport->sent[0]['headers']['Authorization']);
    }
}
