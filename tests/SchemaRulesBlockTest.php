<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Map;
use Dmorenof\SdkRuntime\TypedArray;
use PHPUnit\Framework\TestCase;

final class SchemaRulesBlockTest extends TestCase {
    public function testNumericLimitsDecimalMultiplesAndJsonEquality(): void {
        foreach ([[0.3, ['multipleOf' => 0.1]], [-0.3, ['multipleOf' => 0.1]], [0, ['multipleOf' => 0.1]],
            [2, ['exclusiveMinimum' => 1, 'exclusiveMaximum' => 3]], [1.0, ['const' => 1]], [null, ['const' => null]],
            [[false, 0, '0'], ['uniqueItems' => true]], [(object) ['a' => 1], ['minProperties' => 1, 'maxProperties' => 1]]] as [$value, $rules]) {
            Constraints::validate($value, $rules, 'valid'); self::assertTrue(true);
        }
        foreach ([[0.31, ['multipleOf' => 0.1]], [1, ['exclusiveMinimum' => 1]], [3, ['exclusiveMaximum' => 3]], [null, ['const' => 1]],
            [[1, 1.0], ['uniqueItems' => true]], [[(object) ['a' => 1, 'b' => 2], (object) ['b' => 2, 'a' => 1.0]], ['uniqueItems' => true]],
            [(object) [], ['minProperties' => 1]], [(object) ['a' => 1, 'b' => 2], ['maxProperties' => 1]]] as [$value, $rules]) {
            try { Constraints::validate($value, $rules, 'invalid'); self::fail('Invalid JSON schema value accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testModelCountsFollowWireDirectionAndNullableConstIsNotSkipped(): void {
        $dto = new class((object) ['id' => 1]) extends DTO {
            protected const array MODEL_VALIDATION = ['minProperties' => 1, 'maxProperties' => 1];
            protected const array READ_ONLY_FIELDS = ['id'];
            public int $id;
        };
        $dto->validateForResponse();
        try { $dto->validateForRequest(); self::fail('ReadOnly field satisfied request property count.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
        $dto = new class((object) ['value' => 7]) extends DTO {
            protected const array VALIDATION = ['value' => ['const' => 7]];
            public ?int $value;
        };
        $dto->value = null;
        $this->expectException(\UnexpectedValueException::class); $dto->validate();
    }
    public function testCollectionAndMapConstraintsAreRecheckedAfterMutations(): void {
        $values = new class([1, 2]) extends TypedArray {
            protected string $expected_type = 'integer';
            protected const array COLLECTION_VALIDATION = ['minItems' => 2, 'uniqueItems' => true];
        };
        self::assertSame('[1,2]', (string) $values);
        $values[] = 1;
        try { DTO::validateNestedValue($values, 'request'); self::fail('Duplicate collection mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
        $map = new class((object) ['first' => 1]) extends Map {
            protected const string VALUE_TYPE = 'int';
            protected const array MAP_VALIDATION = ['minProperties' => 1, 'maxProperties' => 2];
        };
        unset($map['first']);
        try { $map->jsonSerialize(); self::fail('Map mutation escaped property count validation.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
    }
    public function testScalarResponseRulesUseStatusSpecificAndLegacyMetadata(): void {
        foreach ([false, true] as $statusMap) {
            $client = new class('https://example.test', transport: new \Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport(new \Dmorenof\SdkRuntime\Transport\HttpResponse(200, '0.31'))) extends \Dmorenof\SdkRuntime\ApiClient {
                public function fetch(bool $statusMap): mixed {
                    return $this->executeOperation(['method' => 'GET', 'path' => '/', 'responseType' => 'float'] + ($statusMap
                        ? ['responseTypes' => [200 => ['type' => 'float', 'schemaNullable' => false, 'rules' => ['multipleOf' => 0.1]]]]
                        : ['responseValidation' => ['multipleOf' => 0.1]]));
                }
            };
            try { $client->fetch($statusMap); self::fail('Invalid scalar response accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testUnicodeLengthAlreadyCountsCodePointsRatherThanBytes(): void {
        foreach (['ñ', '😀', "\n"] as $value) { Constraints::validate($value, ['minLength' => 1, 'maxLength' => 1], 'unicode'); self::assertTrue(true); }
        $this->expectException(\UnexpectedValueException::class);
        Constraints::validate("e\u{0301}", ['maxLength' => 1], 'combining-code-points');
    }
}
