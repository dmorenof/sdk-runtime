<?php
declare(strict_types=1);

namespace Redsys;

use Dmorenof\SdkRuntime\DTO;

/**
 * The data of a payment request (the parameters of Annex 8.1 of Redsys's manual that most shops use).
 *
 * Every value is checked where it is assigned (a PHP 8.4 `set` hook), against the length and format the manual gives:
 *
 *     $payment = new PaymentRequest();
 *     $payment->merchantCode = '999008881';
 *     $payment->order = '1234abc';          // not 'abc': the first four characters must be digits
 */
final class PaymentRequest extends DTO
{
    protected const array REQUIRED_FIELDS = ['merchantCode', 'terminal', 'transactionType', 'amount', 'currency', 'order'];

    protected const array VALIDATION = [
        'merchantCode' => ['pattern' => '^[0-9]{1,9}$'],
        'terminal' => ['pattern' => '^[0-9]{1,3}$'],
        'amount' => ['minimum' => 0, 'maximum' => 999999999999],
        'currency' => ['pattern' => '^[0-9]{1,4}$'],
        'order' => ['pattern' => '^[0-9]{4}[0-9A-Za-z]{0,8}$'],
        'merchantUrl' => ['maxLength' => 250],
        'urlOk' => ['maxLength' => 250],
        'urlKo' => ['maxLength' => 250],
        'productDescription' => ['maxLength' => 125],
        'titular' => ['maxLength' => 60],
        'merchantName' => ['maxLength' => 25],
        'merchantData' => ['maxLength' => 1024],
        'consumerLanguage' => ['pattern' => '^[0-9]{1,3}$'],
    ];

    /** @var array<string, string> PHP property => name in the parameters Redsys reads. */
    protected const array WIRE_PROPERTY_MAP = [
        'merchantCode' => 'Ds_Merchant_MerchantCode',
        'terminal' => 'Ds_Merchant_Terminal',
        'transactionType' => 'Ds_Merchant_TransactionType',
        'amount' => 'Ds_Merchant_Amount',
        'currency' => 'Ds_Merchant_Currency',
        'order' => 'Ds_Merchant_Order',
        'merchantUrl' => 'Ds_Merchant_MerchantURL',
        'urlOk' => 'Ds_Merchant_UrlOK',
        'urlKo' => 'Ds_Merchant_UrlKO',
        'productDescription' => 'Ds_Merchant_ProductDescription',
        'titular' => 'Ds_Merchant_Titular',
        'merchantName' => 'Ds_Merchant_MerchantName',
        'merchantData' => 'Ds_Merchant_MerchantData',
        'consumerLanguage' => 'Ds_Merchant_ConsumerLanguage',
    ];

    protected const array PROPERTY_MAP = [
        'Ds_Merchant_MerchantCode' => 'merchantCode',
        'Ds_Merchant_Terminal' => 'terminal',
        'Ds_Merchant_TransactionType' => 'transactionType',
        'Ds_Merchant_Amount' => 'amount',
        'Ds_Merchant_Currency' => 'currency',
        'Ds_Merchant_Order' => 'order',
        'Ds_Merchant_MerchantURL' => 'merchantUrl',
        'Ds_Merchant_UrlOK' => 'urlOk',
        'Ds_Merchant_UrlKO' => 'urlKo',
        'Ds_Merchant_ProductDescription' => 'productDescription',
        'Ds_Merchant_Titular' => 'titular',
        'Ds_Merchant_MerchantName' => 'merchantName',
        'Ds_Merchant_MerchantData' => 'merchantData',
        'Ds_Merchant_ConsumerLanguage' => 'consumerLanguage',
    ];

    /** FUC code the bank assigned to the merchant (up to 9 digits). */
    public string $merchantCode {
        set {
            $this->assigning('merchantCode', $value);
            $this->merchantCode = $value;
        }
    }

    /** Terminal number the bank assigned (up to 3 digits). */
    public string $terminal {
        set {
            $this->assigning('terminal', $value);
            $this->terminal = $value;
        }
    }

    /** What the terminal is asked to do. */
    public \Redsys\TransactionType $transactionType;

    /** In the smallest unit of the currency: for euros the last two digits are cents (145 = 1.45 EUR). */
    public int $amount {
        set {
            $this->assigning('amount', $value);
            $this->amount = $value;
        }
    }

    /** ISO 4217 numeric code: 978 euros, 840 dollars, 826 pounds, 392 yen. */
    public string $currency {
        set {
            $this->assigning('currency', $value);
            $this->currency = $value;
        }
    }

    /** Order number: up to 12 characters, the first 4 digits, the rest letters or digits. */
    public string $order {
        set {
            $this->assigning('order', $value);
            $this->order = $value;
        }
    }

    /** Where Redsys posts the on-line notification (required when the merchant has it). */
    public ?string $merchantUrl {
        set {
            $this->assigning('merchantUrl', $value);
            $this->merchantUrl = $value;
        }
    }

    /** Where the payer returns after a successful payment. */
    public ?string $urlOk {
        set {
            $this->assigning('urlOk', $value);
            $this->urlOk = $value;
        }
    }

    /** Where the payer returns after a failed payment. */
    public ?string $urlKo {
        set {
            $this->assigning('urlKo', $value);
            $this->urlKo = $value;
        }
    }

    /** Shown to the payer on the confirmation page. */
    public ?string $productDescription {
        set {
            $this->assigning('productDescription', $value);
            $this->productDescription = $value;
        }
    }

    /** Name of the cardholder, shown on the confirmation page. */
    public ?string $titular {
        set {
            $this->assigning('titular', $value);
            $this->titular = $value;
        }
    }

    /** Merchant name for the payer's receipt. */
    public ?string $merchantName {
        set {
            $this->assigning('merchantName', $value);
            $this->merchantName = $value;
        }
    }

    /** Free data the notification returns unchanged. */
    public ?string $merchantData {
        set {
            $this->assigning('merchantData', $value);
            $this->merchantData = $value;
        }
    }

    /** 0 unknown; 001 Spanish, 002 English, 003 Catalan, 004 French, 005 German, 006 Dutch, 007 Italian, 008 Swedish, 009 Portuguese, 010 Valencian, 011 Polish, 012 Galician, 013 Basque. */
    public ?string $consumerLanguage {
        set {
            $this->assigning('consumerLanguage', $value);
            $this->consumerLanguage = $value;
        }
    }

    /**
     * The parameters as Redsys reads them: every value a string, under the names of the manual.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $this->validate();
        $parameters = [];
        foreach (self::WIRE_PROPERTY_MAP as $property => $name) {
            if (!isset($this->{$property})) {
                continue;
            }
            $value = $this->{$property};
            $parameters[$name] = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
        }
        return $parameters;
    }
}
