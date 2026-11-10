<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Tests\Fixtures\Address;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ResponseSelectionTest extends TestCase {
    public function testExactRangeAndDefaultPrecedenceIncludesEmptyDeclarations(): void {
        $operation = new Operation('GET', '/', success: ['2XX', 'default'],
            errors: [400 => null, '4XX' => User::class, 'default' => Address::class],
            responseTypes: [204 => ['type' => null, 'schemaNullable' => false], '2XX' => ['type' => User::class, 'schemaNullable' => false], 'default' => ['type' => Address::class, 'schemaNullable' => false]]);
        self::assertNull($operation->errorTypeFor(400));
        self::assertSame(User::class, $operation->errorTypeFor(404));
        self::assertSame(Address::class, $operation->errorTypeFor(500));
        self::assertNull($operation->responseTypeFor(204)['type']);
        self::assertSame(User::class, $operation->responseTypeFor(299)['type']);
        self::assertSame(Address::class, $operation->responseTypeFor(500)['type']);
        foreach ([200, 204, 299] as $status) { self::assertTrue($operation->succeeded($status)); }
        foreach ([199, 300, 400, 500] as $status) { self::assertFalse($operation->succeeded($status)); }
    }
    public function testMixedResponseSelectionPreservesBinaryStreamsAndErrorFallbackInBothTransports(): void {
        foreach ([false, true] as $psr) {
            foreach ([200 => '{"name":"Ada"}', 201 => "\x00\xffbinary", 204 => '', 299 => '{"name":"last"}', 400 => '', 404 => '{"name":"missing"}', 500 => '{"city":"Madrid"}'] as $status => $body) {
                $response = new Response($status, ['X-Test' => 'preserved'], $body);
                $http = new class($response) implements ClientInterface {
                    public function __construct(private ResponseInterface $response) {}
                    public function sendRequest(RequestInterface $request): ResponseInterface {
                        TestCase::assertSame('application/json, application/octet-stream', $request->getHeaderLine('Accept'));
                        return $this->response;
                    }
                };
                $client = new class('https://example.test', httpClient: $http, transport: $psr ? null : new FakeTransport(new HttpResponse($status, $body, ['X-Test' => 'preserved']))) extends ApiClient {
                    public function fetch(): mixed {
                        return $this->executeOperation(['method' => 'GET', 'path' => '/', 'success' => ['2XX'],
                            'responseTypes' => [201 => ['type' => ResponseInterface::class, 'schemaNullable' => false], 204 => ['type' => null, 'schemaNullable' => false], '2XX' => ['type' => User::class, 'schemaNullable' => false]],
                            'errors' => [400 => null, '4XX' => User::class, 'default' => Address::class]]);
                    }
                };
                if ($status >= 400) {
                    try { $client->fetch(); self::fail('Error status returned as success.'); }
                    catch (ApiException $error) {
                        self::assertSame($status, $error->getCode()); self::assertSame($body, $error->response->body);
                        if ($status === 400) { self::assertNull($error->error); self::assertNull($error->getPrevious()); }
                        else { self::assertInstanceOf($status === 404 ? User::class : Address::class, $error->error); }
                    }
                    continue;
                }
                $result = $client->fetch();
                if ($status === 201) {
                    self::assertInstanceOf(ResponseInterface::class, $result);
                    self::assertSame(0, $result->getBody()->tell()); self::assertSame($body, (string) $result->getBody());
                    self::assertSame('preserved', $result->getHeaderLine('X-Test'));
                    if ($psr) { self::assertSame($response, $result); }
                } elseif ($status === 204) { self::assertNull($result); }
                else { self::assertInstanceOf(User::class, $result); }
            }
        }
    }
}
