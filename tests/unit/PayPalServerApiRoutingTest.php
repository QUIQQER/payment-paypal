<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalSystemException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PayPalServerApiPaymentDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PayPalServerClientDouble;

final class PayPalServerApiRoutingTest extends TestCase
{
    public function testCreateOrderUsesServerSdkClient(): void
    {
        $Client = new PayPalServerClientDouble();
        $Payment = new PayPalServerApiPaymentDouble($Client);
        $body = ['intent' => 'CAPTURE'];

        $response = $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_CREATE_ORDER,
            $body,
            []
        );

        self::assertSame($Client->response, $response);
        self::assertSame([
            'operation' => 'createOrder',
            'arguments' => [$body]
        ], $Client->calls[0]);
    }

    public function testGetOrderUsesStoredOrderId(): void
    {
        $Client = new PayPalServerClientDouble();
        $Payment = new PayPalServerApiPaymentDouble($Client);

        $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_GET_ORDER,
            [],
            [Payment::ATTR_PAYPAL_ORDER_ID => 'ORDER-1']
        );

        self::assertSame([
            'operation' => 'getOrder',
            'arguments' => ['ORDER-1']
        ], $Client->calls[0]);
    }

    public function testPatchOrderUsesStoredOrderIdAndBody(): void
    {
        $Client = new PayPalServerClientDouble();
        $Payment = new PayPalServerApiPaymentDouble($Client);
        $body = [['op' => 'replace']];

        $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_UPDATE_ORDER,
            $body,
            [Payment::ATTR_PAYPAL_ORDER_ID => 'ORDER-2']
        );

        self::assertSame([
            'operation' => 'patchOrder',
            'arguments' => ['ORDER-2', $body]
        ], $Client->calls[0]);
    }

    public function testCaptureOrderUsesStoredOrderIdAndBody(): void
    {
        $Client = new PayPalServerClientDouble();
        $Payment = new PayPalServerApiPaymentDouble($Client);

        $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER,
            [],
            [Payment::ATTR_PAYPAL_ORDER_ID => 'ORDER-3']
        );

        self::assertSame([
            'operation' => 'captureOrder',
            'arguments' => ['ORDER-3', []]
        ], $Client->calls[0]);
    }

    public function testRefundUsesStoredCaptureIdAndV2Body(): void
    {
        $Client = new PayPalServerClientDouble();
        $Payment = new PayPalServerApiPaymentDouble($Client);
        $body = [
            'amount' => [
                'value' => '10.00',
                'currency_code' => 'EUR'
            ],
            'note_to_payer' => 'Refund reason'
        ];

        $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_REFUND_ORDER,
            $body,
            [Payment::ATTR_PAYPAL_CAPTURE_ID => 'CAPTURE-1']
        );

        self::assertSame([
            'operation' => 'refundCapturedPayment',
            'arguments' => ['CAPTURE-1', $body]
        ], $Client->calls[0]);
    }

    public function testApiFailureWithArrayContextKeepsOriginalException(): void
    {
        $Client = new PayPalServerClientDouble();
        $Client->fail = true;
        $Payment = new PayPalServerApiPaymentDouble($Client);

        $this->expectException(PayPalSystemException::class);
        $this->expectExceptionMessage('PayPal unavailable');

        $Payment->payPalApiRequest(
            Payment::PAYPAL_REQUEST_TYPE_GET_ORDER,
            [],
            [Payment::ATTR_PAYPAL_ORDER_ID => 'ORDER-4'],
            true
        );
    }
}
