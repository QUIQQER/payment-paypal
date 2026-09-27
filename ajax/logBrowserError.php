<?php

use QUI\ERP\Payments\PayPal\Diagnostics;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_logBrowserError',
    static function ($token, $payload): bool {
        if (!is_string($token) || !is_string($payload)) {
            return false;
        }

        return Diagnostics::logBrowserError($token, $payload);
    },
    ['token', 'payload']
);
