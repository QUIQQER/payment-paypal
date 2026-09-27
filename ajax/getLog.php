<?php

use QUI\ERP\Payments\PayPal\LogReader;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_getLog',
    static function ($file): array {
        if (!is_string($file)) {
            throw new QUI\Exception('Invalid PayPal log filename.', 400);
        }

        return (new LogReader())->read($file);
    },
    ['file'],
    ['Permission::checkSU']
);
