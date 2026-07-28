<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

final class SubscriptionAccountMigrationTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'phpunit_paypal_legacy_subscription';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_account_hash')
                ->from($this->table())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal subscription account context is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixture();
        $this->resetStatics();
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function testResolvableLegacySubscriptionIsAssignedToCurrentAccount(): void
    {
        $this->insertLegacySubscription();
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('test-token');
        $Client->responses = [[
            'body' => json_encode(['id' => self::SUBSCRIPTION_ID]),
            'status' => 200
        ]];
        $this->setApiClient($Client);

        self::assertTrue(Subscriptions::exists(self::SUBSCRIPTION_ID));
        self::assertSame(
            AccountContext::getHash(),
            $this->connection()->fetchOne(
                'SELECT paypal_account_hash FROM ' . $this->table()
                . ' WHERE paypal_subscription_id = ?',
                [self::SUBSCRIPTION_ID]
            )
        );
    }

    public function testMissingLegacySubscriptionRemainsUnassigned(): void
    {
        $this->insertLegacySubscription();
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('test-token');
        $Client->responses = [[
            'body' => json_encode(['message' => 'Not found']),
            'status' => 404
        ]];
        $this->setApiClient($Client);

        self::assertFalse(Subscriptions::exists(self::SUBSCRIPTION_ID));
        self::assertNull(
            $this->connection()->fetchOne(
                'SELECT paypal_account_hash FROM ' . $this->table()
                . ' WHERE paypal_subscription_id = ?',
                [self::SUBSCRIPTION_ID]
            )
        );
    }

    private function insertLegacySubscription(): void
    {
        $this->connection()->insert(
            $this->table(),
            [
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_plan_id' => 'phpunit-paypal-legacy-plan',
                'customer' => '{}',
                'subscription_data' => '{}',
                'global_process_id' => 'phpunit-paypal-legacy-process',
                'active' => 1,
                'paypal_account_hash' => null
            ]
        );
    }

    private function setApiClient(?SubscriptionsApiClientDouble $Client): void
    {
        $Property = new ReflectionProperty(Subscriptions::class, 'ApiClient');
        $Property->setValue(null, $Client);
    }

    private function resetStatics(): void
    {
        $this->setApiClient(null);

        $Property = new ReflectionProperty(
            Subscriptions::class,
            'legacyAccountMigrationAttempted'
        );
        $Property->setValue(null, []);
    }

    private function cleanupFixture(): void
    {
        $this->connection()->delete(
            $this->table(),
            ['paypal_subscription_id' => self::SUBSCRIPTION_ID]
        );
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
