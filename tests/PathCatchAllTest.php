<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\RequestInput;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class PathCatchAllClient extends ApiClient
{
    /** @param array<string, mixed> $path */
    public function get(array $path, bool $catchAll): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/assets/{asset_id}/indicators{url_path}', 'success' => [204],
            'pathAllowReserved' => $catchAll ? ['url_path'] : []], new RequestInput(path: $path));
    }
}

/** A path parameter that takes the rest of the path keeps its slashes; any other path parameter does not. */
final class PathCatchAllTest extends TestCase
{
    private function url(array $path, bool $catchAll): string
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        PathCatchAllClient::connect(\Dmorenof\SdkRuntime\Tests\Support\Cfg::make('https://api.example.test', transport: $transport))->get($path, $catchAll);
        return $transport->sent[0]['url'];
    }

    public function testACatchAllKeepsItsSlashesAndEncodesEachSegment(): void
    {
        self::assertSame('https://api.example.test/assets/a%2Fb/indicators/period/2024%20Q1/%C3%B1',
            $this->url(['asset_id' => 'a/b', 'url_path' => '/period/2024 Q1/ñ'], true));
    }

    public function testWithoutTheMetadataASlashIsEncoded(): void
    {
        self::assertSame('https://api.example.test/assets/x/indicators%2Fperiod', $this->url(['asset_id' => 'x', 'url_path' => '/period'], false));
    }
}
