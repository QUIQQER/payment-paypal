<?php

use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_activateSubscription',
    function ($subscriptionId) {
        if (!Subscriptions::exists($subscriptionId)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.admin.subscription.not_found'
                )
            );
        }

        Subscriptions::activateSubscription(
            $subscriptionId,
            'Activated by QUIQQER administrator'
        );

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'message.ajax.recurring.activateSubscription.success',
                ['subscriptionId' => $subscriptionId]
            )
        );
    },
    ['subscriptionId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.subscriptions.manage']
);
