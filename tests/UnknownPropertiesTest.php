<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\JsonHydrator;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use PHPUnit\Framework\TestCase;

/** An API that adds a field must not break clients generated before it; a model built by hand stays strict. */
final class UnknownPropertiesTest extends TestCase
{
    public function testAResponseWithUnknownFieldsIsReadAndTheFieldsAreIgnored(): void
    {
        $user = JsonHydrator::decode('{"id":7,"name":"Ada","added_later":true,"address":{"city":"Madrid","zip_plus":"x"}}', User::class);

        self::assertInstanceOf(User::class, $user);
        self::assertSame('Ada', $user->name);
        self::assertSame('Madrid', $user->address->city);
    }

    public function testAModelBuiltFromJsonByHandStillRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown JSON property');
        new User((object) ['id' => 7, 'name' => 'Ada', 'added_later' => true]);
    }

    public function testToleranceDoesNotLeakOutOfTheResponseScope(): void
    {
        JsonHydrator::decode('{"id":7,"name":"Ada","extra":1}', User::class);

        $this->expectException(\InvalidArgumentException::class);
        new User((object) ['id' => 7, 'name' => 'Ada', 'extra' => 1]);
    }

    public function testToleranceIsReleasedWhenReadingFails(): void
    {
        try {
            JsonHydrator::decode('{"id":"not a number","name":"Ada"}', User::class);
            self::fail('Expected a type failure.');
        } catch (\UnexpectedValueException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        new User((object) ['id' => 7, 'name' => 'Ada', 'extra' => 1]);
    }
}
