<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OneTimePaymentDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class OneTimePaymentTest extends TestCase
{
    public function testCreatePayPalOrderPersistsCreatedOrderId(): void
    {
        $Order = new OrderDouble();

        $Payment = new OneTimePaymentDouble();
        $Payment->createPayPalOrder($Order);

        self::assertSame('PAYPAL-ORDER-ID-NEW', $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_ID));
        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_CREATE_ORDER, $Payment->requestType);
        self::assertSame(['intent' => 'CAPTURE'], $Payment->requestBody);
        self::assertSame($Order, $Payment->requestTransaction);
        self::assertTrue($Payment->saved);
        self::assertFalse($Payment->updated);
    }

    public function testCreatePayPalOrderSavesOrderAfterApiFailure(): void
    {
        $Order = new OrderDouble();

        $Payment = new OneTimePaymentDouble();
        $Payment->apiException = new PayPalException('PayPal unavailable');

        try {
            $Payment->createPayPalOrder($Order);
            self::fail('The PayPal exception was not propagated.');
        } catch (PayPalException $Exception) {
            self::assertSame($Payment->apiException, $Exception);
        }

        self::assertTrue($Payment->saved);
    }

    public function testExistingPayPalOrderUsesUpdatePath(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'PAYPAL-ORDER-ID');

        $Payment = new OneTimePaymentDouble();
        $Payment->createPayPalOrder($Order);

        self::assertTrue($Payment->updated);
        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_GET_ORDER, $Payment->requestType);
        self::assertFalse($Payment->saved);
    }

    /**
     * @dataProvider nonReusableOrderStatusProvider
     */
    public function testApprovedOrVoidedPayPalOrderIsReplaced(string $status): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'PAYPAL-ORDER-ID-OLD');

        $Payment = new OneTimePaymentDouble();
        $Payment->existingOrderStatus = $status;
        $Payment->createPayPalOrder($Order);

        self::assertSame(
            'PAYPAL-ORDER-ID-NEW',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_ID)
        );
        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_CREATE_ORDER, $Payment->requestType);
        self::assertFalse($Payment->updated);
        self::assertTrue($Payment->saved);
    }

    public static function nonReusableOrderStatusProvider(): array
    {
        return [
            'approved' => [Payment::PAYPAL_ORDER_STATE_APPROVED_V2],
            'voided' => [Payment::PAYPAL_ORDER_STATE_VOIDED_V2]
        ];
    }

    public function testCompletedPayPalOrderIsNeverReplaced(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'PAYPAL-ORDER-ID-COMPLETED');

        $Payment = new OneTimePaymentDouble();
        $Payment->existingOrderStatus = Payment::PAYPAL_ORDER_STATE_COMPLETED;
        $Payment->createPayPalOrder($Order);

        self::assertSame(
            'PAYPAL-ORDER-ID-COMPLETED',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_ID)
        );
        self::assertTrue($Payment->updated);
        self::assertFalse($Payment->saved);
    }
}
