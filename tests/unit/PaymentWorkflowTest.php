<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class PaymentWorkflowTest extends TestCase
{
    public function testUpdateStopsWhenOrderWasNotCreatedAtPayPal(): void
    {
        $Order = new OrderDouble();
        $Payment = new PaymentWorkflowDouble();

        $Payment->updatePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(0, $Payment->saveCount);
        self::assertContains(
            'PayPal :: Order cannot be updated since it has not been created yet',
            $Order->history
        );
    }

    public function testUpdateSendsPurchaseUnitPatchAndSavesOrder(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-1');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID(), 'amount' => ['value' => '12.00']]
            ]
        ];

        $Payment->updatePayPalOrder($Order);

        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_UPDATE_ORDER, $Payment->apiCalls[0]['request']);
        self::assertSame([
            [
                'op' => 'replace',
                'path' => '/purchase_units/@reference_id==\'' . $Order->getUUID() . '\'',
                'value' => $Payment->payPalData['purchase_units'][0]
            ]
        ], $Payment->apiCalls[0]['body']);
        self::assertSame(1, $Payment->saveCount);
        self::assertContains('PayPal :: Order successfully updated', $Order->history);
    }

    public function testUpdateSavesOrderAndPropagatesApiFailure(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-2');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = ['purchase_units' => [[]]];
        $Payment->apiException = new PayPalException('Update failed');

        try {
            $Payment->updatePayPalOrder($Order);
            self::fail('PayPalException was not propagated.');
        } catch (PayPalException $Exception) {
            self::assertSame($Payment->apiException, $Exception);
        }

        self::assertSame(1, $Payment->saveCount);
        self::assertContains(
            'PayPal :: PayPal API ERROR. Please check error logs.',
            $Order->history
        );
    }

    public function testOrderDetailsRequireStoredOrderId(): void
    {
        $Payment = new PaymentWorkflowDouble();

        self::assertFalse(
            $Payment->fetchPayPalOrderDetails(new OrderDouble())
        );
        self::assertSame([], $Payment->apiCalls);
    }

    public function testOrderDetailsReturnApiResponse(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-3');
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiResponse = ['id' => 'ORDER-3', 'status' => 'APPROVED'];

        self::assertSame(
            $Payment->apiResponse,
            $Payment->fetchPayPalOrderDetails($Order)
        );
        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_GET_ORDER, $Payment->apiCalls[0]['request']);
    }

    public function testOrderDetailsReturnFalseAfterApiFailure(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-4');
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiException = new PayPalException('Lookup failed');

        self::assertFalse($Payment->fetchPayPalOrderDetails($Order));
    }

    public function testVoidHandlesMissingAndExistingOrderIds(): void
    {
        $MissingOrder = new OrderDouble();
        $Payment = new PaymentWorkflowDouble();

        $Payment->voidOrder($MissingOrder);
        self::assertSame(1, $Payment->saveCount);
        self::assertContains(
            'PayPal :: Order cannot be voided because it has not been created yet or was voided before',
            $MissingOrder->history
        );

        $ExistingOrder = new OrderDouble();
        $ExistingOrder->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-5');
        $Payment->voidOrder($ExistingOrder);
        self::assertSame(2, $Payment->saveCount);
        self::assertContains('PayPal :: Order voided.', $ExistingOrder->history);
    }

    public function testAlreadyAuthorizedOrderStopsBeforeApiRequest(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_AUTHORIZATION_ID, 'AUTH-1');
        $Payment = new PaymentWorkflowDouble();

        $Payment->authorizePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(1, $Payment->saveCount);
    }

    public function testAuthorizeStoresSuccessfulAuthorization(): void
    {
        $Order = $this->createCalculatedOrder();
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER] = [
            'id' => 'AUTH-2',
            'state' => 'authorized'
        ];

        $Payment->authorizePayPalOrder($Order);

        self::assertSame('AUTH-2', $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_AUTHORIZATION_ID));
        self::assertSame([
            'amount' => [
                'total' => '12.50',
                'currency' => 'EUR'
            ]
        ], $Payment->apiCalls[0]['body']);
        self::assertSame(1, $Payment->saveCount);
    }

    public function testAuthorizeVoidsRejectedOrderAndReportsReason(): void
    {
        $Order = $this->createCalculatedOrder();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-REJECTED');
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER] = [
            'state' => 'denied',
            'reason_code' => 'RISK_DECLINE'
        ];

        $this->expectException(PayPalException::class);

        try {
            $Payment->authorizePayPalOrder($Order);
        } finally {
            self::assertContains(
                'PayPal :: Order was not authorized by PayPal. Reason: "RISK_DECLINE"',
                $Order->history
            );
            self::assertContains('PayPal :: Order voided.', $Order->history);
        }
    }

    public function testAlreadyCapturedOrderStopsBeforeApiRequest(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        $Payment = new PaymentWorkflowDouble();

        $Payment->capturePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(1, $Payment->saveCount);
    }

    public function testPendingCaptureStoresPaymentWithoutGatewayTransaction(): void
    {
        $Order = $this->createCalculatedOrder();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-PENDING');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID()]
            ]
        ];
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER] = [
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-PENDING',
                        'status' => Payment::PAYPAL_CAPTURE_STATE_PENDING,
                        'amount' => [
                            'value' => '12.50',
                            'currency_code' => 'EUR'
                        ]
                    ]]
                ]
            ]]
        ];

        $Payment->capturePayPalOrder($Order);

        self::assertSame(
            'CAPTURE-PENDING',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_CAPTURE_ID)
        );
        self::assertTrue($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL));
        self::assertTrue($Order->successfulStatusSet);
        self::assertContains(
            'PayPal :: Order capture was not completed immediately.'
            . ' Payment is PENDING and has to be added manually to the order or checked via cronjob.',
            $Order->history
        );
    }

    public function testCompletedCaptureCreatesGatewayTransaction(): void
    {
        $Order = $this->createCalculatedOrder();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_ORDER_ID,
            'ORDER-COMPLETED'
        );
        $Transaction = $this->createMock(
            \QUI\ERP\Accounting\Payments\Transactions\Transaction::class
        );
        $Transaction->expects(self::exactly(2))->method('setData');
        $Transaction->expects(self::once())->method('updateData');
        $Transaction->method('getTxId')->willReturn('TX-COMPLETED');

        $Payment = new PaymentWorkflowDouble();
        $Payment->CaptureTransaction = $Transaction;
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID()]
            ]
        ];
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER] = [
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-COMPLETED',
                        'status' => Payment::PAYPAL_CAPTURE_STATE_COMPLETED,
                        'amount' => [
                            'value' => '12.50',
                            'currency_code' => 'EUR'
                        ]
                    ]]
                ]
            ]]
        ];

        $Payment->capturePayPalOrder($Order);

        self::assertSame(12.5, $Payment->capturePurchase['amount']);
        self::assertSame('EUR', $Payment->capturePurchase['currencyCode']);
        self::assertSame($Order, $Payment->capturePurchase['order']);
        self::assertSame(1, $Order->refreshCount);
        self::assertContains(
            'PayPal :: Order capture was completed. Transaction '
            . 'TX-COMPLETED added.',
            $Order->history
        );
    }

    public function testCaptureRecoversPendingResultAfterApiException(): void
    {
        $Order = $this->createCalculatedOrder();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-RECOVERED');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID()]
            ]
        ];
        $Payment->apiExceptions[Payment::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER] = new PayPalException(
            'Capture response lost'
        );
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_GET_ORDER] = [
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-RECOVERED',
                        'status' => Payment::PAYPAL_CAPTURE_STATE_PENDING,
                        'amount' => [
                            'value' => '12.50',
                            'currency_code' => 'EUR'
                        ]
                    ]]
                ]
            ]]
        ];

        $Payment->capturePayPalOrder($Order);

        self::assertSame(
            'CAPTURE-RECOVERED',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_CAPTURE_ID)
        );
        self::assertContains(
            'PayPal :: Order capture REST request failed. But Order capture was still completed on PayPal site.'
            . ' Continuing payment process.',
            $Order->history
        );
    }

    public function testCaptureFailureVoidsOrderAndThrows(): void
    {
        $Order = $this->createCalculatedOrder();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-DENIED');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID()]
            ]
        ];
        $Payment->apiResponses[Payment::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER] = [
            'reason_code' => 'CAPTURE_DENIED'
        ];

        $this->expectException(PayPalException::class);

        try {
            $Payment->capturePayPalOrder($Order);
        } finally {
            self::assertContains('PayPal :: Order voided.', $Order->history);
            self::assertContains(
                'PayPal :: Order capture was not completed by PayPal. Reason code: "CAPTURE_DENIED"'
                . ' | Capture failed because: ',
                $Order->history
            );
        }
    }

    private function createCalculatedOrder(): OrderDouble
    {
        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn(new CalculationValue(12.5));

        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');

        $Order = new OrderDouble();
        $Order->PriceCalculation = $Calculations;
        $Order->CurrencyValue = $Currency;

        return $Order;
    }
}
