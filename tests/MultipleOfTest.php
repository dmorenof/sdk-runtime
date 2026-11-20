<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `multipleOf` on floats: a real API (Frankfurter) sends 392.4 where the contract says multipleOf 0.00001. */
final class MultipleOfTest extends TestCase
{
    /** @return iterable<string, array{mixed, int|float, bool}> */
    public static function cases(): iterable
    {
        yield 'a rate with the rounding error of a double' => [392.4, 1.0E-5, true];
        yield 'a rate with five decimals' => [1.0842, 1.0E-5, true];
        yield 'a large value on a fine grid' => [1000000.12345, 1.0E-5, true];
        yield 'a tenth the way floats see it' => [0.3, 0.1, true];
        yield 'a negative multiple' => [-0.3, 0.1, true];
        yield 'zero' => [0, 0.1, true];
        yield 'integers that divide' => [8, 2, true];
        yield 'halves' => [7.5, 0.5, true];
        yield 'not a multiple of a tenth' => [0.31, 0.1, false];
        yield 'one step past the grid' => [1.084201, 1.0E-5, false];
        yield 'a hundred-thousandth off' => [100.123456, 1.0E-5, false];
        yield 'integers that do not divide' => [7, 2, false];
        yield 'not a multiple of a half' => [7.3, 0.5, false];
        yield 'a string is not constrained by it' => ['x', 0.5, true];
    }

    #[DataProvider('cases')]
    public function testAFloatIsComparedWithItsOwnRoundingError(mixed $value, int|float $divisor, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('Validation multipleOf failed at here');
        }
        Constraints::validate($value, ['multipleOf' => $divisor], 'here');
        self::assertTrue($valid);
    }

    public function testADivisorThatIsNotPositiveIsAContractError(): void
    {
        $this->expectException(\LogicException::class);
        Constraints::validate(1, ['multipleOf' => 0], 'here');
    }
}
