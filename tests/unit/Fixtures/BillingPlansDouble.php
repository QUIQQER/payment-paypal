<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;

final class BillingPlansDouble extends BillingPlans
{
    public static function usePayment(?Payment $Payment): void
    {
        self::$Payment = $Payment;
    }
}
