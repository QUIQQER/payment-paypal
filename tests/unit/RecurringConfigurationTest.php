<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class RecurringConfigurationTest extends TestCase
{
    public function testLegacyRecurringApiModeIsNotExposed(): void
    {
        $Settings = $this->loadXml('settings.xml');
        $Locale = $this->loadXml('locale.xml');

        self::assertSame(
            [],
            $Settings->xpath("//conf[@name='recurring_api_mode']") ?: []
        );
        self::assertSame(
            [],
            $Settings->xpath("//select[@conf='payment.recurring_api_mode']") ?: []
        );
        self::assertSame(
            [],
            $Locale->xpath(
                "//locale[starts-with(@name, 'settings.payment.recurring_api_mode')]"
            ) ?: []
        );
    }

    public function testSubscriptionAdministrationIsExposed(): void
    {
        $Settings = $this->loadXml('settings.xml');
        $Permissions = $this->loadXml('permissions.xml');

        self::assertCount(
            1,
            $Settings->xpath("//category[@name='subscriptions']") ?: []
        );
        self::assertCount(
            1,
            $Settings->xpath(
                "//input[contains(@data-qui, '/backend/Subscriptions')]"
            ) ?: []
        );
        self::assertCount(
            1,
            $Permissions->xpath(
                "//permission[@name='quiqqer.payments.paypal.subscriptions.view']"
            ) ?: []
        );
        self::assertCount(
            1,
            $Permissions->xpath(
                "//permission[@name='quiqqer.payments.paypal.subscriptions.manage']"
            ) ?: []
        );
    }

    private function loadXml(string $file): SimpleXMLElement
    {
        $Xml = simplexml_load_file(dirname(__DIR__, 2) . '/' . $file);

        self::assertInstanceOf(SimpleXMLElement::class, $Xml);

        return $Xml;
    }
}
