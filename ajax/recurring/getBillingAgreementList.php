<?php

/**
 * Get details of a PayPal Billing Agreement
 *
 * @param array $searchParams - GRID search params
 * @return array - Billing Agreements list
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\Utils\Grid;
use QUI\Utils\Security\Orthos;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreementList',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $Grid = new Grid($searchParams);
        $agreements = BillingAgreements::getBillingAgreementList($searchParams);
        $count = BillingAgreements::getBillingAgreementList($searchParams, true);

        if (!is_array($agreements)) {
            $agreements = [];
        }

        if (!is_int($count)) {
            $count = 0;
        }

        return $Grid->parseResult($agreements, $count);
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_agreements.view']
);
