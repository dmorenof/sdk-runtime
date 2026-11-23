<?php
declare(strict_types=1);

namespace Redsys;

/**
 * The result of a payment as Redsys reports it (the on-line notification, or the data returned to the payer), once its
 * signature has been verified: nothing in it is available before.
 *
 *     $notification = Notification::fromPost($_POST, $secretKey);   // throws InvalidNotification
 *     if ($notification->authorised()) { markPaid($notification->order, $notification->amount); }
 */
final readonly class Notification
{
    /** @param array<string, string> $parameters The decoded parameters, under their canonical names (`Ds_Order`, `Ds_Response`, ...). */
    private function __construct(
        public string $order,
        public int $amount,
        public string $currency,
        public string $response,
        public array $parameters,
    ) {
    }

    /**
     * Verifies the signature and decodes the parameters.
     *
     * @param array<string, mixed> $post `Ds_SignatureVersion`, `Ds_MerchantParameters` and `Ds_Signature`, as received.
     * @throws InvalidNotification
     */
    public static function fromPost(array $post, string $secretKey): self
    {
        $encoded = $post['Ds_MerchantParameters'] ?? null;
        $signature = $post['Ds_Signature'] ?? null;
        if (!is_string($encoded) || !is_string($signature) || ($post['Ds_SignatureVersion'] ?? null) !== Signature::VERSION) {
            throw new InvalidNotification('The notification lacks Ds_MerchantParameters, Ds_Signature or a Ds_SignatureVersion of ' . Signature::VERSION . '.');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $decoded = $json === false ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidNotification('Ds_MerchantParameters is not Base64 JSON.');
        }
        $parameters = [];
        foreach ($decoded as $name => $value) {
            // Redsys writes the names in capitals or in camel case, depending on the channel: one spelling for the caller.
            $parameters[self::canonical((string) $name)] = is_scalar($value) ? (string) $value : '';
        }
        $order = $parameters['Ds_Order'] ?? null;
        if ($order === null) {
            throw new InvalidNotification('The notification has no Ds_Order.');
        }
        if (!Signature::verify($signature, $encoded, $order, $secretKey)) {
            throw new InvalidNotification('The signature of the notification does not match.');
        }
        return new self($order, (int) ($parameters['Ds_Amount'] ?? 0), $parameters['Ds_Currency'] ?? '', $parameters['Ds_Response'] ?? '', $parameters);
    }

    /** Ds_Response 0000 to 0099 is an authorised payment or preauthorisation; 900 a refund or confirmation; 400 a cancellation. */
    public function authorised(): bool
    {
        $code = (int) $this->response;
        return $this->response !== '' && ctype_digit($this->response) && ($code <= 99 || $code === 900 || $code === 400);
    }

    /** A parameter by its manual name (`Ds_AuthorisationCode`), whatever the spelling it arrived in. */
    public function get(string $name): ?string
    {
        return $this->parameters[self::canonical($name)] ?? null;
    }

    /** `DS_AUTHORISATIONCODE` and `Ds_AuthorisationCode` are the same parameter. */
    private static function canonical(string $name): string
    {
        static $known = [
            'ds_date' => 'Ds_Date', 'ds_hour' => 'Ds_Hour', 'ds_amount' => 'Ds_Amount', 'ds_currency' => 'Ds_Currency',
            'ds_order' => 'Ds_Order', 'ds_merchantcode' => 'Ds_MerchantCode', 'ds_terminal' => 'Ds_Terminal',
            'ds_response' => 'Ds_Response', 'ds_merchantdata' => 'Ds_MerchantData', 'ds_securepayment' => 'Ds_SecurePayment',
            'ds_transactiontype' => 'Ds_TransactionType', 'ds_card_country' => 'Ds_Card_Country',
            'ds_authorisationcode' => 'Ds_AuthorisationCode', 'ds_consumerlanguage' => 'Ds_ConsumerLanguage', 'ds_card_type' => 'Ds_Card_Type',
        ];
        return $known[strtolower($name)] ?? $name;
    }
}
