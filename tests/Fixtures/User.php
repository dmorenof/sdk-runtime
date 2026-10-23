<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\DTO;

final class User extends DTO
{
    public const array REQUIRED_FIELDS = ['name'];
    public const array READ_ONLY_FIELDS = ['id'];
    protected const array VALIDATION = ['age' => ['minimum' => 0]];

    public int $id;
    public string $name;
    public int $age;
    public Role $role;
    public Address $address;
    public Tags $tags;
}
