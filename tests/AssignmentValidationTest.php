<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use PHPUnit\Framework\TestCase;

/** What a `set` hook does, written by hand so it runs on any PHP: check the value, then store it. */
final class HandHookedUser extends DTO
{
    protected const bool VALIDATES_ON_ASSIGNMENT = true;
    protected const array VALIDATION = ['name' => ['minLength' => 3], 'age' => ['minimum' => 18]];
    public const array REQUIRED_FIELDS = ['name'];
    public string $name;
    public ?int $age;

    public function setName(string $value): void { $this->assigning('name', $value); $this->name = $value; }
    public function setAge(?int $value): void { $this->assigning('age', $value); $this->age = $value; }
}

final class AssignmentValidationTest extends TestCase
{
    public function testAValueIsCheckedAgainstItsRulesWhenItIsAssigned(): void
    {
        $user = new HandHookedUser();
        $user->setName('Ada');
        $user->setAge(40);
        self::assertSame('Ada', $user->name);

        $this->expectException(\UnexpectedValueException::class);
        $user->setName('Al');
    }

    public function testANullIsLeftToTheTypeUnlessARuleAppliesToIt(): void
    {
        $user = new HandHookedUser();
        $user->setAge(null);
        self::assertNull($user->age);
    }

    public function testValidateOnDemandChecksEveryRuleEvenOnAModelThatChecksOnAssignment(): void
    {
        // A value stored without going through the hook (an array appended to in place, say) is caught by an
        // explicit validate(), which is the check you ask for when you want everything looked at.
        $user = new HandHookedUser();
        $user->name = 'x';
        $this->expectException(\UnexpectedValueException::class);
        $user->validate();
    }

    public function testRequiredFieldsAreStillCheckedWhenTheModelIsValidated(): void
    {
        $user = new HandHookedUser();
        $this->expectException(\UnexpectedValueException::class);
        $user->validate();
    }

    public function testHydrationOfAHookedModelChecksEachValueOnce(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks need PHP 8.4.');
        }
        eval(<<<'PHP'
        namespace Dmorenof\SdkRuntime\Tests;
        final class RealHookedUser extends \Dmorenof\SdkRuntime\DTO
        {
            public static int $checks = 0;
            protected const bool VALIDATES_ON_ASSIGNMENT = true;
            protected const array VALIDATION = ['name' => ['minLength' => 3]];
            public const array REQUIRED_FIELDS = ['name'];
            public string $name {
                set {
                    self::$checks++;
                    $this->assigning('name', $value);
                    $this->name = $value;
                }
            }
        }
        PHP);
        $class = RealHookedUser::class;
        $user = new $class((object) ['name' => 'Ada']);
        self::assertSame('Ada', $user->name);
        self::assertSame(1, $class::$checks);
        $user->validate();
        self::assertSame(1, $class::$checks, 'validate() must not check the rules again');

        $this->expectException(\UnexpectedValueException::class);
        $user->name = 'Al';
    }
}
