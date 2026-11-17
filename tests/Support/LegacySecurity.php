<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Support;

use Dmorenof\SdkRuntime\CookieHeader;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;

/** Test helper: applies security the way the client does, folding the result into headers and query arrays. */
final class LegacySecurity
{
    /**
     * @param list<array<string, list<string>>> $requirements
     * @param array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}> $schemes
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param list<\Dmorenof\SdkRuntime\Security\TokenProviderInterface>|null $providers
     */
    public static function apply(SecurityCredentials $credentials, array $requirements, array $schemes, array &$headers, array &$query, ?array &$providers = null): void
    {
        $applied = SecurityApplier::apply($credentials, $requirements, $schemes);
        $headers = CookieHeader::merge($headers, $applied->headers);
        foreach ($applied->query as $name => $value) {
            $query[$name] = $value;
        }
        $providers = $applied->providers;
    }
}
