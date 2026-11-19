<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use PHPUnit\Framework\TestCase;

final class CountingValidation
{
    /** @var array<string, int> */
    public static array $calls = [];
}

trait CountsValidation
{
    public function validate(): void
    {
        CountingValidation::$calls[static::class] = (CountingValidation::$calls[static::class] ?? 0) + 1;
        parent::validate();
    }
}

final class CountedLeaf extends DTO { use CountsValidation; public const array REQUIRED_FIELDS = ['n']; public string $n; }
final class CountedMid extends DTO { use CountsValidation; public const array REQUIRED_FIELDS = ['leaf']; public CountedLeaf $leaf; }
final class CountedTop extends DTO { use CountsValidation; public const array REQUIRED_FIELDS = ['mid']; public CountedMid $mid; }

/** A tree built from JSON is validated once per level, however deep it is. */
final class ConstructionValidationTest extends TestCase
{
    protected function setUp(): void
    {
        CountingValidation::$calls = [];
    }

    public function testEveryLevelIsValidatedOnceWhenATreeIsBuiltFromJson(): void
    {
        new CountedTop((object) ['mid' => (object) ['leaf' => (object) ['n' => 'x']]]);
        self::assertSame([CountedTop::class => 1, CountedMid::class => 1, CountedLeaf::class => 1], CountingValidation::$calls);
    }

    public function testTheFromValueRouteDoesTheSame(): void
    {
        CountedTop::fromValue((object) ['mid' => (object) ['leaf' => (object) ['n' => 'x']]]);
        self::assertSame([CountedTop::class => 1, CountedMid::class => 1, CountedLeaf::class => 1], CountingValidation::$calls);
    }

    public function testABrokenLeafStillFailsTheWholeConstruction(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new CountedTop((object) ['mid' => (object) ['leaf' => (object) []]]);
    }

    public function testAFailedConstructionDoesNotLeaveLaterOnesUnvalidated(): void
    {
        try {
            new CountedTop((object) ['mid' => (object) ['leaf' => (object) []]]);
        } catch (\UnexpectedValueException) {
        }
        $this->expectException(\UnexpectedValueException::class);
        new CountedLeaf((object) []); // validation is back on after the failure
    }
}
