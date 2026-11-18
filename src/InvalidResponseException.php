<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Transport\HttpResponse;

/**
 * The API answered with a success status but a body that does not match what the operation declares: not JSON,
 * a value of the wrong type, a missing required field, a broken rule. Unlike an {@see ApiException} the call
 * itself worked; the raw response is kept so the cause can be inspected, and `getPrevious()` holds the original
 * failure (a `JsonException`, `UnexpectedValueException`, `ValueError`…).
 */
final class InvalidResponseException extends \UnexpectedValueException
{
    public function __construct(
        public readonly HttpResponse $response,
        string $operation,
        \Throwable $cause,
    ) {
        parent::__construct("Invalid response from $operation: " . $cause->getMessage(), $response->status, $cause);
    }
}
