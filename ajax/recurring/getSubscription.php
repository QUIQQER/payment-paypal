<?php

use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getSubscription',
    function ($subscriptionId) {
        $localData = Subscriptions::getSubscriptionDataForAdministration(
            $subscriptionId
        );

        if ($localData === false) {
            return false;
        }

        $providerData = null;

        try {
            $providerData = Subscriptions::getSubscriptionDetails(
                $subscriptionId,
                !$localData['accountContextValid']
            );
        } catch (PayPalException $Exception) {
            if (!AccountContext::isMissingResource($Exception)) {
                QUI\System\Log::writeDebugException($Exception);
            }
        }

        return [
            'id' => $subscriptionId,
            'local' => $localData,
            'provider' => $providerData,
            'providerAvailable' => $providerData !== null,
            'accountContextValid' => $localData['accountContextValid'],
            'transactions' => Subscriptions::getSubscriptionTransactionList(
                $subscriptionId
            )
        ];
    },
    ['subscriptionId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.subscriptions.view']
);
