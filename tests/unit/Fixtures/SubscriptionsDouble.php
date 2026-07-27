<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use RuntimeException;

final class SubscriptionsDouble extends Subscriptions
{
    private static ?Gateway $Gateway = null;

    public static function useGateway(?Gateway $Gateway): void
    {
        self::$Gateway = $Gateway;
    }

    /**
     * @return array{string, string}
     */
    protected static function getOrCreatePlanReferences(AbstractOrder $Order): array
    {
        return ['PRODUCT-1', 'PLAN-1'];
    }

    protected static function createGatewayForOrder(AbstractOrder $Order): Gateway
    {
        if (self::$Gateway === null) {
            throw new RuntimeException('No gateway configured for subscription test.');
        }

        return self::$Gateway;
    }
}
