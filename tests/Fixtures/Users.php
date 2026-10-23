<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\TypedArray;

final class Users extends TypedArray
{
    protected string $expected_type = User::class;
}
