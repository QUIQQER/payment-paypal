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

        self::assertSame('PAYPAL-ORDER-ID', $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_ID));
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
        self::assertNull($Payment->requestType);
        self::assertFalse($Payment->saved);
    }
}
