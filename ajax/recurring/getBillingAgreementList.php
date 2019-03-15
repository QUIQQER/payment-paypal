<?php

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Grid;

/**
 * Get details of a PayPal Billing Agreement
 *
 * @param array $searchParams - GRID search params
 * @return array - Billing Agreements list
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreementList',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $Grid = new Grid($searchParams);

        return $Grid->parseResult(
            BillingAgreements::getBillingAgreementList($searchParams),
            BillingAgreements::getBillingAgreementList($searchParams, true)
        );
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_agreements.view']
);
