<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

final class RecurringPaymentSubscriptionsTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'phpunit_recurring_payment_subscription';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_subscription_id')
                ->from($this->subscriptionsTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal subscription table is not available: ' . $Throwable->getMessage()
            );
        }

        $this->cleanupFixture();
        $this->insertSubscription();
    }

    protected function tearDown(): void
    {
        $this->setApiClient(null);
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function testMetadataDescribesRecurringOnlyPayment(): void
    {
        $Payment = new Payment();

        self::assertNotSame('', $Payment->getTitle());
        self::assertNotSame('', $Payment->getDescription());
        self::assertTrue($Payment->supportsRecurringPaymentsOnly());
        self::assertFalse($Payment->isSubscriptionEditable());
    }

    public function testCreateSubscriptionReturnsCachedApprovalUrl(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_ID,
            self::SUBSCRIPTION_ID
        );
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL,
            'https://paypal.example/subscription'
        );

        self::assertSame(
            'https://paypal.example/subscription',
            (new Payment())->createSubscription($Order)
        );
    }

    public function testCancelRoutesKnownSubscriptionToModernApi(): void
    {
        $Client = $this->apiClient([[]]);
        $this->setApiClient($Client);

        (new Payment())->cancelSubscription(
            self::SUBSCRIPTION_ID,
            'Customer request'
        );

        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::SUBSCRIPTION_ID)
        );
        self::assertSame(
            ['reason' => 'Customer request'],
            $this->requestBody($Client)
        );
    }

    public function testSuspendAndResumeRouteKnownSubscriptionToModernApi(): void
    {
        $Client = $this->apiClient([[], []]);
        $this->setApiClient($Client);
        $Payment = new Payment();

        $Payment->suspendSubscription(self::SUBSCRIPTION_ID, 'Review');
        $Payment->resumeSubscription(self::SUBSCRIPTION_ID, 'Approved');

        self::assertStringEndsWith(
            '/suspend',
            $Client->requests[0]['url']
        );
        self::assertSame(['reason' => 'Review'], $this->requestBody($Client, 0));
        self::assertStringEndsWith(
            '/activate',
            $Client->requests[1]['url']
        );
        self::assertSame(['reason' => 'Approved'], $this->requestBody($Client, 1));
    }

    public function testSuspensionAndProviderActivityUseModernApi(): void
    {
        $Client = $this->apiClient([
            ['status' => Subscriptions::STATUS_SUSPENDED],
            ['status' => Subscriptions::STATUS_ACTIVE]
        ]);
        $this->setApiClient($Client);
        $Payment = new Payment();

        self::assertTrue($Payment->isSuspended(self::SUBSCRIPTION_ID));
        self::assertTrue(
            $Payment->isSubscriptionActiveAtPaymentProvider(self::SUBSCRIPTION_ID)
        );
    }

    public function testLocalActivityAndDeactivationUseModernRecord(): void
    {
        $Payment = new Payment();

        self::assertTrue(
            $Payment->isSubscriptionActiveAtQuiqqer(self::SUBSCRIPTION_ID)
        );
        $Payment->setSubscriptionAsInactive(self::SUBSCRIPTION_ID);
        self::assertFalse(
            $Payment->isSubscriptionActiveAtQuiqqer(self::SUBSCRIPTION_ID)
        );
    }

    public function testSubscriptionIdsAndGlobalProcessIncludeModernRecord(): void
    {
        $Payment = new Payment();

        self::assertContains(
            self::SUBSCRIPTION_ID,
            $Payment->getSubscriptionIds()
        );
        self::assertSame(
            'phpunit-recurring-process',
            $Payment->getSubscriptionGlobalProcessingId(self::SUBSCRIPTION_ID)
        );

        $Payment->setSubscriptionAsInactive(self::SUBSCRIPTION_ID);
        self::assertNotContains(
            self::SUBSCRIPTION_ID,
            $Payment->getSubscriptionIds()
        );
        self::assertContains(
            self::SUBSCRIPTION_ID,
            $Payment->getSubscriptionIds(true)
        );
    }

    private function apiClient(array $responses): SubscriptionsApiClientDouble
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');

        foreach ($responses as $response) {
            $Client->responses[] = [
                'body' => json_encode($response),
                'status' => 200
            ];
        }

        return $Client;
    }

    private function requestBody(
        SubscriptionsApiClientDouble $Client,
        int $index = 0
    ): array {
        return json_decode(
            $Client->requests[$index]['options'][CURLOPT_POSTFIELDS],
            true
        );
    }

    private function insertSubscription(): void
    {
        $this->connection()->insert(
            $this->subscriptionsTable(),
            [
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_plan_id' => 'phpunit-recurring-plan',
                'customer' => '{}',
                'subscription_data' => json_encode([
                    'status' => Subscriptions::STATUS_ACTIVE
                ]),
                'global_process_id' => 'phpunit-recurring-process',
                'active' => 1,
                'paypal_account_hash' => AccountContext::getHash()
            ]
        );
    }

    private function setApiClient(?SubscriptionsApiClientDouble $Client): void
    {
        $Property = new ReflectionProperty(Subscriptions::class, 'ApiClient');
        $Property->setValue(null, $Client);
    }

    private function cleanupFixture(): void
    {
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
}
