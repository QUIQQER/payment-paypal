<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\ERP\Payments\PayPal\Settings;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

final class SubscriptionsWebhookTest extends TestCase
{
    private const EVENT_ID = 'phpunit_paypal_webhook_event';
    private const SUBSCRIPTION_ID = 'phpunit_paypal_webhook_subscription';
    private const TRANSACTION_ID = 'phpunit_paypal_webhook_transaction';

    private object $Config;
    private mixed $previousWebhookId;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_event_id')
                ->from($this->webhookTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal webhook table is not available: ' . $Throwable->getMessage()
            );
        }

        $this->Config = Settings::getConfig();
        $this->previousWebhookId = $this->Config->get('api', 'webhook_id');
        $this->Config->setValue('api', 'webhook_id', 'phpunit-webhook-id');

        $this->cleanupFixtures();
        $this->connection()->insert(
            $this->subscriptionsTable(),
            [
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_plan_id' => 'phpunit-plan',
                'customer' => '{}',
                'subscription_data' => json_encode([
                    'status' => Subscriptions::STATUS_ACTIVE
                ]),
                'global_process_id' => 'phpunit-process',
                'active' => 1,
                'paypal_account_hash' => AccountContext::getHash()
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->setApiClient(null);
        $this->cleanupFixtures();

        if ($this->previousWebhookId === null) {
            $this->Config->del('api', 'webhook_id');
        } else {
            $this->Config->setValue(
                'api',
                'webhook_id',
                (string)$this->previousWebhookId
            );
        }

        parent::tearDown();
    }

    public function testDuplicateWebhookIsAcknowledgedWithoutReprocessing(): void
    {
        $Client = $this->verifiedClient(2);
        $this->setApiClient($Client);

        $cancelledEvent = $this->event(Subscriptions::STATUS_CANCELLED);
        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($cancelledEvent))
        );
        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::SUBSCRIPTION_ID)
        );

        $replayedEvent = $this->event(Subscriptions::STATUS_ACTIVE);
        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($replayedEvent))
        );

        $data = Subscriptions::getSubscriptionData(self::SUBSCRIPTION_ID);
        self::assertIsArray($data);
        self::assertFalse($data['active']);
        self::assertSame(
            Subscriptions::STATUS_CANCELLED,
            $data['subscriptionData']['status']
        );

        $eventRows = $this->connection()->fetchAllAssociative(
            'SELECT processed FROM ' . $this->webhookTable()
            . ' WHERE paypal_event_id = ?',
            [self::EVENT_ID]
        );
        self::assertCount(1, $eventRows);
        self::assertSame(1, (int)$eventRows[0]['processed']);
    }

    public function testUnprocessedDuplicateWebhookIsRetried(): void
    {
        $this->setApiClient($this->verifiedClient());
        $cancelledEvent = $this->event(Subscriptions::STATUS_CANCELLED);

        $this->connection()->insert(
            $this->webhookTable(),
            [
                'paypal_event_id' => self::EVENT_ID,
                'paypal_event_type' => $cancelledEvent['event_type'],
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_event_data' => json_encode($cancelledEvent),
                'paypal_event_date' => '2026-07-27 10:30:00',
                'processed' => 0
            ]
        );

        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($cancelledEvent))
        );

        $data = Subscriptions::getSubscriptionData(self::SUBSCRIPTION_ID);
        self::assertIsArray($data);
        self::assertFalse($data['active']);
        self::assertSame(
            Subscriptions::STATUS_CANCELLED,
            $data['subscriptionData']['status']
        );
        self::assertSame(1, $this->processedValue());
    }

    public function testWebhookWithoutConfiguredIdIsRejected(): void
    {
        $this->Config->setValue('api', 'webhook_id', '');
        $Client = new SubscriptionsApiClientDouble();
        $this->setApiClient($Client);

        self::assertFalse(
            Subscriptions::handleWebhook([], json_encode($this->event(
                Subscriptions::STATUS_ACTIVE
            )))
        );
        self::assertSame([], $Client->requests);
    }

    public function testInvalidWebhookSignatureIsRejected(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');
        $Client->responses[] = [
            'body' => '{"verification_status":"FAILURE"}',
            'status' => 200
        ];
        $this->setApiClient($Client);

        self::assertFalse(
            Subscriptions::handleWebhook([], json_encode($this->event(
                Subscriptions::STATUS_ACTIVE
            )))
        );
        self::assertSame(0, $this->webhookCount());
    }

    public function testWebhookVerificationFailureIsRejected(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');
        $Client->responses[] = [
            'body' => false,
            'status' => 0
        ];
        $this->setApiClient($Client);

        self::assertFalse(
            Subscriptions::handleWebhook([], json_encode($this->event(
                Subscriptions::STATUS_ACTIVE
            )))
        );
        self::assertSame(0, $this->webhookCount());
    }

    public function testMalformedVerifiedEventIsRejected(): void
    {
        $this->setApiClient($this->verifiedClient());

        self::assertFalse(Subscriptions::handleWebhook([], '{}'));
        self::assertSame(0, $this->webhookCount());
    }

    public function testCompletedPaymentWebhookPersistsTransaction(): void
    {
        $this->setApiClient($this->verifiedClient());
        $event = [
            'id' => self::EVENT_ID,
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'create_time' => '2026-07-27T10:35:00Z',
            'resource' => [
                'id' => self::TRANSACTION_ID,
                'billing_agreement_id' => self::SUBSCRIPTION_ID,
                'amount' => [
                    'total' => '12.50',
                    'currency' => 'EUR'
                ],
                'create_time' => '2026-07-27T10:34:00Z',
                'state' => 'completed'
            ]
        ];

        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($event))
        );

        $transaction = $this->connection()->fetchAssociative(
            'SELECT * FROM ' . $this->transactionsTable()
            . ' WHERE paypal_transaction_id = ?',
            [self::TRANSACTION_ID]
        );
        self::assertIsArray($transaction);
        self::assertSame(self::SUBSCRIPTION_ID, $transaction['paypal_subscription_id']);
        self::assertSame('phpunit-process', $transaction['global_process_id']);
        self::assertSame(
            Subscriptions::TRANSACTION_STATE_COMPLETED,
            json_decode($transaction['paypal_transaction_data'], true)['status']
        );
        self::assertSame(1, $this->processedValue());
    }

    public function testPaymentWebhookWithoutTransactionIdIsMarkedProcessed(): void
    {
        $this->setApiClient($this->verifiedClient());
        $event = [
            'id' => self::EVENT_ID,
            'event_type' => 'PAYMENT.SALE.DENIED',
            'resource' => [
                'billing_agreement_id' => self::SUBSCRIPTION_ID
            ]
        ];

        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($event))
        );
        self::assertSame(0, $this->transactionCount());
        self::assertSame(1, $this->processedValue());
    }

    public function testLifecycleWebhookForUnknownSubscriptionIsMarkedProcessed(): void
    {
        $this->setApiClient($this->verifiedClient());
        $event = $this->event(Subscriptions::STATUS_ACTIVE);
        $event['resource']['id'] = 'phpunit_unknown_subscription';

        self::assertTrue(
            Subscriptions::handleWebhook([], json_encode($event))
        );
        self::assertSame(1, $this->processedValue());
    }

    private function event(string $status): array
    {
        return [
            'id' => self::EVENT_ID,
            'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
            'create_time' => '2026-07-27T10:30:00Z',
            'resource' => [
                'id' => self::SUBSCRIPTION_ID,
                'plan_id' => 'phpunit-plan',
                'status' => $status
            ]
        ];
    }

    private function setApiClient(?SubscriptionsApiClientDouble $Client): void
    {
        $Property = new ReflectionProperty(Subscriptions::class, 'ApiClient');
        $Property->setValue(null, $Client);
    }

    private function cleanupFixtures(): void
    {
        $this->connection()->delete(
            $this->transactionsTable(),
            ['paypal_transaction_id' => self::TRANSACTION_ID]
        );
        $this->connection()->delete(
            $this->webhookTable(),
            ['paypal_event_id' => self::EVENT_ID]
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

    private function webhookTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_WEBHOOK_EVENTS);
    }

    private function subscriptionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTIONS);
    }

    private function transactionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS);
    }

    private function verifiedClient(int $responses = 1): SubscriptionsApiClientDouble
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');

        for ($i = 0; $i < $responses; $i++) {
            $Client->responses[] = [
                'body' => '{"verification_status":"SUCCESS"}',
                'status' => 200
            ];
        }

        return $Client;
    }

    private function webhookCount(): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->webhookTable()
            . ' WHERE paypal_event_id = ?',
            [self::EVENT_ID]
        );
    }

    private function transactionCount(): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->transactionsTable()
            . ' WHERE paypal_transaction_id = ?',
            [self::TRANSACTION_ID]
        );
    }

    private function processedValue(): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT processed FROM ' . $this->webhookTable()
            . ' WHERE paypal_event_id = ?',
            [self::EVENT_ID]
        );
    }
}
