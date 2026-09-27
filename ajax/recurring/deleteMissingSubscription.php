<?php

use QUI\ERP\Payments\PayPal\Recurring\SubscriptionCleanup;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_deleteMissingSubscription',
    function ($subscriptionId) {
        if (!is_string($subscriptionId)) {
            throw new QUI\Exception('Invalid subscription ID.');
        }

        (new SubscriptionCleanup())->deleteMissing($subscriptionId);

        QUI::getMessagesHandler()->addSuccess(QUI::getLocale()->get(
            'quiqqer/payment-paypal',
            'message.ajax.recurring.deleteUnassignedSubscription.success',
            ['subscriptionId' => $subscriptionId]
        ));
    },
    ['subscriptionId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.subscriptions.manage']
);
