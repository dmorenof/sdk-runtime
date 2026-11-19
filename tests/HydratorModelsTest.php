<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Hydrator;
use PHPUnit\Framework\TestCase;

final class ModelsLine extends DTO
{
    public string $sku;
}

final class HydratorModelsTest extends TestCase
{
    public function testAModelIsBuiltFromEachJsonObjectInOrder(): void
    {
        $models = Hydrator::models([(object) ['sku' => 'a'], (object) ['sku' => 'b']], ModelsLine::class);
        self::assertSame(['a', 'b'], array_map(static fn (ModelsLine $line): string => $line->sku, $models));
    }

    public function testAnItemThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Hydrator::models([(object) ['sku' => 'a'], 'x'], ModelsLine::class);
    }
}
