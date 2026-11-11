<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Tests\Support\ApiSnapshot;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase
{
    private const string ROOT = __DIR__ . '/..';

    public function testPublicApiMatchesTheFrozenSnapshot(): void
    {
        $expected = (string) file_get_contents(__DIR__ . '/Fixtures/public-api.txt');

        self::assertSame(
            $expected,
            ApiSnapshot::render(self::ROOT . '/src'),
            'The public API changed. If intentional, run "php ci/api-snapshot.php > tests/Fixtures/public-api.txt", '
            . 'review the diff and record it in CHANGELOG.md (removals or incompatible signatures need a major version).',
        );
    }

    public function testEveryStableClassIsDocumented(): void
    {
        $docs = (string) file_get_contents(self::ROOT . '/docs/PUBLIC_API.md');
        $snapshot = (string) file_get_contents(__DIR__ . '/Fixtures/public-api.txt');
        preg_match_all('/^(?:(?:final|abstract|readonly) )*(?:class|interface) (\S+)/m', $snapshot, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $fqcn) {
            $short = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
            self::assertStringContainsString('`' . $short . '`', $docs, "$short is stable API but missing from docs/PUBLIC_API.md");
        }
    }

    public function testInternalClassesStayOutOfTheSnapshot(): void
    {
        $snapshot = (string) file_get_contents(__DIR__ . '/Fixtures/public-api.txt');

        foreach (['Hydrator', 'JsonHydrator', 'Serializer', 'SchemaValidator', 'QuerySerializer', 'FormSerializer', 'MultipartSerializer', 'Discriminator', 'MediaType', 'SecurityApplier', 'ServerUrl', 'CookieHeader', 'Constraints', 'FormatValidator', 'StreamDecoder', 'CachedTokenProvider', 'OAuth2TokenEndpoint', 'Pkce'] as $internal) {
            self::assertDoesNotMatchRegularExpression('/^(?:\w+ )*class .*\\\\' . $internal . '\b/m', $snapshot, "$internal must be tagged @internal");
        }
    }
}
