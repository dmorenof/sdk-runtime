<?php
declare(strict_types=1);

namespace Redsys;

/** Where a payment is sent. The test environment is identical to the real one, but its payments have no accounting value. */
enum Environment: string
{
    case Test = 'https://sis-t.redsys.es:25443';
    case Live = 'https://sis.redsys.es';

    /** The address the payer's browser posts the payment form to (connection by redirection). */
    public function paymentUrl(): string
    {
        return $this->value . '/sis/realizarPago';
    }
}
