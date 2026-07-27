<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\PaymentExpress;

final class PaymentExpressDouble extends PaymentExpress
{
    /**
     * @param array<string, mixed> $payPalOrder
     * @return array<string, mixed>
     */
    public function getPayerData(array $payPalOrder): array
    {
        return $this->getPayerDataFromPayPalOrder($payPalOrder);
    }
}
