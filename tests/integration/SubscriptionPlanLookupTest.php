<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Article;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use QUI\ERP\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsDouble;

final class SubscriptionPlanLookupTest extends TestCase
{
    private const PRODUCT_ID = 'phpunit_subscription_product';
    private const PLAN_ID = 'phpunit_subscription_plan';

    protected function tearDown(): void
    {
        $this->connection()->delete(
            SubscriptionsDouble::getSubscriptionPlansTableForTest(),
            ['paypal_plan_id' => self::PLAN_ID]
        );

        parent::tearDown();
    }

    public function testStoredPlanReferencesAreFoundByOrderIdentity(): void
    {
        $Order = $this->order();
        $hash = SubscriptionsDouble::getIdentificationHashForTest($Order);

        $this->connection()->insert(
            SubscriptionsDouble::getSubscriptionPlansTableForTest(),
            [
                'paypal_product_id' => self::PRODUCT_ID,
                'paypal_plan_id' => self::PLAN_ID,
                'identification_hash' => $hash,
                'plan_data' => '{}'
            ]
        );

        self::assertSame([
            'paypal_product_id' => self::PRODUCT_ID,
            'paypal_plan_id' => self::PLAN_ID
        ], SubscriptionsDouble::getPlanByOrderForTest($Order));
        self::assertSame(
            [self::PRODUCT_ID, self::PLAN_ID],
            SubscriptionsDouble::getStoredPlanReferencesForTest($Order)
        );
    }

    public function testUnknownOrderHasNoStoredPlan(): void
    {
        self::assertFalse(
            SubscriptionsDouble::getPlanByOrderForTest($this->order())
        );
    }

    private function order(): OrderDouble
    {
        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn(
            new CalculationValue(29.95)
        );
        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');
        $Customer = $this->createMock(User::class);
        $Customer->method('getLang')->willReturn('de');
        $ArticleA = $this->createMock(Article::class);
        $ArticleA->method('getId')->willReturn(20);
        $ArticleB = $this->createMock(Article::class);
        $ArticleB->method('getId')->willReturn(10);
        $Articles = new ArticleList();
        $Articles->addArticle($ArticleA);
        $Articles->addArticle($ArticleB);

        $Order = new OrderDouble();
        $Order->PriceCalculation = $Calculations;
        $Order->CurrencyValue = $Currency;
        $Order->CustomerValue = $Customer;
        $Order->useArticles($Articles);

        return $Order;
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }
}
