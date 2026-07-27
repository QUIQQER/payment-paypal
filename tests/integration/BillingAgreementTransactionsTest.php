<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\BillingAgreementsDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class BillingAgreementTransactionsTest extends TestCase
{
    private const PREFIX = 'phpunit_legacy_transaction_';
    private const AGREEMENT_ID = self::PREFIX . 'agreement';

    private PaymentWorkflowDouble $Payment;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->connection()
                ->createQueryBuilder()
                ->select('paypal_transaction_id')
                ->from($this->transactionsTable())
                ->setMaxResults(1)
                ->executeQuery()
                ->free();
        } catch (Throwable $Throwable) {
            self::markTestSkipped(
                'PayPal billing agreement transactions table is not available: '
                . $Throwable->getMessage()
            );
        }

        $this->cleanupFixtures();
        $this->insertAgreement();
        $this->resetRefreshCache();

        $this->Payment = new PaymentWorkflowDouble();
        BillingAgreementsDouble::usePayment($this->Payment);
    }

    protected function tearDown(): void
    {
        BillingAgreementsDouble::usePayment(null);
        $this->resetRefreshCache();
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testMissingTransactionsAreFetchedPersistedAndFiltered(): void
    {
        $this->Payment->apiResponse = [
            'agreement_transaction_list' => [
                [
                    'transaction_id' => self::PREFIX . 'completed',
                    'status' => BillingAgreements::TRANSACTION_STATE_COMPLETED,
                    'amount' => [
                        'value' => '19.95',
                        'currency' => 'EUR'
                    ],
                    'time_stamp' => '2026-07-27T11:00:00Z'
                ],
                [
                    'transaction_id' => self::PREFIX . 'denied',
                    'status' => BillingAgreements::TRANSACTION_STATE_DENIED,
                    'amount' => [
                        'value' => '19.95',
                        'currency' => 'EUR'
                    ],
                    'time_stamp' => '2026-07-27T11:01:00Z'
                ],
                [
                    'transaction_id' => self::PREFIX . 'unclaimed',
                    'status' => 'Unclaimed',
                    'amount' => [
                        'value' => '19.95',
                        'currency' => 'USD'
                    ],
                    'time_stamp' => '2026-07-27T11:02:00Z'
                ],
                [
                    'transaction_id' => self::PREFIX . 'missing-amount',
                    'status' => BillingAgreements::TRANSACTION_STATE_COMPLETED,
                    'time_stamp' => '2026-07-27T11:03:00Z'
                ]
            ]
        ];

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::AGREEMENT_ID
        );

        self::assertCount(1, $transactions);
        self::assertSame(
            self::PREFIX . 'completed',
            $transactions[0]['transaction_id']
        );
        self::assertSame(2, $this->transactionCount());
    }

    public function testStoredTransactionsAreDecodedAndFiltered(): void
    {
        $this->insertTransaction(
            self::PREFIX . 'pending',
            ['transaction_id' => self::PREFIX . 'pending', 'status' => 'Pending'],
            '2026-07-27 11:10:00'
        );
        $this->insertTransaction(
            self::PREFIX . 'completed',
            [
                'transaction_id' => self::PREFIX . 'completed',
                'status' => BillingAgreements::TRANSACTION_STATE_COMPLETED
            ],
            '2026-07-27 11:11:00'
        );

        $transactions = $this->invoke(
            'getUnprocessedTransactions',
            self::AGREEMENT_ID
        );

        self::assertCount(1, $transactions);
        self::assertSame(
            self::PREFIX . 'completed',
            $transactions[0]['transaction_id']
        );
        self::assertSame([], $this->Payment->apiCalls);
    }

    public function testRefreshSkipsExistingTransaction(): void
    {
        $transaction = [
            'transaction_id' => self::PREFIX . 'existing',
            'status' => BillingAgreements::TRANSACTION_STATE_COMPLETED,
            'amount' => [
                'value' => '10.00',
                'currency' => 'EUR'
            ],
            'time_stamp' => '2026-07-20T08:30:00Z'
        ];
        $this->insertTransaction(
            $transaction['transaction_id'],
            $transaction,
            '2026-07-20 08:30:00'
        );
        $this->Payment->apiResponse = [
            'agreement_transaction_list' => [$transaction]
        ];

        $this->invoke('refreshTransactionList', self::AGREEMENT_ID);
        $this->invoke('refreshTransactionList', self::AGREEMENT_ID);

        self::assertSame(1, $this->transactionCount());
        self::assertCount(1, $this->Payment->apiCalls);
        self::assertSame(
            '2026-07-20',
            $this->Payment->apiCalls[0]['transaction']['start_date']
        );
    }

    private function insertAgreement(): void
    {
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
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertTransaction(string $id, array $data, string $date): void
    {
        $this->connection()->insert(
            $this->transactionsTable(),
            [
                'paypal_transaction_id' => $id,
                'paypal_agreement_id' => self::AGREEMENT_ID,
                'paypal_transaction_data' => json_encode($data),
                'paypal_transaction_date' => $date,
                'global_process_id' => self::PREFIX . 'process'
            ]
        );
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(BillingAgreements::class, $method))
            ->invoke(null, ...$arguments);
    }

    private function resetRefreshCache(): void
    {
        $Property = new ReflectionProperty(
            BillingAgreements::class,
            'transactionsRefreshed'
        );
        $Property->setValue(null, []);
    }

    private function transactionCount(): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->transactionsTable()
            . ' WHERE paypal_agreement_id = ?',
            [self::AGREEMENT_ID]
        );
    }

    private function cleanupFixtures(): void
    {
        $this->connection()->delete(
            $this->transactionsTable(),
            ['paypal_agreement_id' => self::AGREEMENT_ID]
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

    private function transactionsTable(): string
    {
        return QUI::getDBTableName(
            BillingAgreements::TBL_BILLING_AGREEMENT_TRANSACTIONS
        );
    }
}
