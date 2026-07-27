<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
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
        self::assertContains(self::PREFIX . 'active', $allIds);
        self::assertContains(self::PREFIX . 'inactive', $allIds);
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

    private function insertFixture(string $suffix, bool $active): void
    {
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
                'active' => $active ? 1 : 0
            ]
        );
    }

    private function cleanupFixtures(): void
    {
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
}
