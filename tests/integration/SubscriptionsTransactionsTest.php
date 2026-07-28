<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

final class SubscriptionsTransactionsTest extends TestCase
{
    private const PREFIX = 'phpunit_paypal_transactions_';
    private const SUBSCRIPTION_ID = self::PREFIX . 'subscription';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_transaction_id')
                ->from($this->transactionsTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal subscription transactions table is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixtures();
        $this->connection()->insert(
            $this->subscriptionsTable(),
            [
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_plan_id' => self::PREFIX . 'plan',
                'customer' => '{}',
                'subscription_data' => '{}',
                'global_process_id' => self::PREFIX . 'process',
                'active' => 1,
                'paypal_account_hash' => AccountContext::getHash()
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->setApiClient(null);
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testMissingTransactionsAreFetchedAndPersisted(): void
    {
        $Client = $this->apiClient([
            'transactions' => [
                [
                    'id' => self::PREFIX . 'completed',
                    'status' => Subscriptions::TRANSACTION_STATE_COMPLETED,
                    'amount_with_breakdown' => [
                        'gross_amount' => [
                            'value' => '19.95',
                            'currency_code' => 'EUR'
                        ]
                    ],
                    'time' => '2026-07-27T11:00:00Z'
                ],
                [
                    'id' => self::PREFIX . 'denied',
                    'status' => Subscriptions::TRANSACTION_STATE_DENIED,
                    'amount_with_breakdown' => [
                        'gross_amount' => [
                            'value' => '19.95',
                            'currency_code' => 'EUR'
                        ]
                    ],
                    'time' => '2026-07-27T11:01:00Z'
                ]
            ]
        ]);
        $this->setApiClient($Client);

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::SUBSCRIPTION_ID
        );

        self::assertCount(1, $transactions);
        self::assertSame(
            self::PREFIX . 'completed',
            $transactions[0]['id']
        );
        self::assertStringContainsString(
            '/v1/billing/subscriptions/' . self::SUBSCRIPTION_ID . '/transactions?',
            $Client->requests[0]['url']
        );
        self::assertSame(2, $this->transactionCount());
    }

    public function testStoredTransactionsAreDecodedAndFiltered(): void
    {
        $this->insertTransaction(
            self::PREFIX . 'invalid',
            'invalid-json',
            '2026-07-27 11:10:00'
        );
        $this->insertTransaction(
            self::PREFIX . 'pending',
            json_encode(['id' => self::PREFIX . 'pending', 'status' => 'PENDING']),
            '2026-07-27 11:11:00'
        );
        $this->insertTransaction(
            self::PREFIX . 'completed',
            json_encode([
                'id' => self::PREFIX . 'completed',
                'status' => Subscriptions::TRANSACTION_STATE_COMPLETED
            ]),
            '2026-07-27 11:12:00'
        );

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::SUBSCRIPTION_ID
        );

        self::assertCount(1, $transactions);
        self::assertSame(self::PREFIX . 'completed', $transactions[0]['id']);
    }

    public function testStoredNonMatchingTransactionTriggersRefresh(): void
    {
        $this->insertTransaction(
            self::PREFIX . 'pending',
            json_encode([
                'id' => self::PREFIX . 'pending',
                'status' => 'PENDING'
            ]),
            '2026-07-27 11:11:00'
        );
        $Client = $this->apiClient([
            'transactions' => [[
                'id' => self::PREFIX . 'completed',
                'status' => Subscriptions::TRANSACTION_STATE_COMPLETED,
                'time' => '2026-07-27T11:12:00Z'
            ]]
        ]);
        $this->setApiClient($Client);

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::SUBSCRIPTION_ID
        );

        self::assertCount(1, $transactions);
        self::assertSame(self::PREFIX . 'completed', $transactions[0]['id']);
        self::assertCount(1, $Client->requests);
        self::assertSame(2, $this->transactionCount());
    }

    public function testDeniedTransactionFilterReturnsDeniedRows(): void
    {
        $this->insertTransaction(
            self::PREFIX . 'denied',
            json_encode([
                'id' => self::PREFIX . 'denied',
                'state' => 'denied'
            ]),
            '2026-07-27 11:13:00'
        );

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::SUBSCRIPTION_ID,
            Subscriptions::TRANSACTION_STATE_DENIED
        );

        self::assertCount(1, $transactions);
        self::assertSame(self::PREFIX . 'denied', $transactions[0]['id']);
    }

    public function testRefreshUsesLatestStoredTransactionAsStartTime(): void
    {
        $this->insertTransaction(
            self::PREFIX . 'existing',
            '{}',
            '2026-07-20 08:30:00'
        );
        $Client = $this->apiClient(['transactions' => []]);
        $this->setApiClient($Client);

        $this->invoke('refreshTransactionList', self::SUBSCRIPTION_ID);

        parse_str(
            parse_url($Client->requests[0]['url'], PHP_URL_QUERY),
            $query
        );
        self::assertSame(
            '2026-07-20 08:30:00',
            (new \DateTime($query['start_time']))->format('Y-m-d H:i:s')
        );
    }

    public function testRefreshStopsForUnknownSubscription(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $this->setApiClient($Client);

        $this->invoke('refreshTransactionList', self::PREFIX . 'missing');

        self::assertSame([], $Client->requests);
    }

    private function apiClient(array $response): SubscriptionsApiClientDouble
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');
        $Client->responses[] = [
            'body' => json_encode($response),
            'status' => 200
        ];

        return $Client;
    }

    private function insertTransaction(
        string $transactionId,
        string $data,
        string $date
    ): void {
        $this->connection()->insert(
            $this->transactionsTable(),
            [
                'paypal_transaction_id' => $transactionId,
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_transaction_data' => $data,
                'paypal_transaction_date' => $date,
                'global_process_id' => self::PREFIX . 'process'
            ]
        );
    }

    private function setApiClient(?SubscriptionsApiClientDouble $Client): void
    {
        $Property = new ReflectionProperty(Subscriptions::class, 'ApiClient');
        $Property->setValue(null, $Client);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(Subscriptions::class, $method))
            ->invoke(null, ...$arguments);
    }

    private function transactionCount(): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->transactionsTable()
            . ' WHERE paypal_transaction_id LIKE ?',
            [self::PREFIX . '%']
        );
    }

    private function cleanupFixtures(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ' . $this->transactionsTable()
            . ' WHERE paypal_transaction_id LIKE ?',
            [self::PREFIX . '%']
        );
        $this->connection()->delete(
            $this->subscriptionsTable(),
            ['paypal_subscription_id' => self::SUBSCRIPTION_ID]
        );
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private function subscriptionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTIONS);
    }

    private function transactionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS);
    }
}
