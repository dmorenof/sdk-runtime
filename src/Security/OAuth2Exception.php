<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** An OAuth2 exchange failed. Messages carry error codes only, never secrets, tokens or server text. */
class OAuth2Exception extends \RuntimeException
{
    /** @param int $status HTTP status of the token endpoint's answer, or 0 when no answer is to blame. */
    public function __construct(string $message, int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /**
     * The server refused the grant or the client (HTTP 400/401, RFC 6749 section 5.2): sending the same
     * request again cannot succeed. Anything else (a 5xx, a malformed answer) may be transient.
     */
    public function isRejection(): bool
    {
        return in_array($this->getCode(), [400, 401], true);
    }
}
