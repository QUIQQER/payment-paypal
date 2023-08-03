<?php

/**
 * Get some necessary order details for setting up PayPal API
 *
 * @return array|false
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_getOrderDetails',
    function () {
        return [
            'currency' => QUI\ERP\Defaults::getCurrency()->getCode()
        ];
    }
);
