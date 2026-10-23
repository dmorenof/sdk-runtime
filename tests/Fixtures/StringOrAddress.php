<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\OneOf;

final class StringOrAddress extends OneOf
{
    protected const array TYPES = ['string', Address::class];
}
