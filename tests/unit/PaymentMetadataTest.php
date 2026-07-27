<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Payment;

final class PaymentMetadataTest extends TestCase
{
    public function testPaymentMetadataIsAvailable(): void
    {
        $Payment = new Payment();

        self::assertNotSame('', $Payment->getTitle());
        self::assertNotSame('', $Payment->getDescription());
        self::assertStringEndsWith(
            '/quiqqer/payment-paypal/bin/images/Payment.png',
            $Payment->getIcon()
        );
        self::assertTrue($Payment->isGateway());
        self::assertTrue($Payment->refundSupport());
    }

    public function testUnknownOrderIsNotSuccessful(): void
    {
        self::assertFalse(
            (new Payment())->isSuccessful('phpunit-missing-paypal-order')
        );
    }
}
