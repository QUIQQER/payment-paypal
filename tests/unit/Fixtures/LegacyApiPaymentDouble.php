<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PhpSdk\Core\PayPalHttpClient;

final class LegacyApiPaymentDouble extends Payment
{
    public function useLegacyClient(PayPalHttpClient $Client): void
    {
        $this->PayPalClient = $Client;
    }
}
