<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\Utils\Security\Orthos;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Invoice\Handler as InvoiceHandler;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Handler as TransactionHandler;

/**
 * Class BillingAgreements
 *
 * Handler for PayPal Billing Agreement management
 */
class BillingAgreements
{
    const TBL_BILLING_AGREEMENTS             = 'paypal_billing_agreements';
    const TBL_BILLING_AGREEMENT_TRANSACTIONS = 'paypal_billing_agreement_transactions';

    /**
     * Runtime cache that knows then a transaction history
     * for a Billing Agreement has been freshly fetched from PayPal.
     *
     * Prevents multiple unnecessary API calls.
     *
     * @var array
     */
    protected static $transactionsRefreshed = [];

    /**
     * @var QUI\ERP\Payments\PayPal\Payment
     */
    protected static $Payment = null;

    /**
     * Create a PayPal Billing Agreement based on a Billing Plan
     *
     * @param AbstractOrder $Order
     * @return string - Approval URL
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @throws \Exception
     */
    public static function createBillingAgreement(AbstractOrder $Order)
    {
        $billingPlanId = BillingPlans::createBillingPlanFromOrder($Order);

        $Order->addHistory(Utils::getHistoryText('order.billing_plan_created', [
            'billingPlandId' => $billingPlanId
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID, $billingPlanId);

        Utils::saveOrder($Order);

        if ($Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL)) {
            return $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL);
        }

        $Customer = $Order->getCustomer();
        $Gateway  = new Gateway();
        $Gateway->setOrder($Order);

        $body = [
            'name'                          => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.name',
                [
                    'orderReference' => $Order->getPrefixedId()
                ]
            ),
            'description'                   => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.description',
                [
                    'orderReference' => $Order->getPrefixedId(),
                    'url'            => Utils::getProjectUrl()
                ]
            ),
            'payer'                         => [
                'payment_method' => 'paypal',
                'payer_info'     => [
                    'email'      => $Customer->getAttribute('email'),
                    'first_name' => $Customer->getAttribute('firstname'),
                    'last_name'  => $Customer->getAttribute('lastname')
                ]
            ],
            'plan'                          => [
                'id' => $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID)
            ],
            'override_merchant_preferences' => [
                'cancel_url' => $Gateway->getCancelUrl(),
                'return_url' => $Gateway->getSuccessUrl()
            ]
        ];

        // Determine start date
        $Now = new \DateTime();
//        $Now->add(new \DateInterval('PT24H'));

        $body['start_date'] = $Now->format('Y-m-d\TH:i:s\Z');

        try {
            $response = self::payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT,
                $body,
                $Order
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            Utils::saveOrder($Order);
            throw $Exception;
        }

        if (empty($response['links'])) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            Utils::saveOrder($Order);

            QUI\System\Log::addError(
                'PayPal API :: Recurring Payments :: createBillingAgreement -> '.json_encode($response)
            );

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        foreach ($response['links'] as $link) {
            if (!empty($link['href']) && !empty($link['rel']) && $link['rel'] === 'approval_url') {
                $Order->addHistory(Utils::getHistoryText('order.billing_agreement_created', [
                    'approvalUrl' => $link['href']
                ]));

                $Order->setPaymentData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL, $link['href']);
                Utils::saveOrder($Order);

                return $link['href'];
            }
        }

        $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
        Utils::saveOrder($Order);

        QUI\System\Log::addError(
            'PayPal API :: Recurring Payments :: createBillingAgreement -> No approval URL found'
        );

        throw new PayPalException(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'exception.Recurring.order.error'
            )
        );
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws PayPalException
     * @throws \QUI\Exception
     */
    public static function billBillingAgreementBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
            $Invoice->addHistory(
                Utils::getHistoryText('invoice.error.agreement_id_not_found')
            );

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

        $data = self::getBillingAgreementData($billingAgreementId);

        if ($data === false) {
            $Invoice->addHistory(
                Utils::getHistoryText('invoice.error.agreement_not_found', [
                    'billingAgreementId' => $billingAgreementId
                ])
            );

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.agreement_not_found',
                    [
                        'billingAgreementId' => $billingAgreementId
                    ]
                ),
                404
            );
        }

//        try {
//            /** @var QUI\Locale $Locale */
//            $Locale      = $Invoice->getCustomer()->getLocale();
//            $InvoiceDate = new \DateTime($Invoice->getAttribute('date'));
//        } catch (\Exception $Exception) {
//            $Invoice->addHistory(
//                Utils::getHistoryText('invoice.error.general')
//            );
//
//            QUI\System\Log::writeException($Exception);
//            return;
//        }

        // Check if a Billing Agreement transaction matches the Invoice
        $unprocessedTransactions = self::getUnprocessedTransactions($billingAgreementId);
        $Invoice->calculatePayments();

        $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
        $invoiceCurrency = $Invoice->getCurrency()->getCode();
        $Payment         = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (float)$transaction['amount']['value'];
            $currency = $transaction['amount']['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getBillingAgreementTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paypal_transaction_id' => $transaction['transaction_id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_paypal_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'paypalTransactionId'  => $transaction['transaction_id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            break;
        }
    }

    /**
     * Get data of all Billing Agreements (QUIQQER data only; no PayPal query performed!)
     *
     * @param array $searchParams
     * @param bool $countOnly (optional) - Return count of all results
     * @return array|int
     */
    public static function getBillingAgreementList($searchParams, $countOnly = false)
    {
        $Grid       = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $binds = [];
        $where = [];

        if ($countOnly) {
            $sql = "SELECT COUNT(paypal_agreement_id)";
        } else {
            $sql = "SELECT *";
        }

        $sql .= " FROM `".self::getBillingAgreementsTable()."`";

        if (!empty($searchParams['search'])) {
            $where[] = '`global_process_id` LIKE :search';

            $binds['search'] = [
                'value' => '%'.$searchParams['search'].'%',
                'type'  => \PDO::PARAM_STR
            ];
        }

        // build WHERE query string
        if (!empty($where)) {
            $sql .= " WHERE ".implode(" AND ", $where);
        }

        // ORDER
        if (!empty($searchParams['sortOn'])
        ) {
            $sortOn = Orthos::clear($searchParams['sortOn']);
            $order  = "ORDER BY ".$sortOn;

            if (isset($searchParams['sortBy']) &&
                !empty($searchParams['sortBy'])
            ) {
                $order .= " ".Orthos::clear($searchParams['sortBy']);
            } else {
                $order .= " ASC";
            }

            $sql .= " ".$order;
        }

        // LIMIT
        if (!empty($gridParams['limit'])
            && !$countOnly
        ) {
            $sql .= " LIMIT ".$gridParams['limit'];
        } else {
            if (!$countOnly) {
                $sql .= " LIMIT ".(int)20;
            }
        }

        $Stmt = QUI::getPDO()->prepare($sql);

        // bind search values
        foreach ($binds as $var => $bind) {
            $Stmt->bindValue(':'.$var, $bind['value'], $bind['type']);
        }

        try {
            $Stmt->execute();
            $result = $Stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class.' :: searchUsers() -> '.$Exception->getMessage()
            );

            return [];
        }

        if ($countOnly) {
            return (int)current(current($result));
        }

        return $result;
    }

    /**
     * Get details of a Billing Agreement
     *
     * @param string $billingAgreementId
     * @return array
     * @throws PayPalException
     */
    public static function getBillingAgreementDetails($billingAgreementId)
    {
        return self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT,
            [],
            [
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
    }

    /**
     * Get transaction list for a Billing Agreement
     *
     * @param string $billingAgreementId
     * @param \DateTime $Start (optional)
     * @param \DateTime $End (optional)
     * @return array
     * @throws PayPalException
     * @throws \Exception
     */
    public static function getBillingAgreementTransactions(
        $billingAgreementId,
        \DateTime $Start = null,
        \DateTime $End = null
    ) {
        $data = [
            RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => $billingAgreementId
        ];

        if (is_null($Start)) {
            $Start = new \DateTime(date('Y-m').'-01 00:00:00');
        }

        if (is_null($End)) {
            $End = clone $Start;
            $End->add(new \DateInterval('P1M')); // Start + 1 month as default time period
        }

        $data['start_date'] = $Start->format('Y-m-d');
        $data['end_date']   = $End->format('Y-m-d');

        $result = self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT_TRANSACTIONS,
            [],
            $data
        );

        return $result['agreement_transaction_list'];
    }

    /**
     * Cancel a Billing Agreement
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PayPalException
     * @throws QUI\Database\Exception
     */
    public static function cancelBillingAgreement($billingAgreementId, $reason = '')
    {
        $data = self::getBillingAgreementData($billingAgreementId);

        if (empty($data)) {
            return;
        }

        try {
            $Locale = new QUI\Locale();
            $Locale->setCurrent($data['customer']['lang']);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        if (empty($reason)) {
            $reason = $Locale->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.cancel.note',
                [
                    'url'             => Utils::getProjectUrl(),
                    'globalProcessId' => $data['globalProcessId']
                ]
            );
        }

        try {
            self::payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT,
                [
                    'note' => $reason
                ],
                [
                    RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => $billingAgreementId
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.cancel.error'
                )
            );
        }

        // Set status in QUIQQER database to "not active"
        QUI::getDataBase()->update(
            self::getBillingAgreementsTable(),
            [
                'active' => 0
            ],
            [
                'paypal_agreement_id' => $billingAgreementId
            ]
        );
    }

    /**
     * Execute a Billing Agreement
     *
     * @param AbstractOrder $Order
     * @param string $agreementToken
     * @return void
     * @throws PayPalException
     */
    public static function executeBillingAgreement(AbstractOrder $Order, string $agreementToken)
    {
        try {
            $response = self::payPalApiRequest(
                RecurringPayment::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
                [],
                [
                    RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN => $agreementToken
                ]
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            Utils::saveOrder($Order);

            QUI\System\Log::writeException($Exception);

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $Order->addHistory(Utils::getHistoryText('order.billing_agreement_accepted', [
            'agreementToken' => $agreementToken,
            'agreementId'    => $response['id']
        ]));

        $Order->setPaymentData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN, $agreementToken);
        $Order->setPaymentData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID, $response['id']);
        $Order->setPaymentData(BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        Utils::saveOrder($Order);

        // Save billing agreement reference in database
        try {
            QUI::getDataBase()->insert(
                self::getBillingAgreementsTable(),
                [
                    'paypal_agreement_id' => $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID),
                    'paypal_plan_id'      => $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID),
                    'customer'            => json_encode($Order->getCustomer()->getAttributes()),
                    'global_process_id'   => $Order->getHash(),
                    'active'              => 1
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }
    }

    /**
     * Process all unpaid Invoices of Billing Agreements
     *
     * @return void
     */
    public static function processUnpaidInvoices()
    {
        $Invoices = InvoiceHandler::getInstance();

        // Determine payment type IDs
        $payments = Payments::getInstance()->getPayments([
            'select' => ['id'],
            'where'  => [
                'payment_type' => RecurringPayment::class
            ]
        ]);

        $paymentTypeIds = [];

        /** @var QUI\ERP\Accounting\Payments\Types\Payment $Payment */
        foreach ($payments as $Payment) {
            $paymentTypeIds[] = $Payment->getId();
        }

        if (empty($paymentTypeIds)) {
            return;
        }

        // Get all unpaid Invoices
        $result = $Invoices->search([
            'select' => ['id', 'global_process_id'],
            'where'  => [
                'paid_status'    => 0,
                'payment_method' => [
                    'type'  => 'IN',
                    'value' => $paymentTypeIds
                ]
            ]
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

        // Determine relevant Billing Agreements
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['global_process_id'],
                'from'   => self::getBillingAgreementsTable(),
                'where'  => [
                    'global_process_id' => [
                        'type'  => 'IN',
                        'value' => array_keys($invoiceIds)
                    ]
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        // Refresh Billing Agreement transactions
        foreach ($result as $row) {
            // Handle invoices
            foreach ($invoiceIds as $globalProcessId => $invoices) {
                if ($row['global_process_id'] !== $globalProcessId) {
                    continue;
                }

                foreach ($invoices as $invoiceId) {
                    try {
                        $Invoice = $Invoices->get($invoiceId);

                        // First: Process all failed transactions for Invoice
                        self::processDeniedTransactions($Invoice);

                        // Second: Process all completed transactions for Invoice
                        self::billBillingAgreementBalance($Invoice);
                    } catch (\Exception $Exception) {
                        QUI\System\Log::writeException($Exception);
                    }
                }
            }
        }
    }

    /**
     * Processes all denied PayPal transactions for an Invoice and creates a corresponding ERP Transaction
     *
     * @param Invoice $Invoice
     * @return void
     */
    public static function processDeniedTransactions(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
            return;
        }

        $data = self::getBillingAgreementData($billingAgreementId);

        if (empty($data)) {
            return;
        }

        // Get all "Denied" PayPal transactions
        try {
            $unprocessedTransactions = self::getUnprocessedTransactions($billingAgreementId, 'Denied');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        try {
            $Invoice->calculatePayments();

            $invoiceAmount   = (float)$Invoice->getAttribute('toPay');
            $invoiceCurrency = $Invoice->getCurrency()->getCode();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        $Payment = new RecurringPayment();

        foreach ($unprocessedTransactions as $transaction) {
            $amount   = (float)$transaction['amount']['value'];
            $currency = $transaction['amount']['currency'];

            if ($currency !== $invoiceCurrency) {
                continue;
            }

            if ($amount < $invoiceAmount) {
                continue;
            }

            // Transaction amount equals Invoice amount
            try {
                $InvoiceTransaction = TransactionFactory::createPaymentTransaction(
                    $amount,
                    $Invoice->getCurrency(),
                    $Invoice->getHash(),
                    $Payment->getName(),
                    [],
                    null,
                    false,
                    $Invoice->getGlobalProcessId()
                );

                $InvoiceTransaction->changeStatus(TransactionHandler::STATUS_ERROR);

                $Invoice->addTransaction($InvoiceTransaction);

                QUI::getDataBase()->update(
                    self::getBillingAgreementTransactionsTable(),
                    [
                        'quiqqer_transaction_id'        => $InvoiceTransaction->getTxId(),
                        'quiqqer_transaction_completed' => 1
                    ],
                    [
                        'paypal_transaction_id' => $transaction['transaction_id']
                    ]
                );

                $Invoice->addHistory(
                    Utils::getHistoryText('invoice.add_paypal_transaction', [
                        'quiqqerTransactionId' => $InvoiceTransaction->getTxId(),
                        'paypalTransactionId'  => $transaction['id']
                    ])
                );
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Refreshes transactions for a Billing Agreement
     *
     * @param string $billingAgreementId
     * @return void
     * @throws PayPalException
     * @throws QUI\Database\Exception
     * @throws \Exception
     */
    protected static function refreshTransactionList($billingAgreementId)
    {
        if (isset(self::$transactionsRefreshed[$billingAgreementId])) {
            return;
        }

        // Get global process id
        $data            = self::getBillingAgreementData($billingAgreementId);
        $globalProcessId = $data['globalProcessId'];

        // Determine start date
        $result = QUI::getDataBase()->fetch([
            'select' => ['paypal_transaction_date'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'paypal_agreement_id' => $billingAgreementId
            ],
            'order'  => [
                'field' => 'paypal_transaction_date',
                'sort'  => 'DESC'
            ],
            'limit'  => 1
        ]);

        if (empty($result)) {
            $Start = new \DateTime(date('Y').'-01-01 00:00:00'); // Beginning of current year
            $End   = new \DateTime();
            $End->add(new \DateInterval('P1M')); // add 1 month
        } else {
            $Start = new \DateTime($result[0]['paypal_transaction_date']);
            $End   = null;
        }

        // Determine existing transactions
        $result = QUI::getDataBase()->fetch([
            'select' => ['paypal_transaction_id', 'paypal_transaction_date'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'paypal_agreement_id' => $billingAgreementId
            ]
        ]);

        $existing = [];

        foreach ($result as $row) {
            $idHash            = md5($row['paypal_transaction_id'].$row['paypal_transaction_date']);
            $existing[$idHash] = true;
        }

        // Parse NEW transactions
        $transactions = self::getBillingAgreementTransactions($billingAgreementId, $Start, $End);

        foreach ($transactions as $transaction) {
            if (!isset($transaction['amount'])) {
                continue;
            }

            // Add warning if a transaction is unclaimed
            if ($transaction['status'] === 'Unclaimed') {
                QUI\System\Log::addWarning(
                    'PayPal Recurring Payments -> Some transactions for Billing Agreement '.$billingAgreementId
                    .' are marked as "Unclaimed" and cannot be processed for QUIQQER ERP Invoices. This most likely'
                    .' means that your PayPal merchant account does not support transactions'
                    .' in the transaction currency ('.$transaction['amount']['currency'].')!'
                );

                continue;
            }

            // Only collect transactions with status "Completed" or "Denied"
            if ($transaction['status'] !== 'Completed' && $transaction['status'] !== 'Denied') {
                continue;
            }

            $TransactionTime = new \DateTime($transaction['time_stamp']);
            $transactionTime = $TransactionTime->format('Y-m-d H:i:s');

            $idHash = md5($transaction['transaction_id'].$transactionTime);

            if (isset($existing[$idHash])) {
                continue;
            }

            QUI::getDataBase()->insert(
                self::getBillingAgreementTransactionsTable(),
                [
                    'paypal_transaction_id'   => $transaction['transaction_id'],
                    'paypal_agreement_id'     => $billingAgreementId,
                    'paypal_transaction_data' => json_encode($transaction),
                    'paypal_transaction_date' => $transactionTime,
                    'global_process_id'       => $globalProcessId
                ]
            );
        }

        self::$transactionsRefreshed[$billingAgreementId] = true;
    }

    /**
     * Get all completed Billing Agreement transactions that are unprocessed by QUIQQER ERP
     *
     * @param string $billingAgreementId
     * @param string $status (optional) - Get transactions with this status [default: "Completed"]
     * @return array
     * @throws QUI\Database\Exception
     * @throws PayPalException
     * @throws \Exception
     */
    protected static function getUnprocessedTransactions($billingAgreementId, $status = 'Completed')
    {
        $result = QUI::getDataBase()->fetch([
            'select' => ['paypal_transaction_data'],
            'from'   => self::getBillingAgreementTransactionsTable(),
            'where'  => [
                'paypal_agreement_id'    => $billingAgreementId,
                'quiqqer_transaction_id' => null
            ]
        ]);

        // Try to refresh list if no unprocessed transactions found
        if (empty($result)) {
            self::refreshTransactionList($billingAgreementId);

            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_transaction_data'],
                'from'   => self::getBillingAgreementTransactionsTable(),
                'where'  => [
                    'paypal_agreement_id'    => $billingAgreementId,
                    'quiqqer_transaction_id' => null
                ]
            ]);
        }

        $transactions = [];

        foreach ($result as $row) {
            $t = json_decode($row['paypal_transaction_data'], true);

            if ($t['status'] !== $status) {
                continue;
            }

            $transactions[] = $t;
        }

        return $transactions;
    }

    /**
     * Make a PayPal REST API request
     *
     * @param string $request - Request type (see self::PAYPAL_REQUEST_TYPE_*)
     * @param array $body - Request data
     * @param AbstractOrder|Transaction|array $TransactionObj - Object that contains necessary request data
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @return array|false - Response body or false on error
     *
     * @throws PayPalException
     */
    protected static function payPalApiRequest($request, $body, $TransactionObj)
    {
        if (is_null(self::$Payment)) {
            self::$Payment = new QUI\ERP\Payments\PayPal\Payment();
        }

        return self::$Payment->payPalApiRequest($request, $body, $TransactionObj);
    }

    /**
     * Get available data by Billing Agreement ID
     *
     * @param string $billingAgreementId - PayPal Billing Agreement ID
     * @return array|false
     */
    public static function getBillingAgreementData($billingAgreementId)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'from'  => self::getBillingAgreementsTable(),
                'where' => [
                    'paypal_agreement_id' => $billingAgreementId
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        $data = current($result);

        return [
            'active'          => !empty($data['active']) ? true : false,
            'globalProcessId' => $data['global_process_id'],
            'customer'        => json_decode($data['customer'], true),
        ];
    }

    /**
     * @return string
     */
    protected static function getBillingAgreementsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENTS);
    }

    /**
     * @return string
     */
    protected static function getBillingAgreementTransactionsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENT_TRANSACTIONS);
    }
}
