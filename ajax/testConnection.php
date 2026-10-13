<?php

use QUI\ERP\Payments\PayPal\Api\ConnectionTest;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Diagnostics;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_testConnection',
    static function ($currency, $country, $environment = null): array {
        if (!is_string($currency) || !is_string($country)) {
            throw new QUI\Exception('Invalid currency or country code.', 400);
        }

        if ($environment !== null && !in_array($environment, ['production', 'sandbox'], true)) {
            throw new QUI\Exception('Invalid PayPal test environment.', 400);
        }

        $activeSandbox = (bool)Provider::getApiSetting('sandbox');
        $sandbox = $environment === null ? $activeSandbox : $environment === 'sandbox';
        $prefix = $sandbox ? 'sandbox_' : '';
        $Test = new ConnectionTest(
            (string)Provider::getApiSetting($prefix . 'client_id'),
            (string)Provider::getApiSetting($prefix . 'client_secret'),
            $sandbox
        );

        $result = $Test->run($currency, $country);
        $result['activeEnvironment'] = $activeSandbox ? 'sandbox' : 'production';
        $configured = ($result['steps'][0]['reason'] ?? null) !== 'missing_credentials';
        $result['browserConfig'] = $configured ? Diagnostics::getSdkConfig($sandbox) : null;

        return $result;
    },
    ['currency', 'country', 'environment'],
    ['Permission::checkAdminUser', 'quiqqer.settings']
);
