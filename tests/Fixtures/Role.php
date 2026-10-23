<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}
