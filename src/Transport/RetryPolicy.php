<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Psr\Http\Message\ResponseInterface;

/**
 * Opt-in retry rules for transient failures. By default only idempotent methods are replayed;
 * add POST/PATCH to $methods only when the API makes them safe to repeat.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts Total attempts including the first one.
     * @param list<int> $statuses HTTP statuses worth retrying.
     * @param list<string> $methods Upper-case methods that may be replayed.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public array $statuses = [429, 502, 503, 504],
        public array $methods = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'],
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 10_000,
        public bool $retryNetworkErrors = true,
    ) {
        if ($maxAttempts < 1 || $baseDelayMs < 0 || $maxDelayMs < $baseDelayMs) {
            throw new \InvalidArgumentException('Invalid retry policy limits.');
        }
    }

    public function allows(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    public function retriesStatus(int $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    /**
     * Milliseconds to wait before retry number $retry (1 = first retry). Honors Retry-After
     * (seconds or HTTP date) up to maxDelayMs; otherwise exponential backoff with equal jitter.
     */
    public function delayMs(int $retry, ?ResponseInterface $response = null, ?int $now = null): int
    {
        $header = $response?->getHeaderLine('Retry-After') ?? '';
        if ($header !== '') {
            if (ctype_digit($header)) {
                return min($this->maxDelayMs, (int) $header * 1000);
            }
            $at = strtotime($header);
            if ($at !== false) {
                return min($this->maxDelayMs, max(0, ($at - ($now ?? time())) * 1000));
            }
        }
        $ceiling = (int) min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($retry - 1)));
        $half = intdiv($ceiling, 2);
        return $half + ($ceiling - $half > 0 ? random_int(0, $ceiling - $half) : 0);
    }
}
