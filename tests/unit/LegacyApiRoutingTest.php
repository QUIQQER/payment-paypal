<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\PayPalSystemException;
use QUI\ERP\Payments\PayPal\PhpSdk\Core\PayPalHttpClient;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\LegacyApiPaymentDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class LegacyApiRoutingTest extends TestCase
{
    public static function requestProvider(): array
    {
        return [
            'execute payment' => [
                Payment::PAYPAL_REQUEST_TYPE_EXECUTE_ORDER,
                Payment::ATTR_PAYPAL_PAYMENT_ID,
                'PAYMENT-1',
                'PaymentExecuteRequest'
            ],
            'authorize order' => [
                Payment::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER,
                Payment::ATTR_PAYPAL_ORDER_ID,
                'ORDER-1',
                'OrderAuthorizeRequest'
            ],
            'void order' => [
                Payment::PAYPAL_REQUEST_TYPE_VOID_ORDER,
                Payment::ATTR_PAYPAL_ORDER_ID,
                'ORDER-2',
                'OrderVoidRequest'
            ],
            'create plan' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN,
                null,
                null,
                'PlanCreateRequest'
            ],
            'update plan' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
                RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID,
                'PLAN-1',
                'PlanUpdateRequest'
            ],
            'get plan' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_PLAN,
                RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID,
                'PLAN-2',
                'PlanGetRequest'
            ],
            'create agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT,
                null,
                null,
                'AgreementCreateRequest'
            ],
            'update agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID,
                'PLAN-3',
                'PlanUpdateRequest'
            ],
            'execute agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN,
                'TOKEN-1',
                'AgreementExecuteRequest'
            ],
            'bill agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
                'AGREEMENT-1',
                'AgreementBillBalanceRequest'
            ],
            'cancel agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
                'AGREEMENT-2',
                'AgreementCancelRequest'
            ],
            'suspend agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_SUSPEND_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
                'AGREEMENT-3',
                'AgreementSuspendRequest'
            ],
            'resume agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_RESUME_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
                'AGREEMENT-4',
                'AgreementReActivateRequest'
            ],
            'get agreement' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
                'AGREEMENT-5',
                'AgreementGetRequest'
            ],
            'refund sale' => [
                RecurringPayment::PAYPAL_REQUEST_TYPE_SALE_REFUND,
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID,
                'SALE-1',
                'SaleRefundRequest'
            ]
        ];
    }

    #[DataProvider('requestProvider')]
    public function testLegacyRequestTypesAreMapped(
        string $requestType,
        ?string $dataKey,
        ?string $dataValue,
        string $requestClass
    ): void {
        $Client = new PayPalHttpClient();
        $Client->result = (object)[
            'id' => 'RESULT-1'
        ];
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);
        $Order = new OrderDouble();

        if ($dataKey !== null) {
            $Order->setPaymentData($dataKey, $dataValue);
        }

        self::assertSame(
            ['id' => 'RESULT-1'],
            $Payment->payPalApiRequest(
                $requestType,
                ['marker' => true],
                $Order
            )
        );

        $Request = $Client->requests[0];

        self::assertStringEndsWith('\\' . $requestClass, $Request::class);
        self::assertSame(['marker' => true], $Request->body);

        if ($dataKey !== null) {
            self::assertSame([$dataValue], $Request->arguments);
        }
    }

    public function testPlanListAndAgreementDatesAreApplied(): void
    {
        $Client = new PayPalHttpClient();
        $Client->result = (object)[];
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);

        $Payment->payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS,
            [],
            [
                'page' => 2,
                'page_size' => 10,
                'status' => 'ACTIVE',
                'total_required' => 'yes'
            ]
        );
        $Payment->payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT_TRANSACTIONS,
            [],
            [
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => 'AGREEMENT-DATES',
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31'
            ]
        );

        self::assertSame([
            'page' => 2,
            'pageSize' => 10,
            'status' => 'ACTIVE',
            'totalRequired' => 'yes'
        ], $Client->requests[0]->parameters);
        self::assertSame([
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-31'
        ], $Client->requests[1]->parameters);
    }

    public function testTransactionObjectProvidesLegacyReference(): void
    {
        $Transaction = $this->createMock(
            \QUI\ERP\Accounting\Payments\Transactions\Transaction::class
        );
        $Transaction->method('getData')->willReturn('SALE-TRANSACTION');
        $Client = new PayPalHttpClient();
        $Client->result = (object)[];
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);

        $Payment->payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_SALE_REFUND,
            [],
            $Transaction
        );

        self::assertSame(
            ['SALE-TRANSACTION'],
            $Client->requests[0]->arguments
        );
    }

    public function testUnknownLegacyRequestIsRejected(): void
    {
        $this->expectException(PayPalException::class);

        (new LegacyApiPaymentDouble())->payPalApiRequest(
            'unknown-request',
            [],
            []
        );
    }

    public function testClientFailureCanBeReportedAsSystemException(): void
    {
        $Client = new PayPalHttpClient();
        $Client->exception = new Exception('Legacy API unavailable', 503);
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);

        try {
            $Payment->payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN,
                [],
                [],
                true
            );
            self::fail('The legacy client failure was not propagated.');
        } catch (PayPalSystemException $Exception) {
            self::assertSame('Legacy API unavailable', $Exception->getMessage());
            self::assertSame(503, $Exception->getCode());
        }
    }

    public function testClientFailureCanBeReportedAsPublicException(): void
    {
        $Client = new PayPalHttpClient();
        $Client->exception = new Exception('Legacy API unavailable');
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);

        $this->expectException(PayPalException::class);

        $Payment->payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN,
            [],
            []
        );
    }

    public function testNonArrayLegacyResponseIsRejected(): void
    {
        $Client = new PayPalHttpClient();
        $Client->result = 'invalid-response';
        $Payment = new LegacyApiPaymentDouble();
        $Payment->useLegacyClient($Client);

        $this->expectException(PayPalException::class);

        $Payment->payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN,
            [],
            []
        );
    }
}
