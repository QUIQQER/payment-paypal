<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed;
use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\OrderInterface;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\EventsDouble;

final class EventsTest extends TestCase
{
    protected function tearDown(): void
    {
        EventsDouble::$plansInstalled = false;
        EventsDouble::$planDetails = [];
    }

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

    public function testRecurringPaymentRejectsInvoiceIntervalOverOneYear(): void
    {
        EventsDouble::$plansInstalled = true;
        EventsDouble::$planDetails = [
            'invoice_interval' => '13-month'
        ];
        $Payment = $this->createMock(Payment::class);
        $Payment->method('getPaymentType')->willReturn(
            new RecurringPayment()
        );

        $this->expectException(PaymentCanNotBeUsed::class);

        EventsDouble::onPaymentsCanUsedInOrder(
            $Payment,
            $this->createMock(OrderInterface::class)
        );
    }

    public function testRecurringPaymentAllowsMissingInvoiceInterval(): void
    {
        $this->expectNotToPerformAssertions();

        EventsDouble::$plansInstalled = true;
        $Payment = $this->createMock(Payment::class);
        $Payment->method('getPaymentType')->willReturn(
            new RecurringPayment()
        );

        EventsDouble::onPaymentsCanUsedInOrder(
            $Payment,
            $this->createMock(OrderInterface::class)
        );
    }
}
