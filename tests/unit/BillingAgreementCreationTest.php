<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class BillingAgreementCreationTest extends TestCase
{
    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        $this->Payment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->Payment);
        BillingAgreementsDouble::$billingPlanId = 'PLAN-LEGACY';

        $Gateway = $this->createMock(Gateway::class);
        $Gateway->method('getSuccessUrl')->willReturn(
            'https://example.test/success?'
        );
        $Gateway->method('getCancelUrl')->willReturn(
            'https://example.test/cancel?'
        );
        BillingAgreementsDouble::$Gateway = $Gateway;
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        BillingAgreementsDouble::$Gateway = null;
    }

    public function testApprovalUrlIsStoredFromLegacyApiResponse(): void
    {
        $this->Payment->apiResponse = [
            'links' => [
                [
                    'rel' => 'self',
                    'href' => 'https://api.paypal.test/agreement'
                ],
                [
                    'rel' => 'approval_url',
                    'href' => 'https://paypal.test/approve'
                ]
            ]
        ];
        $Order = $this->order();

        self::assertSame(
            'https://paypal.test/approve',
            BillingAgreementsDouble::createBillingAgreement($Order)
        );
        self::assertSame(
            'PLAN-LEGACY',
            $Order->getPaymentDataEntry(
                Payment::ATTR_PAYPAL_BILLING_PLAN_ID
            )
        );
        self::assertSame(
            'https://paypal.test/approve',
            $Order->getPaymentDataEntry(
                Payment::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL
            )
        );

        $call = $this->Payment->apiCalls[0];
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT,
            $call['request']
        );
        self::assertSame('PLAN-LEGACY', $call['body']['plan']['id']);
        self::assertSame(
            'https://example.test/success',
            $call['body']['override_merchant_preferences']['return_url']
        );
        self::assertSame(
            'customer@example.test',
            $call['body']['payer']['payer_info']['email']
        );
        self::assertNotEmpty($call['body']['start_date']);
    }

    public function testExistingApprovalUrlAvoidsAgreementApiRequest(): void
    {
        $Order = $this->order();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL,
            'https://paypal.test/cached'
        );

        self::assertSame(
            'https://paypal.test/cached',
            BillingAgreementsDouble::createBillingAgreement($Order)
        );
        self::assertSame([], $this->Payment->apiCalls);
    }

    public function testMissingApprovalLinkIsRejected(): void
    {
        $this->Payment->apiResponse = [
            'links' => [
                [
                    'rel' => 'self',
                    'href' => 'https://api.paypal.test/agreement'
                ]
            ]
        ];

        $this->expectException(PayPalException::class);

        BillingAgreementsDouble::createBillingAgreement($this->order());
    }

    public function testEmptyLegacyResponseIsRejected(): void
    {
        $this->Payment->apiResponse = [];

        $this->expectException(PayPalException::class);

        BillingAgreementsDouble::createBillingAgreement($this->order());
    }

    public function testLegacyApiFailureIsPropagated(): void
    {
        $this->Payment->apiException = new PayPalException('API failure');

        $this->expectException(PayPalException::class);
        $this->expectExceptionMessage('API failure');

        BillingAgreementsDouble::createBillingAgreement($this->order());
    }

    private function order(): OrderDouble
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getAttribute')->willReturnMap([
            ['email', 'customer@example.test'],
            ['firstname', 'Jane'],
            ['lastname', 'Doe']
        ]);

        $Order = new OrderDouble();
        $Order->CustomerValue = $Customer;

        return $Order;
    }
}
