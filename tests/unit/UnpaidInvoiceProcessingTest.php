<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsDouble;

final class UnpaidInvoiceProcessingTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetSubscriptionDouble();
        $this->resetAgreementDouble();
    }

    public function testModernSubscriptionsProcessMatchingInvoices(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        SubscriptionsDouble::$Invoices = $this->createMock(
            InvoiceHandler::class
        );
        SubscriptionsDouble::$paymentTypeIds = [7];
        SubscriptionsDouble::$unpaidInvoiceRows = [
            [
                'id' => 101,
                'global_process_id' => 'PROCESS-MODERN'
            ],
            [
                'id' => 102,
                'global_process_id' => 'PROCESS-UNMATCHED'
            ]
        ];
        SubscriptionsDouble::$processRows = [
            [
                'global_process_id' => 'PROCESS-MODERN'
            ]
        ];
        SubscriptionsDouble::$invoicesById = [
            101 => $Invoice
        ];

        SubscriptionsDouble::processUnpaidInvoices();

        self::assertSame([
            ['denied', $Invoice],
            ['bill', $Invoice]
        ], SubscriptionsDouble::$processedInvoices);
    }

    public function testLegacyAgreementsProcessMatchingInvoices(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        BillingAgreementsDouble::$Invoices = $this->createMock(
            InvoiceHandler::class
        );
        BillingAgreementsDouble::$paymentTypeIds = [8];
        BillingAgreementsDouble::$unpaidInvoiceRows = [
            [
                'id' => 201,
                'global_process_id' => 'PROCESS-LEGACY'
            ]
        ];
        BillingAgreementsDouble::$processRows = [
            [
                'global_process_id' => 'PROCESS-LEGACY'
            ]
        ];
        BillingAgreementsDouble::$invoicesById = [
            201 => $Invoice
        ];

        BillingAgreementsDouble::processUnpaidInvoices();

        self::assertSame([
            ['denied', $Invoice],
            ['bill', $Invoice]
        ], BillingAgreementsDouble::$processedInvoices);
    }

    public function testEmptyPaymentTypesStopBothProcessors(): void
    {
        SubscriptionsDouble::$Invoices = $this->createMock(
            InvoiceHandler::class
        );
        BillingAgreementsDouble::$Invoices = $this->createMock(
            InvoiceHandler::class
        );

        SubscriptionsDouble::processUnpaidInvoices();
        BillingAgreementsDouble::processUnpaidInvoices();

        self::assertSame([], SubscriptionsDouble::$processedInvoices);
        self::assertSame([], BillingAgreementsDouble::$processedInvoices);
    }

    private function resetSubscriptionDouble(): void
    {
        SubscriptionsDouble::$Invoices = null;
        SubscriptionsDouble::$paymentTypeIds = [];
        SubscriptionsDouble::$unpaidInvoiceRows = [];
        SubscriptionsDouble::$processRows = [];
        SubscriptionsDouble::$invoicesById = [];
        SubscriptionsDouble::$processedInvoices = [];
    }

    private function resetAgreementDouble(): void
    {
        BillingAgreementsDouble::$Invoices = null;
        BillingAgreementsDouble::$paymentTypeIds = [];
        BillingAgreementsDouble::$unpaidInvoiceRows = [];
        BillingAgreementsDouble::$processRows = [];
        BillingAgreementsDouble::$invoicesById = [];
        BillingAgreementsDouble::$processedInvoices = [];
    }
}
