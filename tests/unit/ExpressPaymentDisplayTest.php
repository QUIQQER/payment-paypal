<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\ExpressPaymentDisplay;

final class ExpressPaymentDisplayTest extends TestCase
{
    public function testDisplayRendersConfiguredOrder(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getHash')->willReturn('EXPRESS-ORDER');
        $Display = new ExpressPaymentDisplay();
        $Display->setAttribute('Order', $Order);

        self::assertNotSame('', $Display->getBody());
        self::assertSame(
            'package/quiqqer/payment-paypal/bin/controls/ExpressPaymentDisplay',
            $Display->getAttribute('qui-class')
        );
        self::assertSame(
            'EXPRESS-ORDER',
            $Display->getAttribute('data-qui-options-orderhash')
        );
    }
}
