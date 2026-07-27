<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\Basket\Basket;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\Smarty\Collector;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\EventsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class EventsRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        EventsDouble::$plansInstalled = false;
        EventsDouble::$planOrder = false;
        EventsDouble::$nobodyUser = false;

        $ExpressPayment = $this->createMock(Payment::class);
        $ExpressPayment->method('isActive')->willReturn(true);
        EventsDouble::$ExpressPayment = $ExpressPayment;
    }

    protected function tearDown(): void
    {
        EventsDouble::$ExpressPayment = null;
    }

    public function testOrderProcessBasketRendersExpressButton(): void
    {
        $Collector = new Collector();
        $Basket = $this->createMock(Basket::class);
        $Basket->method('getId')->willReturn(17);
        $Order = new OrderDouble();
        $Order->uuidValue = 'ORDER-EVENT';

        EventsDouble::templateOrderProcessBasketEnd(
            $Collector,
            $Basket,
            $Order
        );

        self::assertStringContainsString(
            'data-qui-options-context="basket"',
            $Collector->getContent()
        );
        self::assertStringContainsString(
            'data-qui-options-basketid="17"',
            $Collector->getContent()
        );
        self::assertStringContainsString(
            'https://example.test/checkout',
            $Collector->getContent()
        );
    }

    public function testSimpleCheckoutRendersExpressButton(): void
    {
        $Collector = new Collector();
        $Order = new OrderDouble();
        $Order->uuidValue = 'ORDER-SIMPLE-EVENT';

        EventsDouble::templateOrderSimpleExpressButtons($Collector, $Order);

        self::assertStringContainsString(
            'data-qui-options-context="simple-checkout"',
            $Collector->getContent()
        );
        self::assertStringContainsString(
            'data-qui-options-orderid="ORDER-SIMPLE-EVENT"',
            $Collector->getContent()
        );
    }

    public function testSmallBasketRendersExpressButton(): void
    {
        $Collector = new Collector();
        $Order = new OrderDouble();
        $Basket = $this->createMock(Basket::class);
        $Basket->method('getId')->willReturn(23);
        $Basket->method('count')->willReturn(1);
        $Basket->method('getOrder')->willReturn($Order);
        $Basket->method('hasOrder')->willReturn(false);

        EventsDouble::templateOrderBasketSmallEnd($Collector, $Basket);

        self::assertStringContainsString(
            'data-qui-options-context="smallbasket"',
            $Collector->getContent()
        );
        self::assertStringContainsString(
            'data-qui-options-basketid="23"',
            $Collector->getContent()
        );
    }

    public function testPlanOrdersDoNotRenderExpressButtons(): void
    {
        EventsDouble::$planOrder = true;
        $Collector = new Collector();
        $Basket = $this->createMock(Basket::class);

        EventsDouble::templateOrderProcessBasketEnd(
            $Collector,
            $Basket,
            new OrderDouble()
        );

        self::assertSame('', $Collector->getContent());
    }
}
