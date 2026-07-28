<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use Throwable;

final class SubscriptionsDatabaseTest extends TestCase
{
    private const PREFIX = 'phpunit_paypal_subscription_';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_subscription_id')
                ->from($this->table())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal subscription table is not available: ' . $Throwable->getMessage()
            );
        }

        $this->cleanupFixtures();
        $this->insertFixture('active', true);
        $this->insertFixture('inactive', false);
        $this->insertFixture(
            'foreign',
            true,
            AccountContext::createHash('foreign-client-id', true)
        );
        $this->insertTransactionFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testSubscriptionLookupAndActivityUseStoredRecords(): void
    {
        self::assertTrue(Subscriptions::exists(self::PREFIX . 'active'));
        self::assertTrue(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::PREFIX . 'active')
        );
        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::PREFIX . 'inactive')
        );
        self::assertFalse(Subscriptions::exists(self::PREFIX . 'missing'));
        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::PREFIX . 'missing')
        );
    }

    public function testSubscriptionIdsCanIncludeOrExcludeInactiveRecords(): void
    {
        $activeIds = Subscriptions::getSubscriptionIds();
        $allIds = Subscriptions::getSubscriptionIds(true);

        self::assertContains(self::PREFIX . 'active', $activeIds);
        self::assertNotContains(self::PREFIX . 'inactive', $activeIds);
        self::assertNotContains(self::PREFIX . 'foreign', $activeIds);
        self::assertContains(self::PREFIX . 'active', $allIds);
        self::assertContains(self::PREFIX . 'inactive', $allIds);
        self::assertNotContains(self::PREFIX . 'foreign', $allIds);
    }

    public function testSubscriptionDataIsDecodedFromStoredRecord(): void
    {
        self::assertSame(
            [
                'active' => true,
                'globalProcessId' => self::PREFIX . 'process_active',
                'customer' => [
                    'email' => 'active@example.test',
                    'firstname' => 'PHPUnit',
                    'lastname' => 'PayPal'
                ],
                'subscriptionData' => [
                    'status' => Subscriptions::STATUS_ACTIVE,
                    'fixture' => 'active'
                ],
                'planId' => self::PREFIX . 'plan'
            ],
            Subscriptions::getSubscriptionData(self::PREFIX . 'active')
        );
        self::assertFalse(
            Subscriptions::getSubscriptionData(self::PREFIX . 'missing')
        );
    }

    public function testSubscriptionCanBeMarkedInactive(): void
    {
        Subscriptions::setSubscriptionAsInactive(self::PREFIX . 'active');

        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::PREFIX . 'active')
        );
        self::assertNotContains(
            self::PREFIX . 'active',
            Subscriptions::getSubscriptionIds()
        );
        self::assertContains(
            self::PREFIX . 'active',
            Subscriptions::getSubscriptionIds(true)
        );
    }

    public function testSubscriptionListSupportsGridSearchAndDecodedData(): void
    {
        $searchParams = [
            'search' => self::PREFIX,
            'page' => 1,
            'perPage' => 10,
            'sortOn' => 'paypal_subscription_id',
            'sortBy' => 'ASC'
        ];
        $subscriptions = Subscriptions::getSubscriptionList($searchParams);

        self::assertCount(2, $subscriptions);
        self::assertSame(
            2,
            Subscriptions::getSubscriptionList($searchParams, true)
        );
        self::assertSame(
            self::PREFIX . 'active',
            $subscriptions[0]['paypal_subscription_id']
        );
        self::assertTrue($subscriptions[0]['active']);
        self::assertSame(
            'active@example.test',
            $subscriptions[0]['customer']['email']
        );
        self::assertSame(
            Subscriptions::STATUS_ACTIVE,
            $subscriptions[0]['subscription_data']['status']
        );

        $filtered = Subscriptions::getSubscriptionList([
            'search' => 'process_inactive',
            'page' => 1,
            'perPage' => 10
        ]);

        self::assertCount(1, $filtered);
        self::assertSame(
            self::PREFIX . 'inactive',
            $filtered[0]['paypal_subscription_id']
        );
    }

    public function testSubscriptionTransactionListReturnsDecodedNewestRows(): void
    {
        $transactions = Subscriptions::getSubscriptionTransactionList(
            self::PREFIX . 'active'
        );

        self::assertCount(1, $transactions);
        self::assertSame(
            self::PREFIX . 'transaction',
            $transactions[0]['paypal_transaction_id']
        );
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_COMPLETED,
            $transactions[0]['paypal_transaction_data']['status']
        );
        self::assertTrue(
            $transactions[0]['quiqqer_transaction_completed']
        );
    }

    private function insertFixture(
        string $suffix,
        bool $active,
        ?string $accountHash = null
    ): void {
        $this->connection()->insert(
            $this->table(),
            [
                'paypal_subscription_id' => self::PREFIX . $suffix,
                'paypal_plan_id' => self::PREFIX . 'plan',
                'customer' => json_encode([
                    'email' => $suffix . '@example.test',
                    'firstname' => 'PHPUnit',
                    'lastname' => 'PayPal'
                ]),
                'subscription_data' => json_encode([
                    'status' => $active
                        ? Subscriptions::STATUS_ACTIVE
                        : Subscriptions::STATUS_CANCELLED,
                    'fixture' => $suffix
                ]),
                'global_process_id' => self::PREFIX . 'process_' . $suffix,
                'active' => $active ? 1 : 0,
                'paypal_account_hash' => $accountHash ?? AccountContext::getHash()
            ]
        );
    }

    private function insertTransactionFixture(): void
    {
        $this->connection()->insert(
            $this->transactionsTable(),
            [
                'paypal_transaction_id' => self::PREFIX . 'transaction',
                'paypal_subscription_id' => self::PREFIX . 'active',
                'paypal_transaction_data' => json_encode([
                    'status' => Subscriptions::TRANSACTION_STATE_COMPLETED,
                    'amount' => [
                        'value' => '12.50',
                        'currency_code' => 'EUR'
                    ]
                ]),
                'paypal_transaction_date' => '2026-07-28 08:00:00',
                'quiqqer_transaction_id' => self::PREFIX . 'quiqqer',
                'quiqqer_transaction_completed' => 1,
                'global_process_id' => self::PREFIX . 'process_active'
            ]
        );
    }

    private function cleanupFixtures(): void
    {
        $TransactionQueryBuilder = $this->connection()->createQueryBuilder();
        $TransactionQueryBuilder
            ->delete($this->transactionsTable())
            ->where(
                $TransactionQueryBuilder->expr()->like(
                    'paypal_subscription_id',
                    ':prefix'
                )
            )
            ->setParameter('prefix', self::PREFIX . '%')
            ->executeStatement();

        $QueryBuilder = $this->connection()->createQueryBuilder();
        $QueryBuilder
            ->delete($this->table())
            ->where(
                $QueryBuilder->expr()->like(
                    'paypal_subscription_id',
                    ':prefix'
                )
            )
            ->setParameter('prefix', self::PREFIX . '%')
            ->executeStatement();
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private function table(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTIONS);
    }

    private function transactionsTable(): string
    {
        return QUI::getDBTableName(
            Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS
        );
    }
}
