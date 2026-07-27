<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\OrderInterface;
use QUI\ERP\Payments\PayPal\Events;

final class EventsDouble extends Events
{
    public static bool $plansInstalled = false;
    public static bool $planOrder = false;
    public static bool $nobodyUser = false;
    public static ?Payment $ExpressPayment = null;
    public static array $planDetails = [];

    protected static function isPlansInstalled(): bool
    {
        return self::$plansInstalled;
    }

    protected static function isPlanOrder(OrderInterface $Order): bool
    {
        return self::$planOrder;
    }

    protected static function getPlanDetailsFromOrder(
        OrderInterface $Order
    ): array {
        return self::$planDetails;
    }

    protected static function getPaymentSetting(string $key): mixed
    {
        return true;
    }

    protected static function getApiSetting(string $key): mixed
    {
        return true;
    }

    protected static function getWidgetSetting(string $key): mixed
    {
        return 'test-' . $key;
    }

    protected static function getPayPalExpressPayment(): bool|Payment
    {
        return self::$ExpressPayment ?? false;
    }

    protected static function getOrderProcessUrl(): string
    {
        return 'https://example.test/checkout';
    }

    protected static function isNobodyUser(): bool
    {
        return self::$nobodyUser;
    }
}
