<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Countries\Country;
use QUI\ERP\Accounting\Article;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Accounting\PriceFactors\FactorList;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Settings;
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
}
