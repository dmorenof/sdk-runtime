<?php
declare(strict_types=1);

namespace Redsys;

/**
 * The form the payer's browser posts to Redsys to pay (connection by redirection): the three fields of the manual and the
 * address to send them to.
 *
 *     $form = PaymentForm::create($payment, $secretKey, Environment::Test);
 *     echo $form->html();            // a form that submits itself
 */
final readonly class PaymentForm
{
    /** @param array{Ds_SignatureVersion: string, Ds_MerchantParameters: string, Ds_Signature: string} $fields */
    private function __construct(public string $action, public array $fields)
    {
    }

    public static function create(PaymentRequest $payment, string $secretKey, Environment $environment = Environment::Live): self
    {
        $parameters = $payment->parameters();
        $encoded = base64_encode(json_encode($parameters, JSON_THROW_ON_ERROR));
        return new self($environment->paymentUrl(), [
            'Ds_SignatureVersion' => Signature::VERSION,
            'Ds_MerchantParameters' => $encoded,
            'Ds_Signature' => Signature::sign($encoded, $parameters['Ds_Merchant_Order'], $secretKey),
        ]);
    }

    /** The form as HTML, every value escaped. With `$autoSubmit` the page sends it as soon as it loads. */
    public function html(bool $autoSubmit = true): string
    {
        $html = '<form name="redsys" action="' . self::escape($this->action) . '" method="POST">';
        foreach ($this->fields as $name => $value) {
            $html .= '<input type="hidden" name="' . self::escape($name) . '" value="' . self::escape($value) . '">';
        }
        $html .= '<noscript><input type="submit" value="Pay"></noscript></form>';
        return $autoSubmit ? $html . '<script>document.redsys.submit();</script>' : $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
