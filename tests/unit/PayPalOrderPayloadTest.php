<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Countries\Country;
use QUI\ERP\Accounting\Article;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Accounting\PriceFactors\Factor;
use QUI\ERP\Accounting\PriceFactors\FactorList;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Settings;
use QUI\ERP\Shipping\Shipping;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentPayloadDouble;

final class PayPalOrderPayloadTest extends TestCase
{
    public function testOrderUsesModernPayPalWalletPaymentSource(): void
    {
        $Config = Settings::getConfig();
        $displayBasket = $Config->get('payment', 'display_paypal_basket');
        $Config->setValue('payment', 'display_paypal_basket', 0);

        try {
            $Gateway = $this->createMock(Gateway::class);
            $Gateway->method('getSuccessUrl')->willReturn('https://example.com/success?');
            $Gateway->method('getCancelUrl')->willReturn('https://example.com/cancel?');

            $Customer = $this->createMock(QUI\ERP\User::class);
            $Customer->method('getAttribute')->willReturnMap([
                ['RUNTIME_NETTO_BRUTTO_STATUS', 2]
            ]);

            $Currency = $this->createMock(Currency::class);
            $Currency->method('getCode')->willReturn('EUR');

            $Calculations = $this->createMock(Calculations::class);
            $Calculations->method('getSum')->willReturn(new CalculationValue(119));
            $Calculations->method('getSubSum')->willReturn(new CalculationValue(100));
            $Calculations->method('getVatSum')->willReturn(new CalculationValue(19));

            $Articles = $this->createMock(ArticleList::class);
            $Articles->method('getPriceFactors')->willReturn(new FactorList());

            $Country = $this->createMock(Country::class);
            $Country->method('getCode')->willReturn('DE');

            $DeliveryAddress = $this->createMock(QUI\ERP\Address::class);
            $DeliveryAddress->method('getAttribute')->willReturnMap([
                ['firstname', 'Jane'],
                ['lastname', 'Doe'],
                ['street_no', 'Example Street 1'],
                ['city', 'Berlin'],
                ['zip', '10115']
            ]);
            $DeliveryAddress->method('getName')->willReturn('Jane Doe');
            $DeliveryAddress->method('getCountry')->willReturn($Country);

            $Order = $this->createMock(AbstractOrder::class);
            $Order->method('getCustomer')->willReturn($Customer);
            $Order->method('getUUID')->willReturn('ORDER-UUID');
            $Order->method('getPrefixedId')->willReturn('ORDER-42');
            $Order->method('getPriceCalculation')->willReturn($Calculations);
            $Order->method('getCurrency')->willReturn($Currency);
            $Order->method('getArticles')->willReturn($Articles);
            $Order->method('getDeliveryAddress')->willReturn($DeliveryAddress);

            $payload = (new PaymentPayloadDouble($Gateway))->buildPayPalDataForOrder($Order);

            self::assertArrayNotHasKey('payer', $payload);
            self::assertArrayNotHasKey('redirect_urls', $payload);
            self::assertSame([
                'experience_context' => [
                    'return_url' => 'https://example.com/success',
                    'cancel_url' => 'https://example.com/cancel'
                ],
                'name' => [
                    'given_name' => 'Jane',
                    'surname' => 'Doe'
                ]
            ], $payload['payment_source']['paypal']);
        } finally {
            if ($displayBasket === null) {
                $Config->del('payment', 'display_paypal_basket');
            } else {
                $Config->setValue('payment', 'display_paypal_basket', $displayBasket);
            }
        }
    }

    public function testDetailedBasketContainsModernItemBreakdown(): void
    {
        $Config = Settings::getConfig();
        $displayBasket = $Config->get('payment', 'display_paypal_basket');
        $Config->setValue('payment', 'display_paypal_basket', 1);

        try {
            $Gateway = $this->createMock(Gateway::class);
            $Gateway->method('getSuccessUrl')->willReturn('https://example.com/success?');
            $Gateway->method('getCancelUrl')->willReturn('https://example.com/cancel?');

            $Customer = $this->createMock(QUI\ERP\User::class);
            $Customer->method('getAttribute')->willReturnMap([
                ['RUNTIME_NETTO_BRUTTO_STATUS', 1]
            ]);
            $Customer->method('getUUID')->willReturn('payload-net-customer');

            $Currency = $this->createMock(Currency::class);
            $Currency->method('getCode')->willReturn('EUR');

            $Article = $this->createMock(Article::class);
            $Article->method('toArray')->willReturn([
                'calculated' => [
                    'price' => 10.0
                ]
            ]);
            $Article->method('getTitle')->willReturn('');
            $Article->method('getArticleNo')->willReturn('SKU-10');
            $Article->method('getId')->willReturn(10);
            $Article->method('getQuantity')->willReturn(2);
            $Article->method('getDescription')->willReturn(
                str_repeat('D', 140)
            );

            $Calculations = $this->createMock(Calculations::class);
            $Calculations->method('getSum')->willReturn(new CalculationValue(23.8));
            $Calculations->method('getSubSum')->willReturn(new CalculationValue(20));
            $Calculations->method('getNettoSubSum')->willReturn(new CalculationValue(20));
            $Calculations->method('getVatSum')->willReturn(new CalculationValue(3.8));
            $Calculations->method('getArticles')->willReturn([$Article]);

            $Articles = $this->createMock(ArticleList::class);
            $Articles->method('getPriceFactors')->willReturn(new FactorList());

            $Country = $this->createMock(Country::class);
            $Country->method('getCode')->willReturn('DE');

            $DeliveryAddress = $this->createMock(QUI\ERP\Address::class);
            $DeliveryAddress->method('getAttribute')->willReturnMap([
                ['firstname', 'Jane'],
                ['lastname', 'Doe'],
                ['street_no', 'Example Street 1'],
                ['city', 'Berlin'],
                ['zip', '10115']
            ]);
            $DeliveryAddress->method('getName')->willReturn('Jane Doe');
            $DeliveryAddress->method('getCountry')->willReturn($Country);

            $Order = $this->createMock(AbstractOrder::class);
            $Order->method('getCustomer')->willReturn($Customer);
            $Order->method('getUUID')->willReturn('ORDER-DETAILED');
            $Order->method('getPrefixedId')->willReturn('ORDER-43');
            $Order->method('getPriceCalculation')->willReturn($Calculations);
            $Order->method('getCurrency')->willReturn($Currency);
            $Order->method('getArticles')->willReturn($Articles);
            $Order->method('getDeliveryAddress')->willReturn($DeliveryAddress);

            $payload = (new PaymentPayloadDouble($Gateway))
                ->buildPayPalDataForOrder($Order);
            $unit = $payload['purchase_units'][0];

            self::assertSame('SKU-10', $unit['items'][0]['name']);
            self::assertSame('SKU-10', $unit['items'][0]['sku']);
            self::assertSame(127, strlen($unit['items'][0]['description']));
            self::assertSame([
                'value' => '20.00',
                'currency_code' => 'EUR'
            ], $unit['amount']['breakdown']['item_total']);
            self::assertSame([
                'value' => '3.80',
                'currency_code' => 'EUR'
            ], $unit['amount']['breakdown']['tax_total']);
            self::assertSame(
                'Example Street 1',
                $unit['shipping']['address']['address_line_1']
            );
        } finally {
            if ($displayBasket === null) {
                $Config->del('payment', 'display_paypal_basket');
            } else {
                $Config->setValue('payment', 'display_paypal_basket', $displayBasket);
            }
        }
    }

    #[DataProvider('basketAmounts')]
    public function testOnlyConsistentBasketDetailsAreSent(
        bool $net,
        array $positions,
        float $subtotal,
        float $tax,
        float $total,
        ?float $shipping,
        bool $sendDetails
    ): void {
        $Config = Settings::getConfig();
        $displayBasket = $Config->get('payment', 'display_paypal_basket');
        $Config->setValue('payment', 'display_paypal_basket', 1);
        $Shipping = Shipping::getInstance();
        $originalShipping = $Shipping->getShippingPriceFactor($this->createMock(AbstractOrder::class));

        try {
            $Gateway = $this->createMock(Gateway::class);
            $Gateway->method('getSuccessUrl')->willReturn('https://example.test/success');
            $Gateway->method('getCancelUrl')->willReturn('https://example.test/cancel');
            $Customer = $this->createMock(QUI\ERP\User::class);
            $Customer->method('getAttribute')->with('RUNTIME_NETTO_BRUTTO_STATUS')->willReturn($net ? 1 : 2);
            $Currency = $this->createMock(Currency::class);
            $Currency->method('getCode')->willReturn('EUR');
            $articles = [];

            foreach ($positions as [$price, $quantity]) {
                $Article = $this->createMock(Article::class);
                $Article->method('toArray')->willReturn([
                    'calculated' => ['price' => $price]
                ]);
                $Article->method('getTitle')->willReturn('Test article');
                $Article->method('getQuantity')->willReturn($quantity);
                $articles[] = $Article;
            }

            $Calculations = $this->createMock(Calculations::class);
            $Calculations->method('getSum')->willReturn(new CalculationValue($total));
            $Calculations->method('getSubSum')->willReturn(new CalculationValue($subtotal));
            $Calculations->method('getNettoSubSum')->willReturn(new CalculationValue($subtotal));
            $Calculations->method('getVatSum')->willReturn(new CalculationValue($tax));
            $Calculations->method('getArticles')->willReturn($articles);
            $factors = new FactorList();

            if ($shipping !== null) {
                $ShippingFactor = $this->createMock(Factor::class);
                $ShippingFactor->method('getSum')->willReturn($shipping);
                $Shipping->setShippingPriceFactor($ShippingFactor);
                $factors->addFactor($ShippingFactor);
            } else {
                $Shipping->setShippingPriceFactor(null);
            }

            $Articles = $this->createMock(ArticleList::class);
            $Articles->method('getPriceFactors')->willReturn($factors);
            $Country = $this->createMock(Country::class);
            $Country->method('getCode')->willReturn('DE');
            $Address = $this->createMock(QUI\ERP\Address::class);
            $Address->method('getName')->willReturn('Test Customer');
            $Address->method('getAttribute')->willReturnMap([
                ['firstname', 'Test'],
                ['lastname', 'Customer'],
                ['street_no', 'Test street 1'],
                ['city', 'Berlin'],
                ['zip', '10115']
            ]);
            $Address->method('getCountry')->willReturn($Country);
            $Order = $this->createMock(AbstractOrder::class);
            $Order->expects(self::once())->method('recalculate');
            $Order->method('getCustomer')->willReturn($Customer);
            $Order->method('getUUID')->willReturn('ORDER-AMOUNTS');
            $Order->method('getPrefixedId')->willReturn('ORDER-44');
            $Order->method('getPriceCalculation')->willReturn($Calculations);
            $Order->method('getCurrency')->willReturn($Currency);
            $Order->method('getArticles')->willReturn($Articles);
            $Order->method('getDeliveryAddress')->willReturn($Address);

            $payload = (new PaymentPayloadDouble($Gateway))->buildPayPalDataForOrder($Order);
            $unit = $payload['purchase_units'][0];
            self::assertSame(sprintf('%.2F', round($total, 2)), $unit['amount']['value']);
            self::assertSame('EUR', $unit['amount']['currency_code']);
            self::assertSame('Test street 1', $unit['shipping']['address']['address_line_1']);

            if ($sendDetails) {
                self::assertCount(count($positions), $unit['items']);
                self::assertArrayHasKey('breakdown', $unit['amount']);

                if ($net) {
                    self::assertSame(sprintf('%.2F', $tax), $unit['amount']['breakdown']['tax_total']['value']);
                } else {
                    self::assertArrayNotHasKey('tax_total', $unit['amount']['breakdown']);
                }

                if ($shipping !== null) {
                    self::assertSame(sprintf('%.2F', $shipping), $unit['amount']['breakdown']['shipping']['value']);
                }
            } else {
                self::assertArrayNotHasKey('items', $unit);
                self::assertArrayNotHasKey('breakdown', $unit['amount']);
                self::assertArrayNotHasKey('details', $unit['amount']);
            }
        } finally {
            $Shipping->setShippingPriceFactor($originalShipping);

            if ($displayBasket === null) {
                $Config->del('payment', 'display_paypal_basket');
            } else {
                $Config->setValue('payment', 'display_paypal_basket', $displayBasket);
            }
        }
    }

    public static function basketAmounts(): iterable
    {
        yield 'net basket' => [true, [[10.0, 2]], 20.0, 3.8, 23.8, null, true];
        yield 'gross basket' => [false, [[11.9, 2]], 23.8, 3.8, 23.8, null, true];
        yield 'decimal cents' => [true, [[0.1, 1], [0.2, 1]], 0.3, 0.06, 0.36, null, true];
        yield 'rounded unit prices disagree' => [true, [[10.125, 3]], 30.375, 0.0, 30.38, null, false];
        yield 'net subtotal disagrees' => [true, [[10.0, 2]], 21.0, 3.99, 24.99, null, false];
        yield 'gross subtotal disagrees' => [false, [[11.9, 2]], 23.81, 3.8, 23.81, null, false];
        yield 'net total disagrees' => [true, [[10.0, 2]], 20.0, 3.8, 23.81, null, false];
        yield 'gross tax counted twice' => [false, [[11.9, 2]], 23.8, 3.8, 27.6, null, false];
        yield 'net shipping' => [true, [[10.0, 2]], 20.0, 3.8, 28.8, 5.0, true];
        yield 'gross shipping' => [false, [[11.9, 2]], 23.8, 3.8, 28.8, 5.0, true];
        yield 'shipping total disagrees' => [true, [[10.0, 2]], 20.0, 3.8, 28.81, 5.0, false];
        yield 'negative shipping' => [true, [[10.0, 2]], 20.0, 3.8, 18.8, -5.0, false];
        yield 'fractional quantity' => [true, [[10.0, 1.5]], 15.0, 2.85, 17.85, null, false];
        yield 'empty basket details' => [true, [], 20.0, 3.8, 23.8, null, false];
        yield 'free position' => [true, [[0.0, 1], [10.0, 1]], 10.0, 1.9, 11.9, null, true];
        yield 'negative position' => [true, [[-1.0, 1], [10.0, 1]], 9.0, 1.71, 10.71, null, false];
    }
}
