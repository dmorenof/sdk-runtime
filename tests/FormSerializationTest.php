<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\FormSerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

enum FormState: string { case Open = 'open'; }

class FormPayload extends DTO
{
    public const array REQUIRED_FIELDS = ['name'];
    protected const array VALIDATION = ['name' => ['minLength' => 1], 'ids' => ['items' => ['type' => 'int', 'rules' => ['minimum' => 1]]]];
    protected const array READ_ONLY_FIELDS = ['internal'];
    protected const array WRITE_ONLY_FIELDS = ['secret'];
    protected const array PROPERTY_MAP = ['display-name' => 'name'];
    protected const array WIRE_PROPERTY_MAP = ['name' => 'display-name'];
    public string $name;
    public ?string $note;
    public bool $active;
    public array $ids;
    public array $codes;
    public string $internal;
    public string $secret;
    public FormState $state;
}

class FormProbeClient extends ApiClient
{
    public function submit(?DTO $body, array $formats = [], string $media = 'application/x-www-form-urlencoded'): void
    {
        $this->executeOperation(['method' => 'POST', 'path' => '/forms', 'success' => [204],
            'requestMediaType' => $media, 'bodySerialization' => $formats],
            queryParameters: ['name' => 'query +'], headerParameters: ['X-Trace' => 'trace'], body: $body);
    }
}

final class FormSerializationTest extends TestCase
{
    public function testFormEncodingPreservesWireNamesDirectionsEnumsAndListModes(): void
    {
        $body = new FormPayload((object) ['display-name' => 'ñ +~%20', 'note' => null, 'active' => false,
            'ids' => [1, 2], 'codes' => ['a,b', 'x y'], 'internal' => 'hidden', 'secret' => '', 'state' => 'open']);
        self::assertSame('display-name=%C3%B1+%2B%7E%2520&active=false&ids=1&ids=2&codes=a%2Cb,x+y&secret=&state=open',
            FormSerializer::encode($body, ['ids' => 'multi', 'codes' => 'csv']));
    }

    public function testRuntimeKeepsQueryHeadersAndFormBodySeparate(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        FormProbeClient::connect('https://api.example.test', transport: $transport)->submit(new FormPayload((object) ['display-name' => 'body value']));
        self::assertSame('https://api.example.test/forms?name=query%20%2B', $transport->sent[0]['url']);
        self::assertSame('display-name=body+value', $transport->sent[0]['body']);
        self::assertSame(['Accept' => 'application/json', 'X-Trace' => 'trace', 'Content-Type' => 'application/x-www-form-urlencoded'], $transport->sent[0]['headers']);
    }

    public function testAbsentBodyAndPresentEmptyBodyAreDifferent(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = FormProbeClient::connect('https://api.example.test', transport: $transport);
        $client->submit(null);
        $client->submit(new DTO());
        self::assertNull($transport->sent[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->sent[0]['headers']);
        self::assertSame('', $transport->sent[1]['body']);
        self::assertSame('application/x-www-form-urlencoded', $transport->sent[1]['headers']['Content-Type']);
    }

    public function testListMutationsAndUndefinedShapesAreRejectedBeforeHttp(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = FormProbeClient::connect('https://api.example.test', transport: $transport);
        $body = new FormPayload((object) ['display-name' => 'Ada', 'ids' => [1]]);
        $body->ids[] = 'wrong';
        try {
            $client->submit($body, ['ids' => 'multi']);
            self::fail('Invalid form mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
        foreach ([[null], [[1]], [INF]] as $items) {
            $body = new FormPayload((object) ['display-name' => 'Ada', 'codes' => $items]);
            try {
                $client->submit($body, ['codes' => 'csv']);
                self::fail('Undefined form list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testEmptyListsAndEmptyStringMembersKeepTheirExpansionRules(): void
    {
        $body = new FormPayload((object) ['display-name' => 'Ada', 'ids' => [], 'codes' => ['', 'x']]);
        self::assertSame('display-name=Ada&codes=,x', FormSerializer::encode($body, ['ids' => 'multi', 'codes' => 'csv']));
    }

    public function testOperationPositionalQueryArgumentRemainsCompatible(): void
    {
        $operation = new \Dmorenof\SdkRuntime\Operation('POST', '/forms', null, [], [204], [], null, false, false, [], null, ['ids' => 'multi']);
        self::assertSame(['ids' => 'multi'], $operation->querySerialization);
        self::assertSame('application/json', $operation->requestMediaType);
        self::assertSame([], $operation->bodySerialization);
    }

    public function testUnsupportedMediaAndJsonFormMetadataFailBeforeHttp(): void
    {
        foreach ([['multipart/form-data', []], ['application/json', ['ids' => 'multi']], ['application/x-www-form-urlencoded', ['ids' => 'pipes']]] as [$media, $formats]) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            try {
                FormProbeClient::connect('https://api.example.test', transport: $transport)->submit(new FormPayload((object) ['display-name' => 'Ada']), $formats, $media);
                self::fail('Unsupported body metadata accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $transport->sent);
            }
        }
    }
}
