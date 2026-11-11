<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Psr\Http\Message\RequestInterface;

/**
 * How the PSR-18 transport follows 3xx responses when a policy is set; without one redirects are not followed.
 * The defaults of this class mirror common client behavior
 * (up to 5 hops, 301/302/303 turn POST into GET, 307/308 keep method and body) with
 * safeguards: no https to http downgrade, and only a safe header allowlist crosses origins
 * so credentials never leak to another host.
 */
final readonly class RedirectPolicy
{
    /**
     * @param int $maxRedirects 0 returns the 3xx response untouched.
     * @param list<int> $statuses Statuses that are followed when a Location header is present.
     * @param bool $allowDowngrade Permit https to http redirects.
     * @param bool $followCrossOrigin When false a redirect to another origin returns the 3xx response.
     * @param list<string> $crossOriginHeaders Request headers still sent to a different origin; all others are dropped.
     */
    public function __construct(
        public int $maxRedirects = 5,
        public array $statuses = [301, 302, 303, 307, 308],
        public bool $allowDowngrade = false,
        public bool $followCrossOrigin = true,
        public array $crossOriginHeaders = ['Accept', 'Accept-Encoding', 'Accept-Language', 'User-Agent', 'Content-Type'],
    ) {
        if ($maxRedirects < 0) {
            throw new \InvalidArgumentException('maxRedirects cannot be negative.');
        }
    }

    /** Never follow: the caller sees the 3xx response (and its Location header). */
    public static function none(): self
    {
        return new self(0);
    }

    public function follows(int $status): bool
    {
        return $this->maxRedirects > 0 && in_array($status, $this->statuses, true);
    }

    /**
     * Method of the follow-up request: 307/308 keep it, 301/302 turn POST into GET and 303 turns anything
     * but GET/HEAD into GET.
     */
    public function methodAfter(string $method, int $status): string
    {
        $upper = strtoupper($method);
        if ($upper === 'GET' || $upper === 'HEAD' || in_array($status, [307, 308], true)) {
            return $method;
        }
        return $status === 303 || ($upper === 'POST' && in_array($status, [301, 302], true)) ? 'GET' : $method;
    }

    /** The request without any header that must not reach another origin (everything but Host and the allowlist). */
    public function withoutCredentials(RequestInterface $request): RequestInterface
    {
        foreach (array_keys($request->getHeaders()) as $name) {
            if (strcasecmp((string) $name, 'Host') !== 0 && !$this->forwardsAcrossOrigins((string) $name)) {
                $request = $request->withoutHeader((string) $name);
            }
        }
        return $request;
    }

    public function forwardsAcrossOrigins(string $header): bool
    {
        foreach ($this->crossOriginHeaders as $allowed) {
            if (strcasecmp($allowed, $header) === 0) {
                return true;
            }
        }
        return false;
    }
}
