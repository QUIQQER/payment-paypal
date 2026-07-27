<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Payments\Types\Payment as PaymentType;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;

final class ProviderTest extends TestCase
{
    public function testPaymentTypesContainAllPayPalVariants(): void
    {
        self::assertSame([
            Payment::class,
            PaymentExpress::class,
            RecurringPayment::class
        ], (new Provider())->getPaymentTypes());
    }

    public function testSettingsAreReadFromPackageConfiguration(): void
    {
        $Config = QUI::getPackage('quiqqer/payment-paypal')->getConfig();

        self::assertSame(
            $Config->get('api', 'sandbox'),
            Provider::getApiSetting('sandbox')
        );
        self::assertSame(
            $Config->get('payment', 'display_paypal_basket'),
            Provider::getPaymentSetting('display_paypal_basket')
        );
        self::assertSame(
            $Config->get('widgets', 'btn_color'),
            Provider::getWidgetsSetting('btn_color')
        );
    }

    public function testApiSetupSupportsSandboxAndProductionCredentials(): void
    {
        $Config = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        $keys = [
            'sandbox',
            'sandbox_client_id',
            'sandbox_client_secret',
            'client_id',
            'client_secret'
        ];
        $previous = [];

        foreach ($keys as $key) {
            $previous[$key] = $Config->get('api', $key);
        }

        try {
            $Config->setValue('api', 'sandbox', 1);
            $Config->setValue('api', 'sandbox_client_id', 'sandbox-client');
            $Config->setValue('api', 'sandbox_client_secret', 'sandbox-secret');
            self::assertTrue(Provider::isApiSetUp());

            $Config->setValue('api', 'sandbox_client_secret', '');
            self::assertFalse(Provider::isApiSetUp());

            $Config->setValue('api', 'sandbox', 0);
            $Config->setValue('api', 'client_id', 'production-client');
            $Config->setValue('api', 'client_secret', 'production-secret');
            self::assertTrue(Provider::isApiSetUp());

            $Config->setValue('api', 'client_id', '');
            self::assertFalse(Provider::isApiSetUp());
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    $Config->del('api', $key);
                    continue;
                }

                $Config->setValue('api', $key, match ($value) {
                    true => 1,
                    false => 0,
                    default => $value
                });
            }
        }
    }

    public function testExpressPaymentLookupReturnsSupportedResult(): void
    {
        $Payment = Provider::getPayPalExpressPayment();

        self::assertTrue($Payment === false || $Payment instanceof PaymentType);
    }
}
