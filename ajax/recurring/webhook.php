<?php

/**
 * Handle PayPal subscription webhooks.
 *
 * Configure PayPal to call the QUIQQER AJAX endpoint for this function and store
 * the PayPal webhook id in the package API settings.
 */

use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_webhook',
    function () {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }

        $rawBody = file_get_contents('php://input');

        if (!Subscriptions::handleWebhook($headers, $rawBody ?: '')) {
            http_response_code(400);

            return [
                'success' => false
            ];
        }

        return [
            'success' => true
        ];
    }
);
