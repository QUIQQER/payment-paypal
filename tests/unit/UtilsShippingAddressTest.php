<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Countries\Country;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Shipping\Shipping;
use QUI\ERP\Shipping\Types\ShippingEntry;

final class UtilsShippingAddressTest extends TestCase
{
    protected function tearDown(): void
    {
        Shipping::getInstance()->setShipping(null);
    }

    public function testShippingAddressIsConvertedForPayPal(): void
    {
        $Country = $this->createMock(Country::class);
        $Country->method('getCode')->willReturn('de');

        $Address = $this->createMock(QUI\ERP\Address::class);
        $Address->method('getName')->willReturn('Jane Doe');
        $Address->method('getAttribute')->willReturnMap([
            ['street_no', 'Example Street 1'],
            ['city', 'Berlin'],
            ['zip', '10115']
        ]);
        $Address->method('getPhone')->willReturn('+49 30 123456');
        $Address->method('getCountry')->willReturn($Country);

        $Shipping = new ShippingEntry($Address);
        Shipping::getInstance()->setShipping($Shipping);

        $Order = $this->createMock(AbstractOrder::class);

        self::assertSame([
            'recipient_name' => 'Jane Doe',
            'line1' => 'Example Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'phone' => '+49 30 123456',
            'country_code' => 'DE'
        ], Utils::getPayPalShippingAddressDataByOrder($Order));
    }

    public function testMissingShippingAddressReturnsFalse(): void
    {
        $Shipping = new ShippingEntry(null);
        Shipping::getInstance()->setShipping($Shipping);

        $Order = $this->createMock(AbstractOrder::class);

        self::assertFalse(Utils::getPayPalShippingAddressDataByOrder($Order));
    }
}
