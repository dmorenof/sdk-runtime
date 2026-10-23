<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\Serializer;
use Dmorenof\SdkRuntime\Tests\Fixtures\Role;
use Dmorenof\SdkRuntime\Tests\Fixtures\Scores;
use Dmorenof\SdkRuntime\Tests\Fixtures\Tags;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintsTest extends TestCase
{
    /** @return iterable<string, array{mixed, array<string, mixed>}> */
    public static function violations(): iterable
    {
        yield 'too short' => ['a', ['minLength' => 2]];
        yield 'too long' => ['abcd', ['maxLength' => 3]];
        yield 'below minimum' => [0, ['minimum' => 1]];
        yield 'above maximum' => [11, ['maximum' => 10]];
        yield 'pattern' => ['abc', ['pattern' => '^[0-9]+$']];
        yield 'not in enum' => ['x', ['enum' => ['a', 'b']]];
        yield 'too few items' => [[1], ['minItems' => 2]];
    }

    /** @param array<string, mixed> $rules */
    #[DataProvider('violations')]
    public function testRejectsValuesBreakingARule(mixed $value, array $rules): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Constraints::validate($value, $rules, 'field');
    }

    public function testAcceptsValuesWithinEveryRule(): void
    {
        Constraints::validate('añb', ['minLength' => 3, 'maxLength' => 3, 'pattern' => '^a.b$'], 'field');
        Constraints::validate(5, ['minimum' => 5, 'maximum' => 5], 'field');
        $this->addToAssertionCount(1);
    }

    public function testLengthCountsCodePointsNotBytes(): void
    {
        Constraints::validate('ñññ', ['maxLength' => 3], 'field');
        $this->addToAssertionCount(1);
    }

    public function testUnknownRuleIsAGeneratorBugNotAValidationFailure(): void
    {
        $this->expectException(\LogicException::class);
        Constraints::validate(1, ['nope' => 1], 'field');
    }

    public function testSerializerHandlesNestedCollectionsMapsAndEnums(): void
    {
        $tags = new Tags(['a', 'b']);
        $scores = new Scores((object) ['x' => 1]);

        self::assertSame(['a', 'b'], Serializer::value($tags, 'neutral'));
        self::assertSame(['x' => 1], (array) Serializer::value($scores, 'neutral'));
        self::assertSame(Role::Admin, Serializer::value(Role::Admin, 'neutral'));
    }

    public function testSerializerChoosesDirectionFromTheModelWhenNeutral(): void
    {
        $user = new User((object) ['id' => 7, 'name' => 'Grace']);

        // User is neither a Request nor a Response, so nothing is omitted.
        self::assertSame(['id' => 7, 'name' => 'Grace'], (array) Serializer::value($user, 'neutral'));
        self::assertSame(['name' => 'Grace'], (array) Serializer::value($user, 'request'));
    }
}
