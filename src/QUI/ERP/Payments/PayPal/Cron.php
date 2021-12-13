<?php

namespace QUI\ERP\Payments\PayPal;

/**
 * Class Cron
 *
 * Cron manager for quiqqer/payment-paypal
 */
class Cron
{
    /**
     * Checks pending captures of unpaid or not-yet-fully-paid PayPal orders.
     *
     * @return void
     */
    public static function checkPendingCaptures()
    {
        $Payment = new Payment();
        $Payment->checkPendingCaptures();
    }
}
