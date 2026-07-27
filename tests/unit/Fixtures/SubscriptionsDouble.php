<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\ERP\Products\Product\Product;
use RuntimeException;

final class SubscriptionsDouble extends Subscriptions
{
    private static ?Gateway $Gateway = null;
    public static ?InvoiceHandler $Invoices = null;
    public static array $paymentTypeIds = [];
    public static array $unpaidInvoiceRows = [];
    public static array $processRows = [];
    public static array $invoicesById = [];
    public static array $processedInvoices = [];

    public static function useGateway(?Gateway $Gateway): void
    {
        self::$Gateway = $Gateway;
    }

    public static function createProductForTest(
        AbstractOrder $Order,
        Product $PlanProduct
    ): string {
        return self::createProduct($Order, $PlanProduct);
    }

    /**
     * @return array<string, mixed>
     */
    public static function createPlanForTest(
        AbstractOrder $Order,
        Product $PlanProduct,
        string $productId
    ): array {
        return self::createPlan($Order, $PlanProduct, $productId);
    }

    /**
     * @param array<string, mixed> $planDetails
     */
    public static function getCycleCountForTest(array $planDetails): int
    {
        return self::getCycleCount($planDetails);
    }

    /**
     * @return array{string, string}
     */
    protected static function getOrCreatePlanReferences(AbstractOrder $Order): array
    {
        return ['PRODUCT-1', 'PLAN-1'];
    }

    protected static function createGatewayForOrder(AbstractOrder $Order): Gateway
    {
        if (self::$Gateway === null) {
            throw new RuntimeException('No gateway configured for subscription test.');
        }

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

    protected static function getSubscriptionProcessRows(
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

    protected static function billInvoiceSubscription(
        Invoice $Invoice
    ): void {
        self::$processedInvoices[] = ['bill', $Invoice];
    }
}
