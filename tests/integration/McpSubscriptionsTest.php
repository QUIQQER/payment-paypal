<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\MCP\Operations;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\ERP\Payments\PayPal\Settings;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class McpSubscriptionsTest extends TestCase
{
    private const IDS = ['I-PHPUNITMCP001', 'I-PHPUNITMCP002', 'I-PHPUNITMCP003'];
    private mixed $originalApiClient;
    private mixed $originalMigrationAttempts;
    private SubscriptionsApiClientDouble $Client;
    private Operations $Operations;

    protected function setUp(): void
    {
        $this->originalApiClient = (new ReflectionProperty(Subscriptions::class, 'ApiClient'))->getValue();
        $this->originalMigrationAttempts = (new ReflectionProperty(Subscriptions::class, 'legacyAccountMigrationAttempted'))
            ->getValue();
        $this->Client = new SubscriptionsApiClientDouble();
        $this->Client->setAccessToken('phpunit-mcp-token');
        (new ReflectionProperty(Subscriptions::class, 'ApiClient'))->setValue(null, $this->Client);
        $this->Operations = new Operations();

        try {
            QUI::getDataBaseConnection()->createQueryBuilder()
                ->select('paypal_subscription_id')->from($this->table())->setMaxResults(1)->executeQuery()->free();
        } catch (Throwable $Exception) {
            self::markTestSkipped('Subscription table is unavailable: ' . $Exception->getMessage());
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        (new ReflectionProperty(Subscriptions::class, 'ApiClient'))->setValue(null, $this->originalApiClient);
        (new ReflectionProperty(Subscriptions::class, 'legacyAccountMigrationAttempted'))
            ->setValue(null, $this->originalMigrationAttempts);
    }

    public function testActionsUseTheExistingApiAndPersistStatus(): void
    {
        $this->insert(self::IDS[0], AccountContext::getHash());
        $expected = [
            'suspend' => [Subscriptions::STATUS_SUSPENDED, true],
            'activate' => [Subscriptions::STATUS_ACTIVE, true],
            'cancel' => [Subscriptions::STATUS_CANCELLED, false]
        ];

        foreach ($expected as $action => [$status, $active]) {
            $this->Client->responses[] = ['status' => 204, 'body' => ''];
            $this->Operations->changeSubscription(self::IDS[0], $action, 'Reviewed request');
            $data = Subscriptions::getSubscriptionData(self::IDS[0]);
            self::assertSame($status, $data['subscriptionData']['status']);
            self::assertSame($active, $data['active']);
            $request = $this->Client->requests[array_key_last($this->Client->requests)];
            self::assertStringEndsWith('/' . self::IDS[0] . '/' . $action, $request['url']);
            self::assertSame(
                ['reason' => 'Reviewed request'],
                json_decode($request['options'][CURLOPT_POSTFIELDS], true)
            );
        }

        self::assertCount(3, $this->Client->requests);
    }

    public function testForeignUnassignedAndMissingRecordsCannotBeChanged(): void
    {
        $this->insert(self::IDS[0], 'foreign-account');
        $this->insert(self::IDS[1], null);

        foreach (self::IDS as $id) {
            try {
                $this->Operations->changeSubscription($id, 'cancel', 'Reviewed request');
                self::fail('A protected subscription was changed.');
            } catch (RuntimeException) {
                self::assertSame([], $this->Client->requests);
            }
        }
    }

    public function testProviderFailureDoesNotChangeLocalSubscriptionStatus(): void
    {
        $this->insert(self::IDS[0], AccountContext::getHash());
        $this->Client->responses[] = [
            'status' => 500,
            'body' => '{"name":"INTERNAL_SERVER_ERROR"}'
        ];

        try {
            $this->Operations->changeSubscription(self::IDS[0], 'cancel', 'Reviewed request');
            self::fail('Provider failure was ignored.');
        } catch (PayPalException) {
            $data = Subscriptions::getSubscriptionData(self::IDS[0]);
            self::assertSame(Subscriptions::STATUS_ACTIVE, $data['subscriptionData']['status']);
            self::assertTrue($data['active']);
        }
    }

    public function testReadReturnsLocalAndProviderDetailsAndAccountAssignment(): void
    {
        $this->insert(self::IDS[0], AccountContext::getHash());
        $this->Client->responses[] = [
            'status' => 200,
            'body' => json_encode(['id' => self::IDS[0], 'status' => 'ACTIVE'])
        ];
        $result = $this->Operations->subscription(self::IDS[0]);
        self::assertSame(self::IDS[0], $result['id']);
        self::assertTrue($result['providerAvailable']);
        self::assertTrue($result['accountContextValid']);
        self::assertSame('ACTIVE', $result['provider']['status']);
        self::assertSame([], $result['transactions']);

        $this->insert(self::IDS[1], null);
        $this->Client->responses[] = [
            'status' => 404,
            'body' => '{"name":"RESOURCE_NOT_FOUND"}'
        ];
        $result = $this->Operations->subscription(self::IDS[1]);
        self::assertFalse($result['providerAvailable']);
        self::assertFalse($result['accountContextValid']);
        self::assertSame(Subscriptions::STATUS_UNASSIGNED, $result['local']['subscriptionData']['status']);
    }

    public function testListsArePagedBoundToTheAccountAndSearchIsParameterized(): void
    {
        $this->insert(self::IDS[0], AccountContext::getHash());
        $this->insert(self::IDS[1], AccountContext::getHash());
        $this->insert(self::IDS[2], 'foreign-account');
        $result = $this->Operations->subscriptions('I-PHPUNITMCP', 2, 1);
        self::assertSame(2, $result['count']);
        self::assertSame([self::IDS[1]], array_column($result['subscriptions'], 'paypal_subscription_id'));
        self::assertSame(2, $result['page']);
        self::assertSame(1, $result['perPage']);
        self::assertArrayNotHasKey('paypal_account_hash', $result['subscriptions'][0]);
        self::assertSame(0, $this->Operations->subscriptions("' OR 1=1 --", 1, 10)['count']);
        self::assertSame([], $this->Client->requests);
    }

    public function testCleanupCannotDeleteAnAssignedSubscription(): void
    {
        $this->insert(self::IDS[0], AccountContext::getHash());

        try {
            $this->Operations->deleteUnassignedSubscription(self::IDS[0]);
            self::fail('Assigned subscription was deleted.');
        } catch (RuntimeException) {
            self::assertTrue(Subscriptions::exists(self::IDS[0]));
        }

        $this->insert(self::IDS[1], null);
        $this->Operations->deleteUnassignedSubscription(self::IDS[1]);
        self::assertFalse(QUI::getDataBaseConnection()->fetchOne(
            'SELECT paypal_subscription_id FROM ' . $this->table() . ' WHERE paypal_subscription_id = ?',
            [self::IDS[1]]
        ));
        self::assertSame([], $this->Client->requests);
    }

    public function testConfigurationReturnsPresenceFlagsWithoutCredentials(): void
    {
        $Config = Settings::getConfig();
        $values = [];

        foreach (['client_id', 'client_secret', 'sandbox_client_id', 'sandbox_client_secret', 'sandbox'] as $key) {
            $values[$key] = $Config->get('api', $key);
        }

        try {
            $Config->setValue('api', 'client_id', 'private-client-id');
            $Config->setValue('api', 'client_secret', 'private-client-secret');
            $Config->setValue('api', 'sandbox_client_id', '');
            $Config->setValue('api', 'sandbox_client_secret', '');
            $Config->setValue('api', 'sandbox', 0);
            $result = $this->Operations->configuration();
            self::assertSame('production', $result['activeEnvironment']);
            self::assertSame([
                'production' => ['clientIdConfigured' => true, 'clientSecretConfigured' => true],
                'sandbox' => ['clientIdConfigured' => false, 'clientSecretConfigured' => false]
            ], $result['environments']);
            self::assertStringNotContainsString('private-client', json_encode($result, JSON_THROW_ON_ERROR));

            // An unconfigured environment returns immediately without making network requests.
            $result = $this->Operations->testConnection('EUR', 'DE', 'sandbox');
            self::assertSame('sandbox', $result['environment']);
            self::assertSame('production', $result['activeEnvironment']);
            self::assertSame('missing_credentials', $result['steps'][0]['reason']);
            self::assertFalse((bool)$Config->get('api', 'sandbox'));
        } finally {
            foreach ($values as $key => $value) {
                $Config->setValue('api', $key, $value);
            }
        }
    }

    private function insert(string $id, ?string $accountHash): void
    {
        QUI::getDataBaseConnection()->insert($this->table(), [
            'paypal_subscription_id' => $id,
            'paypal_plan_id' => 'P-PHPUNITMCP001',
            'paypal_account_hash' => $accountHash,
            'paypal_account_check_hash' => AccountContext::getHash(),
            'customer' => '{}',
            'subscription_data' => '{"status":"ACTIVE"}',
            'global_process_id' => 'phpunit-paypal-mcp',
            'active' => 1
        ]);
    }

    private function cleanup(): void
    {
        foreach (self::IDS as $id) {
            QUI::getDataBaseConnection()->delete($this->table(), ['paypal_subscription_id' => $id]);
        }
    }

    private function table(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTIONS);
    }
}
