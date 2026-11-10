<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests\Fixtures;
class ErrorPayloadHttpChoice extends \Dmorenof\SdkRuntime\OneOf {
    protected const array TYPES = ['int', 'string'];
    protected const array BRANCH_SCHEMAS = [['kind' => 'integer', 'rules' => ['minimum' => 0]], ['kind' => 'string', 'rules' => ['minLength' => 2]]];
}
