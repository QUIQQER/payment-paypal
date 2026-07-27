<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

final class SubscriptionsWebhookTest extends TestCase
{
    private const EVENT_ID = 'phpunit_paypal_webhook_event';
    private const SUBSCRIPTION_ID = 'phpunit_paypal_webhook_subscription';

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

        $this->Config = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
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
                'active' => 1
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
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');
        $Client->responses = [
            [
                'body' => '{"verification_status":"SUCCESS"}',
                'status' => 200
            ],
            [
                'body' => '{"verification_status":"SUCCESS"}',
                'status' => 200
            ]
        ];
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
}
