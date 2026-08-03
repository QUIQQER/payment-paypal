<?php

use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\Utils\Grid;
use QUI\Utils\Security\Orthos;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getSubscriptionList',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));
        $Grid = new Grid($searchParams);
        $subscriptions = Subscriptions::getSubscriptionList($searchParams);
        $count = Subscriptions::getSubscriptionList($searchParams, true);

        return $Grid->parseResult(
            is_array($subscriptions) ? $subscriptions : [],
            is_int($count) ? $count : 0
        );
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.subscriptions.view']
);
