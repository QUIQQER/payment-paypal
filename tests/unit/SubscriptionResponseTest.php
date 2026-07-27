<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionMethod;

final class SubscriptionResponseTest extends TestCase
{
    public function testApprovalUrlUsesApproveLink(): void
    {
        $response = [
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.example/subscription'],
                ['rel' => 'approve', 'href' => 'https://paypal.example/approve'],
                ['rel' => 'cancel', 'href' => 'https://api.example/cancel']
            ]
        ];

        self::assertSame(
            'https://paypal.example/approve',
            self::invoke('getApprovalUrl', $response)
        );
        self::assertSame('', self::invoke('getApprovalUrl', []));
    }

    public function testSubscriptionIdSupportsWebhookResourceVariants(): void
    {
        self::assertSame(
            'BILLING-AGREEMENT-ID',
            self::invoke(
                'getSubscriptionIdFromResource',
                [
                    'billing_agreement_id' => 'BILLING-AGREEMENT-ID',
                    'subscription_id' => 'SUBSCRIPTION-ID',
                    'id' => 'RESOURCE-ID'
                ]
            )
        );
        self::assertSame(
            'SUBSCRIPTION-ID',
            self::invoke(
                'getSubscriptionIdFromResource',
                ['subscription_id' => 'SUBSCRIPTION-ID', 'id' => 'RESOURCE-ID']
            )
        );
        self::assertSame(
            'RESOURCE-ID',
            self::invoke('getSubscriptionIdFromResource', ['id' => 'RESOURCE-ID'])
        );
        self::assertSame('', self::invoke('getSubscriptionIdFromResource', []));
    }

    public function testModernTransactionFieldsAreNormalized(): void
    {
        $transaction = [
            'id' => 'TRANSACTION-ID',
            'amount' => [
                'value' => '12.34',
                'currency_code' => 'EUR'
            ],
            'create_time' => '2026-07-27T08:15:30Z',
            'status' => Subscriptions::TRANSACTION_STATE_COMPLETED
        ];

        self::assertSame('TRANSACTION-ID', self::invoke('getTransactionId', $transaction));
        self::assertSame(12.34, self::invoke('getTransactionAmount', $transaction));
        self::assertSame('EUR', self::invoke('getTransactionCurrency', $transaction));
        self::assertSame('2026-07-27T08:15:30Z', self::invoke('getTransactionTime', $transaction));
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_COMPLETED,
            self::invoke('getTransactionStatus', $transaction)
        );
    }

    public function testLegacyTransactionFieldsAreNormalized(): void
    {
        $transaction = [
            'sale_id' => 'SALE-ID',
            'amount' => [
                'total' => '7.50',
                'currency' => 'USD'
            ],
            'update_time' => '2026-07-26T10:00:00Z',
            'state' => 'completed'
        ];

        self::assertSame('SALE-ID', self::invoke('getTransactionId', $transaction));
        self::assertSame(7.5, self::invoke('getTransactionAmount', $transaction));
        self::assertSame('USD', self::invoke('getTransactionCurrency', $transaction));
        self::assertSame('2026-07-26T10:00:00Z', self::invoke('getTransactionTime', $transaction));
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_COMPLETED,
            self::invoke('getTransactionStatus', $transaction)
        );
    }

    public function testDeniedWebhookEventIsNormalizedWithoutTransactionState(): void
    {
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_DENIED,
            self::invoke(
                'getTransactionStatus',
                [],
                'PAYMENT.SALE.DENIED'
            )
        );
    }

    private static function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(Subscriptions::class, $method))
            ->invoke(null, ...$arguments);
    }
}
