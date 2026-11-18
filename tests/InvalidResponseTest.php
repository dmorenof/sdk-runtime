<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\InvalidResponseException;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Tests\Support\Cfg;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A success status with a body that does not fit is one failure type, with the raw response and the original cause. */
final class InvalidResponseTest extends TestCase
{
    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function badBodies(): iterable
    {
        yield 'empty body' => ['', \JsonException::class];
        yield 'not json' => ['<html>', \JsonException::class];
        yield 'wrong scalar type' => ['{"id":"x","name":"Ada"}', \UnexpectedValueException::class];
        yield 'missing required field' => ['{"id":1}', \UnexpectedValueException::class];
        yield 'enum value the schema does not list' => ['{"id":1,"name":"Ada","role":"root"}', \ValueError::class];
    }

    /** @param class-string<\Throwable> $cause */
    #[DataProvider('badBodies')]
    public function testEveryUnreadableBodyIsAnInvalidResponse(string $body, string $cause): void
    {
        $client = TestClient::connect(Cfg::make('https://api.example.com', transport: new FakeTransport(new HttpResponse(200, $body)), securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Bearer' => 't'])));

        try {
            $client->getUser(1);
            self::fail('Expected an invalid response.');
        } catch (InvalidResponseException $e) {
            self::assertSame($body, $e->response->body);
            self::assertSame(200, $e->getCode());
            self::assertInstanceOf($cause, $e->getPrevious());
            self::assertStringContainsString('Invalid response from', $e->getMessage());
        }
    }
}
