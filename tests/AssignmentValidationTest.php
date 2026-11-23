<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use PHPUnit\Framework\TestCase;

/** A model whose rules are checked by `validate()` only: it has no hooks (plain properties). */
final class PlainRuledUser extends DTO
{
    protected const array VALIDATION = ['name' => ['minLength' => 3]];
    public const array REQUIRED_FIELDS = ['name'];
    public string $name;
}

final class AssignmentValidationTest extends TestCase
{
    public function testValidateOnDemandChecksEveryRule(): void
    {
        $user = new PlainRuledUser();
        $user->name = 'x';
        $this->expectException(\UnexpectedValueException::class);
        $user->validate();
    }

    public function testRequiredFieldsAreCheckedWhenTheModelIsValidated(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new PlainRuledUser())->validate();
    }

    public function testAModelWithoutHooksChecksItsRulesWhenBuiltFromJson(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new PlainRuledUser((object) ['name' => 'x']);
    }

    private static function realHookedClass(): string
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks need PHP 8.4.');
        }
        if (!class_exists(RealHookedUser::class, false)) {
            eval(<<<'PHP'
            namespace Dmorenof\SdkRuntime\Tests;
            final class RealHookedUser extends \Dmorenof\SdkRuntime\DTO
            {
                public static int $checks = 0;
                protected const array VALIDATION = ['name' => ['minLength' => 3], 'age' => ['minimum' => 18], 'nick' => ['minLength' => 2]];
                public const array REQUIRED_FIELDS = ['name'];
                public string $name {
                    set {
                        self::$checks++;
                        $this->assigning('name', $value);
                        $this->name = $value;
                    }
                }
                public ?int $age = null;
                public ?string $nick {
                    set {
                        $this->assigning('nick', $value);
                        $this->nick = $value;
                    }
                }
            }
            PHP);
        }
        return RealHookedUser::class;
    }

    public function testAHookedPropertyIsCheckedOnceWhenAssignedAndNotAgainWhenBuilt(): void
    {
        $class = self::realHookedClass();
        $class::$checks = 0;
        $user = new $class((object) ['name' => 'Ada', 'age' => 40]);
        self::assertSame(1, $class::$checks);
        self::assertSame('Ada', $user->name);

        $this->expectException(\UnexpectedValueException::class);
        $user->name = 'Al';
    }

    public function testAnAnswerIsReadWithoutCheckingItsRulesOrItsRequiredFields(): void
    {
        $class = self::realHookedClass();
        $class::$checks = 0;
        // Too short a name, too young an age, and no name at all: the API said so, and the API has the last word.
        $short = DTO::readingResponse(static fn () => new $class((object) ['name' => 'Al', 'age' => 3]));
        $none = DTO::readingResponse(static fn () => new $class((object) ['age' => 40]));
        self::assertSame('Al', $short->name);
        self::assertSame(3, $short->age);
        self::assertFalse(isset($none->name));
        self::assertFalse(DTO::isReadingResponse(), 'Reading an answer ends with the call that reads it.');

        // Assigning, by you, is checked again.
        $this->expectException(\UnexpectedValueException::class);
        $short->name = 'Al';
    }

    public function testAPropertyWithoutAHookIsStillCheckedWhenBuilt(): void
    {
        $class = self::realHookedClass();
        $this->expectException(\UnexpectedValueException::class);
        new $class((object) ['name' => 'Ada', 'age' => 3]);
    }

    public function testAnExplicitValidateChecksHookedPropertiesToo(): void
    {
        $class = self::realHookedClass();
        $user = new $class((object) ['name' => 'Ada']);
        $user->validate();
        self::assertSame('Ada', $user->name);
    }

    public function testAHookedPropertyNeverSetStaysAbsentInsteadOfBecomingNull(): void
    {
        $class = self::realHookedClass();
        $user = new $class((object) ['name' => 'Ada']);
        self::assertSame(['name' => 'Ada', 'age' => null], json_decode(json_encode($user, JSON_THROW_ON_ERROR), true));
        $user->nick = 'Ad';
        self::assertSame('Ad', json_decode(json_encode($user, JSON_THROW_ON_ERROR), true)['nick']);
    }
}
