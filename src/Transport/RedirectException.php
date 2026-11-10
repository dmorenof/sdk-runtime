<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Psr\Http\Message\ResponseInterface;

/** A redirect that the policy refuses (too many hops, downgrade, unsafe target). Carries the 3xx response. */
final class RedirectException extends \RuntimeException
{
    public function __construct(string $message, public readonly ResponseInterface $response)
    {
        parent::__construct($message, $response->getStatusCode());
    }
}
