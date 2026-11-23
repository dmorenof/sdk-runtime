<?php
declare(strict_types=1);

namespace Redsys;

/**
 * HMAC SHA256 signature of Redsys (`Ds_SignatureVersion` = `HMAC_SHA256_V1`).
 *
 * The signature of an operation is the HMAC SHA256 of the Base64 `Ds_MerchantParameters` string, keyed with a key
 * derived for that operation: the 3DES (CBC, zero IV) encryption of the order number with the merchant's key, which is
 * decoded from Base64 first. The order number is padded with zero bytes to a multiple of 8.
 */
final class Signature
{
    public const string VERSION = 'HMAC_SHA256_V1';

    /** The signature to send with a request: standard Base64. */
    public static function sign(string $encodedParameters, string $order, string $secretKey): string
    {
        return base64_encode(hash_hmac('sha256', $encodedParameters, self::operationKey($order, $secretKey), true));
    }

    /** Whether a signature received (in either Base64 alphabet, with or without padding) is the one this operation must carry. */
    public static function verify(string $received, string $encodedParameters, string $order, string $secretKey): bool
    {
        return hash_equals(self::standard(self::sign($encodedParameters, $order, $secretKey)), self::standard($received));
    }

    /** The key of one operation: the merchant's key, 3DES-encrypting the order number. */
    private static function operationKey(string $order, string $secretKey): string
    {
        $key = base64_decode($secretKey, true);
        if ($key === false || strlen($key) !== 24) {
            throw new \InvalidArgumentException('The merchant key must be the Base64 text Redsys gives, which decodes to 24 bytes.');
        }
        $padded = str_pad($order, (int) (ceil(strlen($order) / 8) * 8), "\0");
        $derived = openssl_encrypt($padded, 'des-ede3-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 8));
        if ($derived === false) {
            throw new \RuntimeException('The 3DES encryption of the order number failed: ' . (openssl_error_string() ?: 'unknown error'));
        }
        return $derived;
    }

    /** Standard Base64 with padding, whatever alphabet it arrived in (Redsys sends the URL-safe one in notifications). */
    private static function standard(string $base64): string
    {
        $standard = strtr(rtrim($base64, '='), '-_', '+/');
        return $standard . str_repeat('=', (4 - strlen($standard) % 4) % 4);
    }
}
