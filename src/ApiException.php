<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Transport\HttpResponse;

/**
 * An HTTP answer the operation does not declare as success. It always keeps the raw response, even when the
 * error body could not be decoded. Connection and transport failures are not wrapped: they keep their own type.
 */
class ApiException extends \RuntimeException
{
    /** The decoded error when it is a model, else null. */
    public readonly ?DTO $error;

    /**
     * @param string $operation Which operation failed, as "METHOD /path" or its label.
     * @param mixed $payload The decoded error body when the API documents one (a model, list, map or scalar).
     * @param ?\Throwable $previous Why decoding the body failed, if it did.
     */
    public function __construct(
        public readonly HttpResponse $response,
        string $operation,
        public readonly mixed $payload = null,
        ?\Throwable $previous = null,
    ) {
        $this->error = $payload instanceof DTO ? $payload : null;
        parent::__construct("Unexpected HTTP status from $operation: " . $response->status, $response->status, $previous);
    }
}
