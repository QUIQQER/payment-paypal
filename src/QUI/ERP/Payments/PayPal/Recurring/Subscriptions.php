<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use DateInterval;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use QUI;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions\ApiClient;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Products\Handler\Products as ProductsHandler;
use QUI\ERP\Products\Product\Product;
use QUI\Utils\Doctrine;

use function class_exists;
use function date;
use function hash;
use function json_encode;
use function json_decode;
use function mb_strtoupper;
use function sort;
use function strlen;
use function substr;

/**
 * New recurring implementation based on PayPal Subscriptions.
 */
class Subscriptions
{
    public const TBL_SUBSCRIPTION_PLANS = 'paypal_subscription_plans';
    public const TBL_SUBSCRIPTIONS = 'paypal_subscriptions';
    public const TBL_SUBSCRIPTION_TRANSACTIONS = 'paypal_subscription_transactions';
    public const TBL_SUBSCRIPTION_WEBHOOK_EVENTS = 'paypal_subscription_webhook_events';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_APPROVAL_PENDING = 'APPROVAL_PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_UNASSIGNED = 'UNASSIGNED';

    public const TRANSACTION_STATE_COMPLETED = 'COMPLETED';
    public const TRANSACTION_STATE_DENIED = 'DENIED';

    protected static ?ApiClient $ApiClient = null;

    /**
     * @var array<string, bool>
     */
    protected static array $legacyAccountMigrationAttempted = [];

    /**
     * @param AbstractOrder $Order
     * @return string
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public static function createSubscription(AbstractOrder $Order): string
    {
        $existingApprovalUrl = (string)$Order->getPaymentDataEntry(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL
        );
        $existingSubscriptionId = (string)$Order->getPaymentDataEntry(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_ID
        );

        if (
            $existingApprovalUrl !== ''
            && $existingSubscriptionId !== ''
            && self::exists($existingSubscriptionId)
        ) {
            return $existingApprovalUrl;
        }

        [$productId, $planId] = static::getOrCreatePlanReferences($Order);

        $Gateway = static::createGatewayForOrder($Order);
        $Customer = $Order->getCustomer();

        $response = static::getApiClient()->post(
            '/v1/billing/subscriptions',
            [
                'plan_id' => $planId,
                'custom_id' => $Order->getUUID(),
                'subscriber' => [
                    'name' => [
                        'given_name' => $Customer->getAttribute('firstname') ?: '',
                        'surname' => $Customer->getAttribute('lastname') ?: ''
                    ],
                    'email_address' => $Customer->getAttribute('email')
                ],
                'application_context' => [
                    'brand_name' => Utils::getProjectUrl(),
                    'return_url' => rtrim($Gateway->getSuccessUrl(), '?'),
                    'cancel_url' => rtrim($Gateway->getCancelUrl(), '?'),
                    'user_action' => 'SUBSCRIBE_NOW'
                ]
            ],
            static::getPayPalRequestId('create-subscription', $Order->getUUID())
        );

        if (empty($response['id'])) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $approvalUrl = static::getApprovalUrl($response);

        if (empty($approvalUrl)) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_PRODUCT_ID, $productId);
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID, $planId);
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID, $response['id']);
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL, $approvalUrl);
        $Order->addHistory(
            Utils::getHistoryText('order.subscription.created', [
                'subscriptionId' => $response['id']
            ])
        );
        Utils::saveOrder($Order);

        static::upsertSubscriptionRecord(
            $response['id'],
            $planId,
            [
                'email' => $Customer->getAttribute('email'),
                'firstname' => $Customer->getAttribute('firstname'),
                'lastname' => $Customer->getAttribute('lastname')
            ],
            $Order->getGlobalProcessId(),
            $response,
            true
        );

        return $approvalUrl;
    }

    protected static function createGatewayForOrder(AbstractOrder $Order): Gateway
    {
        $Gateway = new Gateway();
        $Gateway->setOrder($Order);

        return $Gateway;
    }

    /**
     * @param AbstractOrder $Order
     * @param string $subscriptionId
     * @return void
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public static function approveSubscription(AbstractOrder $Order, string $subscriptionId): void
    {
        $orderSubscriptionId = (string)$Order->getPaymentDataEntry(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_ID
        );

        if (
            $orderSubscriptionId === ''
            || $subscriptionId !== $orderSubscriptionId
        ) {
            throw self::createInvalidSubscriptionException();
        }

        $subscriptionData = self::getSubscriptionDetails($subscriptionId);
        $status = $subscriptionData['status'] ?? '';
        $orderPlanId = (string)$Order->getPaymentDataEntry(
            Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID
        );

        if (
            ($subscriptionData['id'] ?? '') !== $orderSubscriptionId
            || ($subscriptionData['custom_id'] ?? '') !== $Order->getUUID()
            || $orderPlanId === ''
            || ($subscriptionData['plan_id'] ?? '') !== $orderPlanId
            || !in_array($status, [
                self::STATUS_ACTIVE,
                self::STATUS_APPROVED
            ], true)
        ) {
            throw self::createInvalidSubscriptionException();
        }

        self::upsertSubscriptionRecord(
            $subscriptionId,
            $orderPlanId,
            $subscriptionData['subscriber'] ?? [],
            $Order->getGlobalProcessId(),
            $subscriptionData,
            true
        );

        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID, $subscriptionId);
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID, $orderPlanId);
        $Order->setPaymentData(BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        $Order->addHistory(
            Utils::getHistoryText('order.subscription.approved', [
                'subscriptionId' => $subscriptionId
            ])
        );
        Utils::saveOrder($Order);
    }

    protected static function createInvalidSubscriptionException(): PayPalException
    {
        return new PayPalException(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'exception.Recurring.order.error'
            )
        );
    }

    /**
     * @param string $subscriptionId
     * @param string $reason
     * @return void
     * @throws PayPalException
     */
    public static function cancelSubscription(string $subscriptionId, string $reason = ''): void
    {
        self::getApiClient()->post('/v1/billing/subscriptions/' . $subscriptionId . '/cancel', [
            'reason' => $reason ?: 'Cancelled from QUIQQER'
        ]);

        self::updateStoredSubscriptionStatus(
            $subscriptionId,
            self::STATUS_CANCELLED,
            false
        );
    }

    /**
     * @param string $subscriptionId
     * @param string|null $note
     * @return void
     * @throws PayPalException
     */
    public static function suspendSubscription(string $subscriptionId, ?string $note = null): void
    {
        self::getApiClient()->post('/v1/billing/subscriptions/' . $subscriptionId . '/suspend', [
            'reason' => $note ?: 'Suspended from QUIQQER'
        ]);

        self::updateStoredSubscriptionStatus(
            $subscriptionId,
            self::STATUS_SUSPENDED,
            true
        );
    }

    /**
     * @param string $subscriptionId
     * @param string|null $note
     * @return void
     * @throws PayPalException
     */
    public static function activateSubscription(string $subscriptionId, ?string $note = null): void
    {
        self::getApiClient()->post('/v1/billing/subscriptions/' . $subscriptionId . '/activate', [
            'reason' => $note ?: 'Activated from QUIQQER'
        ]);

        self::updateStoredSubscriptionStatus(
            $subscriptionId,
            self::STATUS_ACTIVE,
            true
        );
    }

    /**
     * @param string $subscriptionId
     * @return bool
     * @throws PayPalException
     */
    public static function isSuspended(string $subscriptionId): bool
    {
        $details = self::getSubscriptionDetails($subscriptionId);

        return ($details['status'] ?? '') === self::STATUS_SUSPENDED;
    }

    /**
     * @param string $subscriptionId
     * @return bool
     */
    public static function exists(string $subscriptionId): bool
    {
        self::adoptLegacySubscription($subscriptionId);

        try {
            $result = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('paypal_subscription_id'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('subscriptionId', $subscriptionId)
                ->setParameter('accountHash', AccountContext::getHash())
                ->executeQuery()
                ->fetchOne();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $result !== false;
    }

    /**
     * @param string $subscriptionId
     * @param bool $notFoundExpected
     * @return array<string, mixed>
     * @throws PayPalException
     */
    public static function getSubscriptionDetails(
        string $subscriptionId,
        bool $notFoundExpected = false
    ): array {
        return self::getApiClient()->get(
            '/v1/billing/subscriptions/' . $subscriptionId,
            [],
            $notFoundExpected ? [404] : []
        );
    }

    /**
     * @param string $subscriptionId
     * @return bool
     */
    public static function isSubscriptionActiveAtPaymentProvider(string $subscriptionId): bool
    {
        try {
            $subscription = self::getSubscriptionDetails($subscriptionId);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        return in_array($subscription['status'] ?? '', [
            self::STATUS_ACTIVE,
            self::STATUS_APPROVAL_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_SUSPENDED
        ], true);
    }

    /**
     * @param string $subscriptionId
     * @return bool
     */
    public static function isSubscriptionActiveAtQuiqqer(string $subscriptionId): bool
    {
        self::adoptLegacySubscription($subscriptionId);

        try {
            $result = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('active'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('subscriptionId', $subscriptionId)
                ->setParameter('accountHash', AccountContext::getHash())
                ->executeQuery()
                ->fetchOne();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        if ($result === false) {
            return false;
        }

        return !empty($result);
    }

    /**
     * @param bool $includeInactive
     * @return list<string>
     */
    public static function getSubscriptionIds(bool $includeInactive = false): array
    {
        self::migrateLegacyAccountContexts();

        try {
            $QueryBuilder = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('paypal_subscription_id'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('accountHash', AccountContext::getHash());

            if (!$includeInactive) {
                $QueryBuilder
                    ->andWhere(Doctrine::quoteIdentifier('active') . ' = :active')
                    ->setParameter('active', 1);
            }

            $result = $QueryBuilder
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        return $result;
    }

    /**
     * @param string $subscriptionId
     * @return void
     */
    public static function setSubscriptionAsInactive(string $subscriptionId): void
    {
        self::adoptLegacySubscription($subscriptionId);

        try {
            QUI::getDataBaseConnection()->update(
                self::getSubscriptionsTable(),
                ['active' => 0],
                [
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_account_hash' => AccountContext::getHash()
                ]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param string $subscriptionId
     * @return array{
     *     active: bool,
     *     globalProcessId: string|null,
     *     customer: array<string, mixed>|null,
     *     subscriptionData: array<string, mixed>|null,
     *     planId: string
     * }|false
     */
    public static function getSubscriptionData(string $subscriptionId): array|false
    {
        self::adoptLegacySubscription($subscriptionId);

        try {
            $data = QUI::getQueryBuilder()
                ->select('*')
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('subscriptionId', $subscriptionId)
                ->setParameter('accountHash', AccountContext::getHash())
                ->executeQuery()
                ->fetchAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if ($data === false) {
            return false;
        }

        return [
            'active' => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer' => json_decode($data['customer'], true),
            'subscriptionData' => json_decode($data['subscription_data'] ?? '[]', true),
            'planId' => $data['paypal_plan_id']
        ];
    }

    /**
     * Read Subscription data without hiding database errors from the webhook handler.
     *
     * @param string $subscriptionId
     * @return array{
     *     active: bool,
     *     globalProcessId: string|null,
     *     customer: array<string, mixed>|null,
     *     subscriptionData: array<string, mixed>|null,
     *     planId: string
     * }|false
     * @throws Exception
     */
    protected static function getSubscriptionDataForWebhook(string $subscriptionId): array|false
    {
        self::adoptLegacySubscription($subscriptionId);

        $data = QUI::getQueryBuilder()
            ->select('*')
            ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
            ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
            ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
            ->setParameter('subscriptionId', $subscriptionId)
            ->setParameter('accountHash', AccountContext::getHash())
            ->executeQuery()
            ->fetchAssociative();

        if ($data === false) {
            return false;
        }

        return [
            'active' => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer' => json_decode($data['customer'], true),
            'subscriptionData' => json_decode($data['subscription_data'] ?? '[]', true),
            'planId' => $data['paypal_plan_id']
        ];
    }

    /**
     * @param array<string, mixed> $searchParams
     * @param bool $countOnly
     * @return array<int, array<string, mixed>>|int
     */
    public static function getSubscriptionList(array $searchParams, bool $countOnly = false): array|int
    {
        self::migrateLegacyAccountContexts();

        $Grid = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);
        $QueryBuilder = QUI::getQueryBuilder();
        $accountHash = AccountContext::getHash();

        if ($countOnly) {
            $QueryBuilder->select(
                'COUNT(' . Doctrine::quoteIdentifier('paypal_subscription_id') . ')'
            );
        } else {
            $QueryBuilder->select('*');
        }

        $QueryBuilder->from(
            Doctrine::quoteIdentifier(self::getSubscriptionsTable())
        )
            ->where(
                '('
                . Doctrine::quoteIdentifier('paypal_account_hash')
                . ' = :accountHash OR '
                . Doctrine::quoteIdentifier('paypal_account_hash')
                . ' IS NULL)'
            )
            ->setParameter('accountHash', $accountHash);

        if (!empty($searchParams['search'])) {
            $searchColumns = [
                'paypal_subscription_id',
                'paypal_plan_id',
                'customer',
                'global_process_id'
            ];
            $searchConditions = array_map(
                static fn(string $column): string => Doctrine::quoteIdentifier($column) . ' LIKE :search',
                $searchColumns
            );

            $QueryBuilder
                ->andWhere('(' . implode(' OR ', $searchConditions) . ')')
                ->setParameter('search', '%' . $searchParams['search'] . '%');
        }

        if (!$countOnly) {
            $allowedSortColumns = [
                'paypal_subscription_id',
                'paypal_plan_id',
                'customer',
                'global_process_id',
                'active'
            ];
            $sortOn = in_array(
                $searchParams['sortOn'] ?? '',
                $allowedSortColumns,
                true
            ) ? (string)$searchParams['sortOn'] : 'paypal_subscription_id';
            $sortBy = strtoupper((string)($searchParams['sortBy'] ?? 'ASC')) === 'DESC'
                ? 'DESC'
                : 'ASC';

            $QueryBuilder->orderBy(
                Doctrine::quoteIdentifier($sortOn),
                $sortBy
            );
            self::applyGridLimit($QueryBuilder, $gridParams);
        }

        try {
            $Result = $QueryBuilder->executeQuery();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return $countOnly ? 0 : [];
        }

        if ($countOnly) {
            return (int)$Result->fetchOne();
        }

        return array_map(
            static function (array $row) use ($accountHash): array {
                $accountContextValid = $row['paypal_account_hash'] === $accountHash;
                $row['active'] = !empty($row['active']);
                $row['customer'] = json_decode($row['customer'] ?? '[]', true);
                $subscriptionData = json_decode(
                    $row['subscription_data'] ?? '[]',
                    true
                );

                if (!is_array($subscriptionData)) {
                    $subscriptionData = [];
                }

                $row['subscription_data'] = $subscriptionData;
                $row['account_context_valid'] = $accountContextValid;

                if (!$accountContextValid) {
                    $row['subscription_data']['status'] = self::STATUS_UNASSIGNED;
                }

                unset(
                    $row['paypal_account_hash'],
                    $row['paypal_account_check_hash']
                );

                return $row;
            },
            $Result->fetchAllAssociative()
        );
    }

    /**
     * Read a current or unassigned Subscription for backend inspection.
     *
     * @param string $subscriptionId
     * @return array{
     *     active: bool,
     *     globalProcessId: string|null,
     *     customer: array<string, mixed>|null,
     *     subscriptionData: array<string, mixed>|null,
     *     planId: string,
     *     accountContextValid: bool
     * }|false
     */
    public static function getSubscriptionDataForAdministration(
        string $subscriptionId
    ): array|false {
        self::adoptLegacySubscription($subscriptionId);
        $accountHash = AccountContext::getHash();

        try {
            $data = QUI::getQueryBuilder()
                ->select('*')
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
                ->andWhere(
                    '('
                    . Doctrine::quoteIdentifier('paypal_account_hash')
                    . ' = :accountHash OR '
                    . Doctrine::quoteIdentifier('paypal_account_hash')
                    . ' IS NULL)'
                )
                ->setParameter('subscriptionId', $subscriptionId)
                ->setParameter('accountHash', $accountHash)
                ->executeQuery()
                ->fetchAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if ($data === false) {
            return false;
        }

        $accountContextValid = $data['paypal_account_hash'] === $accountHash;
        $subscriptionData = json_decode(
            $data['subscription_data'] ?? '[]',
            true
        );

        if (!is_array($subscriptionData)) {
            $subscriptionData = [];
        }

        if (!$accountContextValid) {
            $subscriptionData['status'] = self::STATUS_UNASSIGNED;
        }

        return [
            'active' => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer' => json_decode($data['customer'], true),
            'subscriptionData' => $subscriptionData,
            'planId' => $data['paypal_plan_id'],
            'accountContextValid' => $accountContextValid
        ];
    }

    /**
     * Delete an unassigned local Subscription and its local auxiliary records.
     *
     * Provider-owned Subscriptions and records assigned to the current account
     * cannot be deleted with this method.
     *
     * @param string $subscriptionId
     * @return bool
     * @throws Exception
     */
    public static function deleteUnassignedSubscription(string $subscriptionId): bool
    {
        $Connection = QUI::getDataBaseConnection();
        $accountHash = AccountContext::getHash();

        return $Connection->transactional(
            static function (
                Connection $Connection
            ) use (
                $subscriptionId,
                $accountHash
            ): bool {
                $QueryBuilder = $Connection->createQueryBuilder();
                $deleted = $QueryBuilder
                    ->delete(self::getSubscriptionsTable())
                    ->where(
                        Doctrine::quoteIdentifier('paypal_subscription_id')
                        . ' = :subscriptionId'
                    )
                    ->andWhere(
                        Doctrine::quoteIdentifier('paypal_account_hash')
                        . ' IS NULL'
                    )
                    ->andWhere(
                        Doctrine::quoteIdentifier('paypal_account_check_hash')
                        . ' = :accountHash'
                    )
                    ->setParameter('subscriptionId', $subscriptionId)
                    ->setParameter('accountHash', $accountHash)
                    ->executeStatement();

                if ($deleted !== 1) {
                    return false;
                }

                $Connection->delete(
                    self::getSubscriptionTransactionsTable(),
                    ['paypal_subscription_id' => $subscriptionId]
                );
                $Connection->delete(
                    self::getSubscriptionWebhookEventsTable(),
                    ['paypal_subscription_id' => $subscriptionId]
                );

                return true;
            }
        );
    }

    /**
     * @param string $subscriptionId
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public static function getSubscriptionTransactionList(
        string $subscriptionId,
        int $limit = 50
    ): array {
        if (self::getSubscriptionData($subscriptionId) === false) {
            return [];
        }

        try {
            $rows = QUI::getQueryBuilder()
                ->select('*')
                ->from(
                    Doctrine::quoteIdentifier(
                        self::getSubscriptionTransactionsTable()
                    )
                )
                ->where(
                    Doctrine::quoteIdentifier('paypal_subscription_id')
                    . ' = :subscriptionId'
                )
                ->setParameter('subscriptionId', $subscriptionId)
                ->orderBy(
                    Doctrine::quoteIdentifier('paypal_transaction_date'),
                    'DESC'
                )
                ->setMaxResults(max(1, min($limit, 100)))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        return array_map(
            static function (array $row): array {
                $row['paypal_transaction_data'] = json_decode(
                    $row['paypal_transaction_data'] ?? '[]',
                    true
                );
                $row['quiqqer_transaction_completed'] = !empty(
                    $row['quiqqer_transaction_completed']
                );

                return $row;
            },
            $rows
        );
    }

    /**
     * @param Invoice $Invoice
     * @return void
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public static function billSubscriptionInvoice(Invoice $Invoice): void
    {
        $subscriptionId = $Invoice->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID);

        if (empty($subscriptionId)) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.agreement_id_not_found',
                    [
                        'invoiceId' => $Invoice->getId()
                    ]
                ),
                404
            );
        }

        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.agreement_not_found',
                    [
                        'billingAgreementId' => $subscriptionId
                    ]
                ),
                404
            );
        }

        $unprocessedTransactions = self::getUnprocessedTransactions($subscriptionId);
        $Invoice->calculatePayments();

        $invoiceAmount = (float)$Invoice->getAttribute('toPay');
        $invoiceCurrency = $Invoice->getCurrency()->getCode();
        $Payment = new Payment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount = self::getTransactionAmount($transaction);
            $currency = self::getTransactionCurrency($transaction);

            if ($currency !== $invoiceCurrency || $amount < $invoiceAmount) {
                continue;
            }

            try {
                $PayPalTransactionDate = new DateTime(self::getTransactionTime($transaction));

                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getUUID(),
                    $Payment->getName(),
                    [
                        Payment::ATTR_PAYPAL_SUBSCRIPTION_ID => $subscriptionId,
                        BasePayment::ATTR_PAYPAL_CAPTURE_ID => self::getTransactionId($transaction)
                    ],
                    null,
                    $PayPalTransactionDate->getTimestamp(),
                    $Invoice->getGlobalProcessId()
                );

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBaseConnection()->update(
                    self::getSubscriptionTransactionsTable(),
                    [
                        'quiqqer_transaction_id' => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paypal_transaction_id' => self::getTransactionId($transaction)
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_paypal_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'paypalTransactionId' => self::getTransactionId($transaction)
                    ])
                );
            } catch (Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            break;
        }
    }

    /**
     * @param Invoice $Invoice
     * @return void
     */
    public static function processDeniedTransactions(Invoice $Invoice): void
    {
        $subscriptionId = $Invoice->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID);

        if (empty($subscriptionId)) {
            return;
        }

        try {
            $unprocessedTransactions = self::getUnprocessedTransactions(
                $subscriptionId,
                self::TRANSACTION_STATE_DENIED
            );

            $Invoice->calculatePayments();
            $invoiceAmount = (float)$Invoice->getAttribute('toPay');
            $invoiceCurrency = $Invoice->getCurrency()->getCode();
            $Payment = new Payment();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($unprocessedTransactions as $transaction) {
            $amount = self::getTransactionAmount($transaction);
            $currency = self::getTransactionCurrency($transaction);

            if ($currency !== $invoiceCurrency || $amount < $invoiceAmount) {
                continue;
            }

            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getUUID(),
                    $Payment->getName(),
                    [
                        Payment::ATTR_PAYPAL_SUBSCRIPTION_ID => $subscriptionId,
                        BasePayment::ATTR_PAYPAL_CAPTURE_ID => self::getTransactionId($transaction)
                    ],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $InvoiceTransaction->changeStatus(TransactionHandler::STATUS_ERROR);
                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBaseConnection()->update(
                    self::getSubscriptionTransactionsTable(),
                    [
                        'quiqqer_transaction_id' => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paypal_transaction_id' => self::getTransactionId($transaction)
                    ]
                );
            } catch (Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * @return void
     * @throws QUI\DataBase\Exception
     */
    public static function processUnpaidInvoices(): void
    {
        $Invoices = static::getInvoiceHandler();
        $paymentTypeIds = static::getRecurringPaymentTypeIds();

        if (empty($paymentTypeIds)) {
            return;
        }

        $result = static::searchUnpaidInvoiceRows(
            $Invoices,
            $paymentTypeIds
        );
        $invoiceIds = [];

        foreach ($result as $row) {
            $globalProcessId = $row['global_process_id'];

            if (!isset($invoiceIds[$globalProcessId])) {
                $invoiceIds[$globalProcessId] = [];
            }

            $invoiceIds[$globalProcessId][] = $row['id'];
        }

        if (empty($invoiceIds)) {
            return;
        }

        $result = static::getSubscriptionProcessRows(
            array_keys($invoiceIds)
        );

        foreach ($result as $row) {
            foreach ($invoiceIds as $globalProcessId => $invoices) {
                if ($row['global_process_id'] !== $globalProcessId) {
                    continue;
                }

                foreach ($invoices as $invoiceId) {
                    try {
                        $Invoice = static::getInvoiceById(
                            $Invoices,
                            $invoiceId
                        );
                        static::processInvoiceDeniedTransactions($Invoice);
                        static::billInvoiceSubscription($Invoice);
                    } catch (Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    protected static function getInvoiceHandler(): InvoiceHandler
    {
        return InvoiceHandler::getInstance();
    }

    /**
     * @return list<int|string>
     */
    protected static function getRecurringPaymentTypeIds(): array
    {
        $payments = Payments::getInstance()->getPayments([
            'select' => ['id'],
            'where' => [
                'payment_type' => Payment::class
            ]
        ]);

        $paymentTypeIds = [];

        foreach ($payments as $Payment) {
            $paymentTypeIds[] = $Payment->getId();
        }

        return $paymentTypeIds;
    }

    /**
     * @param list<int|string> $paymentTypeIds
     * @return list<array<string, mixed>>
     */
    protected static function searchUnpaidInvoiceRows(
        InvoiceHandler $Invoices,
        array $paymentTypeIds
    ): array {
        return $Invoices->search([
            'select' => ['id', 'global_process_id'],
            'where' => [
                'paid_status' => 0,
                'type' => QUI\ERP\Constants::TYPE_INVOICE,
                'payment_method' => [
                    'type' => 'IN',
                    'value' => $paymentTypeIds
                ]
            ],
            'order' => 'date ASC',
            'limit' => 99999
        ]);
    }

    /**
     * @param list<string> $globalProcessIds
     * @return array<int, array{global_process_id: string}>
     */
    protected static function getSubscriptionProcessRows(
        array $globalProcessIds
    ): array {
        if (empty($globalProcessIds)) {
            return [];
        }

        self::migrateLegacyAccountContexts();

        try {
            $rows = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('global_process_id'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
                ->where(Doctrine::quoteIdentifier('global_process_id') . ' IN (:globalProcessIds)')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('globalProcessIds', $globalProcessIds, ArrayParameterType::STRING)
                ->setParameter('accountHash', AccountContext::getHash())
                ->executeQuery()
                ->fetchAllAssociative();

            return array_map(
                static fn(array $row): array => [
                    'global_process_id' => (string)$row['global_process_id']
                ],
                $rows
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }
    }

    protected static function getInvoiceById(
        InvoiceHandler $Invoices,
        int|string $invoiceId
    ): Invoice {
        $Invoice = $Invoices->get($invoiceId);

        if (!$Invoice instanceof Invoice) {
            throw new \UnexpectedValueException('Expected a persistent invoice.');
        }

        return $Invoice;
    }

    protected static function processInvoiceDeniedTransactions(
        Invoice $Invoice
    ): void {
        self::processDeniedTransactions($Invoice);
    }

    protected static function billInvoiceSubscription(
        Invoice $Invoice
    ): void {
        self::billSubscriptionInvoice($Invoice);
    }

    /**
     * @param array<string, string> $headers
     * @param string $rawBody
     * @return bool
     */
    public static function handleWebhook(array $headers, string $rawBody): bool
    {
        $webhookId = (string)Provider::getApiSetting('webhook_id');

        if ($webhookId === '') {
            QUI\System\Log::addError('PayPal subscription webhook rejected: missing webhook id setting.');
            return false;
        }

        try {
            if (!self::getApiClient()->verifyWebhookSignature($headers, $rawBody, $webhookId)) {
                return false;
            }
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return false;
        }

        $eventPersisted = self::persistWebhookEvent($event);

        if ($eventPersisted === null) {
            return false;
        }

        if ($eventPersisted === false) {
            return true;
        }

        return self::processWebhookEvent($event);
    }

    /**
     * @param array<string, mixed> $event
     * @return bool|null
     *     true if the event must be processed, false if already processed,
     *     null on database error
     */
    protected static function persistWebhookEvent(array $event): ?bool
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = self::getSubscriptionIdFromResource($resource);

        try {
            $processed = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('processed'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionWebhookEventsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_event_id') . ' = :eventId')
                ->setParameter('eventId', $event['id'])
                ->executeQuery()
                ->fetchOne();

            if ($processed !== false) {
                return empty($processed);
            }

            QUI::getDataBaseConnection()->insert(
                self::getSubscriptionWebhookEventsTable(),
                [
                    'paypal_event_id' => $event['id'],
                    'paypal_event_type' => $event['event_type'],
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_event_data' => json_encode($event),
                    'paypal_event_date' => self::formatDate($event['create_time'] ?? 'now'),
                    'processed' => 0
                ]
            );

            return true;
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return bool
     */
    protected static function processWebhookEvent(array $event): bool
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = self::getSubscriptionIdFromResource($resource);

        $eventType = $event['event_type'] ?? '';

        try {
            if (
                $subscriptionId !== ''
                && str_starts_with($eventType, 'BILLING.SUBSCRIPTION.')
            ) {
                self::processSubscriptionLifecycleEvent($subscriptionId, $resource);
            }

            if (
                $subscriptionId !== ''
                && str_starts_with($eventType, 'PAYMENT.SALE.')
            ) {
                self::persistTransactionFromWebhook($subscriptionId, $resource, $eventType);
            }

            QUI::getDataBaseConnection()->update(
                self::getSubscriptionWebhookEventsTable(),
                ['processed' => 1],
                ['paypal_event_id' => $event['id']]
            );

            return true;
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }
    }

    /**
     * @param string $subscriptionId
     * @param array<string, mixed> $resource
     * @return bool
     * @throws Exception
     */
    protected static function processSubscriptionLifecycleEvent(string $subscriptionId, array $resource): bool
    {
        $status = $resource['status'] ?? '';
        $active = !in_array($status, [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED
        ], true);

        $data = self::getSubscriptionDataForWebhook($subscriptionId);

        if ($data === false) {
            return true;
        }

        QUI::getDataBaseConnection()->update(
            self::getSubscriptionsTable(),
            [
                'paypal_plan_id' => $resource['plan_id'] ?? $data['planId'],
                'customer' => json_encode($resource['subscriber'] ?? $data['customer'] ?? []),
                'subscription_data' => json_encode($resource),
                'global_process_id' => $data['globalProcessId'],
                'active' => (int)$active
            ],
            [
                'paypal_subscription_id' => $subscriptionId,
                'paypal_account_hash' => AccountContext::getHash()
            ]
        );

        return true;
    }

    /**
     * @param string $subscriptionId
     * @param array<string, mixed> $resource
     * @param string $eventType
     * @return bool
     * @throws Exception
     */
    protected static function persistTransactionFromWebhook(
        string $subscriptionId,
        array $resource,
        string $eventType
    ): bool {
        $transactionId = self::getTransactionId($resource);

        if ($transactionId === '') {
            return true;
        }

        $data = self::getSubscriptionDataForWebhook($subscriptionId);

        if ($data === false) {
            return true;
        }

        $transactionDate = self::formatDate(self::getTransactionTime($resource));
        $status = self::getTransactionStatus($resource, $eventType);

        $resource['status'] = $status;

        $result = QUI::getQueryBuilder()
            ->select(Doctrine::quoteIdentifier('paypal_transaction_id'))
            ->from(Doctrine::quoteIdentifier(self::getSubscriptionTransactionsTable()))
            ->where(Doctrine::quoteIdentifier('paypal_transaction_id') . ' = :transactionId')
            ->andWhere(Doctrine::quoteIdentifier('paypal_transaction_date') . ' = :transactionDate')
            ->setParameter('transactionId', $transactionId)
            ->setParameter('transactionDate', $transactionDate)
            ->executeQuery()
            ->fetchOne();

        if ($result !== false) {
            return true;
        }

        QUI::getDataBaseConnection()->insert(
            self::getSubscriptionTransactionsTable(),
            [
                'paypal_transaction_id' => $transactionId,
                'paypal_subscription_id' => $subscriptionId,
                'paypal_transaction_data' => json_encode($resource),
                'paypal_transaction_date' => $transactionDate,
                'global_process_id' => $data['globalProcessId']
            ]
        );

        return true;
    }

    /**
     * @param string $subscriptionId
     * @param string $status
     * @return list<array<string, mixed>>
     * @throws QUI\Database\Exception
     */
    protected static function getUnprocessedTransactions(
        string $subscriptionId,
        string $status = self::TRANSACTION_STATE_COMPLETED
    ): array {
        if (self::getSubscriptionData($subscriptionId) === false) {
            return [];
        }

        $transactions = self::getStoredUnprocessedTransactions(
            $subscriptionId,
            $status
        );

        if (!empty($transactions)) {
            return $transactions;
        }

        self::refreshTransactionList($subscriptionId);

        return self::getStoredUnprocessedTransactions(
            $subscriptionId,
            $status
        );
    }

    /**
     * @param string $subscriptionId
     * @param string $status
     * @return list<array<string, mixed>>
     * @throws QUI\Database\Exception
     */
    protected static function getStoredUnprocessedTransactions(
        string $subscriptionId,
        string $status
    ): array {
        $result = QUI::getQueryBuilder()
            ->select(Doctrine::quoteIdentifier('paypal_transaction_data'))
            ->from(Doctrine::quoteIdentifier(self::getSubscriptionTransactionsTable()))
            ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
            ->andWhere(Doctrine::quoteIdentifier('quiqqer_transaction_id') . ' IS NULL')
            ->setParameter('subscriptionId', $subscriptionId)
            ->executeQuery()
            ->fetchAllAssociative();

        $transactions = [];

        foreach ($result as $row) {
            $transaction = json_decode($row['paypal_transaction_data'], true);

            if (!is_array($transaction)) {
                continue;
            }

            if (self::getTransactionStatus($transaction) !== $status) {
                continue;
            }

            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * @param string $subscriptionId
     * @return void
     */
    protected static function refreshTransactionList(string $subscriptionId): void
    {
        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            return;
        }

        try {
            $Start = new DateTime(date('Y') . '-01-01 00:00:00');

            $result = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('paypal_transaction_date'))
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionTransactionsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
                ->setParameter('subscriptionId', $subscriptionId)
                ->orderBy(Doctrine::quoteIdentifier('paypal_transaction_date'), 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($result !== false) {
                $Start = new DateTime((string)$result);
            }

            $End = new DateTime();
            $End->add(new DateInterval('P1D'));

            $response = self::getApiClient()->get('/v1/billing/subscriptions/' . $subscriptionId . '/transactions', [
                'start_time' => $Start->format(DateTime::ATOM),
                'end_time' => $End->format(DateTime::ATOM)
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($response['transactions'] ?? [] as $transaction) {
            self::persistTransactionFromWebhook($subscriptionId, $transaction, '');
        }
    }

    /**
     * @param array<string, mixed> $resource
     * @return string
     */
    protected static function getSubscriptionIdFromResource(array $resource): string
    {
        return (string)($resource['billing_agreement_id']
            ?? $resource['subscription_id']
            ?? $resource['id']
            ?? '');
    }

    /**
     * @param array<string, mixed> $transaction
     * @return string
     */
    protected static function getTransactionId(array $transaction): string
    {
        return (string)($transaction['id'] ?? $transaction['sale_id'] ?? $transaction['transaction_id'] ?? '');
    }

    /**
     * @param array<string, mixed> $transaction
     * @return float
     */
    protected static function getTransactionAmount(array $transaction): float
    {
        return (float)($transaction['amount']['value']
            ?? $transaction['amount']['total']
            ?? $transaction['amount_with_breakdown']['gross_amount']['value']
            ?? 0);
    }

    /**
     * @param array<string, mixed> $transaction
     * @return string
     */
    protected static function getTransactionCurrency(array $transaction): string
    {
        return (string)($transaction['amount']['currency_code']
            ?? $transaction['amount']['currency']
            ?? $transaction['amount_with_breakdown']['gross_amount']['currency_code']
            ?? '');
    }

    /**
     * @param array<string, mixed> $transaction
     * @return string
     */
    protected static function getTransactionTime(array $transaction): string
    {
        return (string)($transaction['create_time']
            ?? $transaction['update_time']
            ?? $transaction['time_stamp']
            ?? 'now');
    }

    /**
     * @param array<string, mixed> $transaction
     * @param string $eventType
     * @return string
     */
    protected static function getTransactionStatus(array $transaction, string $eventType = ''): string
    {
        if (($transaction['status'] ?? '') === self::TRANSACTION_STATE_COMPLETED) {
            return self::TRANSACTION_STATE_COMPLETED;
        }

        if (($transaction['state'] ?? '') === 'completed') {
            return self::TRANSACTION_STATE_COMPLETED;
        }

        if (($transaction['status'] ?? '') === self::TRANSACTION_STATE_DENIED) {
            return self::TRANSACTION_STATE_DENIED;
        }

        if (($transaction['state'] ?? '') === 'denied' || str_ends_with($eventType, '.DENIED')) {
            return self::TRANSACTION_STATE_DENIED;
        }

        return (string)($transaction['status'] ?? $transaction['state'] ?? '');
    }

    /**
     * @param string $date
     * @return string
     */
    protected static function formatDate(string $date): string
    {
        try {
            return (new DateTime($date))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return (new DateTime())->format('Y-m-d H:i:s');
        }
    }

    /**
     * @param AbstractOrder $Order
     * @return array{string, string}
     * @throws PayPalException
     * @throws QUI\Exception
     */
    protected static function getOrCreatePlanReferences(AbstractOrder $Order): array
    {
        $accountHash = AccountContext::getHash();
        $existingPlan = self::getPlanByOrder($Order);

        if ($existingPlan !== false) {
            return [
                $existingPlan['paypal_product_id'],
                $existingPlan['paypal_plan_id']
            ];
        }

        $PlanProduct = self::getPlanProduct($Order);
        $productId = self::createProduct($Order, $PlanProduct);
        $planData = self::createPlan($Order, $PlanProduct, $productId);

        QUI::getDataBaseConnection()->insert(
            self::getSubscriptionPlansTable(),
            [
                'paypal_product_id' => $productId,
                'paypal_plan_id' => $planData['id'],
                'identification_hash' => self::getIdentificationHash($Order, $accountHash),
                'paypal_account_hash' => $accountHash,
                'plan_data' => json_encode($planData)
            ]
        );

        return [$productId, $planData['id']];
    }

    /**
     * @param AbstractOrder $Order
     * @param Product $PlanProduct
     * @return string
     * @throws PayPalException
     */
    protected static function createProduct(AbstractOrder $Order, Product $PlanProduct): string
    {
        $Locale = $Order->getCustomer()->getLocale();
        $name = substr($PlanProduct->getTitle($Locale), 0, 127);

        if (strlen($name) === 127) {
            $name = substr($name, 0, 124) . '...';
        }

        $description = $PlanProduct->getDescription($Locale);

        if (empty($description)) {
            $description = $name;
        }

        $response = self::getApiClient()->post(
            '/v1/catalogs/products',
            [
                'name' => $name,
                'description' => substr($description, 0, 127),
                'type' => 'SERVICE',
                'category' => 'SOFTWARE'
            ],
            self::getPayPalRequestId('create-product', $Order->getUUID())
        );

        if (empty($response['id'])) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        return $response['id'];
    }

    /**
     * @param AbstractOrder $Order
     * @param Product $PlanProduct
     * @param string $productId
     * @return array<string, mixed>
     * @throws PayPalException
     * @throws QUI\Exception
     */
    protected static function createPlan(AbstractOrder $Order, Product $PlanProduct, string $productId): array
    {
        $planDetails = QUI\ERP\Plans\Utils::getPlanDetailsFromProduct($PlanProduct);
        $invoiceIntervalParts = explode('-', $planDetails['invoice_interval']);
        $PriceCalculation = $Order->getPriceCalculation();

        return self::getApiClient()->post(
            '/v1/billing/plans',
            [
                'product_id' => $productId,
                'name' => substr($PlanProduct->getTitle(), 0, 127),
                'description' => substr($PlanProduct->getDescription() ?: $PlanProduct->getTitle(), 0, 127),
                'status' => 'ACTIVE',
                'billing_cycles' => [[
                    'frequency' => [
                        'interval_unit' => mb_strtoupper($invoiceIntervalParts[1]),
                        'interval_count' => (int)$invoiceIntervalParts[0]
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => self::getCycleCount($planDetails),
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => Utils::formatPrice($PriceCalculation->getSum()->get()),
                            'currency_code' => $Order->getCurrency()->getCode()
                        ]
                    ]
                ]],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'setup_fee_failure_action' => 'CONTINUE',
                    'payment_failure_threshold' => 2
                ]
            ],
            self::getPayPalRequestId('create-plan', $Order->getUUID())
        );
    }

    /**
     * @param array<string, mixed> $planDetails
     * @return int
     * @throws PayPalException
     */
    protected static function getCycleCount(array $planDetails): int
    {
        if (!empty($planDetails['auto_extend'])) {
            return 0;
        }

        try {
            $DurationInterval = QUI\ERP\Plans\Utils::parseIntervalFromDuration($planDetails['duration_interval']);
            $InvoiceInterval = QUI\ERP\Plans\Utils::parseIntervalFromDuration($planDetails['invoice_interval']);

            if ($DurationInterval === false || $InvoiceInterval === false) {
                throw new Exception('Could not parse PayPal subscription intervals.');
            }

            $Start = new DateTime();
            $End = clone $Start;
            $End->add($DurationInterval)->sub(new DateInterval('P1D'));
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $cycles = 0;

        while ($Start <= $End) {
            $Start->add($InvoiceInterval);
            $cycles++;
        }

        return $cycles;
    }

    /**
     * @param AbstractOrder $Order
     * @return Product
     * @throws PayPalException
     * @throws QUI\Exception
     */
    protected static function getPlanProduct(AbstractOrder $Order): Product
    {
        if (!class_exists('QUI\ERP\Plans\Utils')) {
            throw new PayPalException('Plans is not installed');
        }

        foreach ($Order->getArticles() as $Article) {
            if (QUI\ERP\Plans\Utils::isPlanArticle($Article)) {
                return ProductsHandler::getProduct($Article->getId());
            }
        }

        throw new PayPalException(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'exception.Recurring.order.error'
            )
        );
    }

    /**
     * @param AbstractOrder $Order
     * @return array{paypal_product_id: string, paypal_plan_id: string}|false
     * @throws QUI\Exception
     * @throws PayPalException
     */
    protected static function getPlanByOrder(AbstractOrder $Order): array|false
    {
        $accountHash = AccountContext::getHash();
        $identificationHash = self::getIdentificationHash($Order, $accountHash);

        try {
            $result = QUI::getQueryBuilder()
                ->select(
                    Doctrine::quoteIdentifier('paypal_product_id'),
                    Doctrine::quoteIdentifier('paypal_plan_id')
                )
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionPlansTable()))
                ->where(Doctrine::quoteIdentifier('identification_hash') . ' = :identificationHash')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' = :accountHash')
                ->setParameter('identificationHash', $identificationHash)
                ->setParameter('accountHash', $accountHash)
                ->executeQuery()
                ->fetchAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if ($result === false) {
            return self::adoptLegacyPlan(
                $Order,
                $accountHash,
                $identificationHash
            );
        }

        return [
            'paypal_product_id' => (string)$result['paypal_product_id'],
            'paypal_plan_id' => (string)$result['paypal_plan_id']
        ];
    }

    /**
     * Adopt an unscoped plan only after PayPal confirms that the current
     * application can access it.
     *
     * @param AbstractOrder $Order
     * @param string $accountHash
     * @param string $identificationHash
     * @return array{paypal_product_id: string, paypal_plan_id: string}|false
     * @throws PayPalException
     */
    private static function adoptLegacyPlan(
        AbstractOrder $Order,
        string $accountHash,
        string $identificationHash
    ): array|false {
        try {
            $legacyPlan = QUI::getQueryBuilder()
                ->select(
                    Doctrine::quoteIdentifier('id'),
                    Doctrine::quoteIdentifier('paypal_product_id'),
                    Doctrine::quoteIdentifier('paypal_plan_id')
                )
                ->from(Doctrine::quoteIdentifier(self::getSubscriptionPlansTable()))
                ->where(Doctrine::quoteIdentifier('identification_hash') . ' = :identificationHash')
                ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' IS NULL')
                ->setParameter('identificationHash', self::getLegacyIdentificationHash($Order))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if ($legacyPlan === false) {
            return false;
        }

        $planId = (string)$legacyPlan['paypal_plan_id'];

        try {
            $planData = self::getApiClient()->get(
                '/v1/billing/plans/' . $planId,
                [],
                [404]
            );
        } catch (PayPalException $Exception) {
            if (AccountContext::isMissingResource($Exception)) {
                return false;
            }

            throw $Exception;
        }

        if (
            ($planData['id'] ?? '') !== $planId
            || ($planData['status'] ?? '') !== self::STATUS_ACTIVE
        ) {
            return false;
        }

        QUI::getDataBaseConnection()->update(
            self::getSubscriptionPlansTable(),
            [
                'identification_hash' => $identificationHash,
                'paypal_account_hash' => $accountHash,
                'plan_data' => json_encode($planData)
            ],
            ['id' => $legacyPlan['id']]
        );

        return [
            'paypal_product_id' => (string)$legacyPlan['paypal_product_id'],
            'paypal_plan_id' => $planId
        ];
    }

    /**
     * @param string $subscriptionId
     * @param string $planId
     * @param array<string, mixed> $customer
     * @param string|null $globalProcessId
     * @param array<string, mixed> $subscriptionData
     * @param bool $active
     * @return void
     */
    protected static function upsertSubscriptionRecord(
        string $subscriptionId,
        string $planId,
        array $customer,
        ?string $globalProcessId,
        array $subscriptionData,
        bool $active
    ): void {
        $accountHash = AccountContext::getHash();

        try {
            if (self::exists($subscriptionId)) {
                QUI::getDataBaseConnection()->update(
                    self::getSubscriptionsTable(),
                    [
                        'paypal_plan_id' => $planId,
                        'customer' => json_encode($customer),
                        'subscription_data' => json_encode($subscriptionData),
                        'global_process_id' => $globalProcessId,
                        'active' => (int)$active,
                        'paypal_account_hash' => $accountHash,
                        'paypal_account_check_hash' => $accountHash
                    ],
                    [
                        'paypal_subscription_id' => $subscriptionId,
                        'paypal_account_hash' => $accountHash
                    ]
                );

                return;
            }

            QUI::getDataBaseConnection()->insert(
                self::getSubscriptionsTable(),
                [
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_plan_id' => $planId,
                    'customer' => json_encode($customer),
                    'subscription_data' => json_encode($subscriptionData),
                    'global_process_id' => $globalProcessId,
                    'active' => (int)$active,
                    'paypal_account_hash' => $accountHash,
                    'paypal_account_check_hash' => $accountHash
                ]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    protected static function getPayPalRequestId(string $operation, string $identifier): string
    {
        return substr(hash('sha256', $operation . '|' . $identifier), 0, 38);
    }

    /**
     * @param array<string, mixed> $response
     * @return string
     */
    protected static function getApprovalUrl(array $response): string
    {
        if (empty($response['links'])) {
            return '';
        }

        foreach ($response['links'] as $link) {
            if (!empty($link['rel']) && $link['rel'] === 'approve' && !empty($link['href'])) {
                return $link['href'];
            }
        }

        return '';
    }

    /**
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    protected static function getIdentificationHash(
        AbstractOrder $Order,
        ?string $accountHash = null
    ): string {
        $accountHash ??= AccountContext::getHash();

        return hash(
            'sha256',
            self::getIdentificationSource($Order) . '|' . $accountHash
        );
    }

    /**
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    private static function getLegacyIdentificationHash(AbstractOrder $Order): string
    {
        return hash(
            'sha256',
            self::getIdentificationSource($Order)
            . (Provider::getApiSetting('sandbox') ? '_sandbox' : '_production')
        );
    }

    /**
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    private static function getIdentificationSource(AbstractOrder $Order): string
    {
        $productIds = [];

        foreach ($Order->getArticles() as $Article) {
            $productIds[] = (int)$Article->getId();
        }

        sort($productIds);

        $planDetails = [];

        try {
            $planDetails = QUI\ERP\Plans\Utils::getPlanDetailsFromProduct(self::getPlanProduct($Order));
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        $lang = $Order->getCustomer()->getLang();
        $totalSum = $Order->getPriceCalculation()->getSum()->get();
        return implode('|', [
            $lang,
            $Order->getCurrency()->getCode(),
            $totalSum,
            implode(',', $productIds),
            $planDetails['invoice_interval'] ?? '',
            $planDetails['duration_interval'] ?? '',
            !empty($planDetails['auto_extend']) ? '1' : '0'
        ]);
    }

    /**
     * Assign legacy subscriptions without account context to the current PayPal
     * application after verifying that the API can resolve them.
     *
     * @return void
     * @throws PayPalException
     * @throws QUI\Database\Exception
     */
    public static function migrateLegacyAccountContexts(): void
    {
        $accountHash = AccountContext::getHash();
        $migrationKey = $accountHash . '|all';

        if (isset(self::$legacyAccountMigrationAttempted[$migrationKey])) {
            return;
        }

        $legacySubscriptions = QUI::getQueryBuilder()
            ->select(Doctrine::quoteIdentifier('paypal_subscription_id'))
            ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
            ->where(Doctrine::quoteIdentifier('paypal_account_hash') . ' IS NULL')
            ->andWhere(
                '('
                . Doctrine::quoteIdentifier('paypal_account_check_hash')
                . ' IS NULL OR '
                . Doctrine::quoteIdentifier('paypal_account_check_hash')
                . ' != :accountHash)'
            )
            ->setParameter('accountHash', $accountHash)
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($legacySubscriptions as $subscriptionId) {
            self::adoptLegacySubscription((string)$subscriptionId);
        }

        self::$legacyAccountMigrationAttempted[$migrationKey] = true;
    }

    /**
     * @param string $subscriptionId
     * @return void
     * @throws PayPalException
     * @throws QUI\Database\Exception
     */
    private static function adoptLegacySubscription(string $subscriptionId): void
    {
        $accountHash = AccountContext::getHash();
        $migrationKey = $accountHash . '|' . $subscriptionId;

        if (isset(self::$legacyAccountMigrationAttempted[$migrationKey])) {
            return;
        }

        $legacySubscriptionId = QUI::getQueryBuilder()
            ->select(Doctrine::quoteIdentifier('paypal_subscription_id'))
            ->from(Doctrine::quoteIdentifier(self::getSubscriptionsTable()))
            ->where(Doctrine::quoteIdentifier('paypal_subscription_id') . ' = :subscriptionId')
            ->andWhere(Doctrine::quoteIdentifier('paypal_account_hash') . ' IS NULL')
            ->andWhere(
                '('
                . Doctrine::quoteIdentifier('paypal_account_check_hash')
                . ' IS NULL OR '
                . Doctrine::quoteIdentifier('paypal_account_check_hash')
                . ' != :accountHash)'
            )
            ->setParameter('subscriptionId', $subscriptionId)
            ->setParameter('accountHash', $accountHash)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($legacySubscriptionId === false) {
            self::$legacyAccountMigrationAttempted[$migrationKey] = true;
            return;
        }

        try {
            $subscriptionData = self::getSubscriptionDetails(
                $subscriptionId,
                true
            );
        } catch (PayPalException $Exception) {
            if (AccountContext::isMissingResource($Exception)) {
                QUI::getDataBaseConnection()->update(
                    self::getSubscriptionsTable(),
                    ['paypal_account_check_hash' => $accountHash],
                    ['paypal_subscription_id' => $subscriptionId]
                );
                self::$legacyAccountMigrationAttempted[$migrationKey] = true;
                return;
            }

            throw $Exception;
        }

        if (($subscriptionData['id'] ?? '') !== $subscriptionId) {
            QUI::getDataBaseConnection()->update(
                self::getSubscriptionsTable(),
                ['paypal_account_check_hash' => $accountHash],
                ['paypal_subscription_id' => $subscriptionId]
            );
            self::$legacyAccountMigrationAttempted[$migrationKey] = true;
            return;
        }

        QUI::getDataBaseConnection()->update(
            self::getSubscriptionsTable(),
            [
                'paypal_account_hash' => $accountHash,
                'paypal_account_check_hash' => $accountHash
            ],
            ['paypal_subscription_id' => $subscriptionId]
        );

        self::$legacyAccountMigrationAttempted[$migrationKey] = true;
    }

    protected static function updateStoredSubscriptionStatus(
        string $subscriptionId,
        string $status,
        bool $active
    ): void {
        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            return;
        }

        $subscriptionData = $data['subscriptionData'] ?? [];
        $subscriptionData['status'] = $status;

        try {
            QUI::getDataBaseConnection()->update(
                self::getSubscriptionsTable(),
                [
                    'subscription_data' => json_encode($subscriptionData),
                    'active' => (int)$active
                ],
                [
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_account_hash' => AccountContext::getHash()
                ]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param QueryBuilder $QueryBuilder
     * @param array<string, mixed> $gridParams
     * @return void
     */
    private static function applyGridLimit(
        QueryBuilder $QueryBuilder,
        array $gridParams
    ): void {
        if (empty($gridParams['limit'])) {
            $QueryBuilder->setMaxResults(20);
            return;
        }

        $limit = explode(',', (string)$gridParams['limit'], 2);

        if (isset($limit[1])) {
            $QueryBuilder->setFirstResult((int)$limit[0]);
            $QueryBuilder->setMaxResults((int)$limit[1]);
            return;
        }

        $QueryBuilder->setMaxResults((int)$limit[0]);
    }

    /**
     * @return ApiClient
     */
    protected static function getApiClient(): ApiClient
    {
        if (self::$ApiClient === null) {
            self::$ApiClient = new ApiClient();
        }

        return self::$ApiClient;
    }

    /**
     * @return string
     */
    protected static function getSubscriptionPlansTable(): string
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTION_PLANS);
    }

    /**
     * @return string
     */
    protected static function getSubscriptionsTable(): string
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTIONS);
    }

    /**
     * @return string
     */
    protected static function getSubscriptionTransactionsTable(): string
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTION_TRANSACTIONS);
    }

    /**
     * @return string
     */
    protected static function getSubscriptionWebhookEventsTable(): string
    {
        return QUI::getDBTableName(self::TBL_SUBSCRIPTION_WEBHOOK_EVENTS);
    }
}
