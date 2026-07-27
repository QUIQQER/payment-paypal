<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\User as ErpUser;
use QUI\Users\Address;
use QUI\Users\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentExpressDouble;

final class PaymentExpressExecutionTest extends TestCase
{
    public function testExistingCustomerAddressCompletesExpressOrder(): void
    {
        $PaymentType = $this->createMock(Payment::class);
        $PaymentType->method('getId')->willReturn(42);
        $ExistingAddress = $this->createMock(Address::class);
        $ExistingAddress->method('getUUID')->willReturn('ADDRESS-EXISTING');
        $ExistingAddress->method('getAttributes')->willReturn([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street_no' => 'Main Street 1',
            'zip' => '12345',
            'city' => 'Berlin',
            'country' => 'DE'
        ]);
        $PayPalAddress = $this->createMock(Address::class);
        $PayPalAddress->method('getUUID')->willReturn('ADDRESS-PAYPAL');
        $PayPalAddress->method('equals')->with($PayPalAddress)
            ->willReturn(false);
        $ExistingAddress->method('equals')->with($PayPalAddress)
            ->willReturn(true);
        $PayPalAddress->expects(self::once())->method('delete');

        $QuiqqerUser = $this->createMock(User::class);
        $QuiqqerUser->method('getAddressList')->willReturn([
            $ExistingAddress
        ]);
        $Customer = $this->createMock(ErpUser::class);
        $Customer->method('getUUID')->willReturn('CUSTOMER-1');
        $Order = new OrderDouble();
        $Order->CustomerValue = $Customer;

        $Express = new PaymentExpressDouble();
        $Express->ExpressPayment = $PaymentType;
        $Express->QuiqqerUser = $QuiqqerUser;
        $Express->PayPalAddress = $PayPalAddress;
        $Express->payPalOrder = $this->payPalOrder();

        $Express->executePayPalOrder($Order);

        self::assertSame(42, $Order->configuredPaymentId);
        self::assertSame($ExistingAddress, $Order->InvoiceAddress);
        self::assertInstanceOf(
            \QUI\ERP\Address::class,
            $Order->DeliveryAddress
        );
        self::assertSame(1, $Express->saveCount);
        self::assertSame(1, $Express->shippingCount);
        self::assertSame(1, $Express->updateCount);
        self::assertSame(0, $Express->voidCount);
    }

    public function testMissingExpressPaymentVoidsOrder(): void
    {
        $Express = new PaymentExpressDouble();
        $Express->payPalOrder = $this->payPalOrder();

        try {
            $Express->executePayPalOrder(new OrderDouble());
            self::fail('Missing PayPal Express payment was accepted.');
        } catch (PayPalException) {
            self::assertSame(1, $Express->voidCount);
        }
    }

    public function testMissingShippingAddressVoidsOrder(): void
    {
        $PaymentType = $this->createMock(Payment::class);
        $PaymentType->method('getId')->willReturn(42);
        $Express = new PaymentExpressDouble();
        $Express->ExpressPayment = $PaymentType;
        $Express->payPalOrder = [
            'purchase_units' => [[]]
        ];

        try {
            $Express->executePayPalOrder(new OrderDouble());
            self::fail('Missing PayPal address was accepted.');
        } catch (PayPalException) {
            self::assertSame(1, $Express->voidCount);
        }
    }

    private function payPalOrder(): array
    {
        return [
            'purchase_units' => [[
                'shipping' => [
                    'address' => [
                        'address_line_1' => 'Main Street 1',
                        'postal_code' => '12345',
                        'admin_area_2' => 'Berlin',
                        'country_code' => 'DE'
                    ]
                ]
            ]],
            'payment_source' => [
                'paypal' => [
                    'name' => [
                        'given_name' => 'Jane',
                        'surname' => 'Doe'
                    ],
                    'email_address' => 'jane@example.test'
                ]
            ]
        ];
    }
}
