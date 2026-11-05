<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\DTO;

final class DirectionalEnvelope extends DTO
{
    public const array REQUIRED_FIELDS = ['result', 'user'];

    public bool $result;
    public DirectionalUser $user;
}
