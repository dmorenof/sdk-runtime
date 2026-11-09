<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Tests\Fixtures\Address;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class StatusResponseClient extends ApiClient
{
    public function fetch(): User|Address|null
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/objects', 'success' => [200, 201, 202, 204],
            'responseTypes' => [
                200 => ['type' => User::class, 'schemaNullable' => false],
                201 => ['type' => Address::class, 'schemaNullable' => false],
                202 => ['type' => User::class, 'schemaNullable' => true],
                204 => ['type' => null, 'schemaNullable' => false],
            ], 'errors' => [400 => Address::class]]);
    }
    public function fallback(): User|Address|null
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/fallback', 'success' => [200, 201, 204],
            'responseType' => User::class, 'noContentAllowed' => true,
            'responseTypes' => [201 => ['type' => Address::class, 'schemaNullable' => false]]]);
    }
}
final class StatusResponseTest extends TestCase
{
    private function client(int $status, string $body, bool $psr): StatusResponseClient
    {
        $http = new class($status, $body) implements ClientInterface {
            public function __construct(private int $status, private string $body) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                TestCase::assertSame('application/json', $request->getHeaderLine('Accept'));
                return new Response($this->status, [], $this->body);
            }
        };
        return StatusResponseClient::connect('https://example.test', httpClient: $http,
            transport: $psr ? null : new FakeTransport(new HttpResponse($status, $body)));
    }
    public function testStatusChoosesTheExactHydratorInBothTransportPaths(): void
    {
        foreach ([false, true] as $psr) {
            self::assertInstanceOf(User::class, $this->client(200, '{"name":"Ada"}', $psr)->fetch());
            self::assertInstanceOf(Address::class, $this->client(201, '{"city":"Madrid"}', $psr)->fetch());
            self::assertInstanceOf(User::class, $this->client(202, '{"name":"Ada"}', $psr)->fetch());
            self::assertNull($this->client(202, 'null', $psr)->fetch());
            self::assertNull($this->client(204, '', $psr)->fetch());
        }
    }
    public function testDoesNotTryAnotherSchemaWhenTheSelectedStatusBodyIsInvalid(): void
    {
        foreach ([false, true] as $psr) {
            foreach ([[200, '{"city":"Madrid"}'], [201, '{"name":"Ada"}'], [200, 'null'], [200, ''],
                [201, 'null'], [202, ''], [200, '{"name":42}'], [200, '{"name":"Ada","age":-1}']] as [$status, $body]) {
                try { $this->client($status, $body, $psr)->fetch(); self::fail('Invalid status-specific body accepted.'); }
                catch (\UnexpectedValueException|\InvalidArgumentException|\JsonException|\TypeError) { self::assertTrue(true); }
            }
        }
    }
    public function testUnexpectedStatusesKeepTypedAndRawApiExceptionBehavior(): void
    {
        foreach ([false, true] as $psr) {
            foreach ([400, 500] as $status) {
                try { $this->client($status, '{"city":"Madrid"}', $psr)->fetch(); self::fail('Unexpected success.'); }
                catch (ApiException $e) {
                    self::assertSame($status, $e->response->status);
                    if ($status === 400) { self::assertInstanceOf(Address::class, $e->error); }
                    else { self::assertNull($e->error); }
                }
            }
        }
    }
    public function testPartialMetadataFallsBackToTheLegacyResponseContract(): void
    {
        self::assertInstanceOf(User::class, $this->client(200, '{"name":"Ada"}', false)->fallback());
        self::assertInstanceOf(Address::class, $this->client(201, '{"city":"Madrid"}', false)->fallback());
        self::assertNull($this->client(204, '', false)->fallback());
        self::assertSame([], Operation::fromArray(['method' => 'GET', 'path' => '/'])->responseTypes);
    }
}
