<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Products\Product\Product;

final class BillingPlansDouble extends BillingPlans
{
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
}
