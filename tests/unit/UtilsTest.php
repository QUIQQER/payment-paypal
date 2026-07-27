<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Products\Interfaces\PriceFactorInterface;
use QUI\ERP\Shipping\Shipping;
use QUI\ERP\Shipping\Types\ShippingEntry;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class UtilsTest extends TestCase
{
    protected function tearDown(): void
    {
        $Shipping = Shipping::getInstance();
        $Shipping->setShippingPriceFactor(null);
        $Shipping->setValidShippingEntries([]);
    }

    public function testFormatPriceUsesTwoDecimalPlaces(): void
    {
        self::assertSame('10.00', Utils::formatPrice(10));
        self::assertSame('10.13', Utils::formatPrice(10.125));
    }

    public function testProjectUrlHasNoTrailingSlash(): void
    {
        $url = Utils::getProjectUrl();

        self::assertNotSame('', $url);
        self::assertStringStartsWith('http', $url);
        self::assertStringEndsNotWith('/', $url);
    }

    public function testSaveOrderUsesSystemUser(): void
    {
        $Order = new OrderDouble();

        Utils::saveOrder($Order);

        self::assertSame(QUI::getUsers()->getSystemUser(), $Order->updateUser);
    }

    public function testHistoryTextUsesPayPalLocale(): void
    {
        $text = Utils::getHistoryText('capture_id', ['captureId' => 'CAPTURE-1']);

        self::assertStringContainsString('CAPTURE-1', $text);
    }

    public function testShippingCostsAreReturnedFromShippingService(): void
    {
        $PriceFactor = $this->createMock(PriceFactorInterface::class);
        Shipping::getInstance()->setShippingPriceFactor($PriceFactor);

        self::assertSame(
            $PriceFactor,
            Utils::getShippingCostsByOrder(new OrderDouble())
        );
    }

    public function testFirstValidExpressShippingEntryIsReturned(): void
    {
        $First = new ShippingEntry(null);
        $Second = new ShippingEntry(null);
        Shipping::getInstance()->setValidShippingEntries([$First, $Second]);

        self::assertSame(
            $First,
            Utils::getDefaultExpressShipping(new OrderDouble())
        );
    }

    public function testMissingExpressShippingEntryReturnsFalse(): void
    {
        self::assertFalse(
            Utils::getDefaultExpressShipping(new OrderDouble())
        );
    }
}
