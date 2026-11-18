<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

enum ParameterStatus: string { case Open = 'open'; case Closed = 'closed'; }
enum ParameterLevel: int { case Low = 1; case High = 2; }

final class EnumQueryParams extends DTO
{
    public const array OPERATION_FIELDS = [
        'path' => ['state' => 'state'],
        'query' => ['status' => 'status', 'level' => 'level', 'tags' => 'tags'],
        'header' => ['X-Status' => 'xStatus'],
        'cookie' => ['mode' => 'mode'],
    ];
    public ParameterStatus $state;
    public ?ParameterStatus $status;
    public ?ParameterLevel $level;
    /** @var list<ParameterStatus> */
    public array $tags;
    public ?ParameterStatus $xStatus;
    public ?ParameterLevel $mode;
}

/** A parameter typed as an enum travels as its backing value, wherever it goes. */
final class EnumParametersTest extends TestCase
{
    private const SPEC = ['method' => 'GET', 'path' => '/items/{state}', 'success' => [204], 'querySerialization' => ['tags' => 'multi']];

    /** @return array{object, list<array{string, string, array<string, string>}>} */
    private static function client(): array
    {
        $transport = new class implements TransportInterface {
            /** @var list<array{string, string, array<string, string>}> */
            public array $sent = [];
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->sent[] = [$method, $url, $headers];
                return new HttpResponse(204, '');
            }
        };
        $configuration = new Configuration();
        $configuration->baseUrl = 'https://api.example.test';
        $configuration->transport = $transport;
        $client = new class($configuration) extends ApiClient {
            /** @param array<string, mixed> $spec */
            public function call(array $spec, EnumQueryParams $params): mixed
            {
                return $this->executeArguments($spec, $params, $params);
            }
            /** @param array<string, mixed> $spec @param array<string, mixed> $path */
            public function callPath(array $spec, array $path): mixed
            {
                return $this->executeArguments($spec, $path);
            }
        };
        return [$client, $transport];
    }

    public function testEveryLocationAcceptsEnumCases(): void
    {
        [$client, $transport] = self::client();
        $params = new EnumQueryParams();
        $params->state = ParameterStatus::Open;
        $params->status = ParameterStatus::Closed;
        $params->level = ParameterLevel::High;
        $params->tags = [ParameterStatus::Open, ParameterStatus::Closed];
        $params->xStatus = ParameterStatus::Closed;
        $params->mode = ParameterLevel::Low;

        $client->call(self::SPEC, $params);

        [$method, $url, $headers] = $transport->sent[0];
        self::assertSame('GET', $method);
        self::assertSame('https://api.example.test/items/open?status=closed&level=2&tags=open&tags=closed', $url);
        self::assertSame('closed', $headers['X-Status']);
        self::assertSame('mode=1', $headers['Cookie']);
    }

    public function testAPathArgumentMayBeAnEnumCase(): void
    {
        [$client, $transport] = self::client();
        $client->callPath(self::SPEC, ['state' => ParameterStatus::Closed]);
        self::assertSame('https://api.example.test/items/closed', $transport->sent[0][1]);
    }

    public function testPlainValuesStillWork(): void
    {
        [$client, $transport] = self::client();
        $client->callPath(self::SPEC, ['state' => 'plain']);
        self::assertSame('https://api.example.test/items/plain', $transport->sent[0][1]);
    }
}
