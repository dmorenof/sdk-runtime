<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Hydrator;
use PHPUnit\Framework\TestCase;

final class NativeSchemasTest extends TestCase {
    public function testPrimitiveUnionsPreserveJsonKindsAndIntegralNumberValues(): void {
        foreach ([['name', 'string'], [7, 'integer'], [true, 'boolean'], [null, 'NULL']] as [$value, $type]) {
            $model = new class((object) ['value' => $value]) extends DTO { public string|int|bool|null $value; };
            self::assertSame($type, gettype($model->value)); self::assertSame($value, $model->value);
        }
        self::assertSame(1, Hydrator::hydrate(1.0, 'int', false, 'native'));
        self::assertSame(\Dmorenof\SdkRuntime\Tests\Fixtures\IntegerCode::One, Hydrator::hydrate(1.0, \Dmorenof\SdkRuntime\Tests\Fixtures\IntegerCode::class, false, 'enum'));
        self::assertSame(PHP_INT_MIN, Hydrator::hydrate((float) PHP_INT_MIN, 'int', false, 'native'));
        self::assertSame(1.0, Hydrator::hydrate(1.0, 'int|float', false, 'native'));
        self::assertSame(1, Hydrator::hydrate(1, 'int|float', false, 'native'));
        self::assertSame('1', Hydrator::hydrate('1', 'string|int', false, 'native'));
        foreach ([1.5, INF, NAN, (float) PHP_INT_MAX] as $invalid) {
            try { Hydrator::hydrate($invalid, 'int', false, 'native'); self::fail('Nonrepresentable integer accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testBooleanSchemasAcceptEveryJsonKindOrNoValue(): void {
        foreach ([null, false, 0, 'text', [], (object) [], (object) ['nested' => [1, null]]] as $value) {
            Constraints::validate($value, ['schema' => true], 'true'); self::assertTrue(true);
            try { Constraints::validate($value, ['schema' => false], 'false'); self::fail('False schema accepted data.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        Constraints::validate([], ['schema' => ['type' => 'array', 'items' => false]], 'empty');
        try { Constraints::validate([null], ['schema' => ['type' => 'array', 'items' => false]], 'nonempty'); self::fail('False items accepted.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
    }
    public function testNativeUnionSchemaRulesKeepKeywordApplicability(): void {
        $rules = ['schema' => ['type' => ['string', 'integer', 'boolean', 'null'], 'minLength' => 2, 'minimum' => 0]];
        foreach (['text', 0, true, null] as $value) { Constraints::validate($value, $rules, 'valid'); self::assertTrue(true); }
        foreach (['x', -1, 1.5, [], (object) []] as $value) {
            try { Constraints::validate($value, $rules, 'invalid'); self::fail('Invalid union data accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
    }
    public function testFalsePropertyCanBeOmittedButRejectsPresentNull(): void {
        $model = new class extends DTO { protected const array VALIDATION = ['forbidden' => ['schema' => false]]; public mixed $forbidden; };
        $model->validate(); self::assertTrue(true); $model->forbidden = null;
        $this->expectException(\UnexpectedValueException::class); $model->validate();
    }
    public function testRequiredNullableWireParametersCannotDisappearDuringSerialization(): void {
        $model = new class((object) ['value' => null]) extends DTO {
            public const array REQUIRED_FIELDS = ['value'];
            public const array OPERATION_FIELDS = ['query' => ['value' => 'value']];
            public int|string|null $value;
        };
        $this->expectException(\UnexpectedValueException::class);
        $model->validateForRequest();
    }
    public function testOptionalHttpParameterNullRetainsOmissionSemantics(): void {
        $model = new class((object) ['value' => null]) extends DTO {
            public const array OPERATION_FIELDS = ['query' => ['value' => 'value']];
            protected const array VALIDATION = ['value' => ['schema' => ['type' => ['string', 'integer']]]];
            public int|string|null $value;
        };
        $model->validateForRequest(); self::assertNull($model->value);
        $model->value = 'valid'; $model->validateForRequest(); self::assertSame('valid', $model->value);
    }
}
