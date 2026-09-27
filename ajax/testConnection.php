<?php

use QUI\ERP\Payments\PayPal\Api\ConnectionTest;
use QUI\ERP\Payments\PayPal\Provider;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_testConnection',
    static function ($currency, $country): array {
        if (!is_string($currency) || !is_string($country)) {
            throw new QUI\Exception('Invalid currency or country code.', 400);
        }

        $sandbox = (bool)Provider::getApiSetting('sandbox');
        $prefix = $sandbox ? 'sandbox_' : '';
        $Test = new ConnectionTest(
            (string)Provider::getApiSetting($prefix . 'client_id'),
            (string)Provider::getApiSetting($prefix . 'client_secret'),
            $sandbox
        );

        return $Test->run($currency, $country);
    },
    ['currency', 'country'],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
