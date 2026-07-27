<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Products\Product\Product;

final class BillingPlansDouble extends BillingPlans
{
    public static ?Product $PlanProduct = null;
    public static ?Gateway $Gateway = null;
    public static array $planDetails = [];

    public static function usePayment(?Payment $Payment): void
    {
        self::$Payment = $Payment;
    }

    public static function getIdentificationHashForTest(AbstractOrder $Order): string
    {
        return self::getIdentificationHash($Order);
    }

    public static function getBillingPlanIdForTest(AbstractOrder $Order): bool|string
    {
        return self::getBillingPlanIdByOrder($Order);
    }

    public static function parsePaymentDefinitionsForTest(
        AbstractOrder $Order,
        Product $PlanProduct
    ): array {
        return self::parsePaymentDefinitionsFromOrder($Order, $PlanProduct);
    }

    protected static function isPlanOrder(AbstractOrder $Order): bool
    {
        return true;
    }

    protected static function getPlanProduct(AbstractOrder $Order): Product
    {
        return self::$PlanProduct;
    }

    protected static function getPlanDetailsFromOrder(
        AbstractOrder $Order
    ): array {
        return self::$planDetails;
    }

    protected static function createGatewayForOrder(
        AbstractOrder $Order
    ): Gateway {
        return self::$Gateway;
    }
}
