<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingPlansDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class AjaxCallbacksTest extends TestCase
{
    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->Payment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->Payment);
        BillingPlansDouble::usePayment($this->Payment);
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        BillingPlansDouble::usePayment(null);
        http_response_code(200);

        parent::tearDown();
    }

    public function testClientAndOrderMetadataCallbacksReturnConfiguration(): void
    {
        $clientId = $this->registeredCallback('getClientId')();
        $expectedSetting = Provider::getApiSetting('sandbox')
            ? 'sandbox_client_id'
            : 'client_id';

        self::assertSame(Provider::getApiSetting($expectedSetting), $clientId);
        self::assertSame(
            ['currency' => QUI\ERP\Defaults::getCurrency()->getCode()],
            $this->registeredCallback('getOrderDetails')()
        );
    }

    public function testOrderCallbacksHandleMissingOrders(): void
    {
        self::assertFalse($this->registeredCallback('createOrder')('', 0));
        self::assertFalse($this->registeredCallback('executeOrder')('missing-order', false));
        self::assertFalse($this->registeredCallback('executeOrder')('missing-order', true));
        self::assertFalse($this->registeredCallback('expressCheckout')('missing-order'));
        self::assertFalse(
            $this->registeredCallback('recurring/createBillingAgreement')('missing-order')
        );
    }

    public function testLegacyAgreementAdminCallbacksUseHandler(): void
    {
        $this->Payment->apiResponse = [
            'id' => 'AJAX-AGREEMENT',
            'state' => BillingAgreements::BILLING_AGREEMENT_STATE_ACTIVE
        ];

        $details = $this->registeredCallback('recurring/getBillingAgreement')(
            'AJAX-AGREEMENT'
        );

        self::assertSame('AJAX-AGREEMENT', $details['id']);
        self::assertFalse($details['quiqqer_data']);

        $list = $this->registeredCallback('recurring/getBillingAgreementList')(
            json_encode([
                'search' => 'phpunit-does-not-exist',
                'page' => 1,
                'perPage' => 10
            ])
        );

        self::assertSame(0, $list['total']);
        self::assertSame([], $list['data']);

        self::assertNull(
            $this->registeredCallback('recurring/cancelBillingAgreement')(
                'AJAX-MISSING-AGREEMENT'
            )
        );
    }

    public function testLegacyPlanAdminCallbacksUseHandler(): void
    {
        $this->Payment->apiResponse = [
            'plans' => [
                ['id' => 'AJAX-PLAN']
            ],
            'total_items' => 1
        ];

        $list = $this->registeredCallback('recurring/getBillingPlans')(
            json_encode([
                'page' => 2,
                'perPage' => 5
            ])
        );

        self::assertSame(1, $list['total']);
        self::assertSame('AJAX-PLAN', $list['data'][0]['id']);

        self::assertNull(
            $this->registeredCallback('recurring/deleteBillingPlan')('AJAX-PLAN')
        );
        self::assertSame(
            BillingPlans::getBillingPlansTable(),
            BillingPlansDouble::getBillingPlansTable()
        );
    }

    public function testSubscriptionAdminCallbacksAreRegistered(): void
    {
        $list = $this->registeredCallback('recurring/getSubscriptionList')(
            json_encode([
                'search' => 'phpunit-does-not-exist',
                'page' => 1,
                'perPage' => 10
            ])
        );

        self::assertSame(0, $list['total']);
        self::assertSame([], $list['data']);
        self::assertFalse(
            $this->registeredCallback('recurring/getSubscription')(
                'phpunit-does-not-exist'
            )
        );
        self::assertIsCallable(
            $this->registeredCallback('recurring/cancelSubscription')
        );
        self::assertIsCallable(
            $this->registeredCallback('recurring/suspendSubscription')
        );
        self::assertIsCallable(
            $this->registeredCallback('recurring/activateSubscription')
        );
        self::assertIsCallable(
            $this->registeredCallback(
                'recurring/deleteUnassignedSubscription'
            )
        );
    }

    public function testWebhookCallbackRejectsEmptyPayload(): void
    {
        $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] = 'AJAX-TRANSMISSION';

        try {
            self::assertSame(
                ['success' => false],
                $this->registeredCallback('recurring/webhook')()
            );
            self::assertSame(400, http_response_code());
        } finally {
            unset($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']);
        }
    }

    private function registeredCallback(string $relativePath): callable
    {
        require dirname(__DIR__, 2) . '/ajax/' . $relativePath . '.php';

        $name = 'package_quiqqer_payment-paypal_ajax_'
            . str_replace('/', '_', $relativePath);
        $registered = QUI\Ajax::getRegisteredCallables();

        self::assertArrayHasKey($name, $registered);

        return $registered[$name]['callable'];
    }
}
