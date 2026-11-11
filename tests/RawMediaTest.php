<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Hydrator;
use Dmorenof\SdkRuntime\Serializer;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class RawMediaTest extends TestCase {
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = []; public int $status = 200; public string $response = 'reply';
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new Response($this->status, [], $this->response); }
        };
        $client = new class('https://example.test', httpClient: $http) extends ApiClient {
            public function post(string $encoding, mixed $value, bool $present = true, ?DTO $params = null): mixed {
                return $this->executeBody(['method' => 'POST', 'path' => '/raw', 'responseType' => 'string', 'responseMediaType' => 'text/plain',
                    'requestMediaType' => $encoding === 'text' ? 'text/plain' : 'application/octet-stream',
                    'requestBody' => ['type' => $encoding === 'text' ? 'string' : 'stream', 'nullable' => false, 'required' => false, 'encoding' => $encoding]], $value, $present, payload: $params);
            }
            public function get(): mixed { return $this->executeOperation(['method' => 'GET', 'path' => '/raw', 'success' => [200, 201, 202, 204],
                'responseTypes' => [200 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/plain'], 201 => ['type' => 'int', 'schemaNullable' => false], 202 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false], 204 => ['type' => null, 'schemaNullable' => false]]]); }
        };
        return [$client, $http];
    }
    public function testTextAndBinaryBytesAreNotJsonEncoded(): void {
        [$client, $http] = $this->setupClient();
        foreach (['text' => "ñ \"text\"\n", 'binary' => "\0\xff\r\n"] as $encoding => $bytes) {
            self::assertSame('reply', $client->post($encoding, $bytes));
            $request = $http->calls[array_key_last($http->calls)];
            self::assertSame($bytes, (string) $request->getBody()); self::assertSame('text/plain', $request->getHeaderLine('Accept'));
        }
        $client->post('text', ''); self::assertSame('text/plain', $http->calls[2]->getHeaderLine('Content-Type'));
        $client->post('text', null, false); self::assertFalse($http->calls[3]->hasHeader('Content-Type'));
        $this->expectException(\UnexpectedValueException::class); $client->post('text', null);
    }
    public function testPsrStreamIsReusedRewoundAndWorksInOperationDto(): void {
        [$client, $http] = $this->setupClient(); $stream = Utils::streamFor("\0\xffstream"); $stream->seek(3);
        $params = new class((object) ['body' => $stream, 'queryBody' => 'separate']) extends DTO {
            public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody']]; public const bool OPERATION_BODY = true;
            public string|\Psr\Http\Message\StreamInterface $body; public ?string $queryBody;
        };
        $client->post('binary', null, params: $params);
        self::assertSame($stream, $http->calls[0]->getBody()); self::assertSame("\0\xffstream", (string) $http->calls[0]->getBody());
        self::assertStringEndsWith('?body=separate', (string) $http->calls[0]->getUri());
        // A non-seekable stream is accepted and sent once, as the same object.
        $params->body = $oneShot = new NoSeekStream(Utils::streamFor('one shot'));
        $client->post('binary', null, params: $params);
        self::assertSame($oneShot, $http->calls[1]->getBody());
        // Unreadable streams still fail before transport.
        $closed = Utils::streamFor('x'); $closed->close(); $params->body = $closed;
        $this->expectException(\UnexpectedValueException::class); $client->post('binary', null, params: $params);
    }
    public function testTextJsonBinaryAndBodylessResponsesSelectTheirOwnCodec(): void {
        [$client, $http] = $this->setupClient();
        foreach ([[200, 'not JSON', 'not JSON'], [200, '', ''], [201, '7', 7], [204, '', null]] as [$status, $body, $expected]) {
            $http->status = $status; $http->response = $body; self::assertSame($expected, $client->get());
            self::assertSame('application/json, text/plain, application/octet-stream', $http->calls[array_key_last($http->calls)]->getHeaderLine('Accept'));
        }
        $http->status = 202; $http->response = "\0\xff"; $result = $client->get();
        self::assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result); self::assertSame("\0\xff", (string) $result->getBody());
    }
    public function testStreamsCannotLeakIntoJsonAndNonSeekableInputsAreAcceptedForOneShotSends(): void {
        $stream = new NoSeekStream(Utils::streamFor('data'));
        self::assertSame($stream, Hydrator::hydrate($stream, 'stream', false, 'body'));
        $closed = Utils::streamFor('data'); $closed->close();
        try { Hydrator::hydrate($closed, 'stream', false, 'body'); self::fail('Unreadable input accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        $this->expectException(\UnexpectedValueException::class); Serializer::value(Utils::streamFor('data'), 'request');
    }
}
