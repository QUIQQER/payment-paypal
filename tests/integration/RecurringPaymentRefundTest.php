<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Currency\Handler as CurrencyHandler;
use QUI\ERP\Payments\PayPal\Payment as OneTimePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\RecurringPaymentWorkflowDouble;

final class RecurringPaymentRefundTest extends TestCase
{
    private const PREFIX = 'phpunit_paypal_recurring_refund_';

    protected function tearDown(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ' . TransactionFactory::table()
            . ' WHERE hash LIKE ? OR global_process_id LIKE ?',
            [self::PREFIX . '%', self::PREFIX . '%']
        );

        parent::tearDown();
    }

    public function testCompletedRefundCreatesCompletedTransaction(): void
    {
        $Payment = new RecurringPaymentWorkflowDouble();
        $Payment->apiResponse = [
            'id' => 'LEGACY-REFUND-COMPLETED',
            'state' => OneTimePayment::PAYPAL_REFUND_STATE_COMPLETED,
            'amount' => [
                'total' => '4.75',
                'currency' => 'EUR'
            ]
        ];

        $Payment->refundPayment(
            $this->sourceTransaction(),
            self::PREFIX . 'completed',
            4.75,
            str_repeat('R', 50)
        );

        $RefundTransaction = $this->transactionByHash(self::PREFIX . 'completed');

        self::assertSame(
            TransactionHandler::STATUS_COMPLETE,
            $RefundTransaction->getStatus()
        );
        self::assertSame(
            'LEGACY-REFUND-COMPLETED',
            $RefundTransaction->getData(OneTimePayment::ATTR_PAYPAL_REFUND_ID)
        );
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_SALE_REFUND,
            $Payment->apiCalls[0]['request']
        );
        self::assertSame([
            'total' => '4.75',
            'currency' => 'EUR'
        ], $Payment->apiCalls[0]['body']['amount']);
        self::assertSame(30, strlen($Payment->apiCalls[0]['body']['reason']));
    }

    public function testApiFailureMarksRefundTransactionAsError(): void
    {
        $Payment = new RecurringPaymentWorkflowDouble();
        $Payment->apiException = new PayPalException('Legacy refund rejected');

        try {
            $Payment->refundPayment(
                $this->sourceTransaction(),
                self::PREFIX . 'error',
                2.5
            );
            self::fail('PayPal refund failure was not propagated.');
        } catch (PayPalException $Exception) {
            self::assertSame($Payment->apiException, $Exception);
        }

        self::assertSame(
            TransactionHandler::STATUS_ERROR,
            $this->transactionByHash(self::PREFIX . 'error')->getStatus()
        );
    }

    public function testRefundRequiresLegacyAgreementTransactionId(): void
    {
        $Payment = new RecurringPaymentWorkflowDouble();

        $this->expectException(PayPalException::class);

        $Payment->refundPayment(
            $this->sourceTransaction(false),
            self::PREFIX . 'missing-source',
            2.5
        );
    }

    public function testUnexpectedRefundStateIsRejected(): void
    {
        $Payment = new RecurringPaymentWorkflowDouble();
        $Payment->apiResponse = [
            'id' => 'LEGACY-REFUND-FAILED',
            'state' => 'FAILED'
        ];

        $this->expectException(PayPalException::class);

        $Payment->refundPayment(
            $this->sourceTransaction(),
            self::PREFIX . 'failed',
            2.5
        );
    }

    private function sourceTransaction(bool $hasAgreementTransaction = true): Transaction
    {
        $Transaction = $this->createMock(Transaction::class);
        $Transaction->method('getTxId')->willReturn(self::PREFIX . 'source');
        $Transaction->method('getGlobalProcessId')->willReturn(self::PREFIX . 'process');
        $Transaction->method('getCurrency')->willReturn(
            CurrencyHandler::getCurrency('EUR')
        );
        $Transaction->method('getPayment')->willReturn(new Payment());
        $Transaction->method('getData')->willReturnMap([
            [
                Payment::ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID,
                $hasAgreementTransaction ? 'SALE-SOURCE' : null
            ]
        ]);

        return $Transaction;
    }

    private function transactionByHash(string $hash): Transaction
    {
        $transactionId = $this->connection()->fetchOne(
            'SELECT txid FROM ' . TransactionFactory::table() . ' WHERE hash = ?',
            [$hash]
        );

        self::assertIsString($transactionId);

        return TransactionHandler::getInstance()->get($transactionId);
    }

    private function connection(): Connection
    {
        return QUI::getDataBaseConnection();
    }
}
