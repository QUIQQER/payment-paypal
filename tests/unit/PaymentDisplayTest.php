<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PaymentDisplay;

final class PaymentDisplayTest extends TestCase
{
    public function testDisplayRendersOrderAndWidgetOptions(): void
    {
        $Sum = $this->createMock(CalculationValue::class);
        $Sum->method('formatted')->willReturn('12,34 €');
        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn($Sum);
        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('USD');
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getPriceCalculation')->willReturn($Calculations);
        $Order->method('getCurrency')->willReturn($Currency);
        $Order->method('getUUID')->willReturn('ORDER-UUID');
        $Order->method('isSuccessful')->willReturn(1);

        $Display = new PaymentDisplay();
        $Display->setAttribute('Order', $Order);
        $body = $Display->getBody();

        self::assertNotSame('', $body);
        self::assertSame(
            'package/quiqqer/payment-paypal/bin/controls/PaymentDisplay',
            $Display->getAttribute('qui-class')
        );
        self::assertSame(
            'ORDER-UUID',
            $Display->getAttribute('data-qui-options-orderhash')
        );
        self::assertSame(
            'USD',
            $Display->getAttribute('data-qui-options-currency')
        );
        self::assertTrue(
            (bool)$Display->getAttribute('data-qui-options-successful')
        );
        self::assertNotNull(
            $Display->getAttribute('data-qui-options-sandbox')
        );
    }
}
