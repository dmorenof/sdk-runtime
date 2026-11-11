<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\ServerUrl;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServersProbe extends ApiClient
{
    protected const array SERVERS = [
        ['url' => 'https://{region}.api.example.com/{version}', 'name' => 'production', 'variables' => [
            'region' => ['default' => 'eu', 'enum' => ['eu', 'us']],
            'version' => ['default' => 'v1'],
        ]],
        ['url' => 'https://sandbox.example.com', 'name' => 'sandbox'],
        ['url' => '/relative-root'],
    ];

    private const array SELECTABLE = [
        ['url' => 'https://{region}.api.example.com/{version}', 'name' => 'production', 'variables' => [
            'region' => ['default' => 'eu', 'enum' => ['eu', 'us']],
            'version' => ['default' => 'v1'],
        ]],
        ['url' => '/sandbox', 'name' => 'sandbox'],
    ];

    public function selectable(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/ping', 'servers' => self::SELECTABLE]);
    }

    public function literal(string $url): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/ping', 'serverUrl' => $url]);
    }

    public function root(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/ping']);
    }
}

final class DynamicServersTest extends TestCase
{
    private function client(string $base = 'https://root.test/v1', array $overrides = []): array
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        return [ServersProbe::connect($base, transport: $transport, serverOverrides: $overrides), $transport];
    }

    /** @return iterable<string, array{string, string}> */
    public static function relativeServers(): iterable
    {
        yield 'path absolute' => ['/v2', 'https://root.test/v2/ping'];
        yield 'path relative is taken from the base directory' => ['v2', 'https://root.test/v1/v2/ping'];
        yield 'parent segment' => ['../v2', 'https://root.test/v2/ping'];
        yield 'current directory' => ['./sub/', 'https://root.test/v1/sub/ping'];
        yield 'scheme relative' => ['//other.test/api', 'https://other.test/api/ping'];
    }

    #[DataProvider('relativeServers')]
    public function testRelativeServerResolvesAgainstTheBaseUrl(string $declared, string $expected): void
    {
        [$client, $transport] = $this->client();

        $client->literal($declared);

        self::assertSame($expected, $transport->sent[0]['url']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLiteralServers(): iterable
    {
        yield 'query' => ['/v2?tenant=1'];
        yield 'fragment' => ['/v2#x'];
        yield 'template' => ['/{tenant}/v2'];
        yield 'whitespace' => ['/v 2'];
        yield 'credentials' => ['//user:pw@other.test/x'];
        yield 'other scheme' => ['ftp://other.test/x'];
    }

    #[DataProvider('invalidLiteralServers')]
    public function testInvalidLiteralServersFailBeforeTransport(string $declared): void
    {
        [$client, $transport] = $this->client();

        try {
            $client->literal($declared);
            self::fail("Accepted $declared");
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->sent);
        }
    }

    public function testEnvironmentOverridesApplyToTheResolvedRelativeUrl(): void
    {
        [$client, $transport] = $this->client('https://root.test/v1', ['https://root.test/v2' => 'http://127.0.0.1:9000/mock']);

        $client->literal('/v2');

        self::assertSame('http://127.0.0.1:9000/mock/ping', $transport->sent[0]['url']);
    }

    public function testTemplatedServerUsesDeclaredDefaultsAndTheFirstServer(): void
    {
        [$client, $transport] = $this->client();

        $client->selectable();

        self::assertSame('https://eu.api.example.com/v1/ping', $transport->sent[0]['url']);
    }

    public function testVariablesAreBoundByName(): void
    {
        [$client, $transport] = $this->client();

        $client->withServerVariables(['region' => 'us'])->selectable();
        $client->withServerVariables(['version' => 'v2'])->selectable();

        self::assertSame('https://us.api.example.com/v1/ping', $transport->sent[0]['url']);
        self::assertSame('https://us.api.example.com/v2/ping', $transport->sent[1]['url'], 'later calls merge with earlier values');
    }

    public function testValueOutsideTheEnumFailsBeforeTransport(): void
    {
        [$client, $transport] = $this->client();

        try {
            $client->withServerVariables(['region' => 'asia'])->selectable();
            self::fail('Expected enum failure.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('eu, us', $e->getMessage());
            self::assertSame([], $transport->sent);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function structureChangingValues(): iterable
    {
        yield 'path in authority' => ['x.evil.test/a'];
        yield 'userinfo' => ['evil@host'];
        yield 'query' => ['a?b=1'];
        yield 'fragment' => ['a#b'];
        yield 'backslash' => ['a\\b'];
        yield 'space' => ['a b'];
        yield 'brace' => ['{x}'];
    }

    #[DataProvider('structureChangingValues')]
    public function testVariableValuesCannotChangeTheUrlStructure(string $value): void
    {
        $server = ['url' => 'https://{host}.example.com/api', 'variables' => ['host' => ['default' => 'eu']]];

        $this->expectException(\InvalidArgumentException::class);
        ServerUrl::expand($server, ['host' => $value]);
    }

    public function testPathVariablesMayContainSlashes(): void
    {
        $server = ['url' => 'https://api.example.com/{prefix}/v1', 'variables' => ['prefix' => ['default' => 'tenant/a']]];

        self::assertSame('https://api.example.com/tenant/a/v1', ServerUrl::expand($server));
    }

    public function testUndeclaredPlaceholderIsRejectedAndUndeclaredValuesAreIgnored(): void
    {
        self::assertSame('https://a.test', ServerUrl::expand(['url' => 'https://a.test'], ['region' => 'us']));

        $this->expectException(\InvalidArgumentException::class);
        ServerUrl::expand(['url' => 'https://{missing}.test']);
    }

    public function testServerCanBeSelectedByIndexOrNameAndRelativeServersResolve(): void
    {
        [$client, $transport] = $this->client();

        $client->useServer(1)->selectable();
        $client->useServer('production')->selectable();
        $client->useServer('sandbox')->selectable();
        $client->useServer(null)->selectable();

        self::assertSame('https://root.test/sandbox/ping', $transport->sent[0]['url']);
        self::assertSame('https://eu.api.example.com/v1/ping', $transport->sent[1]['url']);
        self::assertSame('https://root.test/sandbox/ping', $transport->sent[2]['url']);
        self::assertSame('https://eu.api.example.com/v1/ping', $transport->sent[3]['url']);
    }

    public function testUnknownServerSelectionFailsBeforeTransport(): void
    {
        [$client, $transport] = $this->client();

        foreach ([5, 'staging'] as $selection) {
            try {
                $client->useServer($selection)->selectable();
                self::fail("Accepted $selection");
            } catch (\InvalidArgumentException) {
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testSelectionDoesNotAffectOperationsWithoutServers(): void
    {
        [$client, $transport] = $this->client();

        $client->useServer('sandbox')->withServerVariables(['region' => 'us'])->root();

        self::assertSame('https://root.test/v1/ping', $transport->sent[0]['url']);
    }

    public function testResourcesInheritTheSelectionAndAreIndependentAfterwards(): void
    {
        [$client, $transport] = $this->client();
        $client->useServer('production')->withServerVariables(['region' => 'us']);

        $resource = $client->resource(ServersProbe::class);
        $resource->withServerVariables(['region' => 'eu']);
        $resource->selectable();
        $client->selectable();

        self::assertSame('https://eu.api.example.com/v1/ping', $transport->sent[0]['url']);
        self::assertSame('https://us.api.example.com/v1/ping', $transport->sent[1]['url']);
    }

    public function testSeparateConnectionsKeepSeparateSelections(): void
    {
        [$a, $ta] = $this->client();
        [$b, $tb] = $this->client();

        $a->withServerVariables(['region' => 'us'])->selectable();
        $b->selectable();

        self::assertSame('https://us.api.example.com/v1/ping', $ta->sent[0]['url']);
        self::assertSame('https://eu.api.example.com/v1/ping', $tb->sent[0]['url']);
    }

    public function testOperationCannotDeclareBothServerForms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Operation::fromArray(['method' => 'GET', 'path' => '/x', 'serverUrl' => 'https://a.test', 'servers' => [['url' => 'https://b.test']]]);
    }

    public function testRootServerUrlHelper(): void
    {
        self::assertSame('https://eu.api.example.com/v1', ServersProbe::serverUrl());
        self::assertSame('https://us.api.example.com/v2', ServersProbe::serverUrl('production', ['region' => 'us', 'version' => 'v2']));
        self::assertSame('https://sandbox.example.com', ServersProbe::serverUrl('sandbox'));
        self::assertSame('https://sandbox.example.com', ServersProbe::serverUrl(1));
    }

    public function testRootServerUrlHelperRejectsMistakes(): void
    {
        foreach ([
            [0, ['regoin' => 'us']],     // misspelled variable
            [0, ['region' => 'asia']],   // outside the enum
            [2, []],                     // relative root server has no base to resolve against
            [9, []],                     // no such server
            ['nope', []],
        ] as [$server, $variables]) {
            try {
                ServersProbe::serverUrl($server, $variables);
                self::fail('Accepted ' . json_encode([$server, $variables]));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testConnectedClientUsesTheHelperResult(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, ''));
        $client = ServersProbe::connect(ServersProbe::serverUrl('production', ['region' => 'us']), transport: $transport);

        $client->root();

        self::assertSame('https://us.api.example.com/v1/ping', $transport->sent[0]['url']);
    }

    public function testServerVariableMapIsValidated(): void
    {
        [$client] = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->withServerVariables(['region' => 1]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function schemes(): iterable
    {
        yield 'https' => ['https://api.example.com', true];
        yield 'upper case' => ['HTTPS://API.EXAMPLE.COM', true];
        yield 'mailto' => ['mailto:x@example.com', true];
        yield 'path absolute' => ['/v2', false];
        yield 'path relative' => ['v2/x', false];
        yield 'parent' => ['../v2', false];
        yield 'scheme relative' => ['//host/x', false];
        yield 'digit first' => ['1http://x', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('schemes')]
    public function testIsAbsoluteTellsASchemeFromARelativeUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, ServerUrl::isAbsolute($url));
    }

    public function testAVariableThatRepeatsInTheHostAndThePathIsCheckedPerPosition(): void
    {
        $server = ['url' => 'https://{p}.example.com/{p}', 'variables' => ['p' => ['default' => 'one']]];
        self::assertSame('https://one.example.com/one', ServerUrl::expand($server));

        $this->expectException(\InvalidArgumentException::class);
        ServerUrl::expand($server, ['p' => 'a/b']); // fine in the path, never in the host
    }
}
