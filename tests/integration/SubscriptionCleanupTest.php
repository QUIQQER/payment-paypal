<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\SubscriptionCleanup;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;
use ReflectionProperty;
use RuntimeException;

final class SubscriptionCleanupTest extends TestCase
{
    private const ID = 'phpunit_paypal_cleanup_subscription';
    private SubscriptionsApiClientDouble $Client;
    private SubscriptionCleanup $Cleanup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->connection()->insert($this->table(), [
            'paypal_subscription_id' => self::ID,
            'paypal_plan_id' => 'phpunit-cleanup-plan',
            'customer' => '{}',
            'subscription_data' => json_encode(['status' => Subscriptions::STATUS_APPROVAL_PENDING]),
            'global_process_id' => 'phpunit-paypal-cleanup-process',
            'active' => 1,
            'paypal_account_hash' => AccountContext::getHash()
        ]);
        $this->Client = new SubscriptionsApiClientDouble();
        $this->Client->setAccessToken('TEST-TOKEN');
        (new ReflectionProperty(Subscriptions::class, 'ApiClient'))->setValue(null, $this->Client);
        $this->Cleanup = new SubscriptionCleanup();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Subscriptions::class, 'ApiClient'))->setValue(null, null);
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyConfirmedMissingPendingSubscriptionIsDeleted(): void
    {
        $this->respondMissing();
        $this->Cleanup->deleteMissing(self::ID);

        self::assertFalse($this->storedRow());
        self::assertCount(1, $this->Client->requests);
        self::assertStringEndsWith('/v1/billing/subscriptions/' . self::ID, $this->Client->requests[0]['url']);
        self::assertSame('GET', $this->Client->requests[0]['options'][CURLOPT_CUSTOMREQUEST]);
    }

    #[DataProvider('unsafeResponses')]
    public function testUnconfirmedFailureOrExistingSubscriptionPreservesAllLocalData(
        int $status,
        string|false $body
    ): void {
        $before = $this->storedRow();
        $this->Client->responses[] = ['status' => $status, 'body' => $body];

        try {
            $this->Cleanup->deleteMissing(self::ID);
            self::fail('Cleanup must reject this provider response.');
        } catch (PayPalException) {
            self::assertSame($before, $this->storedRow());
        }

        self::assertCount(1, $this->Client->requests);
        self::assertSame('GET', $this->Client->requests[0]['options'][CURLOPT_CUSTOMREQUEST]);
    }

    public static function unsafeResponses(): array
    {
        return [
            'existing pending' => [200, '{"status":"APPROVAL_PENDING"}'],
            'existing active' => [200, '{"status":"ACTIVE"}'],
            'empty success' => [200, '{}'],
            'authentication' => [401, '{"name":"AUTHENTICATION_FAILURE"}'],
            'permission' => [403, '{"name":"NOT_AUTHORIZED"}'],
            'rate limit' => [429, '{"name":"RATE_LIMIT_REACHED"}'],
            'server error' => [503, '{"name":"SERVICE_UNAVAILABLE"}'],
            'timeout' => [0, false],
            'generic 404' => [404, '{"message":"Not found"}'],
            'HTML 404' => [404, '<html>Not found</html>'],
            'invalid error name' => [404, '{"name":["RESOURCE_NOT_FOUND"]}']
        ];
    }

    public function testOAuth404DoesNotAuthorizeCleanup(): void
    {
        $this->Client->setAccessToken(null);
        $this->respondMissing();

        try {
            $this->Cleanup->deleteMissing(self::ID);
            self::fail('An OAuth failure is not proof that the subscription is missing.');
        } catch (PayPalException) {
            self::assertIsArray($this->storedRow());
        }

        self::assertCount(1, $this->Client->requests);
        self::assertStringEndsWith('/v1/oauth2/token', $this->Client->requests[0]['url']);
    }

    public function testOtherAccountsAndNonPendingStatusesAreProtectedBeforeNetworkAccess(): void
    {
        foreach (
            [
            ['paypal_account_hash' => AccountContext::createHash('other-client', true)],
            ['paypal_account_hash' => null],
            ['subscription_data' => '{"status":"ACTIVE"}'],
            ['subscription_data' => '{"status":"CANCELLED"}']
            ] as $changes
        ) {
            $this->connection()->update($this->table(), [
                'paypal_account_hash' => AccountContext::getHash(),
                'subscription_data' => '{"status":"APPROVAL_PENDING"}'
            ], ['paypal_subscription_id' => self::ID]);
            $this->connection()->update($this->table(), $changes, ['paypal_subscription_id' => self::ID]);
            $before = $this->storedRow();

            try {
                $this->Cleanup->deleteMissing(self::ID);
                self::fail('Protected record must not be deleted.');
            } catch (PayPalException) {
                self::assertSame($before, $this->storedRow());
            }
        }

        self::assertSame([], $this->Client->requests);
    }

    public function testLinkedContractBlocksCleanupBeforeNetworkAccess(): void
    {
        $Cleanup = new class extends SubscriptionCleanup {
            protected function hasContract(string $processId): bool
            {
                TestCase::assertSame('phpunit-paypal-cleanup-process', $processId);
                return true;
            }
        };

        try {
            $Cleanup->deleteMissing(self::ID);
            self::fail('Subscriptions linked to any contract must be kept.');
        } catch (PayPalException) {
            self::assertIsArray($this->storedRow());
        }

        self::assertSame([], $this->Client->requests);
    }

    public function testContractLookupFailurePreservesSubscription(): void
    {
        $Cleanup = new class extends SubscriptionCleanup {
            protected function hasContract(string $processId): bool
            {
                throw new RuntimeException('Database unavailable');
            }
        };

        try {
            $Cleanup->deleteMissing(self::ID);
            self::fail('A failed reference check must abort cleanup.');
        } catch (RuntimeException $Exception) {
            self::assertSame('Database unavailable', $Exception->getMessage());
            self::assertIsArray($this->storedRow());
        }

        self::assertSame([], $this->Client->requests);
    }

    public function testLocalPaymentsPreventDeletion(): void
    {
        $this->connection()->insert($this->transactionsTable(), [
            'paypal_transaction_id' => 'phpunit-paypal-cleanup-payment',
            'paypal_subscription_id' => self::ID,
            'paypal_transaction_data' => '{}',
            'paypal_transaction_date' => '2026-09-27 12:00:00',
            'quiqqer_transaction_id' => 'phpunit-paypal-cleanup-transaction',
            'quiqqer_transaction_completed' => 1,
            'global_process_id' => 'phpunit-paypal-cleanup-process'
        ]);

        try {
            $this->Cleanup->deleteMissing(self::ID);
            self::fail('Payment history must not be deleted.');
        } catch (PayPalException) {
            self::assertIsArray($this->storedRow());
        }

        self::assertSame([], $this->Client->requests);
        self::assertSame(1, (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->transactionsTable() . ' WHERE paypal_subscription_id = ?',
            [self::ID]
        ));
    }

    public function testStatusChangeDuringVerificationPreventsDeletion(): void
    {
        $table = $this->table();
        $Cleanup = new class ($table, self::ID) extends SubscriptionCleanup {
            private int $checks = 0;

            public function __construct(private string $table, private string $id)
            {
            }

            protected function hasContract(string $processId): bool
            {
                if (++$this->checks === 2) {
                    QUI::getDataBaseConnection()->update($this->table, [
                        'subscription_data' => '{"status":"ACTIVE"}'
                    ], ['paypal_subscription_id' => $this->id]);
                }

                return false;
            }
        };
        $this->respondMissing();

        try {
            $Cleanup->deleteMissing(self::ID);
            self::fail('A changed subscription must not be deleted.');
        } catch (PayPalException) {
            self::assertIsArray($this->storedRow());
        }
    }

    public function testMalformedSubscriptionIdCannotChangeRequestPath(): void
    {
        $this->expectException(PayPalException::class);
        $this->Cleanup->deleteMissing('../plans/another-resource');
    }

    private function respondMissing(): void
    {
        $this->Client->responses[] = [
            'status' => 404,
            'body' => '{"name":"RESOURCE_NOT_FOUND","message":"The specified resource does not exist."}'
        ];
    }

    private function storedRow(): array|false
    {
        return $this->connection()->fetchAssociative(
            'SELECT * FROM ' . $this->table() . ' WHERE paypal_subscription_id = ?',
            [self::ID]
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

    private function transactionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS);
    }

    private function clean(): void
    {
        foreach ([$this->transactionsTable(), $this->table()] as $table) {
            $this->connection()->delete($table, ['paypal_subscription_id' => self::ID]);
        }
    }
}
