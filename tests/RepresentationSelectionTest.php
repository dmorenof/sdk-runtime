<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\MediaType;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

final class RepresentationProbe extends ApiClient
{
    private const array EXPORT = [
        'method' => 'GET',
        'path' => '/export',
        'responseTypes' => [
            200 => [
                'type' => null,
                'schemaNullable' => false,
                'alternatives' => [
                    ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'application/json', 'encoding' => 'json'],
                    ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/csv', 'encoding' => 'text'],
                    ['type' => '\\' . ResponseInterface::class, 'schemaNullable' => false, 'mediaType' => 'image/*', 'encoding' => 'binary'],
                ],
            ],
        ],
        'errorResponses' => [
            '4XX' => [
                'type' => null,
                'schemaNullable' => false,
                'alternatives' => [
                    ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'application/*+json', 'encoding' => 'json'],
                ],
            ],
        ],
    ];

    public function export(): mixed
    {
        return $this->executeOperation(self::EXPORT);
    }
}

final class RepresentationSelectionTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function specificities(): iterable
    {
        yield 'exact' => ['application/json', 'application/json', 4];
        yield 'exact ignores case and parameters' => ['Application/JSON; charset=utf-8', 'application/json;q=0.5', 4];
        yield 'suffix range' => ['application/*+json', 'application/problem+json', 3];
        yield 'suffix range needs the suffix' => ['application/*+json', 'application/xml', 0];
        yield 'type range' => ['text/*', 'text/csv', 2];
        yield 'type range other type' => ['text/*', 'image/png', 0];
        yield 'any' => ['*/*', 'image/png', 1];
        yield 'any short form' => ['*', 'image/png', 0];
        yield 'different subtype' => ['application/json', 'application/xml', 0];
        yield 'json does not imply +json' => ['application/json', 'application/problem+json', 0];
    }

    #[DataProvider('specificities')]
    public function testSpecificity(string $declared, string $actual, int $expected): void
    {
        self::assertSame($expected, MediaType::specificity($declared, $actual));
    }

    public function testMostSpecificDeclaredTypeWins(): void
    {
        $declared = ['*/*', 'application/*', 'application/*+json', 'application/vnd.api+json'];

        self::assertSame('application/vnd.api+json', MediaType::select('application/vnd.api+json; charset=utf-8', $declared));
        self::assertSame('application/*+json', MediaType::select('application/problem+json', $declared));
        self::assertSame('application/*', MediaType::select('application/xml', $declared));
        self::assertSame('*/*', MediaType::select('image/png', $declared));
    }

    public function testTiesKeepDeclarationOrderAndNoContentTypeUsesTheFirst(): void
    {
        self::assertSame('text/*', MediaType::select('text/csv', ['text/*', 'text/*']));
        self::assertSame('application/json', MediaType::select(null, ['application/json', 'text/csv']));
        self::assertSame('application/json', MediaType::select('', ['application/json', 'text/csv']));
        self::assertNull(MediaType::select('not a media type', ['application/json']));
        self::assertNull(MediaType::select('application/xml', ['application/json']));
        self::assertNull(MediaType::select('application/xml', []));
    }

    private function legacy(int $status, string $body, ?string $contentType, ?FakeTransport &$transport = null): RepresentationProbe
    {
        $transport = new FakeTransport(new HttpResponse($status, $body, $contentType === null ? [] : ['content-type' => $contentType]));
        return RepresentationProbe::connect('https://api.example.com', transport: $transport);
    }

    public function testAcceptListsEveryAlternativeWithRangesLast(): void
    {
        $client = $this->legacy(200, '{"id":1,"name":"Grace"}', 'application/json', $transport);

        $client->export();

        self::assertSame('application/json, text/csv, image/*, application/*+json', $transport->sent[0]['headers']['Accept']);
    }

    public function testDecodesEachRepresentationByContentType(): void
    {
        $user = $this->legacy(200, '{"id":1,"name":"Grace"}', 'application/json; charset=utf-8')->export();
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Grace', $user->name);

        self::assertSame("a,b\n1,2", $this->legacy(200, "a,b\n1,2", 'text/csv')->export());

        $image = $this->legacy(200, "\x89PNG", 'image/png')->export();
        self::assertInstanceOf(ResponseInterface::class, $image);
        self::assertSame("\x89PNG", (string) $image->getBody());
    }

    public function testResponseWithoutContentTypeUsesTheFirstAlternative(): void
    {
        $user = $this->legacy(200, '{"id":2,"name":"Ada"}', null)->export();

        self::assertInstanceOf(User::class, $user);
        self::assertSame('Ada', $user->name);
    }

    public function testUndeclaredSuccessRepresentationIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("'application/xml'");
        $this->legacy(200, '<a/>', 'application/xml')->export();
    }

    public function testErrorPayloadUsesTheMatchingRange(): void
    {
        try {
            $this->legacy(422, '{"id":5,"name":"bad"}', 'application/problem+json')->export();
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertInstanceOf(User::class, $e->payload);
            self::assertSame('bad', $e->payload->name);
        }
    }

    public function testUndeclaredErrorRepresentationKeepsTheHttpFailure(): void
    {
        try {
            $this->legacy(404, '<html>nope</html>', 'text/html')->export();
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(404, $e->response->status);
            self::assertSame('<html>nope</html>', $e->response->body);
            self::assertNull($e->payload);
        }
    }

    public function testSelectionAlsoWorksOverPsr18(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/csv; charset=utf-8'], 'x,y');
            }
        };
        $client = RepresentationProbe::connect('https://api.example.com', $http);

        self::assertSame('x,y', $client->export());
        self::assertSame('text/csv; charset=utf-8', $client->lastResponse()?->headers->first('content-type'));
    }

    public function testPsr18BinaryRangeKeepsTheStream(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'image/webp'], 'RIFF');
            }
        };

        $result = RepresentationProbe::connect('https://api.example.com', $http)->export();

        self::assertInstanceOf(ResponseInterface::class, $result);
        self::assertSame('RIFF', (string) $result->getBody());
    }

    public function testNormalizeAcceptsEveryTokenCharacterIncludingTheTilde(): void
    {
        // Regression: the tilde in the token class used to cut the pattern short, so nothing normalized.
        self::assertSame('application/vnd.a~b+json', MediaType::normalize('Application/VND.a~b+JSON; charset=utf-8'));
        self::assertSame('text/plain', MediaType::normalize(' text/plain '));
        self::assertNull(MediaType::normalize('not a media type'));
        self::assertNull(MediaType::normalize('application/'));
        self::assertNull(MediaType::normalize(null));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function jsonTypes(): iterable
    {
        yield 'plain' => ['application/json', true];
        yield 'with parameters' => ['application/problem+json; charset=utf-8', true];
        yield 'mixed case' => ['Application/Vnd.Api+JSON', true];
        yield 'text json' => ['text/json', false];
        yield 'longer subtype' => ['application/jsonx', false];
        yield 'xml' => ['application/xml', false];
        yield 'suffix range' => ['application/*+json', false];
        yield 'garbage' => ['json', false];
    }

    #[DataProvider('jsonTypes')]
    public function testIsJson(string $mediaType, bool $expected): void
    {
        self::assertSame($expected, MediaType::isJson($mediaType));
    }

    public function testPreferenceOrdersAcceptHeaderEntries(): void
    {
        $order = ['application/json', 'application/problem+json; charset=utf-8', 'text/plain', 'application/octet-stream', 'text/csv', 'image/*', '*/*', 'application/*+json'];

        self::assertSame([0, 0, 1, 2, 3, 4, 4, 4], array_map([MediaType::class, 'preference'], $order));
        // Established behaviour for previously emitted SDKs: only the bare text/plain counts as plain text.
        self::assertSame(3, MediaType::preference('text/plain; charset=utf-8'));
    }

    public function testDefaultMediaTypeOfARepresentationWithoutOne(): void
    {
        self::assertSame('text/event-stream', MediaType::defaultFor(null, 'sse'));
        self::assertSame('application/jsonl', MediaType::defaultFor('x', 'jsonl'));
        self::assertSame('application/json-seq', MediaType::defaultFor(null, 'json-seq'));
        self::assertSame('application/json', MediaType::defaultFor('App\\Model', 'json'));
        self::assertSame('application/json', MediaType::defaultFor(null, null));
        self::assertSame('application/octet-stream', MediaType::defaultFor('\\' . PsrResponseInterface::class, null));
        self::assertSame('application/octet-stream', MediaType::defaultFor(PsrResponseInterface::class, 'binary'));
    }
}
