<?php

/**
 * Delete a PayPal Billing Plan
 *
 * @param string $billingPlanId
 * @return void
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_deleteBillingPlan',
    function ($billingPlanId) {
        BillingPlans::deleteBillingPlan($billingPlanId);

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'message.ajax.recurring.deleteBillingPlan.success',
                [
                    'billingPlanId' => $billingPlanId
                ]
            )
        );
    },
    ['billingPlanId'],
    ['Permission::checkAdminUser', 'quiqqer.payments.paypal.billing_plans.delete']
);
