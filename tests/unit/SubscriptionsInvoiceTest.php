<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

final class SubscriptionsInvoiceTest extends TestCase
{
    public function testBillingRequiresSubscriptionReference(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentData')->willReturn(null);
        $Invoice->method('getId')->willReturn(42);

        try {
            Subscriptions::billSubscriptionInvoice($Invoice);
            self::fail('Invoice without a subscription reference was accepted.');
        } catch (PayPalException $Exception) {
            self::assertSame(404, $Exception->getCode());
        }
    }

    public function testBillingRejectsUnknownSubscription(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentData')->willReturn(
            'phpunit_missing_subscription'
        );

        try {
            Subscriptions::billSubscriptionInvoice($Invoice);
            self::fail('Unknown subscription was accepted for invoice billing.');
        } catch (PayPalException $Exception) {
            self::assertSame(404, $Exception->getCode());
        }
    }

    public function testDeniedTransactionProcessingSkipsUnrelatedInvoice(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->expects(self::once())
            ->method('getPaymentDataEntry')
            ->with(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID)
            ->willReturn(null);
        $Invoice->expects(self::never())->method('calculatePayments');

        Subscriptions::processDeniedTransactions($Invoice);
    }

    public function testRecurringPaymentRoutesModernInvoiceToSubscriptions(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentDataEntry')->willReturn(
            'phpunit_missing_subscription'
        );
        $Invoice->method('getPaymentData')->willReturn(
            'phpunit_missing_subscription'
        );

        $this->expectException(PayPalException::class);
        $this->expectExceptionCode(404);

        (new Payment())->captureSubscription($Invoice);
    }
}
