<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;

final class PaymentPayloadDouble extends Payment
{
    public function __construct(private readonly Gateway $Gateway)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayPalDataForOrder(AbstractOrder $Order): array
    {
        return $this->getPayPalDataForOrder($Order);
    }

    protected function createGatewayForOrder(AbstractOrder $Order): Gateway
    {
        return $this->Gateway;
    }
}
