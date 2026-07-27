<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use DateInterval;
use DateTime;
use Exception;
use QUI;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions\ApiClient;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Products\Handler\Products as ProductsHandler;
use QUI\ERP\Products\Product\Product;

use function array_column;
use function class_exists;
use function current;
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

    public const TRANSACTION_STATE_COMPLETED = 'COMPLETED';
    public const TRANSACTION_STATE_DENIED = 'DENIED';

    protected static ?ApiClient $ApiClient = null;

    /**
     * @param AbstractOrder $Order
     * @return string
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public static function createSubscription(AbstractOrder $Order): string
    {
        if ($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL)) {
            return $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL);
        }

        [$productId, $planId] = self::getOrCreatePlanReferences($Order);

        $Gateway = new Gateway();
        $Gateway->setOrder($Order);
        $Customer = $Order->getCustomer();

        $response = self::getApiClient()->post('/v1/billing/subscriptions', [
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
        ]);

        if (empty($response['id'])) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $approvalUrl = self::getApprovalUrl($response);

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
        $Order->addHistory('PayPal :: Subscription created: ' . $response['id']);
        Utils::saveOrder($Order);

        self::upsertSubscriptionRecord(
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

    /**
     * @param AbstractOrder $Order
     * @param string $subscriptionId
     * @return void
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public static function approveSubscription(AbstractOrder $Order, string $subscriptionId): void
    {
        $subscriptionData = self::getSubscriptionDetails($subscriptionId);
        $status = $subscriptionData['status'] ?? '';

        if (
            !in_array($status, [
                self::STATUS_ACTIVE,
                self::STATUS_APPROVAL_PENDING,
                self::STATUS_APPROVED
            ], true)
        ) {
            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $planId = $subscriptionData['plan_id']
            ?? $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID);

        self::upsertSubscriptionRecord(
            $subscriptionId,
            $planId,
            $subscriptionData['subscriber'] ?? [],
            $Order->getGlobalProcessId(),
            $subscriptionData,
            true
        );

        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_ID, $subscriptionId);
        $Order->setPaymentData(Payment::ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID, $planId);
        $Order->setPaymentData(BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        $Order->addHistory('PayPal :: Subscription approved: ' . $subscriptionId);
        Utils::saveOrder($Order);
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

        self::setSubscriptionAsInactive($subscriptionId);
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
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_subscription_id'],
                'from' => self::getSubscriptionsTable(),
                'where' => [
                    'paypal_subscription_id' => $subscriptionId
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return !empty($result);
    }

    /**
     * @param string $subscriptionId
     * @return array
     * @throws PayPalException
     */
    public static function getSubscriptionDetails(string $subscriptionId): array
    {
        return self::getApiClient()->get('/v1/billing/subscriptions/' . $subscriptionId);
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
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['active'],
                'from' => self::getSubscriptionsTable(),
                'where' => [
                    'paypal_subscription_id' => $subscriptionId
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        if (empty($result)) {
            return false;
        }

        return !empty($result[0]['active']);
    }

    /**
     * @param bool $includeInactive
     * @return array
     */
    public static function getSubscriptionIds(bool $includeInactive = false): array
    {
        $where = [];

        if (!$includeInactive) {
            $where['active'] = 1;
        }

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_subscription_id'],
                'from' => self::getSubscriptionsTable(),
                'where' => $where
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        return array_column($result, 'paypal_subscription_id');
    }

    /**
     * @param string $subscriptionId
     * @return void
     */
    public static function setSubscriptionAsInactive(string $subscriptionId): void
    {
        try {
            QUI::getDataBase()->update(
                self::getSubscriptionsTable(),
                ['active' => 0],
                ['paypal_subscription_id' => $subscriptionId]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param string $subscriptionId
     * @return array|false
     */
    public static function getSubscriptionData(string $subscriptionId): array|false
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from' => self::getSubscriptionsTable(),
                'where' => [
                    'paypal_subscription_id' => $subscriptionId
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        $data = current($result);

        return [
            'active' => !empty($data['active']),
            'globalProcessId' => $data['global_process_id'],
            'customer' => json_decode($data['customer'], true),
            'subscriptionData' => json_decode($data['subscription_data'] ?? '[]', true),
            'planId' => $data['paypal_plan_id']
        ];
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

                QUI::getDataBase()->update(
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

                QUI::getDataBase()->update(
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
        $Invoices = InvoiceHandler::getInstance();
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

        if (empty($paymentTypeIds)) {
            return;
        }

        $result = $Invoices->search([
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

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from' => self::getSubscriptionsTable(),
                'where' => [
                    'global_process_id' => [
                        'type' => 'IN',
                        'value' => array_keys($invoiceIds)
                    ]
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($result as $row) {
            foreach ($invoiceIds as $globalProcessId => $invoices) {
                if ($row['global_process_id'] !== $globalProcessId) {
                    continue;
                }

                foreach ($invoices as $invoiceId) {
                    try {
                        $Invoice = $Invoices->get($invoiceId);
                        self::processDeniedTransactions($Invoice);
                        self::billSubscriptionInvoice($Invoice);
                    } catch (Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    /**
     * @param array $headers
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

        self::processWebhookEvent($event);

        return true;
    }

    /**
     * @param array $event
     * @return bool|null - true if persisted, false if already known, null on database error
     */
    protected static function persistWebhookEvent(array $event): ?bool
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = self::getSubscriptionIdFromResource($resource);

        try {
            if (self::webhookEventExists($event['id'])) {
                return false;
            }

            QUI::getDataBase()->insert(
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
     * @param array $event
     * @return void
     */
    protected static function processWebhookEvent(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = self::getSubscriptionIdFromResource($resource);

        if ($subscriptionId === '') {
            return;
        }

        $eventType = $event['event_type'] ?? '';

        try {
            if (str_starts_with($eventType, 'BILLING.SUBSCRIPTION.')) {
                self::processSubscriptionLifecycleEvent($subscriptionId, $resource);
            }

            if (str_starts_with($eventType, 'PAYMENT.SALE.')) {
                self::persistTransactionFromWebhook($subscriptionId, $resource, $eventType);
            }

            QUI::getDataBase()->update(
                self::getSubscriptionWebhookEventsTable(),
                ['processed' => 1],
                ['paypal_event_id' => $event['id']]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param string $subscriptionId
     * @param array $resource
     * @return void
     */
    protected static function processSubscriptionLifecycleEvent(string $subscriptionId, array $resource): void
    {
        $status = $resource['status'] ?? '';
        $active = !in_array($status, [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED
        ], true);

        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            return;
        }

        self::upsertSubscriptionRecord(
            $subscriptionId,
            $resource['plan_id'] ?? $data['planId'],
            $resource['subscriber'] ?? $data['customer'] ?? [],
            $data['globalProcessId'],
            $resource,
            $active
        );
    }

    /**
     * @param string $subscriptionId
     * @param array $resource
     * @param string $eventType
     * @return void
     */
    protected static function persistTransactionFromWebhook(string $subscriptionId, array $resource, string $eventType): void
    {
        $transactionId = self::getTransactionId($resource);

        if ($transactionId === '') {
            return;
        }

        $data = self::getSubscriptionData($subscriptionId);

        if ($data === false) {
            return;
        }

        $transactionDate = self::formatDate(self::getTransactionTime($resource));
        $status = self::getTransactionStatus($resource, $eventType);

        $resource['status'] = $status;

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_transaction_id'],
                'from' => self::getSubscriptionTransactionsTable(),
                'where' => [
                    'paypal_transaction_id' => $transactionId,
                    'paypal_transaction_date' => $transactionDate
                ]
            ]);

            if (!empty($result)) {
                return;
            }

            QUI::getDataBase()->insert(
                self::getSubscriptionTransactionsTable(),
                [
                    'paypal_transaction_id' => $transactionId,
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_transaction_data' => json_encode($resource),
                    'paypal_transaction_date' => $transactionDate,
                    'global_process_id' => $data['globalProcessId']
                ]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param string $subscriptionId
     * @param string $status
     * @return array
     * @throws QUI\Database\Exception
     */
    protected static function getUnprocessedTransactions(
        string $subscriptionId,
        string $status = self::TRANSACTION_STATE_COMPLETED
    ): array {
        $result = QUI::getDataBase()->fetch([
            'select' => ['paypal_transaction_data'],
            'from' => self::getSubscriptionTransactionsTable(),
            'where' => [
                'paypal_subscription_id' => $subscriptionId,
                'quiqqer_transaction_id' => null
            ]
        ]);

        if (empty($result)) {
            self::refreshTransactionList($subscriptionId);

            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_transaction_data'],
                'from' => self::getSubscriptionTransactionsTable(),
                'where' => [
                    'paypal_subscription_id' => $subscriptionId,
                    'quiqqer_transaction_id' => null
                ]
            ]);
        }

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

            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_transaction_date'],
                'from' => self::getSubscriptionTransactionsTable(),
                'where' => [
                    'paypal_subscription_id' => $subscriptionId
                ],
                'order' => [
                    'field' => 'paypal_transaction_date',
                    'sort' => 'DESC'
                ],
                'limit' => 1
            ]);

            if (!empty($result)) {
                $Start = new DateTime($result[0]['paypal_transaction_date']);
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
     * @param string $eventId
     * @return bool
     */
    protected static function webhookEventExists(string $eventId): bool
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_event_id'],
                'from' => self::getSubscriptionWebhookEventsTable(),
                'where' => [
                    'paypal_event_id' => $eventId
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return !empty($result);
    }

    /**
     * @param array $resource
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
     * @param array $transaction
     * @return string
     */
    protected static function getTransactionId(array $transaction): string
    {
        return (string)($transaction['id'] ?? $transaction['sale_id'] ?? $transaction['transaction_id'] ?? '');
    }

    /**
     * @param array $transaction
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
     * @param array $transaction
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
     * @param array $transaction
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
     * @param array $transaction
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
     * @return array
     * @throws PayPalException
     * @throws QUI\Exception
     */
    protected static function getOrCreatePlanReferences(AbstractOrder $Order): array
    {
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

        QUI::getDataBase()->insert(
            self::getSubscriptionPlansTable(),
            [
                'paypal_product_id' => $productId,
                'paypal_plan_id' => $planData['id'],
                'identification_hash' => self::getIdentificationHash($Order),
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

        $response = self::getApiClient()->post('/v1/catalogs/products', [
            'name' => $name,
            'description' => substr($description, 0, 127),
            'type' => 'SERVICE',
            'category' => 'SOFTWARE'
        ]);

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
     * @return array
     * @throws PayPalException
     * @throws QUI\Exception
     */
    protected static function createPlan(AbstractOrder $Order, Product $PlanProduct, string $productId): array
    {
        $planDetails = QUI\ERP\Plans\Utils::getPlanDetailsFromProduct($PlanProduct);
        $invoiceIntervalParts = explode('-', $planDetails['invoice_interval']);
        $PriceCalculation = $Order->getPriceCalculation();

        return self::getApiClient()->post('/v1/billing/plans', [
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
        ]);
    }

    /**
     * @param array $planDetails
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
     * @return array|false
     * @throws QUI\Exception
     */
    protected static function getPlanByOrder(AbstractOrder $Order): array|false
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_product_id', 'paypal_plan_id'],
                'from' => self::getSubscriptionPlansTable(),
                'where' => [
                    'identification_hash' => self::getIdentificationHash($Order)
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        return $result[0];
    }

    /**
     * @param string $subscriptionId
     * @param string $planId
     * @param array $customer
     * @param string|null $globalProcessId
     * @param array $subscriptionData
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
        try {
            if (self::exists($subscriptionId)) {
                QUI::getDataBase()->update(
                    self::getSubscriptionsTable(),
                    [
                        'paypal_plan_id' => $planId,
                        'customer' => json_encode($customer),
                        'subscription_data' => json_encode($subscriptionData),
                        'global_process_id' => $globalProcessId,
                        'active' => (int)$active
                    ],
                    [
                        'paypal_subscription_id' => $subscriptionId
                    ]
                );

                return;
            }

            QUI::getDataBase()->insert(
                self::getSubscriptionsTable(),
                [
                    'paypal_subscription_id' => $subscriptionId,
                    'paypal_plan_id' => $planId,
                    'customer' => json_encode($customer),
                    'subscription_data' => json_encode($subscriptionData),
                    'global_process_id' => $globalProcessId,
                    'active' => (int)$active
                ]
            );
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @param array $response
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
    protected static function getIdentificationHash(AbstractOrder $Order): string
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
        $hashedString = implode('|', [
            $lang,
            $Order->getCurrency()->getCode(),
            $totalSum,
            implode(',', $productIds),
            $planDetails['invoice_interval'] ?? '',
            $planDetails['duration_interval'] ?? '',
            !empty($planDetails['auto_extend']) ? '1' : '0'
        ]);
        $hashedString .= Provider::getApiSetting('sandbox') ? '_sandbox' : '_production';

        return hash('sha256', $hashedString);
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
