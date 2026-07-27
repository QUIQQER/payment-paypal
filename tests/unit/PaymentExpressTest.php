<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Payments\Types\Payment as PaymentType;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\ERP\Shipping\Shipping;
use QUI\ERP\Shipping\Types\ShippingEntry;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class PaymentExpressTest extends TestCase
{
    protected function tearDown(): void
    {
        Shipping::getInstance()->setValidShippingEntries([]);
    }

    public function testMetadataDescribesUniqueExpressPayment(): void
    {
        $Payment = new PaymentExpress();

        self::assertNotSame('', $Payment->getTitle());
        self::assertNotSame('', $Payment->getDescription());
        self::assertTrue($Payment->isUnique());
    }

    public function testVisibilityRequiresMatchingPaymentType(): void
    {
        $ConfiguredPayment = $this->createMock(PaymentType::class);
        $ConfiguredPayment->method('getPaymentType')->willReturn(
            new PaymentExpress()
        );
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getPayment')->willReturn($ConfiguredPayment);

        self::assertTrue((new PaymentExpress())->isVisible($Order));
    }

    public function testVisibilityRejectsOrderWithoutPayment(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getPayment')->willReturn(null);

        self::assertFalse((new PaymentExpress())->isVisible($Order));
    }

    public function testDefaultShippingIsAppliedAndOrderIsSaved(): void
    {
        $ShippingEntry = new ShippingEntry(null);
        Shipping::getInstance()->setValidShippingEntries([$ShippingEntry]);
        $Order = new OrderDouble();

        (new PaymentExpress())->setDefaultShipping($Order);

        self::assertSame($ShippingEntry, $Order->Shipping);
        self::assertNotNull($Order->updateUser);
    }

    public function testMissingDefaultShippingLeavesOrderUntouched(): void
    {
        $Order = new OrderDouble();

        (new PaymentExpress())->setDefaultShipping($Order);

        self::assertNull($Order->Shipping);
        self::assertNull($Order->updateUser);
    }
}
