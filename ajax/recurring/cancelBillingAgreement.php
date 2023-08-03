<?php

/**
 * Cancel a PayPal Billing Agreement
 *
 * @param string $billingAgreementId - PayPal Billing Agreement ID
 * @return void
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_cancelBillingAgreement',
    function ($billingAgreementId) {
        BillingAgreements::cancelBillingAgreement($billingAgreementId);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'message.ajax.recurring.cancelBillingAgreement.success',
                [
                    'billingAgreementId' => $billingAgreementId
                ]
            )
        );
    },
    ['billingAgreementId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_agreements.cancel']
);
