<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

final class SecurityApplier
{
    /**
     * @param list<array<string, list<string>>> $requirements Alternatives are OR; schemes within one entry are AND.
     * @param array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}> $schemes
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public static function apply(
        SecurityCredentials $credentials,
        array $requirements,
        array $schemes,
        array &$headers,
        array &$query,
    ): void {
        if ($requirements === []) {
            return;
        }

        foreach ($requirements as $requirement) {
            if ($requirement === []) {
                return;
            }
            $selected = [];
            $satisfied = true;
            foreach ($requirement as $name => $_scopes) {
                $credential = $credentials->forScheme($name);
                if ($credential === null) {
                    $satisfied = false;
                    break;
                }
                $scheme = $schemes[$name] ?? null;
                if ($scheme === null) {
                    throw new \LogicException("Missing generated security scheme $name.");
                }
                $selected[] = [$name, $scheme, $credential];
            }
            if (!$satisfied) {
                continue;
            }

            $securityHeaders = [];
            $securityQuery = [];
            foreach ($selected as [$name, $scheme, $credential]) {
                if ($scheme['type'] === 'http' && $scheme['scheme'] === 'bearer') {
                    if (!is_string($credential) || $credential === '') {
                        throw new \InvalidArgumentException("Bearer credential $name must be a nonempty string.");
                    }
                    self::addHeader($securityHeaders, 'Authorization', 'Bearer ' . $credential);
                } elseif ($scheme['type'] === 'http' && $scheme['scheme'] === 'basic') {
                    if (!$credential instanceof BasicAuthCredentials) {
                        throw new \InvalidArgumentException("Basic credential $name must be BasicAuthCredentials.");
                    }
                    self::addHeader(
                        $securityHeaders,
                        'Authorization',
                        'Basic ' . base64_encode($credential->username() . ':' . $credential->password()),
                    );
                } elseif ($scheme['type'] === 'apiKey') {
                    if (!is_string($credential) || $credential === '') {
                        throw new \InvalidArgumentException("API key credential $name must be a nonempty string.");
                    }
                    if ($scheme['location'] === 'header') {
                        self::addHeader($securityHeaders, $scheme['parameterName'], $credential);
                    } elseif ($scheme['location'] === 'query') {
                        self::addQuery($securityQuery, $scheme['parameterName'], $credential);
                    } else {
                        throw new \LogicException("Unsupported generated API key location for $name.");
                    }
                } else {
                    throw new \LogicException("Unsupported generated security scheme $name.");
                }
            }
            foreach ($securityHeaders as $name => $value) {
                $headers[$name] = $value;
            }
            foreach ($securityQuery as $name => $value) {
                $query[$name] = $value;
            }
            return;
        }

        throw new \InvalidArgumentException(
            'No configured credentials satisfy this operation security requirement.',
        );
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