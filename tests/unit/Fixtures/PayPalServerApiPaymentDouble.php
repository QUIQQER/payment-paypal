<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Api\ServerClientInterface;
use QUI\ERP\Payments\PayPal\Payment;

final class PayPalServerApiPaymentDouble extends Payment
{
    public function __construct(private readonly ServerClientInterface $ServerClient)
    {
    }

    protected function getPayPalServerClient(): ServerClientInterface
    {
        return $this->ServerClient;
    }
}
