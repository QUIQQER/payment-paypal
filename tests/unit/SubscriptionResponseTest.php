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
        self::assertSame('', self::invoke('getApprovalUrl', [
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.example/subscription'],
                ['rel' => 'approve']
            ]
        ]));
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

    public function testDeniedAndUnknownTransactionStatesAreNormalized(): void
    {
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_DENIED,
            self::invoke('getTransactionStatus', ['status' => 'DENIED'])
        );
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_DENIED,
            self::invoke('getTransactionStatus', ['state' => 'denied'])
        );
        self::assertSame(
            'PENDING',
            self::invoke('getTransactionStatus', ['status' => 'PENDING'])
        );
        self::assertSame('', self::invoke('getTransactionStatus', []));
    }

    public function testTransactionFallbackFieldsAreNormalized(): void
    {
        $transaction = [
            'transaction_id' => 'FALLBACK-ID',
            'amount_with_breakdown' => [
                'gross_amount' => [
                    'value' => '22.75',
                    'currency_code' => 'GBP'
                ]
            ],
            'time_stamp' => '2026-07-25T12:00:00Z'
        ];

        self::assertSame('FALLBACK-ID', self::invoke('getTransactionId', $transaction));
        self::assertSame(22.75, self::invoke('getTransactionAmount', $transaction));
        self::assertSame('GBP', self::invoke('getTransactionCurrency', $transaction));
        self::assertSame('2026-07-25T12:00:00Z', self::invoke('getTransactionTime', $transaction));

        self::assertSame('', self::invoke('getTransactionId', []));
        self::assertSame(0.0, self::invoke('getTransactionAmount', []));
        self::assertSame('', self::invoke('getTransactionCurrency', []));
        self::assertSame('now', self::invoke('getTransactionTime', []));
    }

    public function testTransactionsApiTimeFieldIsNormalized(): void
    {
        self::assertSame(
            '2026-07-27T11:00:00Z',
            self::invoke('getTransactionTime', ['time' => '2026-07-27T11:00:00Z'])
        );
    }

    public function testDatesAreNormalizedForDatabaseStorage(): void
    {
        self::assertSame(
            '2026-07-27 10:15:30',
            self::invoke('formatDate', '2026-07-27T10:15:30Z')
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            self::invoke('formatDate', 'invalid date value')
        );
    }

    public function testCycleCountSupportsOpenAndFixedPlans(): void
    {
        self::assertSame(0, self::invoke('getCycleCount', [
            'auto_extend' => true
        ]));
        self::assertSame(3, self::invoke('getCycleCount', [
            'auto_extend' => false,
            'duration_interval' => '3-month',
            'invoice_interval' => '1-month'
        ]));
    }

    private static function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(Subscriptions::class, $method))
            ->invoke(null, ...$arguments);
    }
}
