<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use ReflectionProperty;
use Throwable;

final class BillingAgreementInvoiceProcessingTest extends TestCase
{
    private const PREFIX = 'phpunit_legacy_invoice_';
    private const AGREEMENT_ID = self::PREFIX . 'agreement';

    /** @var list<string> */
    private array $transactionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_transaction_id')
                ->from($this->paypalTransactionsTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal billing agreement transaction table is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixtures();
        $this->connection()->insert(
            $this->agreementsTable(),
            [
                'paypal_agreement_id' => self::AGREEMENT_ID,
                'paypal_plan_id' => self::PREFIX . 'plan',
                'customer' => '{}',
                'global_process_id' => self::PREFIX . 'process',
                'active' => 1
            ]
        );
        $this->resetRefreshCache();
    }

    protected function tearDown(): void
    {
        $this->resetRefreshCache();
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testCompletedLegacyTransactionPaysInvoice(): void
    {
        $paypalTransactionId = self::PREFIX . 'completed';
        $this->insertPayPalTransaction(
            $paypalTransactionId,
            BillingAgreements::TRANSACTION_STATE_COMPLETED
        );
        $addedTransactions = [];
        $history = [];
        $Invoice = $this->invoice($addedTransactions, $history);

        BillingAgreements::billBillingAgreementBalance($Invoice);

        self::assertCount(1, $addedTransactions);
        $Transaction = $addedTransactions[0];
        $this->transactionIds[] = $Transaction->getTxId();

        self::assertSame(19.95, $Transaction->getAmount());
        self::assertSame(TransactionHandler::STATUS_COMPLETE, $Transaction->getStatus());
        self::assertSame(
            $paypalTransactionId,
            $Transaction->getData(Payment::ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID)
        );
        self::assertPayPalTransactionProcessed(
            $paypalTransactionId,
            $Transaction->getTxId()
        );
        self::assertNotEmpty($history);
    }

    public function testDeniedLegacyTransactionCreatesErrorTransaction(): void
    {
        $paypalTransactionId = self::PREFIX . 'denied';
        $this->insertPayPalTransaction(
            $paypalTransactionId,
            BillingAgreements::TRANSACTION_STATE_DENIED
        );
        $addedTransactions = [];
        $history = [];
        $Invoice = $this->invoice($addedTransactions, $history);

        BillingAgreements::processDeniedTransactions($Invoice);

        self::assertCount(1, $addedTransactions);
        $Transaction = $addedTransactions[0];
        $this->transactionIds[] = $Transaction->getTxId();

        self::assertSame(TransactionHandler::STATUS_ERROR, $Transaction->getStatus());
        self::assertPayPalTransactionProcessed(
            $paypalTransactionId,
            $Transaction->getTxId()
        );
        self::assertNotEmpty($history);
    }

    public function testInvoiceWithoutAgreementReferenceIsRejected(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentDataEntry')->willReturn(null);
        $Invoice->expects(self::once())->method('addHistory');

        $this->expectException(PayPalException::class);

        BillingAgreements::billBillingAgreementBalance($Invoice);
    }

    public function testInvoiceWithUnknownAgreementIsRejected(): void
    {
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentDataEntry')->willReturn(
            self::PREFIX . 'unknown'
        );
        $Invoice->expects(self::once())->method('addHistory');

        $this->expectException(PayPalException::class);

        BillingAgreements::billBillingAgreementBalance($Invoice);
    }

    public function testMismatchedLegacyTransactionsAreIgnored(): void
    {
        $this->insertPayPalTransaction(
            self::PREFIX . 'wrong-currency',
            BillingAgreements::TRANSACTION_STATE_COMPLETED,
            '19.95',
            'USD'
        );
        $this->insertPayPalTransaction(
            self::PREFIX . 'too-small',
            BillingAgreements::TRANSACTION_STATE_COMPLETED,
            '5.00',
            'EUR'
        );
        $addedTransactions = [];
        $history = [];

        BillingAgreements::billBillingAgreementBalance(
            $this->invoice($addedTransactions, $history)
        );

        self::assertSame([], $addedTransactions);
        self::assertSame([], $history);
    }

    /**
     * @param list<Transaction> $addedTransactions
     * @param list<string> $history
     */
    private function invoice(array &$addedTransactions, array &$history): Invoice
    {
        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');
        $Currency->method('toArray')->willReturn([
            'code' => 'EUR',
            'sign' => '€'
        ]);

        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentDataEntry')->willReturn(self::AGREEMENT_ID);
        $Invoice->method('getAttribute')->willReturnMap([
            ['toPay', 10.0]
        ]);
        $Invoice->method('getCurrency')->willReturn($Currency);
        $Invoice->method('getUUID')->willReturn(self::PREFIX . 'invoice');
        $Invoice->method('getGlobalProcessId')->willReturn(self::PREFIX . 'process');
        $Invoice->method('addTransaction')->willReturnCallback(
            static function (Transaction $Transaction) use (&$addedTransactions): void {
                $addedTransactions[] = $Transaction;
            }
        );
        $Invoice->method('addHistory')->willReturnCallback(
            static function (string $entry) use (&$history): void {
                $history[] = $entry;
            }
        );

        return $Invoice;
    }

    private function insertPayPalTransaction(
        string $id,
        string $status,
        string $amount = '19.95',
        string $currency = 'EUR'
    ): void {
        $this->connection()->insert(
            $this->paypalTransactionsTable(),
            [
                'paypal_transaction_id' => $id,
                'paypal_agreement_id' => self::AGREEMENT_ID,
                'paypal_transaction_data' => json_encode([
                    'transaction_id' => $id,
                    'status' => $status,
                    'amount' => [
                        'value' => $amount,
                        'currency' => $currency
                    ],
                    'time_stamp' => '2026-07-27T11:00:00Z'
                ]),
                'paypal_transaction_date' => '2026-07-27 11:00:00',
                'global_process_id' => self::PREFIX . 'process'
            ]
        );
    }

    private function assertPayPalTransactionProcessed(
        string $paypalTransactionId,
        string $quiqqerTransactionId
    ): void {
        $row = $this->connection()->fetchAssociative(
            'SELECT quiqqer_transaction_id, quiqqer_transaction_completed'
            . ' FROM ' . $this->paypalTransactionsTable()
            . ' WHERE paypal_transaction_id = ?',
            [$paypalTransactionId]
        );

        self::assertSame(
            $quiqqerTransactionId,
            $row['quiqqer_transaction_id']
        );
        self::assertSame(1, (int)$row['quiqqer_transaction_completed']);
    }

    private function resetRefreshCache(): void
    {
        $Property = new ReflectionProperty(
            BillingAgreements::class,
            'transactionsRefreshed'
        );
        $Property->setValue(null, [self::AGREEMENT_ID => true]);
    }

    private function cleanupFixtures(): void
    {
        if ($this->transactionIds !== []) {
            $this->connection()->executeStatement(
                'DELETE FROM ' . TransactionFactory::table() . ' WHERE txid IN (?)',
                [$this->transactionIds],
                [ArrayParameterType::STRING]
            );
        }

        $this->transactionIds = [];

        $this->connection()->executeStatement(
            'DELETE FROM ' . $this->paypalTransactionsTable()
            . ' WHERE paypal_transaction_id LIKE ?',
            [self::PREFIX . '%']
        );
        $this->connection()->delete(
            $this->agreementsTable(),
            ['paypal_agreement_id' => self::AGREEMENT_ID]
        );
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }

    private function agreementsTable(): string
    {
        return QUI::getDBTableName(BillingAgreements::TBL_BILLING_AGREEMENTS);
    }

    private function paypalTransactionsTable(): string
    {
        return QUI::getDBTableName(
            BillingAgreements::TBL_BILLING_AGREEMENT_TRANSACTIONS
        );
    }
}
