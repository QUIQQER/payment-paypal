<?php

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;

/**
 * Get details of a PayPal Billing Agreement
 *
 * @param string $billingAgreementId - PayPal Billing Agreement ID
 * @return array - Billing Agreement data
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreement',
    function ($billingAgreementId) {
        return BillingAgreements::getBillingAgreementDetails($billingAgreementId);
    },
    ['billingAgreementId'],
    'Permission::checkAdminUser'
);
