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
    /** Decoded declared error payload; the legacy error property remains DTO-only. */
    public readonly mixed $payload;
    public function __construct(
        public readonly HttpResponse $response,
        public readonly ?DTO $error,
        string $operation,
        ?\Throwable $previous = null,
        mixed $payload = null,
    ) {
        $this->payload = $payload ?? $error;
        parent::__construct(
            "Unexpected HTTP status from $operation: " . $response->status,
            $response->status,
            $previous,
        );
    }

    /** @param string|null $errorType Legacy DTO error class.
     * @param array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string,encoding?:'json'|'text'|'binary'}|null $errorResponse Declared general error response.
     */
    public static function fromResponse(HttpResponse $response, string $operation, ?string $errorType, ?array $errorResponse = null): self
    {
        $error = null;
        $previous = null;
        if ($errorResponse !== null) {
            $payload = null;
            if ($errorResponse['type'] !== null) {
                try {
                    $media = $errorResponse['mediaType'] ?? 'application/json';
                    $encoding = $errorResponse['encoding'] ?? (in_array($media, ['text/plain', 'application/octet-stream'], true) ? 'text' : 'json');
                    $payload = match ($encoding) {
                        'text', 'binary' => Hydrator::hydrate($response->body, $errorResponse['type'], false, $operation . ' error'),
                        'json' => JsonHydrator::decode($response->body, $errorResponse['type'], $errorResponse['schemaNullable']),
                    };
                    DTO::validateNestedValue($payload, 'response');
                    Constraints::validate($payload, $errorResponse['rules'] ?? [], $operation . ' error', 'response');
                    $error = $payload instanceof DTO ? $payload : null;
                } catch (\Throwable $exception) {
                    $previous = $exception;
                    $payload = null;
                }
            }
            return new self($response, $error, $operation, $previous, $payload);
        }
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
                $error->validateForResponse();
            } catch (\Throwable $exception) {
                // Always expose HTTP response information to the caller.
                $previous = $exception;
            }
        }
        return new self($response, $error, $operation, $previous);
    }
}