<?php
declare(strict_types=1);

namespace Redsys\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redsys\Environment;
use Redsys\PaymentForm;
use Redsys\PaymentRequest;
use Redsys\Signature;
use Redsys\TransactionType;

/** Needs PHP 8.4: the request checks every value where it is assigned, with property hooks. */
final class PaymentTest extends TestCase
{
    private const KEY = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';

    private static function payment(): PaymentRequest
    {
        $payment = new PaymentRequest();
        $payment->merchantCode = '999008881';
        $payment->terminal = '1';
        $payment->transactionType = TransactionType::Authorisation;
        $payment->amount = 145;
        $payment->currency = '978';
        $payment->order = '1446117555';
        $payment->merchantUrl = 'http://www.prueba.com/urlNotificacion.php';
        $payment->urlOk = 'http://www.prueba.com/urlOK.php';
        $payment->urlKo = 'http://www.prueba.com/urlKO.php';
        return $payment;
    }

    public function testTheParametersAreStringsUnderTheNamesOfTheManual(): void
    {
        self::assertSame([
            'Ds_Merchant_MerchantCode' => '999008881', 'Ds_Merchant_Terminal' => '1', 'Ds_Merchant_TransactionType' => '0',
            'Ds_Merchant_Amount' => '145', 'Ds_Merchant_Currency' => '978', 'Ds_Merchant_Order' => '1446117555',
            'Ds_Merchant_MerchantURL' => 'http://www.prueba.com/urlNotificacion.php', 'Ds_Merchant_UrlOK' => 'http://www.prueba.com/urlOK.php',
            'Ds_Merchant_UrlKO' => 'http://www.prueba.com/urlKO.php',
        ], self::payment()->parameters());
    }

    public function testTheFormCarriesTheThreeFieldsAndASignatureThatVerifies(): void
    {
        $form = PaymentForm::create(self::payment(), self::KEY, Environment::Test);
        self::assertSame('https://sis-t.redsys.es:25443/sis/realizarPago', $form->action);
        self::assertSame(['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'], array_keys($form->fields));
        self::assertSame('HMAC_SHA256_V1', $form->fields['Ds_SignatureVersion']);
        self::assertSame(self::payment()->parameters(), json_decode(base64_decode($form->fields['Ds_MerchantParameters'], true), true));
        self::assertTrue(Signature::verify($form->fields['Ds_Signature'], $form->fields['Ds_MerchantParameters'], '1446117555', self::KEY));
    }

    public function testTheLiveEnvironmentIsTheDefault(): void
    {
        self::assertSame('https://sis.redsys.es/sis/realizarPago', PaymentForm::create(self::payment(), self::KEY)->action);
    }

    public function testTheHtmlEscapesEveryValueAndCanWaitForTheClick(): void
    {
        $payment = self::payment();
        $payment->productDescription = 'Tom & "Jerry" <b>';
        $html = PaymentForm::create($payment, self::KEY, Environment::Test)->html(false);
        self::assertStringContainsString('<form name="redsys" action="https://sis-t.redsys.es:25443/sis/realizarPago" method="POST">', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<script>document.redsys.submit();</script>', PaymentForm::create($payment, self::KEY)->html());
        self::assertStringNotContainsString('Tom & "Jerry"', $html);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function refused(): iterable
    {
        yield 'an order that does not start with four digits' => ['order', 'abc1234567'];
        yield 'an order of 13 characters' => ['order', '1234567890123'];
        yield 'an order with a symbol' => ['order', '1234-567'];
        yield 'a merchant code of 10 digits' => ['merchantCode', '1234567890'];
        yield 'a terminal of 4 digits' => ['terminal', '1234'];
        yield 'a negative amount' => ['amount', -1];
        yield 'a currency in letters' => ['currency', 'EUR'];
        yield 'a description of 126 characters' => ['productDescription', str_repeat('x', 126)];
        yield 'a language of letters' => ['consumerLanguage', 'es'];
    }

    #[DataProvider('refused')]
    public function testAValueThatBreaksTheManualIsRefusedWhereItIsAssigned(string $property, mixed $value): void
    {
        $payment = self::payment();
        $this->expectException(\Throwable::class);
        $payment->{$property} = $value;
    }

    public function testAPaymentWithoutWhatTheManualRequiresIsRefused(): void
    {
        $payment = new PaymentRequest();
        $payment->order = '1234abc';
        $this->expectException(\Throwable::class);
        $payment->parameters();
    }
}
