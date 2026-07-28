<?php

use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_deleteUnassignedSubscription',
    function ($subscriptionId) {
        if (!Subscriptions::deleteUnassignedSubscription($subscriptionId)) {
            throw new QUI\Exception(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.admin.subscription.delete_unassigned'
                )
            );
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'message.ajax.recurring.deleteUnassignedSubscription.success',
                ['subscriptionId' => $subscriptionId]
            )
        );
    },
    ['subscriptionId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.subscriptions.manage']
);
