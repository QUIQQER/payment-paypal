<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;

final class BillingAgreementsDouble extends BillingAgreements
{
    public static string $billingPlanId = 'PLAN-LEGACY';
    public static ?Gateway $Gateway = null;

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
}
