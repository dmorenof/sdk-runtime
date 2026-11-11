<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\Tests\Fixtures\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The string enum/const shortcut must give the same verdict as the schema validator it bypasses. */
final class ConstraintsFastPathTest extends TestCase
{
    private static function verdict(mixed $value, array $rules): string
    {
        try {
            Constraints::validate($value, $rules, 'field');
            return 'valid';
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }
    }

    /** @return iterable<string, array{mixed, list<mixed>}> */
    public static function enums(): iterable
    {
        yield 'member' => ['b', ['a', 'b']];
        yield 'not a member' => ['z', ['a', 'b']];
        yield 'empty string member' => ['', ['']];
        yield 'unicode' => ['é', ['é', 'e']];
        yield 'case differs' => ['E', ['e']];
        yield 'number-like string vs numbers' => ['1', [1, 2]];
        yield 'mixed members' => ['a', ['a', 1, null, ['x' => 1]]];
        yield 'null member only' => ['a', [null]];
    }

    /** @param list<mixed> $members */
    #[DataProvider('enums')]
    public function testEnumVerdictMatchesTheSchemaPath(mixed $value, array $members): void
    {
        self::assertSame(
            self::verdict($value, ['schema' => ['enum' => $members]]) === 'valid',
            self::verdict($value, ['enum' => $members]) === 'valid',
        );
    }

    public function testEnumFailureNamesTheRuleAndTheField(): void
    {
        self::assertSame('Validation enum failed at field.', self::verdict('z', ['enum' => ['a', 'b']]));
        self::assertSame('valid', self::verdict('a', ['enum' => ['a', 'b']]));
    }

    public function testConstOnAStringAndOtherRulesStillApply(): void
    {
        self::assertSame('valid', self::verdict('a', ['const' => 'a']));
        self::assertSame('Validation const failed at field.', self::verdict('b', ['const' => 'a']));
        self::assertNotSame('valid', self::verdict('ab', ['enum' => ['ab'], 'maxLength' => 1]));
        self::assertNotSame('valid', self::verdict('ab', ['enum' => ['ab'], 'schema' => ['minLength' => 5]]));
        self::assertSame('valid', self::verdict('ab', ['enum' => ['ab'], 'pattern' => '^a']));
    }

    public function testNonStringValuesKeepJsonEquality(): void
    {
        self::assertSame('valid', self::verdict(1, ['enum' => [1, 2]]));
        self::assertSame('valid', self::verdict(1.0, ['enum' => [1, 2]]), 'JSON numbers compare by value');
        self::assertNotSame('valid', self::verdict('1', ['enum' => [1, 2]]));
        self::assertSame('valid', self::verdict(null, ['enum' => ['a', null]]));
        self::assertNotSame('valid', self::verdict('a', ['const' => 1]));
    }

    public function testBackedEnumsAreCheckedByTheirValue(): void
    {
        self::assertSame('valid', self::verdict(Role::Admin, ['enum' => ['admin', 'member']]));
        self::assertNotSame('valid', self::verdict(Role::Member, ['enum' => ['admin']]));
    }

    public function testEnumAndConstTogetherAreLeftToTheSchemaValidator(): void
    {
        self::assertSame('valid', self::verdict('a', ['enum' => ['a', 'b'], 'const' => 'a']));
        self::assertNotSame('valid', self::verdict('b', ['enum' => ['a', 'b'], 'const' => 'a']));

        $this->expectException(\Throwable::class); // a const outside its own enum is a contradictory schema
        Constraints::validate('c', ['enum' => ['a', 'b'], 'const' => 'c'], 'field');
    }

    public function testItemsAreCheckedOneByOne(): void
    {
        $rules = ['items' => ['type' => 'string', 'rules' => ['enum' => ['a', 'b']]]];
        self::assertSame('valid', self::verdict(['a', 'b', 'a'], $rules));
        self::assertNotSame('valid', self::verdict(['a', 'q'], $rules));
    }
}
