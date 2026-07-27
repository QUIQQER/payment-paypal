<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsDouble;

final class SubscriptionsOperationsTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'phpunit_paypal_operations_subscription';

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
    }

    protected function tearDown(): void
    {
        $this->setApiClient(null);
        SubscriptionsDouble::useGateway(null);
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function testExistingApprovalUrlIsReturnedWithoutApiRequest(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $this->setApiClient($Client);
        $Order = new OrderDouble();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL,
            'https://paypal.example/approve'
        );

        self::assertSame(
            'https://paypal.example/approve',
            Subscriptions::createSubscription($Order)
        );
        self::assertSame([], $Client->requests);
    }

    public function testCreateSubscriptionPersistsModernApiResponse(): void
    {
        $Client = $this->apiClientWithResponses([[
            'id' => self::SUBSCRIPTION_ID,
            'status' => Subscriptions::STATUS_APPROVAL_PENDING,
            'links' => [[
                'rel' => 'approve',
                'href' => 'https://paypal.example/approve-subscription'
            ]]
        ]]);
        $this->setApiClient($Client);

        $Gateway = $this->createMock(Gateway::class);
        $Gateway->method('getSuccessUrl')->willReturn('https://example.test/success?');
        $Gateway->method('getCancelUrl')->willReturn('https://example.test/cancel?');
        SubscriptionsDouble::useGateway($Gateway);

        $Customer = $this->createMock(QUI\ERP\User::class);
        $Customer->method('getAttribute')->willReturnMap([
            ['firstname', 'Jane'],
            ['lastname', 'Doe'],
            ['email', 'jane@example.test']
        ]);

        $Order = new OrderDouble();
        $Order->CustomerValue = $Customer;

        self::assertSame(
            'https://paypal.example/approve-subscription',
            SubscriptionsDouble::createSubscription($Order)
        );
        self::assertSame(
            self::SUBSCRIPTION_ID,
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID)
        );
        self::assertSame(
            'PRODUCT-1',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_PRODUCT_ID)
        );
        self::assertSame(
            'PLAN-1',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID)
        );
        self::assertContains(
            'PayPal :: Subscription created: ' . self::SUBSCRIPTION_ID,
            $Order->history
        );

        $request = $this->requestBody($Client);
        self::assertSame('PLAN-1', $request['plan_id']);
        self::assertSame([
            'given_name' => 'Jane',
            'surname' => 'Doe'
        ], $request['subscriber']['name']);
        self::assertSame(
            'https://example.test/success',
            $request['application_context']['return_url']
        );

        $data = Subscriptions::getSubscriptionData(self::SUBSCRIPTION_ID);
        self::assertIsArray($data);
        self::assertSame('PLAN-1', $data['planId']);
        self::assertSame('jane@example.test', $data['customer']['email']);
    }

    public function testCancelSubscriptionUsesDefaultReasonAndMarksRecordInactive(): void
    {
        $this->insertSubscription();
        $Client = $this->apiClientWithResponses([[]]);
        $this->setApiClient($Client);

        Subscriptions::cancelSubscription(self::SUBSCRIPTION_ID);

        self::assertFalse(
            Subscriptions::isSubscriptionActiveAtQuiqqer(self::SUBSCRIPTION_ID)
        );
        self::assertSame(
            '/v1/billing/subscriptions/' . self::SUBSCRIPTION_ID . '/cancel',
            $this->requestPath($Client)
        );
        self::assertSame(
            ['reason' => 'Cancelled from QUIQQER'],
            $this->requestBody($Client)
        );
    }

    public function testSuspendAndActivateSubscriptionUseProvidedAndDefaultNotes(): void
    {
        $Client = $this->apiClientWithResponses([[], []]);
        $this->setApiClient($Client);

        Subscriptions::suspendSubscription(self::SUBSCRIPTION_ID, 'Payment overdue');
        Subscriptions::activateSubscription(self::SUBSCRIPTION_ID);

        self::assertSame(
            ['reason' => 'Payment overdue'],
            $this->requestBody($Client, 0)
        );
        self::assertSame(
            ['reason' => 'Activated from QUIQQER'],
            $this->requestBody($Client, 1)
        );
    }

    public function testSubscriptionDetailsAndSuspensionStatusUseApi(): void
    {
        $Client = $this->apiClientWithResponses([
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_ACTIVE
            ],
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_SUSPENDED
            ]
        ]);
        $this->setApiClient($Client);

        self::assertSame(
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_ACTIVE
            ],
            Subscriptions::getSubscriptionDetails(self::SUBSCRIPTION_ID)
        );
        self::assertTrue(Subscriptions::isSuspended(self::SUBSCRIPTION_ID));
    }

    public function testProviderActivitySupportsAllPayPalLifecycleStates(): void
    {
        $statuses = [
            Subscriptions::STATUS_ACTIVE => true,
            Subscriptions::STATUS_APPROVAL_PENDING => true,
            Subscriptions::STATUS_APPROVED => true,
            Subscriptions::STATUS_SUSPENDED => true,
            Subscriptions::STATUS_CANCELLED => false
        ];
        $Client = $this->apiClientWithResponses(
            array_map(
                static fn(string $status): array => ['status' => $status],
                array_keys($statuses)
            )
        );
        $this->setApiClient($Client);

        foreach ($statuses as $expected) {
            self::assertSame(
                $expected,
                Subscriptions::isSubscriptionActiveAtPaymentProvider(self::SUBSCRIPTION_ID)
            );
        }
    }

    public function testProviderActivityRemainsTrueWhenApiIsUnavailable(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');
        $Client->responses[] = [
            'body' => false,
            'status' => 0
        ];
        $this->setApiClient($Client);

        self::assertTrue(
            Subscriptions::isSubscriptionActiveAtPaymentProvider(self::SUBSCRIPTION_ID)
        );
    }

    public function testApproveSubscriptionInsertsAndUpdatesStoredRecord(): void
    {
        $Client = $this->apiClientWithResponses([
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_ACTIVE,
                'plan_id' => 'PLAN-1',
                'subscriber' => ['email_address' => 'first@example.test']
            ],
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_APPROVED,
                'plan_id' => 'PLAN-2',
                'subscriber' => ['email_address' => 'second@example.test']
            ]
        ]);
        $this->setApiClient($Client);
        $Order = new OrderDouble();

        Subscriptions::approveSubscription($Order, self::SUBSCRIPTION_ID);

        self::assertSame(
            self::SUBSCRIPTION_ID,
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID)
        );
        self::assertSame(
            'PLAN-1',
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID)
        );
        self::assertTrue(
            (bool)$Order->getPaymentDataEntry(BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL)
        );
        self::assertNotNull($Order->updateUser);
        self::assertContains(
            'PayPal :: Subscription approved: ' . self::SUBSCRIPTION_ID,
            $Order->history
        );

        Subscriptions::approveSubscription($Order, self::SUBSCRIPTION_ID);

        $data = Subscriptions::getSubscriptionData(self::SUBSCRIPTION_ID);
        self::assertIsArray($data);
        self::assertSame('PLAN-2', $data['planId']);
        self::assertSame(
            'second@example.test',
            $data['customer']['email_address']
        );
        self::assertSame(
            Subscriptions::STATUS_APPROVED,
            $data['subscriptionData']['status']
        );
    }

    public function testApproveSubscriptionRejectsInvalidStatus(): void
    {
        $Client = $this->apiClientWithResponses([
            ['status' => Subscriptions::STATUS_CANCELLED]
        ]);
        $this->setApiClient($Client);

        $this->expectException(PayPalException::class);

        Subscriptions::approveSubscription(
            new OrderDouble(),
            self::SUBSCRIPTION_ID
        );
    }

    private function apiClientWithResponses(array $responses): SubscriptionsApiClientDouble
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

    private function requestPath(
        SubscriptionsApiClientDouble $Client,
        int $index = 0
    ): string {
        $url = $Client->requests[$index]['url'];

        return substr($url, strpos($url, '/v1/'));
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
                'paypal_plan_id' => 'phpunit-plan',
                'customer' => '{}',
                'subscription_data' => '{}',
                'global_process_id' => 'phpunit-process',
                'active' => 1
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
