<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

/**
 * How the PSR-18 transport follows 3xx responses. Defaults mirror common client behavior
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
