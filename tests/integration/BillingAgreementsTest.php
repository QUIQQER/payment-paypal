<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use DateTime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class BillingAgreementsTest extends TestCase
{
    private const AGREEMENT_ID = 'phpunit_legacy_agreement';

    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_agreement_id')
                ->from($this->table())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal billing agreement table is not available: ' . $Throwable->getMessage()
            );
        }

        $this->cleanupFixture();
        $this->insertFixture();

        $this->Payment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->Payment);
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function testStoredAgreementDataAndListAreAvailable(): void
    {
        self::assertSame([
            'active' => true,
            'globalProcessId' => 'phpunit-legacy-process',
            'customer' => [
                'lang' => 'en',
                'email' => 'legacy@example.test'
            ]
        ], BillingAgreementsDouble::getBillingAgreementData(self::AGREEMENT_ID));

        $list = BillingAgreementsDouble::getBillingAgreementList([
            'search' => 'phpunit-legacy',
            'sortOn' => 'global_process_id',
            'sortBy' => 'ASC',
            'perPage' => 10,
            'page' => 1
        ]);

        self::assertCount(1, $list);
        self::assertSame(self::AGREEMENT_ID, $list[0]['paypal_agreement_id']);
        self::assertSame(1, BillingAgreementsDouble::getBillingAgreementList([
            'search' => 'phpunit-legacy'
        ], true));
    }

    public function testAgreementDetailsAndSuspensionUseLegacyApi(): void
    {
        $this->Payment->apiResponse = [
            'id' => self::AGREEMENT_ID,
            'state' => BillingAgreements::BILLING_AGREEMENT_STATE_SUSPENDED
        ];

        self::assertSame(
            $this->Payment->apiResponse,
            BillingAgreementsDouble::getBillingAgreementDetails(self::AGREEMENT_ID)
        );
        self::assertTrue(BillingAgreementsDouble::isSuspended(self::AGREEMENT_ID));
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT,
            $this->Payment->apiCalls[0]['request']
        );
    }

    public function testAgreementTransactionsNormalizeEqualDates(): void
    {
        $transactions = [
            ['transaction_id' => 'TRANSACTION-1']
        ];
        $this->Payment->apiResponse = [
            'agreement_transaction_list' => $transactions
        ];

        self::assertSame(
            $transactions,
            BillingAgreementsDouble::getBillingAgreementTransactions(
                self::AGREEMENT_ID,
                new DateTime('2026-01-10'),
                new DateTime('2026-01-10')
            )
        );
        self::assertSame([
            Payment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => self::AGREEMENT_ID,
            'start_date' => '2026-01-10',
            'end_date' => '2026-01-11'
        ], $this->Payment->apiCalls[0]['transaction']);
    }

    public function testSuspendAndResumeSendNotesToLegacyApi(): void
    {
        BillingAgreementsDouble::suspendBillingAgreement(self::AGREEMENT_ID, 'Review');
        BillingAgreementsDouble::resumeSubscription(self::AGREEMENT_ID, 'Approved');

        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_SUSPEND_BILLING_AGREEMENT,
            $this->Payment->apiCalls[0]['request']
        );
        self::assertSame(['note' => 'Review'], $this->Payment->apiCalls[0]['body']);
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_RESUME_BILLING_AGREEMENT,
            $this->Payment->apiCalls[1]['request']
        );
        self::assertSame(['note' => 'Approved'], $this->Payment->apiCalls[1]['body']);
    }

    public function testCancelSendsReasonAndMarksAgreementInactive(): void
    {
        BillingAgreementsDouble::cancelBillingAgreement(
            self::AGREEMENT_ID,
            'Customer request'
        );

        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
            $this->Payment->apiCalls[0]['request']
        );
        self::assertSame(
            ['note' => 'Customer request'],
            $this->Payment->apiCalls[0]['body']
        );
        self::assertFalse(
            BillingAgreementsDouble::getBillingAgreementData(self::AGREEMENT_ID)['active']
        );
    }

    public function testMissingAgreementSkipsLifecycleApiCalls(): void
    {
        BillingAgreementsDouble::cancelBillingAgreement('missing', 'Cancel');
        BillingAgreementsDouble::suspendBillingAgreement('missing', 'Suspend');
        BillingAgreementsDouble::resumeSubscription('missing', 'Resume');

        self::assertSame([], $this->Payment->apiCalls);
        self::assertFalse(BillingAgreementsDouble::getBillingAgreementData('missing'));
    }

    public function testLegacyTableNamesUseQuiqqerPrefix(): void
    {
        self::assertSame(
            $this->table(),
            BillingAgreementsDouble::getBillingAgreementsTable()
        );
        self::assertSame(
            QUI::getDBTableName(BillingAgreements::TBL_BILLING_AGREEMENT_TRANSACTIONS),
            BillingAgreementsDouble::getBillingAgreementTransactionsTable()
        );
    }

    private function insertFixture(): void
    {
        $this->connection()->insert(
            $this->table(),
            [
                'paypal_agreement_id' => self::AGREEMENT_ID,
                'paypal_plan_id' => 'phpunit-legacy-plan',
                'customer' => json_encode([
                    'lang' => 'en',
                    'email' => 'legacy@example.test'
                ]),
                'global_process_id' => 'phpunit-legacy-process',
                'active' => 1
            ]
        );
    }

    private function cleanupFixture(): void
    {
        $this->connection()->delete(
            $this->table(),
            ['paypal_agreement_id' => self::AGREEMENT_ID]
        );
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private function table(): string
    {
        return QUI::getDBTableName(BillingAgreements::TBL_BILLING_AGREEMENTS);
    }
}
