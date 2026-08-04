<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed;
use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\OrderInterface;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\Package\Package;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\EventsDouble;

final class EventsTest extends TestCase
{
    protected function tearDown(): void
    {
        EventsDouble::$plansInstalled = false;
        EventsDouble::$planDetails = [];
        EventsDouble::$billedSubscriptionInvoice = null;
        EventsDouble::$subscriptionInvoicePaid = true;
        EventsDouble::$subscriptionInvoiceBillingAttempts = 0;
        EventsDouble::$subscriptionTransactionWaits = 0;
        EventsDouble::$subscriptionCronRows = [];
        EventsDouble::$subscriptionCronsUpdated = [];
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

    public function testPackageSetupKeepsMissingSubscriptionCronDeleted(): void
    {
        $Package = $this->createMock(Package::class);
        $Package->method('getName')->willReturn('quiqqer/payment-paypal');

        EventsDouble::onPackageSetup($Package);

        self::assertSame([], EventsDouble::$subscriptionCronsUpdated);
    }

    public function testPackageSetupUpdatesSubscriptionCronInterval(): void
    {
        $cron = [
            'id' => 54,
            'min' => '5',
            'hour' => '*',
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*'
        ];
        EventsDouble::$subscriptionCronRows = [$cron];
        $Package = $this->createMock(Package::class);
        $Package->method('getName')->willReturn('quiqqer/payment-paypal');

        EventsDouble::onPackageSetup($Package);

        self::assertSame([$cron], EventsDouble::$subscriptionCronsUpdated);
    }

    public function testPackageSetupKeepsCurrentSubscriptionCronInterval(): void
    {
        EventsDouble::$subscriptionCronRows = [[
            'id' => 54,
            'min' => '*/5',
            'hour' => '*',
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*'
        ]];
        $Package = $this->createMock(Package::class);
        $Package->method('getName')->willReturn('quiqqer/payment-paypal');

        EventsDouble::onPackageSetup($Package);

        self::assertSame([], EventsDouble::$subscriptionCronsUpdated);
    }

    public function testPackageSetupKeepsCustomSubscriptionCronInterval(): void
    {
        EventsDouble::$subscriptionCronRows = [[
            'id' => 54,
            'min' => '15',
            'hour' => '2',
            'day' => '*',
            'month' => '*',
            'dayOfWeek' => '*'
        ]];
        $Package = $this->createMock(Package::class);
        $Package->method('getName')->willReturn('quiqqer/payment-paypal');

        EventsDouble::onPackageSetup($Package);

        self::assertSame([], EventsDouble::$subscriptionCronsUpdated);
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

    public function testContractInvoiceCreationSynchronizesSubscriptionPayment(): void
    {
        $Invoice = $this->createMock(InvoiceTemporary::class);
        $Invoice->method('getPaymentData')
            ->with(RecurringPayment::ATTR_PAYPAL_SUBSCRIPTION_ID)
            ->willReturn('I-PHPUNIT');

        EventsDouble::onQuiqqerContractsCreateInvoiceEnd(new \stdClass(), $Invoice);

        self::assertSame($Invoice, EventsDouble::$billedSubscriptionInvoice);
        self::assertSame(1, EventsDouble::$subscriptionInvoiceBillingAttempts);
        self::assertSame(0, EventsDouble::$subscriptionTransactionWaits);
    }

    public function testContractInvoiceCreationIgnoresNonSubscriptionPayment(): void
    {
        $Invoice = $this->createMock(InvoiceTemporary::class);
        $Invoice->method('getPaymentData')
            ->with(RecurringPayment::ATTR_PAYPAL_SUBSCRIPTION_ID)
            ->willReturn(null);

        EventsDouble::onQuiqqerContractsCreateInvoiceEnd(new \stdClass(), $Invoice);

        self::assertNull(EventsDouble::$billedSubscriptionInvoice);
    }

    public function testContractInvoiceCreationRetriesDelayedSubscriptionTransaction(): void
    {
        EventsDouble::$subscriptionInvoicePaid = false;
        $Invoice = $this->createMock(InvoiceTemporary::class);
        $Invoice->method('getPaymentData')
            ->with(RecurringPayment::ATTR_PAYPAL_SUBSCRIPTION_ID)
            ->willReturn('I-PHPUNIT');

        EventsDouble::onQuiqqerContractsCreateInvoiceEnd(new \stdClass(), $Invoice);

        self::assertSame(3, EventsDouble::$subscriptionInvoiceBillingAttempts);
        self::assertSame(2, EventsDouble::$subscriptionTransactionWaits);
    }
}
