<?php

/**
 * Get details of a PayPal Billing Agreement
 *
 * @param string $billingAgreementId - PayPal Billing Agreement ID
 * @return array|false Billing Agreement data
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreement',
    function ($billingAgreementId) {
        try {
            $billingAgreement = BillingAgreements::getBillingAgreementDetails($billingAgreementId);
            $billingAgreement['quiqqer_data'] = BillingAgreements::getBillingAgreementData($billingAgreementId);

            return $billingAgreement;
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return false;
        }
    },
    ['billingAgreementId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_agreements.view']
);
