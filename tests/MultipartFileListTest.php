<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\MultipartSerializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class FileListPayload extends DTO
{
    public const array REQUIRED_FIELDS = ['files'];
    protected const array VALIDATION = ['files' => ['minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'binary', 'rules' => []]]];
    protected const array PROPERTY_MAP = ['upload-files' => 'files'];
    protected const array WIRE_PROPERTY_MAP = ['files' => 'upload-files'];
    protected const array READ_ONLY_FIELDS = ['internal'];
    public array $files;
    public array $internal;
}

class FileListProbeClient extends ApiClient
{
    public function submit(DTO $body, ?string $mime = null): void
    {
        $this->executeOperation(['method' => 'POST', 'path' => '/files', 'success' => [204],
            'requestMediaType' => 'multipart/form-data', 'bodyParts' => [
                'upload-files' => ['contentType' => $mime, 'filename' => 'upload.bin'],
            ]], body: $body);
    }
}

final class MultipartFileListTest extends TestCase
{
    private static function upload(string $bytes = "\x00\xff", string $name = 'photo.png', string $mime = 'image/png'): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, $mime);
    }

    public function testFileListsRepeatExactWireNamesAndPreservePerItemMetadataAndBytes(): void
    {
        $body = new FileListPayload((object) ['upload-files' => [self::upload(), "raw\x00\xff"]]);
        $body->internal = [new UploadedFile(null, null, UPLOAD_ERR_PARTIAL)];
        $encoded = (string) MultipartSerializer::encode($body, ['upload-files' => ['contentType' => null, 'filename' => 'upload.bin']]);
        self::assertSame(2, substr_count($encoded, 'name="upload-files"'));
        self::assertStringContainsString('filename="photo.png"', $encoded);
        self::assertStringContainsString('filename="upload.bin"', $encoded);
        self::assertStringContainsString('Content-Type: image/png', $encoded);
        self::assertStringContainsString('Content-Type: application/octet-stream', $encoded);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n", $encoded);
        self::assertStringContainsString("\r\n\r\nraw\x00\xff\r\n", $encoded);
        self::assertStringNotContainsString('upload-files[', $encoded);
        self::assertStringNotContainsString('name="internal"', $encoded);
    }

    public function testConstructionAndMutationsValidateListShapeItemsAndBounds(): void
    {
        foreach ([[], [null], [1], [false], [["nested"]], [(object) []], ['key' => 'bytes'], ['a', 'b', 'c']] as $value) {
            try {
                new FileListPayload((object) ['upload-files' => $value]);
                self::fail('Invalid file list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = FileListProbeClient::connect('https://example.test', transport: $transport);
        $body = new FileListPayload((object) ['upload-files' => [self::upload()]]);
        $body->files[] = null;
        try {
            $client->submit($body);
            self::fail('Invalid file item mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
        $body->files = ['a', 'b', 'c'];
        try {
            $client->submit($body);
            self::fail('Invalid file count mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->sent);
        }
    }

    public function testEachFileChecksMimeUploadStatusAndIndependentCursorBeforeTransport(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = FileListProbeClient::connect('https://example.test', transport: $transport);
        $first = self::upload();
        foreach ([self::upload('pdf', 'report.pdf', 'application/pdf'), new UploadedFile(null, null, UPLOAD_ERR_PARTIAL), $first] as $second) {
            try {
                $client->submit(new FileListPayload((object) ['upload-files' => [$first, $second]]), 'image/png');
                self::fail('Invalid file list reached transport.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->sent);
                self::assertSame(0, $first->getStream()->tell());
            }
        }
    }

    public function testOptionalEmptyNullAndEmptyFilesFollowExistingPartPolicies(): void
    {
        foreach ([null, [], ['']] as $value) {
            $body = new class extends DTO {
                protected const array VALIDATION = ['files' => ['items' => ['type' => 'binary', 'rules' => []]]];
                public ?array $files;
            };
            $body->files = $value;
            $encoded = (string) MultipartSerializer::encode($body, ['files' => ['contentType' => null, 'filename' => 'empty.bin']]);
            self::assertSame($value === [''] ? 1 : 0, substr_count($encoded, 'name="files"'));
        }
    }

    public function testJsonKeepsStringListsAndRejectsUploadedObjectsInsideLists(): void
    {
        $body = new FileListPayload((object) ['upload-files' => ['plain']]);
        self::assertSame('{"upload-files":["plain"]}', json_encode($body, JSON_THROW_ON_ERROR));
        $body->files[] = self::upload();
        $this->expectException(\UnexpectedValueException::class);
        json_encode($body, JSON_THROW_ON_ERROR);
    }
}
