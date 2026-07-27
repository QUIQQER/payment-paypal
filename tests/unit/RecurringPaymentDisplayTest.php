<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Recurring\PaymentDisplay;

final class RecurringPaymentDisplayTest extends TestCase
{
    public function testDisplayRendersConfiguredOrder(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getHash')->willReturn('RECURRING-ORDER');
        $Display = new PaymentDisplay();
        $Display->setAttribute('Order', $Order);

        self::assertNotSame('', $Display->getBody());
        self::assertSame(
            'package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay',
            $Display->getAttribute('qui-class')
        );
        self::assertSame(
            'RECURRING-ORDER',
            $Display->getAttribute('data-qui-options-orderhash')
        );
    }
}
