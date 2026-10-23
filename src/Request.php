<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Base request behavior. API-specific request fields are generated separately. */
class Request extends DTO
{
    public function validate(): void
    {
        $this->validateForRequest();
    }

    public function jsonSerialize(): object
    {
        return $this->jsonSerializeForRequest();
    }
}