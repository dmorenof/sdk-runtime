<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/** Base response behavior; vendor-specific envelopes are NOT universal fields. */
class Response extends DTO
{
    public function validate(): void
    {
        $this->validateForResponse();
    }

    public function jsonSerialize(): object
    {
        return $this->jsonSerializeForResponse();
    }
}