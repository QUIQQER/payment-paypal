<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalSystemException;
use QUI\ERP\Payments\PayPal\Utils;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PendingCapturePaymentDouble;

final class PendingCaptureTest extends TestCase
{
    public function testOrdersWithoutPayPalIdAreSkippedUntilAnIdIsAssigned(): void
    {
        $Order = new OrderDouble();
        $EmptyOrder = new OrderDouble();
        $EmptyOrder->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, '');
        $Payment = $this->paymentWithOrder($Order);
        $Payment->rows[] = ['id' => 2];
        $Payment->orders[2] = $EmptyOrder;

        $Payment->checkPendingCaptures();

        self::assertSame(0, $Payment->apiRequestCount);
        self::assertSame(0, $Payment->saveCount);
        self::assertSame([], $Payment->purchase);
        self::assertSame([], $Order->history);
        self::assertSame([], $EmptyOrder->history);
        self::assertFalse((bool)$Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST));
        self::assertFalse((bool)$EmptyOrder->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST));

        $EmptyOrder->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-PENDING');
        $Payment->checkPendingCaptures();

        self::assertSame(1, $Payment->apiRequestCount);
    }

    public function testCompletedCapturesCreateCombinedTransaction(): void
    {
        $Order = $this->order();
        $Transaction = $this->createMock(Transaction::class);
        $Transaction->expects(self::exactly(2))
            ->method('setData')
            ->willReturnCallback(
                static function (string $key, mixed $value): void {
                    $expected = [
                        Payment::ATTR_PAYPAL_ORDER_ID => 'ORDER-PENDING',
                        Payment::ATTR_PAYPAL_CAPTURE_ID => 'CAPTURE-PENDING'
                    ];
                    self::assertSame($expected[$key], $value);
                }
            );
        $Transaction->expects(self::once())->method('updateData');
        $Transaction->method('getTxId')->willReturn('TRANSACTION-PENDING');

        $Payment = $this->paymentWithOrder($Order);
        $Payment->Transaction = $Transaction;
        $Payment->apiResponse = [
            'purchase_units' => [[
                'payments' => [
                    'captures' => [
                        [
                            'status' => Payment::PAYPAL_CAPTURE_STATE_COMPLETED,
                            'amount' => [
                                'value' => '10.25',
                                'currency_code' => 'EUR'
                            ]
                        ],
                        [
                            'status' => Payment::PAYPAL_CAPTURE_STATE_COMPLETED,
                            'amount' => [
                                'value' => '4.75',
                                'currency_code' => 'EUR'
                            ]
                        ]
                    ]
                ]
            ]]
        ];

        $Payment->checkPendingCaptures();

        self::assertSame(15.0, $Payment->purchase['amount']);
        self::assertSame('EUR', $Payment->purchase['currencyCode']);
        self::assertSame($Order, $Payment->purchase['order']);
        self::assertSame(1, $Payment->saveCount);
        self::assertContains(
            Utils::getHistoryText('order.capture.pending_completed', [
                'transactionId' => 'TRANSACTION-PENDING'
            ]),
            $Order->history
        );
    }

    public function testMissingPayPalResourceMarksOrderPermanently(): void
    {
        $Order = $this->order();
        $Payment = $this->paymentWithOrder($Order);
        $Payment->apiException = new PayPalSystemException(json_encode([
            'name' => Payment::PAYPAL_API_EXCEPTION_MESSAGE_RESOURCE_NOT_FOUND
        ]));

        $Payment->checkPendingCaptures();

        self::assertTrue(
            $Order->getPaymentDataEntry(
                Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST
            )
        );
        self::assertSame(1, $Payment->saveCount);
        self::assertNotEmpty($Order->history);
    }

    public function testIgnoredAndIncompleteOrdersCreateNoTransaction(): void
    {
        $IgnoredOrder = $this->order();
        $IgnoredOrder->setPaymentData(
            Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST,
            true
        );
        $IncompleteOrder = $this->order();
        $Payment = new PendingCapturePaymentDouble();
        $Payment->rows = [
            ['id' => 1],
            ['id' => 2]
        ];
        $Payment->orders = [
            1 => $IgnoredOrder,
            2 => $IncompleteOrder
        ];
        $Payment->apiResponse = [
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'status' => 'PENDING',
                        'amount' => [
                            'value' => '10.00',
                            'currency_code' => 'EUR'
                        ]
                    ]]
                ]
            ]]
        ];

        $Payment->checkPendingCaptures();

        self::assertSame([], $Payment->purchase);
        self::assertSame(0, $Payment->saveCount);
    }

    public function testHttpFailureWithStructuredErrorCodeStillMarksMissingPayPalOrder(): void
    {
        $Order = $this->order();
        $Payment = $this->paymentWithOrder($Order);
        $Payment->apiException = new PayPalSystemException(
            'PayPal API returned an unsuccessful HTTP response.',
            404,
            ['paypalError' => Payment::PAYPAL_API_EXCEPTION_MESSAGE_RESOURCE_NOT_FOUND]
        );
        $Payment->checkPendingCaptures();
        self::assertTrue($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST));
        self::assertSame(1, $Payment->saveCount);
    }

    public function testMissingPaymentTypesAndEmptyResponsesAreIgnored(): void
    {
        $Payment = new PendingCapturePaymentDouble();
        $Payment->paymentTypeIds = [];
        $Payment->checkPendingCaptures();

        self::assertSame([], $Payment->purchase);

        $Payment = $this->paymentWithOrder($this->order());
        $Payment->apiResponse = false;
        $Payment->checkPendingCaptures();
        self::assertSame([], $Payment->purchase);

        $Payment->apiResponse = ['purchase_units' => [[]]];
        $Payment->checkPendingCaptures();
        self::assertSame([], $Payment->purchase);
    }

    private function paymentWithOrder(
        OrderDouble $Order
    ): PendingCapturePaymentDouble {
        $Payment = new PendingCapturePaymentDouble();
        $Payment->rows = [
            ['id' => 1]
        ];
        $Payment->orders = [
            1 => $Order
        ];

        return $Payment;
    }

    private function order(): OrderDouble
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-PENDING');
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_CAPTURE_ID,
            'CAPTURE-PENDING'
        );

        return $Order;
    }
}
