<?php

/**
 * Get list of PayPal Billing Plans
 *
 * @param array $searchParams - Grid search params
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\Utils\Grid;
use QUI\Utils\Security\Orthos;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_getBillingPlans',
    function ($searchParams) {
        $searchParams = Orthos::clearArray(json_decode($searchParams, true));

        $page = 0;
        $perPage = null;

        if (!empty($searchParams['page'])) {
            $page = (int)$searchParams['page'] - 1;
        }

        if (!empty($searchParams['perPage'])) {
            $perPage = (int)$searchParams['perPage'];
        }

        $list = null;

        try {
            $list = BillingPlans::getBillingPlanList($page, $perPage);
        } catch (Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        $plans = [];
        $count = 0;

        if (!empty($list)) {
            $plans = $list['plans'];
            $count = $list['total_items'];
        }

        $Grid = new Grid($searchParams);

        return $Grid->parseResult($plans, $count);
    },
    ['searchParams'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_plans.view']
);
