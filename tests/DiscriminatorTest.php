<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Hydrator;
use Dmorenof\SdkRuntime\OneOf;
use Dmorenof\SdkRuntime\SchemaValidator;
use PHPUnit\Framework\TestCase;

class DiscriminatorPet extends DTO
{
    public const string DISCRIMINATOR_BASE = self::class;
    public const string DISCRIMINATOR_PROPERTY = 'pet_type';
    public const array DISCRIMINATOR_MAPPING = ['canine' => DiscriminatorDog::class, 'feline' => DiscriminatorCat::class];
    public const array REQUIRED_FIELDS = ['pet_type'];
    public string $pet_type;
}

class DiscriminatorDog extends DiscriminatorPet
{
    protected const array VALIDATION = ['pet_type' => ['enum' => ['canine']]];
    public bool $bark;
}

class DiscriminatorCat extends DiscriminatorPet
{
    protected const array VALIDATION = ['pet_type' => ['enum' => ['feline']]];
    public bool $hunts;
}

class DiscriminatorChoice extends OneOf
{
    protected const array TYPES = [DiscriminatorDog::class, DiscriminatorCat::class];
    protected const array BRANCH_SCHEMAS = [
        ['kind' => 'object', 'properties' => ['pet_type' => ['kind' => 'string', 'enum' => ['canine']]], 'required' => ['pet_type']],
        ['kind' => 'object', 'properties' => ['pet_type' => ['kind' => 'string', 'enum' => ['feline']]], 'required' => ['pet_type']],
    ];
    protected const string DISCRIMINATOR_PROPERTY = 'pet_type';
    protected const array DISCRIMINATOR_MAPPING = DiscriminatorPet::DISCRIMINATOR_MAPPING;
}

class AmbiguousDiscriminatorChoice extends OneOf
{
    protected const array TYPES = [DiscriminatorDog::class, DiscriminatorCat::class];
    protected const array BRANCH_SCHEMAS = [['kind' => 'object'], ['kind' => 'object']];
    protected const string DISCRIMINATOR_PROPERTY = 'pet_type';
    protected const array DISCRIMINATOR_MAPPING = DiscriminatorPet::DISCRIMINATOR_MAPPING;
}

final class DiscriminatorTest extends TestCase
{
    public function testBaseHydrationAndFactorySelectSubclass(): void
    {
        $pet = Hydrator::hydrate((object) ['pet_type' => 'canine', 'bark' => true], DiscriminatorPet::class, false, 'pet');
        self::assertInstanceOf(DiscriminatorDog::class, $pet);
        self::assertInstanceOf(DiscriminatorCat::class, DiscriminatorPet::fromValue((object) ['pet_type' => 'feline']));
        self::assertInstanceOf(DiscriminatorDog::class, DiscriminatorDog::fromValue((object) ['pet_type' => 'canine']));
    }

    public function testMissingTagFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing discriminator');
        DiscriminatorPet::fromValue((object) []);
    }

    public function testUnknownTagFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown discriminator');
        DiscriminatorPet::fromValue((object) ['pet_type' => 'bird']);
    }

    public function testDiscriminatorCannotHideAmbiguity(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('found 2');
        AmbiguousDiscriminatorChoice::fromValue((object) ['pet_type' => 'canine']);
    }

    public function testSnapshotIsIndependentAndSelectedMutationsAreSerialized(): void
    {
        $json = (object) ['pet_type' => 'canine', 'bark' => true];
        $choice = DiscriminatorChoice::fromValue($json);
        $json->pet_type = 'feline';
        $dog = $choice->matchedVariants()[0];
        self::assertInstanceOf(DiscriminatorDog::class, $dog);
        $dog->bark = false;
        self::assertSame('{"pet_type":"canine","bark":false}', json_encode($choice, JSON_THROW_ON_ERROR));
        $dog->pet_type = 'feline';
        $this->expectException(\UnexpectedValueException::class);
        $choice->jsonSerialize();
    }

    public function testSchemaMatchingDoesNotDispatchAndPreservesAdditionalProperties(): void
    {
        $definitions = ['Pet' => ['kind' => 'object', 'properties' => ['pet_type' => ['kind' => 'string']], 'required' => ['pet_type']]];
        $schema = ['kind' => 'allOf', 'branches' => [
            ['kind' => 'reference', 'ref' => 'Pet'],
            ['kind' => 'object', 'properties' => ['pet_type' => ['kind' => 'string', 'enum' => ['canine']]]],
        ]];
        self::assertTrue(SchemaValidator::matches((object) ['pet_type' => 'canine', 'extra' => 1], $schema, $definitions));
        self::assertFalse(SchemaValidator::matches((object) ['pet_type' => 'feline'], $schema, $definitions));
        self::assertFalse(SchemaValidator::matches((object) [], $schema, $definitions));
        self::assertFalse(SchemaValidator::matches((object) ['extra' => 1], ['kind' => 'object', 'additional' => false]));
    }
}
