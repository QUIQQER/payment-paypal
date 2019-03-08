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

/**
 * Class BillingAgreements
 *
 * Handler for PayPal Billing Agreement management
 */
class BillingAgreements
{
    const TBL_BILLING_AGREEMENTS = 'paypal_billing_agreements';

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
            $response = self::payPalApiRequest(RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT, $body,
                $Order);
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
     */
    public static function billBillingAgreementBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID);

        if (empty($billingAgreementId)) {
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

        try {
            /** @var QUI\Locale $Locale */
            $Locale = $Invoice->getCustomer()->getLocale();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT,
            [
                'note' => $Locale->get(
                    'quiqqer/payment-paypal',
                    'recurring.billing_agreement.bill_balance.note',
                    [
                        'invoiceReference' => $Invoice->getId(),
                        'url'              => Utils::getProjectUrl()
                    ]
                )
            ],
            [
                RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
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
     * Cancel a Billing Agreement
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PayPalException
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

        // Remove from QUIQQER database
//        QUI::getDataBase()->delete(
//            self::getBillingAgreementsTable(),
//            [
//                'paypal_agreement_id' => $billingAgreementId
//            ]
//        );
    }

    /**
     * Execute a Billing Agreement
     *
     * @param AbstractOrder $Order
     * @param string $agreementToken
     * @return void
     * @throws PayPalException
     */
    protected static function executeBillingAgreement(AbstractOrder $Order, string $agreementToken)
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
                    'global_process_id'   => $Order->getHash()
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
    protected static function getBillingAgreementData($billingAgreementId)
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
}
