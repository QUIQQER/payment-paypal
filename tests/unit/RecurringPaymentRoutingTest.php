<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class RecurringPaymentRoutingTest extends TestCase
{
    public function testSubscriptionIdTakesPrecedenceOverLegacyAgreementId(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID, 'SUBSCRIPTION-ID');
        $Order->setPaymentData(Payment::ATTR_PAYPAL_BILLING_AGREEMENT_ID, 'AGREEMENT-ID');

        self::assertSame(
            'SUBSCRIPTION-ID',
            (new Payment())->getSubscriptionIdByOrder($Order)
        );
    }

    public function testLegacyAgreementIdRemainsAvailableAsFallback(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_BILLING_AGREEMENT_ID, 'AGREEMENT-ID');

        self::assertSame(
            'AGREEMENT-ID',
            (new Payment())->getSubscriptionIdByOrder($Order)
        );
    }

    public function testMissingSubscriptionReferencesReturnFalse(): void
    {
        self::assertFalse(
            (new Payment())->getSubscriptionIdByOrder(new OrderDouble())
        );
    }
}
