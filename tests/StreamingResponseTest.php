<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Streaming\ServerSentEvent;
use Dmorenof\SdkRuntime\Streaming\StreamDecoder;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** Delivers one byte per read, so every chunk boundary is exercised. */
final class TrickleStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $reads = 0;

    public function read($length): string
    {
        $this->reads++;
        return $this->stream->read(1);
    }
}

/** Claims more data may come but delivers none. */
final class StalledStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function read($length): string
    {
        return '';
    }

    public function eof(): bool
    {
        return false;
    }
}

final class StreamingProbe extends ApiClient
{
    public function events(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/events', 'responseTypes' => [
            200 => ['type' => null, 'schemaNullable' => false, 'mediaType' => 'text/event-stream', 'encoding' => 'sse'],
        ]]);
    }

    public function typedEvents(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/events', 'responseTypes' => [
            200 => ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'text/event-stream', 'encoding' => 'sse'],
        ]]);
    }

    public function users(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/users', 'responseTypes' => [
            200 => ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'application/jsonl', 'encoding' => 'jsonl'],
        ]]);
    }

    /** Legacy single-response metadata instead of per-status entries. */
    public function legacy(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/legacy', 'responseType' => User::class,
            'responseMediaType' => 'application/x-ndjson', 'responseEncoding' => 'jsonl']);
    }

    public function negotiated(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/export', 'responseTypes' => [
            200 => ['type' => null, 'schemaNullable' => false, 'alternatives' => [
                ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/csv', 'encoding' => 'text'],
                ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'application/jsonl', 'encoding' => 'jsonl'],
            ]],
        ]]);
    }

    /** Legacy single-response keys with no item type at all. */
    public function legacyEvents(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/legacy-events',
            'responseMediaType' => 'text/event-stream', 'responseEncoding' => 'sse']);
    }

    public function plain(): mixed
    {
        return $this->executeOperation(['method' => 'GET', 'path' => '/plain', 'responseType' => User::class]);
    }
}

final class StreamingResponseTest extends TestCase
{
    private static function trickle(string $bytes): TrickleStream
    {
        return new TrickleStream(Utils::streamFor($bytes));
    }

    /** @param array<string,mixed> $entry */
    private static function collect(string $bytes, array $entry): array
    {
        return iterator_to_array(StreamDecoder::iterate(self::trickle($bytes), $entry + ['schemaNullable' => false, 'type' => null], 'test'), false);
    }

    public function testLinesSplitOnLfCrlfAndCrAcrossChunkBoundaries(): void
    {
        self::assertSame(['a', 'b', 'c', 'd'], iterator_to_array(StreamDecoder::lines(self::trickle("a\nb\r\nc\rd")), false));
        self::assertSame(['x', '', 'y'], iterator_to_array(StreamDecoder::lines(self::trickle("x\r\r\ny")), false));
        self::assertSame([], iterator_to_array(StreamDecoder::lines(self::trickle('')), false));
        self::assertSame(['only'], iterator_to_array(StreamDecoder::lines(self::trickle("only\n")), false));
    }

    public function testOverlongLineIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('exceeds');
        iterator_to_array(StreamDecoder::lines(Utils::streamFor(str_repeat('a', StreamDecoder::MAX_RECORD_BYTES + 10))), false);
    }

    public function testStalledReadIsAnErrorNotATruncation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        iterator_to_array(StreamDecoder::lines(new StalledStream(Utils::streamFor('data'))), false);
    }

    public function testJsonLinesYieldHydratedItemsWithBlankLinesAndCrlf(): void
    {
        $items = self::collect("{\"id\":1,\"name\":\"Ada\"}\r\n\r\n{\"id\":2,\"name\":\"Grace\"}", ['type' => User::class, 'encoding' => 'jsonl']);

        self::assertCount(2, $items);
        self::assertContainsOnlyInstancesOf(User::class, $items);
        self::assertSame(['Ada', 'Grace'], [$items[0]->name, $items[1]->name]);
    }

    public function testJsonLinesWithoutTypeYieldDecodedValues(): void
    {
        $items = self::collect("{\"a\":1}\n[1,2]\n3\n", ['encoding' => 'jsonl']);

        self::assertEquals((object) ['a' => 1], $items[0]);
        self::assertSame([1, 2], $items[1]);
        self::assertSame(3, $items[2]);
    }

    public function testJsonLinesFailLazilyOnTheBadLine(): void
    {
        $generator = StreamDecoder::iterate(self::trickle("{\"id\":1,\"name\":\"Ada\"}\nnot json\n"), ['type' => User::class, 'schemaNullable' => false, 'encoding' => 'jsonl'], 'users');

        self::assertSame('Ada', $generator->current()->name);
        $this->expectException(\Throwable::class);
        $generator->next();
    }

    public function testItemRulesAreApplied(): void
    {
        $generator = StreamDecoder::iterate(self::trickle("1\n-5\n"), ['type' => 'int', 'schemaNullable' => false, 'rules' => ['minimum' => 0], 'encoding' => 'jsonl'], 'numbers');

        self::assertSame(1, $generator->current());
        $this->expectException(\UnexpectedValueException::class);
        $generator->next();
    }

    public function testJsonSequenceRecordsUseTheRecordSeparator(): void
    {
        $items = self::collect("\x1e{\"a\":1}\n\x1e{\"a\":2}\n\x1e\x1e{\"a\":3}", ['encoding' => 'json-seq']);

        self::assertEquals([(object) ['a' => 1], (object) ['a' => 2], (object) ['a' => 3]], $items);
    }

    public function testServerSentEventsFollowTheHtmlParsingRules(): void
    {
        $events = self::collect(
            "event: tick\ndata: 1\n\n: a comment\ndata: a\ndata: b\nid: 7\n\nretry: 3000\ndata: x\n\n",
            ['encoding' => 'sse'],
        );

        self::assertCount(3, $events);
        self::assertContainsOnlyInstancesOf(ServerSentEvent::class, $events);
        self::assertEquals(new ServerSentEvent('tick', '1'), $events[0]);
        self::assertEquals(new ServerSentEvent('message', "a\nb", '7'), $events[1]);
        self::assertEquals(new ServerSentEvent('message', 'x', '7', 3000), $events[2], 'the last event id persists');
    }

    public function testServerSentEventEdgeCases(): void
    {
        $events = self::collect(
            "\xEF\xBB\xBFdata: one\r\n\r\ndata\r\n\r\ndata:  two\r\n\r\nid: a\0b\ndata: three\n\nretry: soon\ndata: four\n\ndata: partial",
            ['encoding' => 'sse'],
        );

        self::assertSame(['one', '', ' two', 'three', 'four'], array_map(static fn (ServerSentEvent $e): string => $e->data, $events), 'incomplete trailing event is discarded');
        self::assertNull($events[3]->id, 'an id containing NUL is ignored');
        self::assertNull($events[4]->retry, 'a non-numeric retry is ignored');
    }

    public function testEventsWithoutDataAreNotDispatched(): void
    {
        self::assertSame([], self::collect("event: ping\n\nid: 1\n\n: just a comment\n\n", ['encoding' => 'sse']));
    }

    public function testServerSentEventDataIsDecodedIntoTheDeclaredType(): void
    {
        $events = self::collect("data: {\"id\":1,\"name\":\"Ada\"}\n\n", ['encoding' => 'sse', 'type' => User::class]);

        self::assertInstanceOf(User::class, $events[0]->item);
        self::assertSame('Ada', $events[0]->item->name);
        self::assertSame('{"id":1,"name":"Ada"}', $events[0]->data);
    }

    public function testConsumingIsLazyAndDestroyingTheGeneratorClosesTheStream(): void
    {
        $body = implode('', array_map(static fn (int $i): string => "{\"n\":$i}\n", range(1, 200)));
        $stream = self::trickle($body);
        $generator = StreamDecoder::iterate($stream, ['type' => null, 'schemaNullable' => false, 'encoding' => 'jsonl'], 'lazy');

        self::assertEquals((object) ['n' => 1], $generator->current());
        self::assertLessThan(strlen($body) / 10, $stream->reads, 'only the first item was read');
        self::assertTrue($stream->isReadable());

        unset($generator);
        self::assertFalse($stream->isReadable(), 'the stream is closed when the generator is destroyed');
    }

    public function testUnknownStreamingEncodingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamDecoder::iterate(Utils::streamFor(''), ['type' => null, 'schemaNullable' => false, 'encoding' => 'xml'], 'x');
    }

    private function legacy(string $body, string $contentType, int $status = 200, ?FakeTransport &$transport = null): StreamingProbe
    {
        $transport = new FakeTransport(new HttpResponse($status, $body, ['content-type' => $contentType]));
        return StreamingProbe::connect('https://api.example.com', transport: $transport);
    }

    public function testOperationReturnsALazyGeneratorOfItems(): void
    {
        $result = $this->legacy("{\"id\":1,\"name\":\"Ada\"}\n{\"id\":2,\"name\":\"Grace\"}\n", 'application/jsonl', 200, $transport)->users();

        self::assertInstanceOf(\Generator::class, $result);
        self::assertSame(['Ada', 'Grace'], array_map(static fn (User $u): string => $u->name, iterator_to_array($result, false)));
        self::assertSame('application/jsonl', $transport->sent[0]['headers']['Accept']);
    }

    public function testLegacySingleResponseMetadataStreamsToo(): void
    {
        $result = $this->legacy("{\"id\":1,\"name\":\"Ada\"}\n", 'application/x-ndjson', 200, $transport)->legacy();

        self::assertInstanceOf(\Generator::class, $result);
        self::assertSame('Ada', $result->current()->name);
        self::assertSame('application/x-ndjson', $transport->sent[0]['headers']['Accept']);
    }

    public function testServerSentEventOperationWithoutItemTypeStillAdvertisesTheMediaType(): void
    {
        $result = $this->legacy("data: hi\n\n", 'text/event-stream', 200, $transport)->events();

        self::assertSame('hi', $result->current()->data);
        self::assertSame('text/event-stream', $transport->sent[0]['headers']['Accept']);
        self::assertNull($result->current()->item);
    }

    public function testTypedServerSentEventsDecodeTheirData(): void
    {
        $result = $this->legacy("data: {\"id\":3,\"name\":\"Lin\"}\n\n", 'text/event-stream')->typedEvents();

        self::assertSame('Lin', $result->current()->item->name);
    }

    public function testRepresentationSelectionPicksBufferedOrStreamedCodec(): void
    {
        self::assertSame("a,b\n1,2", $this->legacy("a,b\n1,2", 'text/csv')->negotiated());

        $streamed = $this->legacy("{\"id\":1,\"name\":\"Ada\"}\n", 'application/jsonl')->negotiated();
        self::assertInstanceOf(\Generator::class, $streamed);
        self::assertSame('Ada', $streamed->current()->name);
    }

    public function testErrorsAreNotStreamedAndKeepTheirHttpFailure(): void
    {
        try {
            $this->legacy('{"message":"boom"}', 'application/json', 500)->users();
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(500, $e->response->status);
        }
    }

    public function testBodylessStatusesReturnNullInsteadOfAnEmptyStream(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, '', []));
        $client = new class ('https://api.example.com', transport: $transport) extends ApiClient {
            public function go(): mixed
            {
                return $this->executeOperation(['method' => 'GET', 'path' => '/e', 'success' => [200, 204], 'responseTypes' => [
                    200 => ['type' => null, 'schemaNullable' => false, 'mediaType' => 'text/event-stream', 'encoding' => 'sse'],
                ]]);
            }
        };

        self::assertNull($client->go());
    }

    public function testOperationReportsWhetherItStreams(): void
    {
        self::assertFalse(Operation::fromArray(['method' => 'GET', 'path' => '/x'])->streamsResponse());
        self::assertFalse(Operation::fromArray(['method' => 'GET', 'path' => '/x', 'responseType' => User::class])->streamsResponse());
        self::assertTrue(Operation::fromArray(['method' => 'GET', 'path' => '/x', 'responseEncoding' => 'sse'])->streamsResponse());
        self::assertTrue(Operation::fromArray(['method' => 'GET', 'path' => '/x', 'responseTypes' => [
            200 => ['type' => null, 'schemaNullable' => false, 'alternatives' => [
                ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/csv', 'encoding' => 'text'],
                ['type' => null, 'schemaNullable' => false, 'mediaType' => 'application/json-seq', 'encoding' => 'json-seq'],
            ]],
        ]])->streamsResponse());
    }

    /** A Guzzle-style client that records the options it receives. */
    private function guzzleLike(ResponseInterface $response): object
    {
        return new class ($response) implements \GuzzleHttp\ClientInterface, \Psr\Http\Client\ClientInterface {
            /** @var list<array<string,mixed>> */
            public array $options = [];
            public int $psrCalls = 0;

            public function __construct(private readonly ResponseInterface $response) {}

            public function send(RequestInterface $request, array $options = []): ResponseInterface
            {
                $this->options[] = $options;
                return $this->response;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->psrCalls++;
                return $this->response;
            }

            public function sendAsync(RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \LogicException('not used');
            }

            public function request(string $method, $uri, array $options = []): ResponseInterface
            {
                throw new \LogicException('not used');
            }

            public function requestAsync(string $method, $uri, array $options = []): \GuzzleHttp\Promise\PromiseInterface
            {
                throw new \LogicException('not used');
            }

            public function getConfig(?string $option = null): mixed
            {
                return null;
            }
        };
    }

    public function testGuzzleClientsAreAskedNotToBufferStreamingResponses(): void
    {
        $guzzle = $this->guzzleLike(new Response(200, ['Content-Type' => 'text/event-stream'], "data: a\n\ndata: b\n\n"));
        $client = StreamingProbe::connect('https://api.example.com', $guzzle);

        $events = $client->events();

        self::assertSame(['a', 'b'], array_map(static fn (ServerSentEvent $e): string => $e->data, iterator_to_array($events, false)));
        self::assertTrue($guzzle->options[0]['stream']);
        self::assertFalse($guzzle->options[0]['http_errors']);
        self::assertSame(0, $guzzle->psrCalls);
    }

    public function testNonStreamingOperationsKeepUsingPlainPsr18(): void
    {
        $guzzle = $this->guzzleLike(new Response(200, ['Content-Type' => 'application/json'], '{"id":1,"name":"Ada"}'));
        $client = StreamingProbe::connect('https://api.example.com', $guzzle);

        self::assertSame('Ada', $client->plain()->name);
        self::assertSame(1, $guzzle->psrCalls);
        self::assertSame([], $guzzle->options);
    }

    public function testLegacyKeysCanDeclareAStreamWithoutAnItemType(): void
    {
        // Regression: a null response type used to reach ltrim() and fail with a TypeError.
        $result = $this->legacy("data: hi\n\n", 'text/event-stream', 200, $transport)->legacyEvents();

        self::assertSame('hi', $result->current()->data);
        self::assertSame('text/event-stream', $transport->sent[0]['headers']['Accept']);
    }
}
