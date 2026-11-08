<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\MultipartSerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class MultipartPayload extends DTO
{
    public const array REQUIRED_FIELDS = ['name'];
    protected const array VALIDATION = ['name' => ['minLength' => 1], 'ids' => ['items' => ['type' => 'int', 'rules' => ['minimum' => 1]]]];
    protected const array PROPERTY_MAP = ['display-name' => 'name'];
    protected const array WIRE_PROPERTY_MAP = ['name' => 'display-name'];
    protected const array READ_ONLY_FIELDS = ['internal'];
    public string $name;
    public string $file;
    public bool $active;
    public ?string $note;
    public array $ids;
    public string $internal;
}

class MultipartProbeClient extends ApiClient
{
    public function submit(?DTO $body): void
    {
        $this->executeOperation(['method' => 'POST', 'path' => '/files', 'success' => [204],
            'requestMediaType' => 'multipart/form-data', 'bodyParts' => [
                'display-name' => ['contentType' => 'text/plain'], 'active' => ['contentType' => 'text/plain'],
                'file' => ['contentType' => 'application/octet-stream', 'filename' => 'file'],
                'ids' => ['contentType' => 'text/plain'], 'note' => ['contentType' => 'text/plain'],
            ]], queryParameters: ['body' => 'query +'], headerParameters: ['X-Trace' => 'trace'], body: $body);
    }
}

final class MultipartSerializationTest extends TestCase
{
    public function testGuzzleProducesMultipartWithBinaryBytesRepeatedNamesAndDirections(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $body = new MultipartPayload((object) ['display-name' => 'ñ +%~', 'file' => "\x00\xff\r\n", 'active' => false,
            'ids' => [1, 2], 'internal' => 'hidden', 'note' => null]);
        MultipartProbeClient::connect('https://example.test', transport: $transport)->submit($body);
        $request = $transport->sent[0];
        self::assertSame('https://example.test/files?body=query%20%2B', $request['url']);
        self::assertSame('trace', $request['headers']['X-Trace']);
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=([a-f0-9]+)$/', $request['headers']['Content-Type']);
        $boundary = substr($request['headers']['Content-Type'], strlen('multipart/form-data; boundary='));
        self::assertStringStartsWith('--' . $boundary . "\r\n", $request['body']);
        self::assertStringEndsWith('--' . $boundary . "--\r\n", $request['body']);
        self::assertStringContainsString('name="display-name"', $request['body']);
        self::assertStringContainsString("\r\n\r\nñ +%~\r\n", $request['body']);
        self::assertStringContainsString('name="file"; filename="file"', $request['body']);
        self::assertStringContainsString('Content-Type: application/octet-stream', $request['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", $request['body']);
        self::assertSame(2, substr_count($request['body'], 'name="ids"'));
        self::assertStringContainsString("\r\n\r\nfalse\r\n", $request['body']);
        self::assertStringNotContainsString('name="internal"', $request['body']);
        self::assertStringNotContainsString('name="note"', $request['body']);
    }

    public function testAbsentEmptyNullAndEmptyStringRemainDistinct(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = MultipartProbeClient::connect('https://example.test', transport: $transport);
        $client->submit(null);
        $client->submit(new DTO());
        $client->submit(new MultipartPayload((object) ['display-name' => 'Ada', 'file' => '', 'ids' => [], 'note' => '']));
        self::assertNull($transport->sent[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->sent[0]['headers']);
        self::assertMatchesRegularExpression('/^--[a-f0-9]+--\r\n$/', $transport->sent[1]['body']);
        self::assertStringContainsString('filename="file"', $transport->sent[2]['body']);
        self::assertStringContainsString('name="note"', $transport->sent[2]['body']);
        self::assertStringNotContainsString('name="ids"', $transport->sent[2]['body']);
    }

    public function testMutationsAndUnsupportedValuesFailBeforeTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = MultipartProbeClient::connect('https://example.test', transport: $transport);
        $body = new MultipartPayload((object) ['display-name' => 'Ada', 'ids' => [1]]);
        $body->ids[] = 0;
        try {
            $client->submit($body);
            self::fail('Invalid mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
        foreach ([[null], [[1]], [INF], ['key' => 'value']] as $value) {
            $dto = new class extends DTO { public array $items; };
            $dto->items = $value;
            try {
                MultipartSerializer::encode($dto, ['items' => ['contentType' => 'text/plain']]);
                self::fail('Undefined multipart list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testUnsafeMetadataAndMissingFieldsAreRejected(): void
    {
        foreach ([['x' => ['contentType' => "text/plain\r\nX: y"]],
            ["x\"\r\n" => ['contentType' => 'text/plain']],
            ['x' => ['contentType' => 'text/plain', 'filename' => 'a"b']],
            ['x' => ['contentType' => 'application/octet-stream', 'filename' => '0']]] as $parts) {
            try {
                MultipartSerializer::encode(new DTO(), $parts);
                self::fail('Unsafe metadata accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        MultipartSerializer::encode(new MultipartPayload((object) ['display-name' => 'Ada']), []);
    }

    public function testNumericWireNamesRemainStringsWhenPhpCastsArrayKeys(): void
    {
        $body = new class extends DTO {
            protected const array WIRE_PROPERTY_MAP = ['value' => '0'];
            public string $value = 'zero';
        };
        $stream = MultipartSerializer::encode($body, ['0' => ['contentType' => 'text/plain']]);
        self::assertStringContainsString('name="0"', (string) $stream);
        self::assertStringContainsString("\r\n\r\nzero\r\n", (string) $stream);
    }
}
