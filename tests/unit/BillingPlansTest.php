<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingPlansDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class BillingPlansTest extends TestCase
{
    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        $this->Payment = new PaymentWorkflowDouble();
        BillingPlansDouble::usePayment($this->Payment);
    }

    protected function tearDown(): void
    {
        BillingPlansDouble::usePayment(null);
    }

    public function testActivateBillingPlanUsesLegacyUpdateRequest(): void
    {
        BillingPlansDouble::activateBillingPlan('PLAN-ACTIVE');

        self::assertSame([
            'request' => Payment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
            'body' => [[
                'op' => 'replace',
                'path' => '/',
                'value' => [
                    'state' => 'ACTIVE'
                ]
            ]],
            'transaction' => [
                Payment::ATTR_PAYPAL_BILLING_PLAN_ID => 'PLAN-ACTIVE'
            ],
            'throwSystemException' => false
        ], $this->Payment->apiCalls[0]);
    }

    public function testDeleteBillingPlanUsesDeletedState(): void
    {
        BillingPlansDouble::deleteBillingPlan('PLAN-DELETED');

        self::assertSame(
            'DELETED',
            $this->Payment->apiCalls[0]['body'][0]['value']['state']
        );
        self::assertSame(
            'PLAN-DELETED',
            $this->Payment->apiCalls[0]['transaction'][Payment::ATTR_PAYPAL_BILLING_PLAN_ID]
        );
    }

    public function testBillingPlanListNormalizesPagination(): void
    {
        $this->Payment->apiResponse = [
            'plans' => [
                ['id' => 'PLAN-1']
            ]
        ];

        self::assertSame(
            $this->Payment->apiResponse,
            BillingPlansDouble::getBillingPlanList(-5, 50)
        );
        self::assertSame([
            'page' => 0,
            'page_size' => 20,
            'status' => 'ACTIVE',
            'total_required' => 'yes'
        ], $this->Payment->apiCalls[0]['transaction']);

        BillingPlansDouble::getBillingPlanList(2, 0);
        self::assertSame(1, $this->Payment->apiCalls[1]['transaction']['page_size']);
    }

    public function testBillingPlanTableUsesQuiqqerPrefix(): void
    {
        self::assertSame(
            QUI::getDBTableName(BillingPlans::TBL_BILLING_PLANS),
            BillingPlansDouble::getBillingPlansTable()
        );
    }
}
