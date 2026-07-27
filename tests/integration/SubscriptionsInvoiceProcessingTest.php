<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use Throwable;

final class SubscriptionsInvoiceProcessingTest extends TestCase
{
    private const PREFIX = 'phpunit_subscription_invoice_';
    private const SUBSCRIPTION_ID = self::PREFIX . 'subscription';

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
                'PayPal subscription transaction table is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixtures();
        $this->connection()->insert(
            $this->subscriptionsTable(),
            [
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_plan_id' => self::PREFIX . 'plan',
                'customer' => '{}',
                'subscription_data' => '{}',
                'global_process_id' => self::PREFIX . 'process',
                'active' => 1
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testCompletedPayPalTransactionPaysInvoice(): void
    {
        $paypalTransactionId = self::PREFIX . 'completed';
        $this->insertPayPalTransaction(
            $paypalTransactionId,
            Subscriptions::TRANSACTION_STATE_COMPLETED
        );
        $addedTransactions = [];
        $Invoice = $this->invoice($addedTransactions);

        Subscriptions::billSubscriptionInvoice($Invoice);

        self::assertCount(1, $addedTransactions);
        $Transaction = $addedTransactions[0];
        $this->transactionIds[] = $Transaction->getTxId();

        self::assertSame(19.95, $Transaction->getAmount());
        self::assertSame(TransactionHandler::STATUS_COMPLETE, $Transaction->getStatus());
        self::assertSame(
            $paypalTransactionId,
            $Transaction->getData(Payment::ATTR_PAYPAL_CAPTURE_ID)
        );
        self::assertPayPalTransactionProcessed(
            $paypalTransactionId,
            $Transaction->getTxId()
        );
    }

    public function testDeniedPayPalTransactionCreatesErrorTransaction(): void
    {
        $paypalTransactionId = self::PREFIX . 'denied';
        $this->insertPayPalTransaction(
            $paypalTransactionId,
            Subscriptions::TRANSACTION_STATE_DENIED
        );
        $addedTransactions = [];
        $Invoice = $this->invoice($addedTransactions);

        Subscriptions::processDeniedTransactions($Invoice);

        self::assertCount(1, $addedTransactions);
        $Transaction = $addedTransactions[0];
        $this->transactionIds[] = $Transaction->getTxId();

        self::assertSame(TransactionHandler::STATUS_ERROR, $Transaction->getStatus());
        self::assertPayPalTransactionProcessed(
            $paypalTransactionId,
            $Transaction->getTxId()
        );
    }

    /**
     * @param list<Transaction> $addedTransactions
     */
    private function invoice(array &$addedTransactions): Invoice
    {
        $Currency = $this->createMock(Currency::class);
        $Currency->method('getCode')->willReturn('EUR');
        $Currency->method('toArray')->willReturn([
            'code' => 'EUR',
            'sign' => '€'
        ]);

        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getPaymentDataEntry')->willReturn(self::SUBSCRIPTION_ID);
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

        return $Invoice;
    }

    private function insertPayPalTransaction(string $id, string $status): void
    {
        $this->connection()->insert(
            $this->paypalTransactionsTable(),
            [
                'paypal_transaction_id' => $id,
                'paypal_subscription_id' => self::SUBSCRIPTION_ID,
                'paypal_transaction_data' => json_encode([
                    'id' => $id,
                    'status' => $status,
                    'amount_with_breakdown' => [
                        'gross_amount' => [
                            'value' => '19.95',
                            'currency_code' => 'EUR'
                        ]
                    ],
                    'time' => '2026-07-27T11:00:00Z'
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

    private function paypalTransactionsTable(): string
    {
        return QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS);
    }
}
