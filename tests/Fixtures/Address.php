<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\DTO;

final class Address extends DTO
{
    public const array REQUIRED_FIELDS = ['city'];
    protected const array VALIDATION = ['city' => ['minLength' => 2]];

    public string $city;
}
