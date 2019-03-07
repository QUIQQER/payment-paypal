<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;
use QUI\Utils\Grid;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;

/**
 * Get list of PayPal Billing Plans
 *
 * @param array $searchParams - Grid search params
 * @return void
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_deleteBillingPlan',
    function ($billingPlanId) {
        BillingPlans::deleteBillingPlan($billingPlanId);
    },
    ['searchParams'],
    'Permission::checkAdminUser'
);
