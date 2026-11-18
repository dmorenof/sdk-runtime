<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\InvalidResponseException;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

/** The answers worked out for an operation are reused; each call must still see its own response. */
final class OperationReuseTest extends TestCase
{
    /** @param list<HttpResponse> $answers */
    private function client(array $answers): TestClient
    {
        $transport = new class($answers) implements TransportInterface {
            /** @param list<HttpResponse> $answers */
            public function __construct(private array $answers) {}
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                return array_shift($this->answers) ?? throw new \LogicException('No answer left.');
            }
        };
        return TestClient::connect(\Dmorenof\SdkRuntime\Tests\Support\Cfg::make('https://api.example.com/', securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Bearer' => 'secret']), transport: $transport));
    }

    public function testRepeatedCallsOfOneOperationKeepTheirOwnOutcome(): void
    {
        $client = $this->client([
            new HttpResponse(200, '{"id":1,"name":"Ada"}'),
            new HttpResponse(404, '{"message":"gone"}'),
            new HttpResponse(200, '{"id":2,"name":"Grace"}'),
            new HttpResponse(200, 'not json'),
            new HttpResponse(200, '{"id":3,"name":"Linus"}'),
        ]);

        self::assertSame('Ada', $client->getUserReusingOperation(1)->name);
        try {
            $client->getUserReusingOperation(2);
            self::fail('A 404 must be an ApiException.');
        } catch (ApiException $error) {
            self::assertSame(404, $error->response->status);
        }
        self::assertSame('Grace', $client->getUserReusingOperation(3)->name);
        try {
            $client->getUserReusingOperation(4);
            self::fail('A body that is not JSON must be refused.');
        } catch (InvalidResponseException) {
            self::assertTrue(true);
        }
        self::assertSame('Linus', $client->getUserReusingOperation(5)->name);
    }

    public function testLastResponseFollowsTheLatestCall(): void
    {
        $client = $this->client([
            new HttpResponse(200, '{"id":1,"name":"Ada"}', ['X-Call' => ['first']]),
            new HttpResponse(404, '{"message":"gone"}', ['X-Call' => ['second']]),
        ]);

        self::assertNull($client->lastResponse());
        $client->getUserReusingOperation(1);
        self::assertSame(200, $client->lastResponse()?->status);
        self::assertSame('first', $client->lastResponse()?->headers->first('X-Call'));
        try {
            $client->getUserReusingOperation(2);
        } catch (ApiException) {
        }
        self::assertSame(404, $client->lastResponse()?->status);
        self::assertSame('second', $client->lastResponse()?->headers->first('X-Call'));
    }
}
