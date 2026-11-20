<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * Checks a JWS signature (RFC 7515) with a public key given as a JWK (RFC 7517), using OpenSSL: RS256/384/512
 * (RFC 7518 section 3.3) and ES256/384/512 (section 3.4). Nothing else is accepted, so there is no `none` and no
 * shared-secret algorithm to be tricked into.
 *
 * @internal Implementation detail of IdTokenValidator: not covered by the backward-compatibility promise.
 */
final class JwsAlgorithm
{
    private const int MIN_RSA_BITS = 2048;

    /** Per algorithm: key type, hash, and for ECDSA the curve, its coordinate size and its DER object identifier. */
    private const array ALGORITHMS = [
        'RS256' => ['kty' => 'RSA', 'hash' => 'sha256'],
        'RS384' => ['kty' => 'RSA', 'hash' => 'sha384'],
        'RS512' => ['kty' => 'RSA', 'hash' => 'sha512'],
        'ES256' => ['kty' => 'EC', 'hash' => 'sha256', 'crv' => 'P-256', 'size' => 32, 'oid' => "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"],
        'ES384' => ['kty' => 'EC', 'hash' => 'sha384', 'crv' => 'P-384', 'size' => 48, 'oid' => "\x06\x05\x2B\x81\x04\x00\x22"],
        'ES512' => ['kty' => 'EC', 'hash' => 'sha512', 'crv' => 'P-521', 'size' => 66, 'oid' => "\x06\x05\x2B\x81\x04\x00\x23"],
    ];

    /** The hash an algorithm uses, e.g. `sha256` for `ES256`; null when the algorithm is not supported. */
    public static function hash(string $algorithm): ?string
    {
        return self::ALGORITHMS[$algorithm]['hash'] ?? null;
    }

    /**
     * Whether this key can be used for signatures made with this algorithm (`kty`, `crv`, `use`, `alg`).
     *
     * @param array<mixed> $jwk
     */
    public static function suits(string $algorithm, array $jwk): bool
    {
        $spec = self::ALGORITHMS[$algorithm] ?? null;
        return $spec !== null
            && ($jwk['kty'] ?? null) === $spec['kty']
            && (!isset($spec['crv']) || ($jwk['crv'] ?? null) === $spec['crv'])
            && (!isset($jwk['use']) || $jwk['use'] === 'sig')
            && (!isset($jwk['alg']) || $jwk['alg'] === $algorithm);
    }

    /** @param array<mixed> $jwk A key that {@see suits()} the algorithm. */
    public static function verify(string $algorithm, array $jwk, string $signingInput, string $signature): bool
    {
        $spec = self::ALGORITHMS[$algorithm] ?? null;
        if ($spec === null) {
            return false;
        }
        if ($spec['kty'] === 'RSA') {
            $pem = self::rsaKey($jwk);
        } else {
            $pem = self::ecKey($jwk, $spec['size'], $spec['oid']);
            $signature = self::derSignature($signature, $spec['size']);
        }
        if ($pem === null || $signature === null) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }
        return openssl_verify($signingInput, $signature, $key, $spec['hash']) === 1;
    }

    /** @param array<mixed> $jwk */
    private static function rsaKey(array $jwk): ?string
    {
        $n = self::b64($jwk['n'] ?? null);
        $e = self::b64($jwk['e'] ?? null);
        if ($n === null || $e === null || strlen(ltrim($n, "\0")) * 8 < self::MIN_RSA_BITS) {
            return null;
        }
        $algorithm = self::der(0x30, "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $key = self::der(0x30, self::integer($n) . self::integer($e));
        return self::pem(self::der(0x30, $algorithm . self::der(0x03, "\0" . $key)));
    }

    /** @param array<mixed> $jwk */
    private static function ecKey(array $jwk, int $size, string $curveOid): ?string
    {
        $x = self::b64($jwk['x'] ?? null);
        $y = self::b64($jwk['y'] ?? null);
        if ($x === null || $y === null || strlen($x) !== $size || strlen($y) !== $size) {
            return null;
        }
        $algorithm = self::der(0x30, "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . $curveOid);
        return self::pem(self::der(0x30, $algorithm . self::der(0x03, "\0\x04" . $x . $y)));
    }

    /** ECDSA signatures in a JWS are the fixed-size R and S side by side; OpenSSL wants them as a DER sequence. */
    private static function derSignature(string $raw, int $size): ?string
    {
        if (strlen($raw) !== 2 * $size) {
            return null;
        }
        return self::der(0x30, self::integer(substr($raw, 0, $size)) . self::integer(substr($raw, $size)));
    }

    /** Base64url (RFC 4648 section 5, no padding) to bytes; null when it is anything else. */
    public static function b64(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1 || strlen($value) % 4 === 1) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        return $bytes === false ? null : $bytes;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\0" . $bytes;
        }
        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return pack('CC', $tag, $length) . $content;
        }
        $octets = ltrim(pack('N', $length), "\0");
        return pack('CC', $tag, 0x80 | strlen($octets)) . $octets . $content;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
