<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use PHPUnit\Framework\TestCase;

enum ShortcutKind: string { case A = 'a'; }

final class ShortcutLeaf extends DTO
{
    public const array REQUIRED_FIELDS = ['name'];
    public string $name;
}

final class ShortcutPlain extends DTO
{
    public string $text;
    public int $count;
    public ?bool $flag;
    public ?float $ratio;
    public ShortcutKind $kind;
}

final class ShortcutParent extends DTO
{
    public ShortcutLeaf $leaf;
}

final class ShortcutUnion extends DTO
{
    public ShortcutLeaf|string $either;
}

final class ShortcutUntyped extends DTO
{
    public mixed $anything;
}

final class ShortcutExtra extends DTO
{
    protected const string ADDITIONAL_PROPERTIES_TYPE = ShortcutLeaf::class;
    protected const bool ADDITIONAL_PROPERTIES_NULLABLE = false;
    public string $id;
}

/** Skipping what cannot fail must never skip what can. */
final class ValidationShortcutsTest extends TestCase
{
    public function testAModelWithNothingToCheckHydratesAndValidates(): void
    {
        $model = new ShortcutPlain((object) ['text' => 'x', 'count' => 3, 'flag' => null, 'ratio' => 2, 'kind' => 'a']);
        $model->validate();
        self::assertSame(3, $model->count);
        self::assertNull($model->flag);
        self::assertSame(2.0, $model->ratio);
        self::assertSame(ShortcutKind::A, $model->kind);
    }

    public function testScalarsOfTheWrongTypeAreStillRefused(): void
    {
        foreach ([['count' => '3'], ['text' => 5], ['flag' => 'yes'], ['ratio' => 'x']] as $fields) {
            try {
                new ShortcutPlain((object) $fields);
                self::fail('A scalar of the wrong type was accepted: ' . json_encode($fields));
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
        $whole = new ShortcutPlain((object) ['count' => 4.0]);
        self::assertSame(4, $whole->count); // an integral float is an int
    }

    public function testANestedModelIsValidatedEvenWhenItsParentChecksNothing(): void
    {
        $parent = new ShortcutParent();
        $parent->leaf = new ShortcutLeaf();
        $this->expectException(\UnexpectedValueException::class);
        $parent->validate();
    }

    public function testAUnionOrUntypedPropertyThatHoldsAModelIsValidated(): void
    {
        $union = new ShortcutUnion();
        $union->either = new ShortcutLeaf();
        $untyped = new ShortcutUntyped();
        $untyped->anything = new ShortcutLeaf();
        foreach ([$union, $untyped] as $model) {
            try {
                $model->validate();
                self::fail('A nested model that breaks its rules was not found.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAdditionalPropertiesThatAreModelsAreValidated(): void
    {
        $model = new ShortcutExtra((object) ['id' => '1', 'a' => (object) ['name' => 'ok']]);
        $model->validate();
        $this->expectException(\UnexpectedValueException::class);
        new ShortcutExtra((object) ['id' => '1', 'a' => (object) []]);
    }
}
