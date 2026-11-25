<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Streaming\ServerSentEvent;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Tests\Support\Cfg;
use Dmorenof\SdkRuntime\Tests\Support\ScriptedServer;
use PHPUnit\Framework\TestCase;

/**
 * Streaming answers on a real socket: a server that sends chunked pieces over time (events, JSON lines) and the generator that reads them
 * with the default Guzzle client. The fake-stream tests prove the decoding; these prove that an item reaches the caller when it arrives,
 * not when the answer ends, and that stopping early or a stalled server behave.
 */
final class RealStreamingResponseTest extends TestCase
{
    /** @var list<ScriptedServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    /** @param list<array<string, mixed>> $script */
    private function server(array $script): ScriptedServer
    {
        return $this->servers[] = ScriptedServer::start($script);
    }

    private static function client(string $url, ?float $timeout = null): ApiClient
    {
        $configuration = Cfg::make($url);
        if ($timeout !== null) {
            $configuration->timeout = $timeout;
        }
        return new class ($configuration) extends ApiClient {
            public function events(): mixed
            {
                return $this->executeOperation(['method' => 'GET', 'path' => '/events', 'responseTypes' => [
                    200 => ['type' => null, 'schemaNullable' => false, 'mediaType' => 'text/event-stream', 'encoding' => 'sse'],
                ]]);
            }

            public function users(): mixed
            {
                return $this->executeOperation(['method' => 'GET', 'path' => '/users', 'responseTypes' => [
                    200 => ['type' => User::class, 'schemaNullable' => false, 'mediaType' => 'application/jsonl', 'encoding' => 'jsonl'],
                ]]);
            }
        };
    }

    public function testEventsReachTheCallerWhenTheyArriveNotWhenTheAnswerEnds(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['Content-Type' => 'text/event-stream'], 'chunks' => [
            ['data' => "data: one\n\n"],
            ['data' => "event: tick\ndata: two\n\n", 'delayMs' => 600],
            ['data' => "id: 9\ndata: three\n\n", 'delayMs' => 600],
        ]]]);
        $arrivals = [];
        $events = [];

        foreach (self::client($server->url())->events() as $event) {
            $arrivals[] = microtime(true);
            $events[] = $event;
        }

        self::assertCount(3, $events);
        self::assertContainsOnlyInstancesOf(ServerSentEvent::class, $events);
        self::assertSame(['message:one', 'tick:two', 'message:three'], array_map(static fn (ServerSentEvent $e): string => $e->event . ':' . $e->data, $events));
        self::assertSame('9', $events[2]->id);
        self::assertGreaterThan(0.4, $arrivals[1] - $arrivals[0], 'The second event was held back until the answer ended.');
        self::assertGreaterThan(0.4, $arrivals[2] - $arrivals[1]);
        $request = $server->requests()[0];
        self::assertStringContainsString('text/event-stream', $request['headers']['accept'][0]);
        self::assertSame(['identity'], $request['headers']['accept-encoding'], 'A stream asks for no compression.');
    }

    public function testStoppingEarlyDoesNotWaitForTheRestOfTheStream(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['Content-Type' => 'text/event-stream'], 'chunks' => [
            ['data' => "data: first\n\n"],
            ['data' => "data: later\n\n", 'delayMs' => 3000],
        ]]]);

        $start = microtime(true);
        foreach (self::client($server->url())->events() as $event) {
            self::assertSame('first', $event->data);
            break;
        }

        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testJsonLinesSplitAcrossChunksAreDecodedIntoModels(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['Content-Type' => 'application/jsonl'], 'chunks' => [
            ['data' => "{\"id\":1,\"name\":\"Ada\"}\n{\"id\":2,\"na"],
            ['data' => "me\":\"Bob\"}\r\n\r\n", 'delayMs' => 100],
            ['data' => "{\"id\":3,\"name\":\"Cy\"}\n", 'delayMs' => 100],
        ]]]);

        $users = iterator_to_array(self::client($server->url())->users(), false);

        self::assertContainsOnlyInstancesOf(User::class, $users);
        self::assertSame(['Ada', 'Bob', 'Cy'], array_map(static fn (User $u): string => $u->name, $users));
    }

    public function testAnErrorStatusIsNeverStreamed(): void
    {
        $server = $this->server([['status' => 500, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'down']]);

        $this->expectException(ApiException::class);
        iterator_to_array(self::client($server->url())->events(), false);
    }

    public function testAServerThatGoesQuietEndsInAnErrorNotInATruncatedStream(): void
    {
        $server = $this->server([['status' => 200, 'headers' => ['Content-Type' => 'text/event-stream'], 'chunks' => [
            ['data' => "data: one\n\n"],
            ['data' => "data: never\n\n", 'delayMs' => 4000],
        ]]]);
        $seen = [];
        $ended = false;

        try {
            foreach (self::client($server->url(), 1.0)->events() as $event) {
                $seen[] = $event->data;
            }
            $ended = true;
        } catch (\Throwable) {
            // the read timed out: what the caller must get
        }

        self::assertFalse($ended, 'A stalled stream ended as if it were complete.');
        self::assertSame(['one'], $seen);
    }
}
