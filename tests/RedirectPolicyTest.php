<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Tests\Fixtures\TestClient;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\RedirectException;
use Dmorenof\SdkRuntime\Transport\RedirectPolicy;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RedirectPolicyTest extends TestCase
{
    /** @param list<ResponseInterface> $script */
    private function http(array $script): object
    {
        return new class ($script) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @var list<string> bodies as sent */
            public array $bodies = [];

            /** @param list<ResponseInterface> $script */
            public function __construct(private array $script) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $this->bodies[] = (string) $request->getBody();
                return array_shift($this->script) ?? throw new \LogicException('Script exhausted.');
            }
        };
    }

    private static function redirect(int $status, string $location): Response
    {
        return new Response($status, ['Location' => $location]);
    }

    private function send(object $http, string $method, string $url, array $headers = [], mixed $body = null, ?RedirectPolicy $policy = null): ResponseInterface
    {
        return (new Psr18Transport($http, [], redirectPolicy: $policy))->sendResponse($method, $url, $headers, $body);
    }

    public function testFollowsRelativeAndAbsoluteLocations(): void
    {
        $http = $this->http([self::redirect(302, '/b?x=1'), self::redirect(301, 'https://api.example.com/c'), new Response(200, [], 'done')]);

        $response = $this->send($http, 'GET', 'https://api.example.com/a', ['Accept' => 'application/json']);

        self::assertSame('done', (string) $response->getBody());
        self::assertSame(
            ['https://api.example.com/a', 'https://api.example.com/b?x=1', 'https://api.example.com/c'],
            array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $http->requests),
        );
    }

    public function testPostBecomesGetOn301302And303WithoutBody(): void
    {
        foreach ([301, 302, 303] as $status) {
            $http = $this->http([self::redirect($status, '/next'), new Response(200)]);
            $this->send($http, 'POST', 'https://api.example.com/a', ['Content-Type' => 'application/json'], '{"a":1}');

            self::assertSame('GET', $http->requests[1]->getMethod(), "status $status");
            self::assertSame('', $http->bodies[1]);
            self::assertFalse($http->requests[1]->hasHeader('Content-Type'));
        }
    }

    public function testPutBecomesGetOnlyOn303(): void
    {
        $http = $this->http([self::redirect(303, '/next'), new Response(200)]);
        $this->send($http, 'PUT', 'https://api.example.com/a', [], 'x');
        self::assertSame('GET', $http->requests[1]->getMethod());

        $http = $this->http([self::redirect(302, '/next'), new Response(200)]);
        $this->send($http, 'PUT', 'https://api.example.com/a', [], 'x');
        self::assertSame('PUT', $http->requests[1]->getMethod());
        self::assertSame('x', $http->bodies[1]);
    }

    public function testHeadStaysHeadOn303(): void
    {
        $http = $this->http([self::redirect(303, '/next'), new Response(200)]);
        $this->send($http, 'HEAD', 'https://api.example.com/a');

        self::assertSame('HEAD', $http->requests[1]->getMethod());
    }

    public function testTemporaryAndPermanentRedirectKeepMethodAndBody(): void
    {
        foreach ([307, 308] as $status) {
            $http = $this->http([self::redirect($status, '/next'), new Response(201)]);
            $response = $this->send($http, 'POST', 'https://api.example.com/a', ['Content-Type' => 'application/json'], '{"a":1}');

            self::assertSame(201, $response->getStatusCode());
            self::assertSame('POST', $http->requests[1]->getMethod());
            self::assertSame('{"a":1}', $http->bodies[1], "status $status resends the body");
            self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        }
    }

    public function testNonSeekableBodyCannotBeResentSoThe3xxIsReturned(): void
    {
        $http = $this->http([self::redirect(307, '/next')]);

        $response = $this->send($http, 'POST', 'https://api.example.com/a', [], new NoSeekStream(Utils::streamFor('payload')));

        self::assertSame(307, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testSameOriginKeepsCredentials(): void
    {
        $http = $this->http([self::redirect(302, 'https://API.example.com:443/next'), new Response(200)]);
        $this->send($http, 'GET', 'https://api.example.com/a', ['Authorization' => 'Bearer t', 'Cookie' => 'a=1', 'X-Api-Key' => 'k']);

        self::assertSame('Bearer t', $http->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('k', $http->requests[1]->getHeaderLine('X-Api-Key'));
    }

    public function testCrossOriginForwardsOnlyTheSafeAllowlist(): void
    {
        $http = $this->http([self::redirect(302, 'https://cdn.other.test/file'), new Response(200)]);
        $this->send($http, 'GET', 'https://api.example.com/a', [
            'Authorization' => 'Bearer t', 'Cookie' => 'a=1', 'X-Api-Key' => 'k', 'Accept' => 'application/json', 'User-Agent' => 'sdk',
        ]);

        $second = $http->requests[1];
        self::assertSame('cdn.other.test', $second->getHeaderLine('Host'));
        self::assertFalse($second->hasHeader('Authorization'));
        self::assertFalse($second->hasHeader('Cookie'));
        self::assertFalse($second->hasHeader('X-Api-Key'));
        self::assertSame('application/json', $second->getHeaderLine('Accept'));
        self::assertSame('sdk', $second->getHeaderLine('User-Agent'));
    }

    public function testDifferentPortCountsAsAnotherOrigin(): void
    {
        $http = $this->http([self::redirect(302, 'https://api.example.com:8443/x'), new Response(200)]);
        $this->send($http, 'GET', 'https://api.example.com/a', ['Authorization' => 'Bearer t']);

        self::assertFalse($http->requests[1]->hasHeader('Authorization'));
    }

    public function testCrossOriginCanBeForbidden(): void
    {
        $http = $this->http([self::redirect(302, 'https://other.test/x')]);

        $response = $this->send($http, 'GET', 'https://api.example.com/a', [], null, new RedirectPolicy(followCrossOrigin: false));

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testRefusesHttpsToHttpDowngradeUnlessAllowed(): void
    {
        $http = $this->http([self::redirect(302, 'http://api.example.com/x')]);
        try {
            $this->send($http, 'GET', 'https://api.example.com/a');
            self::fail('Expected RedirectException.');
        } catch (RedirectException $e) {
            self::assertSame(302, $e->response->getStatusCode());
        }

        $http = $this->http([self::redirect(302, 'http://api.example.com/x'), new Response(200)]);
        self::assertSame(200, $this->send($http, 'GET', 'https://api.example.com/a', [], null, new RedirectPolicy(allowDowngrade: true))->getStatusCode());
    }

    public function testRejectsUnsafeTargets(): void
    {
        foreach (['ftp://api.example.com/x', 'file:///etc/passwd', 'https://user:pw@api.example.com/x', 'http://'] as $target) {
            try {
                $this->send($this->http([self::redirect(302, $target)]), 'GET', 'https://api.example.com/a');
                self::fail("Accepted $target");
            } catch (RedirectException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStopsAfterTheConfiguredNumberOfRedirects(): void
    {
        $http = $this->http([self::redirect(302, '/1'), self::redirect(302, '/2'), self::redirect(302, '/3')]);

        $this->expectException(RedirectException::class);
        try {
            $this->send($http, 'GET', 'https://api.example.com/a', [], null, new RedirectPolicy(maxRedirects: 2));
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testNoneReturnsTheRedirectResponse(): void
    {
        $http = $this->http([self::redirect(302, '/next')]);

        $response = $this->send($http, 'GET', 'https://api.example.com/a', [], null, RedirectPolicy::none());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/next', $response->getHeaderLine('Location'));
    }

    public function testStatusWithoutLocationOrNotInPolicyIsReturned(): void
    {
        self::assertSame(302, $this->send($this->http([new Response(302)]), 'GET', 'https://api.example.com/a')->getStatusCode());
        self::assertSame(
            301,
            $this->send($this->http([self::redirect(301, '/x')]), 'GET', 'https://api.example.com/a', [], null, new RedirectPolicy(statuses: [302]))->getStatusCode(),
        );
        self::assertSame(
            304,
            $this->send($this->http([new Response(304)]), 'GET', 'https://api.example.com/a')->getStatusCode(),
        );
    }

    public function testMiddlewareRunsOnEveryHop(): void
    {
        $http = $this->http([self::redirect(302, '/next'), new Response(200)]);
        $middleware = new class implements HttpMiddlewareInterface {
            public int $before = 0;
            public int $after = 0;

            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                $this->before++;
                return $request;
            }

            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $this->after++;
                return $response;
            }
        };
        $transport = new Psr18Transport($http);
        $transport->addMiddleware($middleware);

        $transport->sendResponse('GET', 'https://api.example.com/a', [], null);

        self::assertSame([2, 2], [$middleware->before, $middleware->after]);
    }

    public function testRejectsNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedirectPolicy(maxRedirects: -1);
    }

    public function testApiClientFollowsRedirectsByDefaultAndCanOptOut(): void
    {
        $http = $this->http([self::redirect(302, '/pong'), new Response(200, [], '')]);
        $client = TestClient::connect('https://api.example.com', httpClient: $http);
        $client->ping();
        self::assertCount(2, $http->requests);

        $http = $this->http([self::redirect(302, '/pong')]);
        $client = TestClient::connect('https://api.example.com', httpClient: $http)->withRedirectPolicy(RedirectPolicy::none());
        try {
            $client->ping();
            self::fail('Expected ApiException.');
        } catch (ApiException) {
            self::assertSame(302, $client->lastResponse()?->status);
            self::assertSame('/pong', $client->lastResponse()->headers->first('Location'));
        }
    }
}
