<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Tests\Fixtures\Role;
use Dmorenof\SdkRuntime\Tests\Fixtures\Scores;
use Dmorenof\SdkRuntime\Tests\Fixtures\StringOrAddress;
use Dmorenof\SdkRuntime\Tests\Fixtures\User;
use Dmorenof\SdkRuntime\Tests\Fixtures\Users;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function testHydratesNestedEnumAndCollectionValues(): void
    {
        $user = new User((object) [
            'id' => 7,
            'name' => 'Grace',
            'role' => 'admin',
            'address' => (object) ['city' => 'Madrid'],
            'tags' => ['a', 'b'],
        ]);

        self::assertSame(Role::Admin, $user->role);
        self::assertSame('Madrid', $user->address->city);
        self::assertSame(['a', 'b'], $user->tags->getArrayCopy());
    }

    public function testRejectsUnknownProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User((object) ['name' => 'Grace', 'nope' => 1]);
    }

    public function testRejectsMissingRequiredField(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new User((object) ['age' => 3]);
    }

    public function testRejectsWrongScalarType(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new User((object) ['name' => 'Grace', 'age' => 'old']);
    }

    public function testValidatesConstraintsOnSerialization(): void
    {
        $user = new User((object) ['name' => 'Grace']);
        $user->age = -1;

        $this->expectException(\UnexpectedValueException::class);
        json_encode($user, JSON_THROW_ON_ERROR);
    }

    public function testRequestSerializationOmitsReadOnlyFields(): void
    {
        $user = new User((object) ['id' => 7, 'name' => 'Grace', 'role' => 'member']);

        self::assertSame('{"name":"Grace","role":"member"}', json_encode($user->jsonSerializeForRequest()));
        self::assertStringContainsString('"id":7', json_encode($user->jsonSerializeForResponse()));
    }

    public function testCollectionsOfModelsHydrate(): void
    {
        $users = DTO::hydrateValue([(object) ['name' => 'A'], (object) ['name' => 'B']], Users::class, false, 'test');

        self::assertCount(2, $users);
        self::assertSame('B', $users[1]->name);
    }

    public function testMapValidatesValueTypes(): void
    {
        $scores = new Scores((object) ['a' => 1, 'b' => 2]);
        self::assertCount(2, $scores);
        self::assertSame('{"a":1,"b":2}', json_encode($scores));

        $this->expectException(\UnexpectedValueException::class);
        new Scores((object) ['a' => 'x']);
    }

    public function testOneOfRequiresExactlyOneMatch(): void
    {
        self::assertSame('plain', StringOrAddress::fromValue('plain')->matchedVariants()[0]);
        self::assertSame('Madrid', StringOrAddress::fromValue((object) ['city' => 'Madrid'])->matchedVariants()[0]->city);

        $this->expectException(\UnexpectedValueException::class);
        StringOrAddress::fromValue(12);
    }
}
