<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\DTO;

/** A reusable model, both a request body and part of a response. */
final class DirectionalUser extends DTO
{
    public const array REQUIRED_FIELDS = ['id', 'name', 'password'];
    protected const array READ_ONLY_FIELDS = ['id'];
    protected const array WRITE_ONLY_FIELDS = ['password'];

    public int $id;
    public string $name;
    public string $password;
}
