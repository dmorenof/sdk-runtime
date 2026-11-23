<?php
declare(strict_types=1);

namespace Redsys\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redsys\InvalidNotification;
use Redsys\Notification;
use Redsys\Signature;

final class NotificationTest extends TestCase
{
    private const KEY = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';

    /**
     * A notification as Redsys sends it: the parameters in URL-safe Base64 and signed with the key of the order.
     *
     * @param array<string, string> $parameters
     * @return array<string, string>
     */
    private static function post(array $parameters, string $key = self::KEY): array
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($parameters, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $order = $parameters['Ds_Order'] ?? $parameters['DS_ORDER'];
        return [
            'Ds_SignatureVersion' => 'HMAC_SHA256_V1',
            'Ds_MerchantParameters' => $encoded,
            'Ds_Signature' => rtrim(strtr(Signature::sign($encoded, $order, $key), '+/', '-_'), '='),
        ];
    }

    public function testASignedNotificationIsReadAndAnAuthorisedPaymentIsRecognised(): void
    {
        $notification = Notification::fromPost(self::post([
            'Ds_Date' => '23%2F11%2F2026', 'Ds_Amount' => '145', 'Ds_Currency' => '978', 'Ds_Order' => '1446117555',
            'Ds_MerchantCode' => '999008881', 'Ds_Terminal' => '001', 'Ds_Response' => '0000', 'Ds_AuthorisationCode' => '123456',
        ]), self::KEY);
        self::assertSame('1446117555', $notification->order);
        self::assertSame(145, $notification->amount);
        self::assertSame('978', $notification->currency);
        self::assertTrue($notification->authorised());
        self::assertSame('123456', $notification->get('DS_AUTHORISATIONCODE'));
    }

    public function testNamesInCapitalsAreTheSameAsCamelCase(): void
    {
        $notification = Notification::fromPost(self::post(['DS_ORDER' => '1446117555', 'DS_AMOUNT' => '99', 'DS_RESPONSE' => '0101']), self::KEY);
        self::assertSame('1446117555', $notification->order);
        self::assertSame(99, $notification->amount);
        self::assertFalse($notification->authorised());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function responses(): iterable
    {
        foreach (['0000' => true, '0001' => true, '0099' => true, '0100' => false, '101' => false, '900' => true, '400' => true, '9915' => false, '9999' => false, '' => false, 'abc' => false] as $code => $authorised) {
            yield "Ds_Response '$code'" => [(string) $code, $authorised];
        }
    }

    #[DataProvider('responses')]
    public function testOnlyTheCodesOfTheManualAreAuthorised(string $code, bool $authorised): void
    {
        $notification = Notification::fromPost(self::post(['Ds_Order' => '1446117555', 'Ds_Response' => $code]), self::KEY);
        self::assertSame($authorised, $notification->authorised());
    }

    public function testAnotherKeyIsRefused(): void
    {
        $this->expectException(InvalidNotification::class);
        Notification::fromPost(self::post(['Ds_Order' => '1446117555', 'Ds_Response' => '0000'], base64_encode(str_repeat('k', 24))), self::KEY);
    }

    public function testChangedParametersAreRefused(): void
    {
        $post = self::post(['Ds_Order' => '1446117555', 'Ds_Amount' => '145', 'Ds_Response' => '0000']);
        $post['Ds_MerchantParameters'] = rtrim(strtr(base64_encode('{"Ds_Order":"1446117555","Ds_Amount":"1","Ds_Response":"0000"}'), '+/', '-_'), '=');
        $this->expectException(InvalidNotification::class);
        Notification::fromPost($post, self::KEY);
    }

    public function testAnOrderChangedAfterSigningIsRefused(): void
    {
        $parameters = ['Ds_Order' => '1446117555', 'Ds_Response' => '0000'];
        $post = self::post($parameters);
        // Signed for another order: same parameters string would carry another key.
        $post['Ds_Signature'] = Signature::sign($post['Ds_MerchantParameters'], '9999999999', self::KEY);
        $this->expectException(InvalidNotification::class);
        Notification::fromPost($post, self::KEY);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformed(): iterable
    {
        yield 'nothing' => [[]];
        yield 'no signature' => [['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => 'e30']];
        yield 'another version' => [['Ds_SignatureVersion' => 'HMAC_SHA512_V2', 'Ds_MerchantParameters' => 'e30', 'Ds_Signature' => 'x']];
        yield 'not base64 json' => [['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => '%%%', 'Ds_Signature' => 'x']];
        yield 'no order' => [['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => 'e30', 'Ds_Signature' => 'x']];
        yield 'arrays instead of strings' => [['Ds_SignatureVersion' => 'HMAC_SHA256_V1', 'Ds_MerchantParameters' => ['x'], 'Ds_Signature' => ['y']]];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('malformed')]
    public function testAMalformedNotificationIsRefused(array $post): void
    {
        $this->expectException(InvalidNotification::class);
        Notification::fromPost($post, self::KEY);
    }
}
