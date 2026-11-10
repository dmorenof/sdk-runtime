<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\FormSerializer;
use Dmorenof\SdkRuntime\MultipartSerializer;
use PHPUnit\Framework\TestCase;

final class FormEncodingBlockTest extends TestCase {
    public function testDelimitedListsAndFlatObjectsUseExistingFormEncoding(): void {
        $body = new class((object) ['values' => ['a/b', '%2F', 'ñ']]) extends DTO { public array $values; };
        foreach (['csv' => ',', 'ssv' => '+', 'tsv' => '%09', 'pipes' => '%7C'] as $format => $separator) {
            self::assertSame('values=a%2Fb' . $separator . '%252F' . $separator . '%C3%B1', FormSerializer::encode($body, ['values' => $format]));
        }
        $body = new class extends DTO { public DTO $filter; public bool $active = false; };
        $body->filter = new class((object) ['displayName' => 'a/b ñ', 'count' => 0]) extends DTO {
            protected const array WIRE_PROPERTY_MAP = ['displayName' => 'display-name'];
            public string $displayName; public int $count;
        };
        self::assertSame('filter=display-name,a%2Fb+%C3%B1,count,0&active=false', FormSerializer::encode($body, ['filter' => 'form-object']));
        self::assertSame('display-name=a%2Fb+%C3%B1&count=0&active=false', FormSerializer::encode($body, ['filter' => 'form-object-explode']));
        self::assertSame('filter%5Bdisplay-name%5D=a%2Fb+%C3%B1&filter%5Bcount%5D=0&active=false', FormSerializer::encode($body, ['filter' => 'deepObject']));
    }
    public function testAmbiguousDelimitedValuesAndExpandedObjectCollisionsFail(): void {
        foreach (['ssv' => 'a b', 'tsv' => "a\tb", 'pipes' => 'a|b'] as $format => $value) {
            $body = new class((object) ['values' => [$value]]) extends DTO { public array $values; };
            try { FormSerializer::encode($body, ['values' => $format]); self::fail('Ambiguous list accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        $body = new class extends DTO { public DTO $filter; public string $count = 'outer'; };
        $body->filter = new class extends DTO { public int $count = 1; };
        $this->expectException(\UnexpectedValueException::class);
        FormSerializer::encode($body, ['filter' => 'form-object-explode']);
    }
    public function testMultipartJsonUsesRequestDirectionAndRepeatedComplexParts(): void {
        $record = new class((object) ['id' => 7, 'displayName' => 'ñ/x']) extends DTO {
            protected const array READ_ONLY_FIELDS = ['id'];
            protected const array WIRE_PROPERTY_MAP = ['displayName' => 'display-name'];
            public int $id; public string $displayName;
        };
        $body = new class extends DTO { public DTO $record; public array $records; public string $title = 'literal'; public string $file = "\x00\xff"; };
        $body->record = $record; $body->records = [$record, $record];
        $mime = (string) MultipartSerializer::encode($body, [
            'record' => ['contentType' => 'application/json', 'json' => true],
            'records' => ['contentType' => 'application/vnd.test+json', 'json' => true],
            'title' => ['contentType' => 'application/json', 'json' => true],
            'file' => ['contentType' => null, 'filename' => 'file.bin'],
        ]);
        self::assertSame(3, substr_count($mime, '{"display-name":"\u00f1\/x"}'));
        self::assertStringNotContainsString('"id"', $mime);
        self::assertSame(2, substr_count($mime, 'name="records"'));
        self::assertStringContainsString("\r\n\r\n\"literal\"\r\n", $mime);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n", $mime);
    }
    public function testJsonPartsRejectIncompatibleMetadata(): void {
        $body = new class extends DTO { public string $value = 'x'; };
        foreach ([['contentType' => 'text/plain', 'json' => true], ['contentType' => 'application/json', 'json' => true, 'filename' => 'x.bin']] as $part) {
            try { MultipartSerializer::encode($body, ['value' => $part]); self::fail('Invalid JSON part metadata accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
