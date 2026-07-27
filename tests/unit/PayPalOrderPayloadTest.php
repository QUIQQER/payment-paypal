<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Countries\Country;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Accounting\PriceFactors\FactorList;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Order\AbstractOrder;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentPayloadDouble;

final class PayPalOrderPayloadTest extends TestCase
{
    public function testOrderUsesModernPayPalWalletPaymentSource(): void
    {
        $Config = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
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
}
