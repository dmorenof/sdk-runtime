<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;
use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class MimeMediaTest extends TestCase {
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = []; public \Psr\Http\Message\ResponseInterface $reply;
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return $this->reply; }
        };
        $client = new class('https://example.test', httpClient: $http) extends ApiClient {
            public function post(array $spec, mixed $body): mixed { return $this->executeBody(['method' => 'POST', 'path' => '/media'] + $spec, $body); }
            public function get(array $spec): mixed { return $this->executeOperation(['method' => 'GET', 'path' => '/media'] + $spec); }
        };
        return [$client, $http];
    }
    public function testCsvXmlAndDeclaredParametersKeepRawBytesAndMedia(): void {
        [$client, $http] = $this->setupClient();
        foreach (['text/csv; charset=utf-8' => "a,b\n1,2\n", 'application/xml' => '<user>ñ</user>'] as $media => $bytes) {
            $http->reply = new Response(200, ['Content-Type' => $media], $bytes);
            $result = $client->post(['requestMediaType' => $media, 'requestBody' => ['type' => 'string', 'nullable' => false, 'required' => true, 'encoding' => 'text'],
                'responseType' => 'string', 'responseMediaType' => $media, 'responseEncoding' => 'text'], $bytes);
            self::assertSame($bytes, $result); $request = $http->calls[array_key_last($http->calls)];
            self::assertSame($bytes, (string) $request->getBody()); self::assertSame($media, $request->getHeaderLine('Content-Type')); self::assertSame($media, $request->getHeaderLine('Accept'));
        }
    }
    public function testBinaryMimeRetainsInputAndOutputPsrStreams(): void {
        [$client, $http] = $this->setupClient(); $input = Utils::streamFor("\0\xffinput"); $input->seek(3);
        $http->reply = new Response(200, ['Content-Type' => 'image/png'], Utils::streamFor("\0\xffreply"));
        $result = $client->post(['requestMediaType' => 'application/pdf', 'requestBody' => ['type' => 'stream', 'nullable' => false, 'required' => true, 'encoding' => 'binary'],
            'responseType' => \Psr\Http\Message\ResponseInterface::class, 'responseMediaType' => 'image/png', 'responseEncoding' => 'binary'], $input);
        self::assertSame($http->reply, $result); self::assertSame($input, $http->calls[0]->getBody()); self::assertSame("\0\xffinput", (string) $http->calls[0]->getBody());
        self::assertSame('image/png', $http->calls[0]->getHeaderLine('Accept')); self::assertSame('application/pdf', $http->calls[0]->getHeaderLine('Content-Type'));
    }
    public function testVendorJsonKeepsItsMimeAndExplicitCodecOverridesLegacyInference(): void {
        [$client, $http] = $this->setupClient(); $http->reply = new Response(200, [], '{"ok":true}');
        $result = $client->post(['requestMediaType' => 'application/vnd.example+json', 'requestBody' => ['type' => 'mixed', 'nullable' => false, 'required' => true],
            'responseType' => 'mixed', 'responseMediaType' => 'application/problem+json'], (object) ['value' => 7]);
        self::assertTrue($result->ok); self::assertSame('{"value":7}', (string) $http->calls[0]->getBody());
        self::assertSame('application/problem+json', $http->calls[0]->getHeaderLine('Accept')); self::assertSame('application/vnd.example+json', $http->calls[0]->getHeaderLine('Content-Type'));
        $http->reply = new Response(200, [], '"decoded"');
        self::assertSame('decoded', $client->get(['responseType' => 'string', 'responseMediaType' => 'text/plain', 'responseEncoding' => 'json']));
        self::assertSame($http->reply, $client->get(['responseType' => \Psr\Http\Message\ResponseInterface::class, 'responseMediaType' => 'application/json']));
        self::assertSame('application/json', $http->calls[2]->getHeaderLine('Accept'));
    }
    public function testRawErrorCodecsRetainTheirDeclaredMimeAndFailure(): void {
        [$client, $http] = $this->setupClient();
        foreach (['application/xml' => ['text', '<error/>'], 'image/png' => ['binary', "\0\xfferror"]] as $media => [$encoding, $bytes]) {
            $http->reply = new Response(400, ['Content-Type' => $media], $bytes);
            try { $client->get(['errorResponses' => [400 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => $media, 'encoding' => $encoding]]]); self::fail('HTTP failure became success.'); }
            catch (ApiException $error) { self::assertSame($bytes, $error->payload); self::assertNull($error->getPrevious()); self::assertSame(400, $error->getCode()); }
            self::assertSame($media, $http->calls[array_key_last($http->calls)]->getHeaderLine('Accept'));
        }
    }
}
