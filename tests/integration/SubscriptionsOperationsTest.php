<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\ERP\Plans\Handler as PlansHandler;
use QUI\ERP\Products\Product\Product;
use QUI\Locale;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsDouble;

final class SubscriptionsOperationsTest extends TestCase
{
    private const SUBSCRIPTION_ID = 'phpunit_paypal_operations_subscription';
    private const PLAN_ID = 'PLAN-1';

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

    public function testProductAndPlanPayloadsUseModernSubscriptionsApi(): void
    {
        $Client = $this->apiClientWithResponses([
            ['id' => 'PRODUCT-NEW'],
            ['id' => 'PLAN-NEW', 'status' => 'ACTIVE']
        ]);
        $this->setApiClient($Client);

        $Locale = $this->createMock(Locale::class);
        $Customer = $this->createMock(QUI\ERP\User::class);
        $Customer->method('getLocale')->willReturn($Locale);

        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn(new CalculationValue(24.95));

        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');

        $Order = new OrderDouble();
        $Order->CustomerValue = $Customer;
        $Order->PriceCalculation = $Calculations;
        $Order->CurrencyValue = $Currency;

        $PlanProduct = $this->createMock(Product::class);
        $PlanProduct->method('getTitle')->willReturn(str_repeat('T', 140));
        $PlanProduct->method('getDescription')->willReturn('');
        $PlanProduct->method('getFieldValue')->willReturnMap([
            [PlansHandler::FIELD_AUTO_EXTEND, 1],
            [PlansHandler::FIELD_DURATION, '12-month'],
            [PlansHandler::FIELD_NOTICE_PERIOD, '1-month'],
            [PlansHandler::FIELD_INVOICE_INTERVAL, '1-month'],
            [PlansHandler::FIELD_MIN_DURATION, '12-month']
        ]);

        self::assertSame(
            'PRODUCT-NEW',
            SubscriptionsDouble::createProductForTest($Order, $PlanProduct)
        );
        self::assertSame(
            ['id' => 'PLAN-NEW', 'status' => 'ACTIVE'],
            SubscriptionsDouble::createPlanForTest(
                $Order,
                $PlanProduct,
                'PRODUCT-NEW'
            )
        );

        $productBody = $this->requestBody($Client, 0);
        self::assertSame('SERVICE', $productBody['type']);
        self::assertSame(127, strlen($productBody['name']));

        $planBody = $this->requestBody($Client, 1);
        self::assertSame('PRODUCT-NEW', $planBody['product_id']);
        self::assertSame(
            [
                'interval_unit' => 'MONTH',
                'interval_count' => 1
            ],
            $planBody['billing_cycles'][0]['frequency']
        );
        self::assertSame(
            [
                'value' => '24.95',
                'currency_code' => 'EUR'
            ],
            $planBody['billing_cycles'][0]['pricing_scheme']['fixed_price']
        );
        self::assertSame(0, $planBody['billing_cycles'][0]['total_cycles']);
    }

    public function testFiniteSubscriptionCycleCountIsCalculated(): void
    {
        self::assertSame(0, SubscriptionsDouble::getCycleCountForTest([
            'auto_extend' => true
        ]));
        self::assertSame(12, SubscriptionsDouble::getCycleCountForTest([
            'auto_extend' => false,
            'duration_interval' => '12-month',
            'invoice_interval' => '1-month'
        ]));
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
        self::assertSame(
            Subscriptions::STATUS_CANCELLED,
            Subscriptions::getSubscriptionData(
                self::SUBSCRIPTION_ID
            )['subscriptionData']['status']
        );
    }

    public function testSuspendAndActivateSubscriptionUseProvidedAndDefaultNotes(): void
    {
        $this->insertSubscription();
        $Client = $this->apiClientWithResponses([[], []]);
        $this->setApiClient($Client);

        Subscriptions::suspendSubscription(self::SUBSCRIPTION_ID, 'Payment overdue');
        self::assertSame(
            Subscriptions::STATUS_SUSPENDED,
            Subscriptions::getSubscriptionData(
                self::SUBSCRIPTION_ID
            )['subscriptionData']['status']
        );

        Subscriptions::activateSubscription(self::SUBSCRIPTION_ID);

        self::assertSame(
            ['reason' => 'Payment overdue'],
            $this->requestBody($Client, 0)
        );
        self::assertSame(
            ['reason' => 'Activated from QUIQQER'],
            $this->requestBody($Client, 1)
        );
        self::assertSame(
            Subscriptions::STATUS_ACTIVE,
            Subscriptions::getSubscriptionData(
                self::SUBSCRIPTION_ID
            )['subscriptionData']['status']
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
                'custom_id' => 'phpunit-order-uuid',
                'plan_id' => self::PLAN_ID,
                'subscriber' => ['email_address' => 'first@example.test']
            ],
            [
                'id' => self::SUBSCRIPTION_ID,
                'status' => Subscriptions::STATUS_APPROVED,
                'custom_id' => 'phpunit-order-uuid',
                'plan_id' => self::PLAN_ID,
                'subscriber' => ['email_address' => 'second@example.test']
            ]
        ]);
        $this->setApiClient($Client);
        $Order = $this->createOrderWithSubscriptionReferences();

        Subscriptions::approveSubscription($Order, self::SUBSCRIPTION_ID);

        self::assertSame(
            self::SUBSCRIPTION_ID,
            $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID)
        );
        self::assertSame(
            self::PLAN_ID,
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
        self::assertSame(self::PLAN_ID, $data['planId']);
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
        $Client = $this->apiClientWithResponses([[
            'id' => self::SUBSCRIPTION_ID,
            'status' => Subscriptions::STATUS_CANCELLED,
            'custom_id' => 'phpunit-order-uuid',
            'plan_id' => self::PLAN_ID
        ]]);
        $this->setApiClient($Client);

        $this->expectException(PayPalException::class);

        Subscriptions::approveSubscription(
            $this->createOrderWithSubscriptionReferences(),
            self::SUBSCRIPTION_ID
        );
    }

    public function testApproveSubscriptionRejectsPendingApproval(): void
    {
        $Client = $this->apiClientWithResponses([[
            'id' => self::SUBSCRIPTION_ID,
            'status' => Subscriptions::STATUS_APPROVAL_PENDING,
            'custom_id' => 'phpunit-order-uuid',
            'plan_id' => self::PLAN_ID
        ]]);
        $this->setApiClient($Client);
        $Order = $this->createOrderWithSubscriptionReferences();

        try {
            Subscriptions::approveSubscription($Order, self::SUBSCRIPTION_ID);
            self::fail('A subscription pending buyer approval was accepted.');
        } catch (PayPalException) {
            self::assertFalse(
                (bool)$Order->getPaymentDataEntry(
                    BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL
                )
            );
            self::assertSame([], $Order->history);
        }
    }

    public function testApproveSubscriptionRejectsUnexpectedReturnId(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $this->setApiClient($Client);

        $this->expectException(PayPalException::class);

        try {
            Subscriptions::approveSubscription(
                $this->createOrderWithSubscriptionReferences(),
                'ANOTHER-SUBSCRIPTION'
            );
        } finally {
            self::assertSame([], $Client->requests);
        }
    }

    public function testApproveSubscriptionRejectsUnexpectedApiId(): void
    {
        $this->assertSubscriptionReferenceIsRejected([
            'id' => 'ANOTHER-SUBSCRIPTION',
            'status' => Subscriptions::STATUS_ACTIVE,
            'custom_id' => 'phpunit-order-uuid',
            'plan_id' => self::PLAN_ID
        ]);
    }

    public function testApproveSubscriptionRejectsUnexpectedCustomId(): void
    {
        $this->assertSubscriptionReferenceIsRejected([
            'id' => self::SUBSCRIPTION_ID,
            'status' => Subscriptions::STATUS_ACTIVE,
            'custom_id' => 'another-order',
            'plan_id' => self::PLAN_ID
        ]);
    }

    public function testApproveSubscriptionRejectsUnexpectedPlanId(): void
    {
        $this->assertSubscriptionReferenceIsRejected([
            'id' => self::SUBSCRIPTION_ID,
            'status' => Subscriptions::STATUS_ACTIVE,
            'custom_id' => 'phpunit-order-uuid',
            'plan_id' => 'ANOTHER-PLAN'
        ]);
    }

    private function assertSubscriptionReferenceIsRejected(array $subscriptionData): void
    {
        $Client = $this->apiClientWithResponses([$subscriptionData]);
        $this->setApiClient($Client);
        $Order = $this->createOrderWithSubscriptionReferences();

        try {
            Subscriptions::approveSubscription($Order, self::SUBSCRIPTION_ID);
            self::fail('A subscription belonging to another order was accepted.');
        } catch (PayPalException) {
            self::assertFalse(
                (bool)$Order->getPaymentDataEntry(
                    BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL
                )
            );
            self::assertSame([], $Order->history);
        }
    }

    private function createOrderWithSubscriptionReferences(): OrderDouble
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_ID,
            self::SUBSCRIPTION_ID
        );
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID,
            self::PLAN_ID
        );

        return $Order;
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
