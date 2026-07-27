<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\Users\Address;
use QUI\Users\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentExpressDouble;

final class PaymentExpressAddressTest extends TestCase
{
    public function testModernPayPalAddressIsStoredForQuiqqerUser(): void
    {
        $CreatedAddress = $this->createMock(Address::class);
        $CreatedAddress->method('getUUID')->willReturn('ADDRESS-NEW');
        $CreatedAddress->expects(self::once())
            ->method('addMail')
            ->with('jane@example.test');
        $CreatedAddress->expects(self::once())
            ->method('setCustomDataEntry')
            ->with('source', 'PayPal');
        $CreatedAddress->expects(self::once())->method('save');

        $QuiqqerUser = $this->createMock(User::class);
        $QuiqqerUser->expects(self::once())
            ->method('addAddress')
            ->with(self::callback(static function (array $address): bool {
                self::assertSame('Jane', $address['firstname']);
                self::assertSame('Doe', $address['lastname']);
                self::assertSame(
                    'Main Street 1 Apartment 4',
                    $address['street_no']
                );
                self::assertSame('Berlin, Berlin', $address['city']);
                self::assertSame('10115', $address['zip']);
                self::assertSame('DE', $address['country']);

                return true;
            }))
            ->willReturn($CreatedAddress);

        $ReloadedAddress = $this->createMock(Address::class);
        $Payment = new PaymentExpressDouble();
        $Payment->PayPalAddress = $ReloadedAddress;

        self::assertSame(
            $ReloadedAddress,
            $Payment->createAddressForTest(
                [
                    'purchase_units' => [[
                        'shipping' => [
                            'address' => [
                                'address_line_1' => 'Main Street 1',
                                'address_line_2' => 'Apartment 4',
                                'admin_area_2' => 'Berlin',
                                'admin_area_1' => 'Berlin',
                                'postal_code' => '10115',
                                'country_code' => 'de'
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
                ],
                $QuiqqerUser
            )
        );
    }
}
