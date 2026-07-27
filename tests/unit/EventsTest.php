<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\EventsDouble;

final class EventsTest extends TestCase
{
    public function testRecurringPaymentRequiresPlansPackage(): void
    {
        try {
            EventsDouble::onPaymentsCreateBegin(RecurringPayment::class);
            self::fail('Recurring payment was accepted without the plans package.');
        } catch (PaymentCanNotBeUsed $Exception) {
            self::assertSame(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.onPaymentsCreateBegin.erp_plans_missing'
                ),
                $Exception->getMessage()
            );
        }
    }
}
