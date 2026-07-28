<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\User;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class BillingAgreementExecutionTest extends TestCase
{
    private const AGREEMENT_ID = 'phpunit_executed_agreement';

    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanup();
        $this->Payment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->Payment);
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        $this->cleanup();

        parent::tearDown();
    }

    public function testAgreementExecutionPersistsLegacyReference(): void
    {
        $this->Payment->apiResponse = [
            'id' => self::AGREEMENT_ID
        ];
        $Order = $this->order();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_BILLING_PLAN_ID,
            'PLAN-EXECUTED'
        );

        BillingAgreementsDouble::executeBillingAgreement(
            $Order,
            'EC-AGREEMENT-TOKEN'
        );

        self::assertSame(
            self::AGREEMENT_ID,
            $Order->getPaymentDataEntry(
                Payment::ATTR_PAYPAL_BILLING_AGREEMENT_ID
            )
        );
        self::assertSame(
            'EC-AGREEMENT-TOKEN',
            $Order->getPaymentDataEntry(
                Payment::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN
            )
        );
        self::assertTrue(
            $Order->getPaymentDataEntry(
                \QUI\ERP\Payments\PayPal\Payment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL
            )
        );

        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM ' . BillingAgreements::getBillingAgreementsTable()
            . ' WHERE paypal_agreement_id = ?',
            [self::AGREEMENT_ID]
        );

        self::assertSame('PLAN-EXECUTED', $row['paypal_plan_id']);
        self::assertSame($Order->getUUID(), $row['global_process_id']);
        self::assertSame(1, (int)$row['active']);
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
            $this->Payment->apiCalls[0]['request']
        );
    }

    public function testExistingAgreementIsNotExecutedAgain(): void
    {
        $Order = $this->order();
        $Order->setPaymentData(
            Payment::ATTR_PAYPAL_BILLING_AGREEMENT_ID,
            'ALREADY-EXECUTED'
        );

        BillingAgreementsDouble::executeBillingAgreement(
            $Order,
            'IGNORED-TOKEN'
        );

        self::assertSame([], $this->Payment->apiCalls);
    }

    public function testLegacyExecutionFailureIsTranslated(): void
    {
        $this->Payment->apiException = new PayPalException('API failure');
        $Order = $this->order();

        try {
            BillingAgreementsDouble::executeBillingAgreement(
                $Order,
                'FAILED-TOKEN'
            );
            self::fail('The legacy execution failure was not propagated.');
        } catch (PayPalException) {
            self::assertContains(
                Utils::getHistoryText('api.error'),
                $Order->history
            );
            self::assertNotNull($Order->updateUser);
        }
    }

    private function order(): OrderDouble
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getAttributes')->willReturn([
            'email' => 'agreement@example.test'
        ]);

        $Order = new OrderDouble();
        $Order->CustomerValue = $Customer;
        $Order->uuidValue = 'phpunit-executed-process';

        return $Order;
    }

    private function cleanup(): void
    {
        $this->connection()->delete(
            BillingAgreements::getBillingAgreementsTable(),
            ['paypal_agreement_id' => self::AGREEMENT_ID]
        );
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }
}
