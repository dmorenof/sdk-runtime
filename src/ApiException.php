<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Transport\HttpResponse;

/**
 * Typed HTTP API error that preserves the raw response even when error DTO
 * decoding fails. Connection/transport exceptions retain their original type.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly HttpResponse $response,
        public readonly ?DTO $error,
        string $operation,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Unexpected HTTP status from $operation: " . $response->status,
            $response->status,
            $previous,
        );
    }

    /** @param string|null $errorType Name of the DTO subclass describing the error body. */
    public static function fromResponse(HttpResponse $response, string $operation, ?string $errorType): self
    {
        $error = null;
        $previous = null;
        if ($errorType !== null) {
            try {
                if (!is_a($errorType, DTO::class, true)) {
                    throw new \LogicException("Error type $errorType must extend Core\\DTO.");
                }
                $json = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
                if (!is_object($json)) {
                    throw new \UnexpectedValueException("Expected JSON object for error type $errorType.");
                }
                $error = new $errorType($json);
            } catch (\Throwable $exception) {
                // Always expose HTTP response information to the caller.
                $previous = $exception;
            }
        }
        return new self($response, $error, $operation, $previous);
    }
}