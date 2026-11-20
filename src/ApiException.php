<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Security\OAuth2TokenEndpoint;
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

    /**
     * The scopes the API says the token lacks: the `scope` parameter of a `403` whose `WWW-Authenticate` challenge has
     * `error="insufficient_scope"` (RFC 6750 section 3.1). Empty when the answer names none.
     *
     * @return list<string>
     */
    public function missingScopes(): array
    {
        $parameters = $this->challengeParameters();
        return strtolower($parameters['error'] ?? '') === 'insufficient_scope' ? (OAuth2TokenEndpoint::scopes($parameters['scope'] ?? '') ?? []) : [];
    }

    /** Whether the API refused the call because the token lacks a scope (a `403` with `error="insufficient_scope"`, RFC 6750). */
    public function isInsufficientScope(): bool
    {
        return strtolower($this->challengeParameters()['error'] ?? '') === 'insufficient_scope';
    }

    /**
     * The auth-params of the `WWW-Authenticate` challenge of a `403`, by lowercase name; the first of each name wins.
     *
     * @return array<string, string>
     */
    private function challengeParameters(): array
    {
        $challenge = $this->response->status === 403 ? $this->response->header('WWW-Authenticate') : null;
        if ($challenge === null || preg_match_all('/([A-Za-z_][\w-]*)=(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s,]+))/', $challenge, $found, PREG_SET_ORDER) < 1) {
            return [];
        }
        $parameters = [];
        foreach ($found as $match) {
            $parameters[strtolower($match[1])] ??= ($match[3] ?? '') !== '' ? $match[3] : stripslashes($match[2]);
        }
        return $parameters;
    }
}
