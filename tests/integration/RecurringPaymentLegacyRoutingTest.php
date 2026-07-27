<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class RecurringPaymentLegacyRoutingTest extends TestCase
{
    private const AGREEMENT_ID = 'phpunit_recurring_legacy_agreement';

    private PaymentWorkflowDouble $ApiPayment;

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
                'PayPal billing agreement table is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixture();
        $this->insertFixture();

        $this->ApiPayment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->ApiPayment);
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        $this->cleanupFixture();
        parent::tearDown();
    }

    public function testLegacyLifecycleOperationsRouteToBillingAgreements(): void
    {
        $Payment = new Payment();

        $Payment->suspendSubscription(self::AGREEMENT_ID, 'Review');
        $Payment->resumeSubscription(self::AGREEMENT_ID, 'Approved');

        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_SUSPEND_BILLING_AGREEMENT,
            $this->ApiPayment->apiCalls[0]['request']
        );
        self::assertSame(['note' => 'Review'], $this->ApiPayment->apiCalls[0]['body']);
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_RESUME_BILLING_AGREEMENT,
            $this->ApiPayment->apiCalls[1]['request']
        );
        self::assertSame(['note' => 'Approved'], $this->ApiPayment->apiCalls[1]['body']);

        $Payment->cancelSubscription(self::AGREEMENT_ID, 'Customer request');

        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
            $this->ApiPayment->apiCalls[2]['request']
        );
        self::assertFalse($Payment->isSubscriptionActiveAtQuiqqer(self::AGREEMENT_ID));
    }

    public function testLegacySuspensionAndProviderActivityUseBillingAgreementApi(): void
    {
        $this->ApiPayment->apiResponse = [
            'state' => BillingAgreements::BILLING_AGREEMENT_STATE_SUSPENDED
        ];
        $Payment = new Payment();

        self::assertTrue($Payment->isSuspended(self::AGREEMENT_ID));
        self::assertTrue(
            $Payment->isSubscriptionActiveAtPaymentProvider(self::AGREEMENT_ID)
        );

        $this->ApiPayment->apiResponse = [
            'state' => BillingAgreements::BILLING_AGREEMENT_STATE_CANCELLED
        ];
        self::assertFalse(
            $Payment->isSubscriptionActiveAtPaymentProvider(self::AGREEMENT_ID)
        );
    }

    public function testLegacyLocalDataIsExposedByRecurringPaymentFacade(): void
    {
        $Payment = new Payment();

        self::assertTrue($Payment->isSubscriptionActiveAtQuiqqer(self::AGREEMENT_ID));
        self::assertContains(self::AGREEMENT_ID, $Payment->getSubscriptionIds());
        self::assertSame(
            'phpunit-recurring-legacy-process',
            $Payment->getSubscriptionGlobalProcessingId(self::AGREEMENT_ID)
        );

        $Payment->setSubscriptionAsInactive(self::AGREEMENT_ID);

        self::assertFalse($Payment->isSubscriptionActiveAtQuiqqer(self::AGREEMENT_ID));
        self::assertNotContains(self::AGREEMENT_ID, $Payment->getSubscriptionIds());
        self::assertContains(self::AGREEMENT_ID, $Payment->getSubscriptionIds(true));
    }

    public function testUnknownLegacyAgreementIsReportedAsInactive(): void
    {
        $Payment = new Payment();

        self::assertFalse($Payment->isSubscriptionActiveAtQuiqqer('missing'));
        self::assertFalse($Payment->getSubscriptionGlobalProcessingId('missing'));
    }

    private function insertFixture(): void
    {
        $this->connection()->insert(
            $this->table(),
            [
                'paypal_agreement_id' => self::AGREEMENT_ID,
                'paypal_plan_id' => 'phpunit-recurring-legacy-plan',
                'customer' => json_encode([
                    'lang' => 'en'
                ]),
                'global_process_id' => 'phpunit-recurring-legacy-process',
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
