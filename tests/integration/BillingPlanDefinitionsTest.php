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
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Plans\Handler as PlansHandler;
use QUI\ERP\Products\Product\Product;
use QUI\ERP\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingPlansDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;

final class BillingPlanDefinitionsTest extends TestCase
{
    private const PLAN_ID = 'phpunit_legacy_plan_definition';

    protected function tearDown(): void
    {
        $this->connection()->delete(
            BillingPlans::getBillingPlansTable(),
            ['paypal_id' => self::PLAN_ID]
        );

        parent::tearDown();
    }

    public function testAutomaticPlanDefinitionUsesUnlimitedCycles(): void
    {
        $definitions = BillingPlansDouble::parsePaymentDefinitionsForTest(
            $this->pricedOrder(),
            $this->planProduct(true)
        );

        self::assertCount(1, $definitions);
        self::assertSame('REGULAR', $definitions[0]['type']);
        self::assertSame('1', $definitions[0]['frequency_interval']);
        self::assertSame('MONTH', $definitions[0]['frequency']);
        self::assertSame(0, $definitions[0]['cycles']);
        self::assertSame([
            'value' => '20.00',
            'currency' => 'EUR'
        ], $definitions[0]['amount']);
        self::assertSame([
            [
                'type' => 'TAX',
                'amount' => [
                    'value' => '3.80',
                    'currency' => 'EUR'
                ]
            ]
        ], $definitions[0]['charge_models']);
    }

    public function testFinitePlanDefinitionCalculatesCycles(): void
    {
        $definitions = BillingPlansDouble::parsePaymentDefinitionsForTest(
            $this->pricedOrder(),
            $this->planProduct(false)
        );

        self::assertSame(12, $definitions[0]['cycles']);
    }

    public function testIdentificationIsOrderIndependentAndFindsStoredPlan(): void
    {
        $Order = $this->pricedOrder();
        $Articles = new ArticleList();
        $Articles->addArticle($this->article(20));
        $Articles->addArticle($this->article(10));
        $Order->useArticles($Articles);

        $hash = BillingPlansDouble::getIdentificationHashForTest($Order);

        $this->connection()->insert(
            BillingPlans::getBillingPlansTable(),
            [
                'paypal_id' => self::PLAN_ID,
                'identification_hash' => $hash
            ]
        );

        self::assertSame(
            self::PLAN_ID,
            BillingPlansDouble::getBillingPlanIdForTest($Order)
        );
        self::assertSame(
            self::PLAN_ID,
            BillingPlansDouble::createBillingPlanFromOrder($Order)
        );
    }

    public function testOrderPlanReferenceTakesPrecedenceOverLookup(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_BILLING_PLAN_ID,
            'PLAN-FROM-ORDER'
        );

        self::assertSame(
            'PLAN-FROM-ORDER',
            BillingPlansDouble::createBillingPlanFromOrder($Order)
        );
    }

    private function pricedOrder(): OrderDouble
    {
        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn(new CalculationValue(23.8));
        $Calculations->method('getNettoSum')->willReturn(new CalculationValue(20));
        $Calculations->method('getVatSum')->willReturn(new CalculationValue(3.8));

        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');

        $Customer = $this->createMock(User::class);
        $Customer->method('getLang')->willReturn('de');

        $Order = new OrderDouble();
        $Order->PriceCalculation = $Calculations;
        $Order->CurrencyValue = $Currency;
        $Order->CustomerValue = $Customer;

        return $Order;
    }

    private function planProduct(bool $autoExtend): Product
    {
        $Product = $this->createMock(Product::class);
        $Product->method('getTitle')->willReturn('Monthly plan');
        $Product->method('getFieldValue')->willReturnMap([
            [PlansHandler::FIELD_AUTO_EXTEND, $autoExtend ? 1 : 0],
            [PlansHandler::FIELD_DURATION, '12-month'],
            [PlansHandler::FIELD_NOTICE_PERIOD, '1-month'],
            [PlansHandler::FIELD_INVOICE_INTERVAL, '1-month'],
            [PlansHandler::FIELD_MIN_DURATION, '12-month']
        ]);

        return $Product;
    }

    private function article(int $id): Article
    {
        $Article = $this->createMock(Article::class);
        $Article->method('getId')->willReturn($id);

        return $Article;
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }
}
