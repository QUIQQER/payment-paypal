<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Constants;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsDouble;
use ReflectionMethod;

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

    public function testModernSubscriptionsProcessTemporaryInvoices(): void
    {
        $Invoice = $this->createMock(InvoiceTemporary::class);
        SubscriptionsDouble::$Invoices = $this->createMock(
            InvoiceHandler::class
        );
        SubscriptionsDouble::$paymentTypeIds = [7];
        SubscriptionsDouble::$unpaidInvoiceRows = [
            [
                'id' => 301,
                'global_process_id' => 'PROCESS-MODERN-TEMPORARY',
                'temporary' => true
            ]
        ];
        SubscriptionsDouble::$processRows = [
            [
                'global_process_id' => 'PROCESS-MODERN-TEMPORARY'
            ]
        ];
        SubscriptionsDouble::$invoicesById = [
            301 => $Invoice
        ];

        SubscriptionsDouble::processUnpaidInvoices();

        self::assertSame([
            ['bill', $Invoice]
        ], SubscriptionsDouble::$processedInvoices);
    }

    public function testLegacyAgreementsProcessTemporaryInvoicesWithoutDeniedProcessing(): void
    {
        $Draft = $this->createMock(InvoiceTemporary::class);
        BillingAgreementsDouble::$Invoices = $this->createMock(InvoiceHandler::class);
        BillingAgreementsDouble::$paymentTypeIds = [8];
        BillingAgreementsDouble::$unpaidInvoiceRows = [[
            'id' => 302,
            'global_process_id' => 'PROCESS-LEGACY-TEMPORARY',
            'temporary' => true
        ]];
        BillingAgreementsDouble::$processRows = [[
            'global_process_id' => 'PROCESS-LEGACY-TEMPORARY'
        ]];
        BillingAgreementsDouble::$invoicesById = [302 => $Draft];

        BillingAgreementsDouble::processUnpaidInvoices();

        self::assertSame([['bill', $Draft]], BillingAgreementsDouble::$processedInvoices);
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

    /** @return array<string, array{class-string}> */
    public static function recurringProcessors(): array
    {
        return [
            'subscriptions' => [Subscriptions::class],
            'legacy agreements' => [BillingAgreements::class]
        ];
    }

    /** @dataProvider recurringProcessors */
    public function testInvoiceSearchProcessesDraftsAndInvoicesInDateOrder(string $processorClass): void
    {
        $Invoices = $this->createMock(InvoiceHandler::class);
        $Invoices->expects(self::once())->method('search')->with(self::callback(
            static fn(array $params): bool => $params['where']['type'] === Constants::TYPE_INVOICE
                && $params['where']['paid_status'] === Constants::PAYMENT_STATUS_OPEN
                && $params['where']['payment_method']['value'] === [7]
                && in_array('date', $params['select'], true)
        ))->willReturn([
            ['id' => 42, 'global_process_id' => 'process', 'date' => '2026-11-11 13:46:45'],
            ['id' => 43, 'global_process_id' => 'process', 'date' => '2026-11-13 13:46:45']
        ]);
        $Invoices->expects(self::once())->method('searchTemporaryInvoices')->with(self::callback(
            static fn(array $params): bool => $params['where']['type'] === Constants::TYPE_INVOICE_TEMPORARY
                && $params['where']['paid_status'] === Constants::PAYMENT_STATUS_OPEN
                && $params['where']['payment_method']['value'] === [7]
                && in_array('date', $params['select'], true)
        ))->willReturn([
            ['id' => 42, 'global_process_id' => 'process', 'date' => '2026-11-10 13:46:45'],
            ['id' => 43, 'global_process_id' => 'process', 'date' => '2026-11-12 13:46:45']
        ]);

        $Search = new ReflectionMethod($processorClass, 'searchUnpaidInvoiceRows');
        $rows = $Search->invoke(null, $Invoices, [7]);

        self::assertSame([42, 42, 43, 43], array_column($rows, 'id'));
        self::assertSame([true, false, true, false], array_column($rows, 'temporary'));
        self::assertSame([
            '2026-11-10 13:46:45',
            '2026-11-11 13:46:45',
            '2026-11-12 13:46:45',
            '2026-11-13 13:46:45'
        ], array_column($rows, 'date'));
    }

    /** @dataProvider recurringProcessors */
    public function testInvoiceLookupDistinguishesDraftAndInvoiceWithSameId(string $processorClass): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Draft = $this->createMock(InvoiceTemporary::class);
        $Invoices = $this->createMock(InvoiceHandler::class);
        $Invoices->expects(self::once())->method('getInvoice')->with(42)->willReturn($Invoice);
        $Invoices->expects(self::once())->method('getTemporaryInvoice')->with(42)->willReturn($Draft);
        $Invoices->expects(self::never())->method('get');

        $Lookup = new ReflectionMethod($processorClass, 'getInvoiceById');

        self::assertSame($Invoice, $Lookup->invoke(null, $Invoices, 42, false));
        self::assertSame($Draft, $Lookup->invoke(null, $Invoices, 42, true));
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
