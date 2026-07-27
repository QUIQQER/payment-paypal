<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;

final class BillingAgreementsDouble extends BillingAgreements
{
    public static string $billingPlanId = 'PLAN-LEGACY';
    public static ?Gateway $Gateway = null;
    public static ?InvoiceHandler $Invoices = null;
    public static array $paymentTypeIds = [];
    public static array $unpaidInvoiceRows = [];
    public static array $processRows = [];
    public static array $invoicesById = [];
    public static array $processedInvoices = [];

    public static function usePayment(?Payment $Payment): void
    {
        self::$Payment = $Payment;
    }

    protected static function createBillingPlanFromOrder(
        AbstractOrder $Order
    ): string {
        return self::$billingPlanId;
    }

    protected static function createGatewayForOrder(
        AbstractOrder $Order
    ): Gateway {
        return self::$Gateway;
    }

    protected static function getInvoiceHandler(): InvoiceHandler
    {
        return self::$Invoices;
    }

    protected static function getRecurringPaymentTypeIds(): array
    {
        return self::$paymentTypeIds;
    }

    protected static function searchUnpaidInvoiceRows(
        InvoiceHandler $Invoices,
        array $paymentTypeIds
    ): array {
        return self::$unpaidInvoiceRows;
    }

    protected static function getAgreementProcessRows(
        array $globalProcessIds
    ): array {
        return self::$processRows;
    }

    protected static function getInvoiceById(
        InvoiceHandler $Invoices,
        int|string $invoiceId
    ): Invoice {
        return self::$invoicesById[$invoiceId];
    }

    protected static function processInvoiceDeniedTransactions(
        Invoice $Invoice
    ): void {
        self::$processedInvoices[] = ['denied', $Invoice];
    }

    protected static function billInvoiceAgreement(
        Invoice $Invoice
    ): void {
        self::$processedInvoices[] = ['bill', $Invoice];
    }
}
