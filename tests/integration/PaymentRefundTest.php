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
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class PaymentRefundTest extends TestCase
{
    private const PREFIX = 'phpunit_paypal_refund_';

    protected function tearDown(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ' . TransactionFactory::table()
            . ' WHERE hash LIKE ? OR global_process_id LIKE ?',
            [self::PREFIX . '%', self::PREFIX . '%']
        );

        parent::tearDown();
    }

    public function testCompletedRefundCreatesNegativeCompletedTransaction(): void
    {
        $SourceTransaction = $this->sourceTransaction();
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiResponse = [
            'id' => 'REFUND-COMPLETED',
            'status' => Payment::PAYPAL_REFUND_STATE_COMPLETED,
            'amount' => [
                'value' => '5.25',
                'currency_code' => 'EUR'
            ]
        ];

        $Payment->refundPayment(
            $SourceTransaction,
            self::PREFIX . 'completed',
            5.25,
            str_repeat('R', 300)
        );

        $RefundTransaction = $this->transactionByHash(self::PREFIX . 'completed');

        self::assertSame(-5.25, $RefundTransaction->getAmount());
        self::assertSame(
            TransactionHandler::STATUS_COMPLETE,
            $RefundTransaction->getStatus()
        );
        self::assertSame(
            'REFUND-COMPLETED',
            $RefundTransaction->getData(Payment::ATTR_PAYPAL_REFUND_ID)
        );
        self::assertSame(
            Payment::PAYPAL_REQUEST_TYPE_REFUND_ORDER,
            $Payment->apiCalls[0]['request']
        );
        self::assertSame(
            [
                'value' => '5.25',
                'currency_code' => 'EUR'
            ],
            $Payment->apiCalls[0]['body']['amount']
        );
        self::assertSame(
            255,
            strlen($Payment->apiCalls[0]['body']['note_to_payer'])
        );
    }

    public function testApiFailureMarksRefundTransactionAsError(): void
    {
        $SourceTransaction = $this->sourceTransaction();
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiException = new PayPalException('Refund rejected');

        try {
            $Payment->refundPayment(
                $SourceTransaction,
                self::PREFIX . 'error',
                2.5,
                'Customer request'
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

    private function sourceTransaction(): Transaction
    {
        $Transaction = $this->createMock(Transaction::class);
        $Transaction->method('getTxId')->willReturn(self::PREFIX . 'source');
        $Transaction->method('getHash')->willReturn(self::PREFIX . 'source');
        $Transaction->method('getGlobalProcessId')->willReturn(self::PREFIX . 'process');
        $Transaction->method('getCurrency')->willReturn(
            CurrencyHandler::getCurrency('EUR')
        );
        $Transaction->method('getPayment')->willReturn(new Payment());
        $Transaction->method('getData')->willReturnMap([
            [Payment::ATTR_PAYPAL_CAPTURE_ID, 'CAPTURE-SOURCE']
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
