<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/** @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md). */
final class SecurityApplier
{
    /**
     * Applies the first requirement the configured credentials can satisfy. An empty requirement means the
     * operation may be called without credentials.
     *
     * @param list<array<string, list<string>>> $requirements Alternatives are OR; schemes within one entry are AND.
     * @param array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}> $schemes
     */
    public static function apply(SecurityCredentials $credentials, array $requirements, array $schemes): AppliedSecurity
    {
        foreach ($requirements as $requirement) {
            if ($requirement === []) {
                return new AppliedSecurity();
            }
            $selected = self::satisfy($credentials, $requirement, $schemes);
            if ($selected !== null) {
                return self::build($selected);
            }
        }
        if ($requirements === []) {
            return new AppliedSecurity();
        }
        throw new \InvalidArgumentException('No configured credentials satisfy this operation security requirement.');
    }

    /**
     * Each scheme of a requirement with its definition and credential, or null when one has no credential.
     *
     * @param array<string, list<string>> $requirement
     * @param array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}> $schemes
     * @return list<array{string, array{type:string,scheme:?string,location:?string,parameterName:?string}, string|BasicAuthCredentials|TokenProviderInterface|ClientCertificate}>|null
     */
    private static function satisfy(SecurityCredentials $credentials, array $requirement, array $schemes): ?array
    {
        $selected = [];
        foreach ($requirement as $name => $_scopes) {
            $credential = $credentials->forScheme($name);
            if ($credential === null) {
                return null;
            }
            $scheme = $schemes[$name] ?? throw new \LogicException("Missing generated security scheme $name.");
            $selected[] = [$name, $scheme, $credential];
        }
        return $selected;
    }

    /**
     * @param list<array{string, array{type:string,scheme:?string,location:?string,parameterName:?string}, string|BasicAuthCredentials|TokenProviderInterface|ClientCertificate}> $selected
     */
    private static function build(array $selected): AppliedSecurity
    {
        $headers = [];
        $query = [];
        $providers = [];
        $certificate = null;
        foreach ($selected as [$name, $scheme, $credential]) {
            if ($scheme['type'] === 'mutualTLS') {
                if (!$credential instanceof ClientCertificate) {
                    throw new \InvalidArgumentException("Mutual TLS credential $name must be a ClientCertificate.");
                }
                if ($certificate !== null && $certificate !== $credential) {
                    throw new \InvalidArgumentException("Conflicting client certificates for mutual TLS scheme $name.");
                }
                $certificate = $credential;
            } elseif (($scheme['type'] === 'http' && $scheme['scheme'] === 'bearer') || in_array($scheme['type'], ['oauth2', 'openIdConnect'], true)) {
                if ($credential instanceof TokenProviderInterface) {
                    $providers[] = $credential;
                    $credential = $credential->accessToken();
                }
                if (!is_string($credential) || $credential === '') {
                    throw new \InvalidArgumentException("Bearer credential $name must be a nonempty string or a token provider.");
                }
                self::addHeader($headers, 'Authorization', 'Bearer ' . $credential);
            } elseif ($scheme['type'] === 'http' && $scheme['scheme'] === 'basic') {
                if (!$credential instanceof BasicAuthCredentials) {
                    throw new \InvalidArgumentException("Basic credential $name must be BasicAuthCredentials.");
                }
                self::addHeader($headers, 'Authorization', 'Basic ' . base64_encode($credential->username() . ':' . $credential->password()));
            } elseif ($scheme['type'] === 'apiKey') {
                self::applyApiKey($name, $scheme, $credential, $headers, $query);
            } else {
                throw new \LogicException("Unsupported generated security scheme $name.");
            }
        }
        return new AppliedSecurity($headers, $query, $providers, $certificate);
    }

    /**
     * @param array{type:string,scheme:?string,location:?string,parameterName:?string} $scheme
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private static function applyApiKey(string $name, array $scheme, string|BasicAuthCredentials|TokenProviderInterface|ClientCertificate $credential, array &$headers, array &$query): void
    {
        if (!is_string($credential) || $credential === '') {
            throw new \InvalidArgumentException("API key credential $name must be a nonempty string.");
        }
        $parameterName = $scheme['parameterName'] ?? throw new \LogicException("API key scheme $name declares no parameter name.");
        match ($scheme['location']) {
            'header' => self::addHeader($headers, $parameterName, $credential),
            'cookie' => $headers = \Dmorenof\SdkRuntime\CookieHeader::merge($headers, ['Cookie' => \Dmorenof\SdkRuntime\CookieHeader::pair($parameterName, $credential)]),
            'query' => self::addQuery($query, $parameterName, $credential),
            default => throw new \LogicException("Unsupported generated API key location for $name."),
        };
    }

    /** @param array<string, string> $headers */
    private static function addHeader(array &$headers, string $name, string $value): void
    {
        foreach ($headers as $existingName => $existingValue) {
            if (strcasecmp($existingName, $name) === 0) {
                if ($existingValue !== $value) {
                    throw new \InvalidArgumentException("Conflicting security values for header $name.");
                }
                return;
            }
        }
        $headers[$name] = $value;
    }

    /** @param array<string, string> $query */
    private static function addQuery(array &$query, string $name, string $value): void
    {
        if (array_key_exists($name, $query) && $query[$name] !== $value) {
            throw new \InvalidArgumentException("Conflicting security values for query parameter $name.");
        }
        $query[$name] = $value;
    }
}