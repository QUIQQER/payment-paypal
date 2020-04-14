<?php

namespace QUI\ERP\Payments\PayPal;

use PayPal\v1\BillingAgreements\AgreementBillBalanceRequest;
use PayPal\v1\BillingAgreements\AgreementCancelRequest;
use PayPal\v1\BillingAgreements\AgreementCreateRequest;
use PayPal\v1\BillingAgreements\AgreementExecuteRequest;
use PayPal\v1\BillingAgreements\AgreementGetRequest;
use PayPal\v1\BillingAgreements\AgreementTransactionsRequest;
use PayPal\v1\Payments\SaleRefundRequest;
use PayPal\v1\BillingPlans\PlanCreateRequest;
use PayPal\v1\BillingPlans\PlanGetRequest;
use PayPal\v1\BillingPlans\PlanListRequest;
use PayPal\v1\BillingPlans\PlanUpdateRequest;
use PayPal\v1\Payments\OrderAuthorizeRequest;
use PayPal\v1\Payments\OrderCaptureRequest;
use PayPal\v1\Payments\OrderGetRequest;
use PayPal\v1\Payments\OrderVoidRequest;
use PayPal\v1\Payments\PaymentExecuteRequest;
use PayPal\v1\Payments\CaptureRefundRequest;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use PayPal\Core\PayPalHttpClient as PayPalClient;
use PayPal\Core\ProductionEnvironment;
use PayPal\Core\SandboxEnvironment;
use PayPal\v1\Payments\PaymentCreateRequest;
use QUI;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Utils\User as ERPUserUtils;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\Shipping\Shipping;

/**
 * Class Payment
 *
 * Main Payment class for PayPal payment processing
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * PayPal API Order attributes
     */
    const ATTR_PAYPAL_PAYMENT_ID         = 'paypal-PaymentId';
    const ATTR_PAYPAL_PAYER_ID           = 'paypal-PayerId';
    const ATTR_PAYPAL_ORDER_ID           = 'paypal-OrderId';
    const ATTR_PAYPAL_AUTHORIZATION_ID   = 'paypal-AuthorizationId';
    const ATTR_PAYPAL_CAPTURE_ID         = 'paypal-CaptureId';
    const ATTR_PAYPAL_PAYMENT_SUCCESSFUL = 'paypal-PaymentSuccessful';
    const ATTR_PAYPAL_PAYER_DATA         = 'paypal-PayerData';
    const ATTR_PAYPAL_REFUND_ID          = 'paypal-RefundIds';

    /**
     * PayPal Order states
     */
    const PAYPAL_ORDER_STATE_PENDING    = 'pending';
    const PAYPAL_ORDER_STATE_AUTHORIZED = 'authorized';
    const PAYPAL_ORDER_STATE_CAPTURED   = 'captured';
    const PAYPAL_ORDER_STATE_COMPLETED  = 'completed';
    const PAYPAL_ORDER_STATE_VOIDED     = 'voided';

    /**
     * PayPal Refund states
     */
    const PAYPAL_REFUND_STATE_PENDING   = 'pending';
    const PAYPAL_REFUND_STATE_COMPLETED = 'completed';

    /**
     * PayPal REST API request types
     */
    const PAYPAL_REQUEST_TYPE_GET_ORDER       = 'paypal-api-get_order';
    const PAYPAL_REQUEST_TYPE_CREATE_ORDER    = 'paypal-api-create_order';
    const PAYPAL_REQUEST_TYPE_EXECUTE_ORDER   = 'paypal-api-execute_order';
    const PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER = 'paypal-api-authorize_order';
    const PAYPAL_REQUEST_TYPE_CAPTURE_ORDER   = 'paypal-api-capture_order';
    const PAYPAL_REQUEST_TYPE_VOID_ORDER      = 'paypal-api-void_oder';
    const PAYPAL_REQUEST_TYPE_REFUND_ORDER    = 'paypal-api-refund_order';

    /**
     * Error codes
     */
    const PAYPAL_ERROR_GENERAL_ERROR                         = 'general_error';
    const PAYPAL_ERROR_ORDER_NOT_APPROVED                    = 'order_not_approved';
    const PAYPAL_ERROR_ORDER_NOT_AUTHORIZED                  = 'order_not_authorized';
    const PAYPAL_ERROR_ORDER_NOT_CAPTURED                    = 'order_not_captured';
    const PAYPAL_ERROR_ORDER_NOT_REFUNDED                    = 'order_not_refunded';
    const PAYPAL_ERROR_ORDER_NOT_REFUNDED_ORDER_NOT_CAPTURED = 'order_not_refunded_order_not_captured';

    /**
     * PayPal PHP REST Client (v2)
     *
     * @var PayPalClient
     */
    protected $PayPalClient = null;

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.description');
    }

    /**
     * Return the payment icon (the URL path)
     * Can be overwritten
     *
     * @return string
     */
    public function getIcon()
    {
        return Payments::getInstance()->getHost().
               URL_OPT_DIR.
               'quiqqer/payment-paypal/bin/images/Payment.png';
    }

    /**
     * Is the payment process successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful($hash)
    {
        try {
            $Order = OrderHandler::getInstance()->getOrderByHash($hash);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                'PayPal :: Cannot check if payment process for Order #'.$hash.' is successful'
                .' -> '.$Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL);
    }

    /**
     * Is the payment a gateway payment?
     *
     * @return bool
     */
    public function isGateway()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function refundSupport()
    {
        return true;
    }

    /**
     * Execute a refund
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param int|float $amount
     * @param string $message
     * @param false|string $hash - if a new hash will be used
     * @throws QUI\ERP\Accounting\Payments\Transactions\RefundException
     */
    public function refund(
        Transaction $Transaction,
        $amount,
        $message = '',
        $hash = false
    ) {
        try {
            if ($hash === false) {
                $hash = $Transaction->getHash();
            }

            $this->refundPayment($Transaction, $hash, $amount, $message);
        } catch (PayPalException $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-paypal',
                'exception.Payment.refund_error'
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new QUI\ERP\Accounting\Payments\Transactions\RefundException([
                'quiqqer/payment-paypal',
                'exception.Payment.refund_error'
            ]);
        }
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
     *
     * @throws QUI\Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null)
    {
        $Control = new PaymentDisplay();
        $Control->setAttribute('Order', $Order);
        $Control->setAttribute('Payment', $this);

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__).'/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Create a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function createPayPalOrder(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Create Order');

        $PriceCalculation = $Order->getPriceCalculation();
        $currencyCode     = $Order->getCurrency()->getCode();

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_ID)) {
            $Order->addHistory('PayPal :: Order already created. Voiding and re-creating...');
            $this->voidPayPalOrder($Order);
        }

        $isNetto = ERPUserUtils::isNettoUser($Order->getCustomer());

        // Basic payment data
        $transactionData = [
            'reference_id' => $Order->getHash(),
            'description'  => $this->getLocale()->get(
                'quiqqer/payment-paypal',
                'Payment.order.create.description',
                [
                    'orderId' => $Order->getPrefixedId()
                ]
            )
        ];

        $amount = [
            'currency' => $currencyCode,
            'total'    => $PriceCalculation->getSum()->precision(2)->get()
        ];

        // Shipping data
        $shippingCost    = Utils::getShippingCostsByOrder($Order);
        $shippingAddress = Utils::getPayPalShippingAddressDataByOrder($Order);

        if ($isNetto || $shippingCost) {
            $amountDetails = [
                'subtotal' => $PriceCalculation->getNettoSum()->precision(2)->get(),
                'tax'      => $PriceCalculation->getVatSum()->precision(2)->get()
            ];

            if ($shippingCost !== false) {
                $amountDetails['shipping'] = $shippingCost;
            }

            $amount['details'] = $amountDetails;
        }

        $transactionData['amount'] = $amount;

        // Article List
        if (Provider::getPaymentSetting('display_paypal_basket')) {
            $items = [];

            /** @var QUI\ERP\Accounting\Article $OrderArticle */
            foreach ($PriceCalculation->getArticles() as $OrderArticle) {
                $articleData = $OrderArticle->toArray();
                $calculated  = $articleData['calculated'];
                $FactorPrice = new CalculationValue($calculated['price']); // unit price

                $item = [
                    'name'     => $OrderArticle->getTitle(),
                    'quantity' => $OrderArticle->getQuantity(),
                    'price'    => $FactorPrice->precision(2)->get(),
                    'currency' => $currencyCode
                ];

                // Optional: product description
                $description = $OrderArticle->getDescription();

                if (!empty($description)) {
                    $item['description'] = $description;
                }

                // Optional: product article no.
                $articleNo = $OrderArticle->getArticleNo();

                if (!empty($articleNo)) {
                    $item['sku'] = $articleNo;
                }

                $items[] = $item;
            }

            // add price factors
            $PriceFactors = $Order->getArticles()->getPriceFactors();

            /** @var QUI\ERP\Accounting\PriceFactors\Factor $PriceFactor */
            foreach ($PriceFactors as $PriceFactor) {
                $FactorPriceCalc = new CalculationValue($PriceFactor->getSum());
                $factorExtraText = ''; // @todo
                $name            = $PriceFactor->getTitle();

                if (!empty($factorExtraText)) {
                    $name .= ' ('.$factorExtraText.')';
                }

                $items[] = [
                    'name'     => $name,
                    'quantity' => 1,
                    'price'    => $FactorPriceCalc->precision(2)->get(),
                    'currency' => $currencyCode
                ];
            }

            $transactionData['item_list']['items'] = $items;

            if ($shippingAddress !== false) {
                $transactionData['item_list']['shipping_address'] = $shippingAddress;
            }
        }

        // Return URLs
        $Gateway = new Gateway();

        $body = [
            'intent'        => 'order',
            'payer'         => [
                'payment_method' => 'paypal'
            ],
            'transactions'  => [$transactionData],
            'redirect_urls' => [
                'return_url' => $Gateway->getGatewayUrl(),
                'cancel_url' => $Gateway->getGatewayUrl()
            ]
        ];

        try {
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_CREATE_ORDER, $body, $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        $Order->addHistory('PayPal :: Order successfully created');
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_ID, $response['id']);
        $this->saveOrder($Order);
    }

    /**
     * Execute a PayPal Order
     *
     * @param string $paymentId - The paymentID from the user authorization of the Order
     * (this is used to verify if the QUIQQER ERP Order is actually the PayPal Order that is executed here)
     * @param string $payerId - The payerID from the user authorization of the Order
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public function executePayPalOrder(AbstractOrder $Order, $paymentId, $payerId)
    {
        $Order->addHistory('PayPal :: Execute Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)) {
            $Order->addHistory('PayPal :: Order already executed');
            $this->saveOrder($Order);
            return;
        }

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_ID) !== $paymentId) {
            $Order->addHistory(
                'PayPal :: PayPal Order ID that was saved in the QUIQQER Order'
                .' did not match the PayPal paymentID that was given to the executePayPalOrder method'
            );

            $this->saveOrder($Order);
            $this->throwPayPalException();
        }

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_EXECUTE_ORDER,
                ['payer_id' => $payerId],
                $Order
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        if (empty($response['state'])
            || $response['state'] !== 'approved') {
            if (empty($response['failure_reason'])) {
                $Order->addHistory(
                    'PayPal :: Order execution was not approved by PayPal because of an unknown error'
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order execution was not approved by PayPal. Reason: "'.$response['failure_reason'].'"'
                );
            }

            $this->voidPayPalOrder($Order);
            $this->saveOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_APPROVED);
        }

        // parse Order ID for the transaction
        $transaction = current($response['transactions']);
        $resources   = current($transaction['related_resources']);

        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_ID, $resources['order']['id']);

        $Order->addHistory(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'history.order_id',
                [
                    'orderId' => $resources['order']['id']
                ]
            )
        );

        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_ID, $payerId);
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_DATA, $response['payer']);

        $Order->addHistory('PayPal :: Order successfully executed and ready for authorization');
        $this->saveOrder($Order);
    }

    /**
     * Authorize a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @internal This method is currently not called in the order process, since PayPal Orders are captured
     * immediately after they are executed
     *
     */
    public function authorizePayPalOrder(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Authorize Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_AUTHORIZATION_ID)) {
            $Order->addHistory('PayPal :: Order already authorized');
            $this->saveOrder($Order);
            return;
        }

        $PriceCalculation = $Order->getPriceCalculation();
        $amountTotal      = Utils::formatPrice($PriceCalculation->getSum()->get());

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER,
                [
                    'amount' => [
                        'total'    => $amountTotal,
                        'currency' => $Order->getCurrency()->getCode()
                    ]
                ],
                $Order
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        // @todo handle pending status

        if (empty($response['state'])
            || $response['state'] !== 'authorized') {
            if (empty($response['reason_code'])) {
                $Order->addHistory(
                    'PayPal :: Order was not authorized by PayPal because of an unknown error'
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order was not authorized by PayPal. Reason: "'.$response['reason_code'].'"'
                );
            }

            $this->saveOrder($Order);
            $this->voidPayPalOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_AUTHORIZED);
        }

        $Order->setPaymentData(self::ATTR_PAYPAL_AUTHORIZATION_ID, $response['id']);
        $Order->addHistory('PayPal :: Order successfully authorized and ready for capture');

        $this->saveOrder($Order);
    }

    /**
     * Capture a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function capturePayPalOrder(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Capture Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL)) {
            $Order->addHistory('PayPal :: Order already captured');
            $this->saveOrder($Order);
            return;
        }

        $PriceCalculation = $Order->getPriceCalculation();
        $amountTotal      = Utils::formatPrice($PriceCalculation->getSum()->get());
        $captureId        = false;

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER,
                [
                    'amount'           => [
                        'total'    => $amountTotal,
                        'currency' => $Order->getCurrency()->getCode()
                    ],
                    'is_final_capture' => true // capture full amount directly
                ],
                $Order
            );

            $captured  = !empty($response['state']) && $response['state'] === self::PAYPAL_ORDER_STATE_COMPLETED;
            $captureId = $response['id'];
            $amount    = $response['amount'];
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);

            // it may happen that the capture was actually completed and the PHP SDK just
            // threw an exception
            $orderDetails = $this->getPayPalOrderDetails($Order);

            if (!$orderDetails) {
                $Order->addHistory('PayPal :: Order details could not be queried after failed capture request');
                $this->saveOrder($Order);
                $this->throwPayPalException();
            }

            $orderState = $orderDetails['state'];
            $captured   = $orderState === self::PAYPAL_ORDER_STATE_COMPLETED
                          || $orderState === self::PAYPAL_ORDER_STATE_CAPTURED;
            $amount     = $orderDetails['amount'];

            $Order->addHistory('PayPal :: Order status after failed capture request: "'.$orderState.'"');

            if ($captured) {
                $Order->addHistory(
                    'PayPal :: Order capture REST request failed. But Order capture was still completed on PayPal site.'
                    .' Continuing payment process.'
                );
            }

            $this->saveOrder($Order);
        }

        if (!$captured) {
            if (empty($response['reason_code'])) {
                $Order->addHistory(
                    'PayPal :: Order capture was not completed by PayPal because of an unknown error'
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order capture was not completed by PayPal. Reason: "'.$response['reason_code'].'"'
                );
            }

            $this->saveOrder($Order);

            // @todo mark $Order as problematic
            // @todo pending status
            $this->voidPayPalOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_CAPTURED);
        }

        if ($captureId) {
            $Order->setPaymentData(self::ATTR_PAYPAL_CAPTURE_ID, $captureId);
        }

        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);

        $Order->addHistory('PayPal :: Order successfully captured');

        $Order->addHistory(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'history.capture_id',
                [
                    'captureId' => $captureId
                ]
            )
        );

        $this->saveOrder($Order);

        $Order->setSuccessfulStatus();

        // Gateway purchase
        $Order->addHistory('PayPal :: Set Gateway purchase');

        $Transaction = Gateway::getInstance()->purchase(
            $amount['total'],
            new QUI\ERP\Currency\Currency($amount['currency']),
            $Order,
            $this
        );

        $Transaction->setData(
            self::ATTR_PAYPAL_ORDER_ID,
            $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
        );

        $Transaction->setData(
            self::ATTR_PAYPAL_CAPTURE_ID,
            $Order->getPaymentDataEntry(self::ATTR_PAYPAL_CAPTURE_ID)
        );

        $Transaction->updateData();

        $Order->addHistory('PayPal :: Gateway purchase completed and Order payment finished');
        $this->saveOrder($Order);
    }

    /**
     * Refund partial or full payment of an Order
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param string $refundHash - Hash of the refund Transaction
     * @param float $amount - The amount to be refunden
     * @param string $reason (optional) - The reason for the refund [default: none; max. 255 characters]
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public function refundPayment(Transaction $Transaction, $refundHash, $amount, $reason = '')
    {
        $Process = new QUI\ERP\Process($Transaction->getGlobalProcessId());
        $Process->addHistory('PayPal :: Start refund for transaction #'.$Transaction->getTxId());

        if (!$Transaction->getData(self::ATTR_PAYPAL_CAPTURE_ID)) {
            $Process->addHistory('PayPal :: Transaction cannot be refunded because it is not yet captured / completed.');
            $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_REFUNDED_ORDER_NOT_CAPTURED);
            return;
        }

        // create a refund transaction
        $RefundTransaction = TransactionFactory::createPaymentRefundTransaction(
            $amount,
            $Transaction->getCurrency(),
            $refundHash,
            $Transaction->getPayment()->getName(),
            [
                'isRefund' => 1,
                'message'  => $reason
            ],
            null,
            false,
            $Transaction->getGlobalProcessId()
        );

        $RefundTransaction->pending();

        $Currency       = $Transaction->getCurrency();
        $AmountCalc     = new CalculationValue($amount, $Currency, 2);
        $amountRefunded = Utils::formatPrice($AmountCalc->get());

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_REFUND_ORDER,
                [
                    'amount' => [
                        'total'    => $amountRefunded,
                        'currency' => $Currency->getCode()
                    ],
                    'reason' => mb_substr($reason, 0, 30)
                ],
                $Transaction
            );
        } catch (PayPalException $Exception) {
            $Process->addHistory(
                'PayPal :: Refund operation failed.'
                .' Reason: "'.$Exception->getMessage().'".'
                .' ReasonCode: "'.$Exception->getCode().'".'
                .' Transaction #'.$Transaction->getTxId()
            );

            $RefundTransaction->error();

            throw $Exception;
        }

        switch ($response['state']) {
            // SUCCESS
            case self::PAYPAL_REFUND_STATE_COMPLETED:
            case self::PAYPAL_REFUND_STATE_PENDING:
                $RefundTransaction->setData(self::ATTR_PAYPAL_REFUND_ID, $response['id']);
                $RefundTransaction->updateData();

                $Process->addHistory(
                    QUI::getLocale()->get(
                        'quiqqer/payment-paypal',
                        'history.refund',
                        [
                            'refundId' => $response['id'],
                            'amount'   => $response['amount']['total'],
                            'currency' => $response['amount']['currency']
                        ]
                    )
                );

                $RefundTransaction->complete();

                QUI::getEvents()->fireEvent('transactionSuccessfullyRefunded', [
                    $RefundTransaction,
                    $this
                ]);
                break;

            // FAILURE
            default:
                $Process->addHistory(
                    'PayPal :: Order refund was not completed by PayPal because of an unknown error.'
                    .' Refund state: '.$response['state']
                );

                $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_REFUNDED);
        }
    }

    /**
     * Get details of a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return false|string - false if details cannot be fetched (e.g. if Order has not been created with PayPal);
     * details otherwise
     */
    protected function getPayPalOrderDetails(AbstractOrder $Order)
    {
        $payPalOrderId = $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID);

        if (!$payPalOrderId) {
            return false;
        }

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_GET_ORDER,
                [],
                $Order
            );
        } catch (PayPalException $Exception) {
            return false;
        }

        return $response;
    }

    /**
     * Void a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     */
    protected function voidPayPalOrder(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Void Order');

        if (!$Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)) {
            $Order->addHistory(
                'PayPal :: Order cannot be voided because it has not been created yet'
                .' or was voided before'
            );

            $this->saveOrder($Order);
            return;
        }

        try {
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_VOID_ORDER, [], $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);

            throw $Exception;
        }

        if (empty($response['state'])
            || $response['state'] !== 'voided') {
            if (empty($response['reason_code'])) {
                $Order->addHistory(
                    'PayPal :: Order could not be voided because of an unknown reason.'
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order could not be voided. Reason: "'.$response['reason_code'].'"'
                );
            }

            $this->saveOrder($Order);
            $this->throwPayPalException();
        }

        // reset payment data so the order can be created again
        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_ID, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_DATA, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_AUTHORIZATION_ID, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_ID, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_CAPTURE_ID, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_ID, null);
        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, false);

        $Order->addHistory('PayPal :: Order successfully voided');
        $this->saveOrder($Order);
    }

    /**
     * Throw PayPalException for specific PayPal API Error
     *
     * @param string $errorCode (optional) - default: general error message
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return string
     *
     * @throws PayPalException
     */
    protected function throwPayPalException($errorCode = self::PAYPAL_ERROR_GENERAL_ERROR, $exceptionAttributes = [])
    {
        $L   = $this->getLocale();
        $lg  = 'quiqqer/payment-paypal';
        $msg = $L->get($lg, 'payment.error_msg.'.$errorCode);

        $Exception = new PayPalException($msg);
        $Exception->setAttributes($exceptionAttributes);

        throw $Exception;
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    protected function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
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
    public function payPalApiRequest($request, $body, $TransactionObj)
    {
        $getData = function ($key) use ($TransactionObj) {
            if ($TransactionObj instanceof AbstractOrder) {
                return $TransactionObj->getPaymentDataEntry($key);
            }

            if ($TransactionObj instanceof Transaction) {
                return $TransactionObj->getData($key);
            }

            if (is_array($TransactionObj) && array_key_exists($key, $TransactionObj)) {
                return $TransactionObj[$key];
            }

            return false;
        };

        switch ($request) {
            case self::PAYPAL_REQUEST_TYPE_GET_ORDER:
                $Request = new OrderGetRequest(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_CREATE_ORDER:
                $Request = new PaymentCreateRequest();
                break;

            case self::PAYPAL_REQUEST_TYPE_EXECUTE_ORDER:
                $Request = new PaymentExecuteRequest(
                    $getData(self::ATTR_PAYPAL_PAYMENT_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER:
                $Request = new OrderAuthorizeRequest(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER:
                $Request = new OrderCaptureRequest(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_VOID_ORDER:
                $Request = new OrderVoidRequest(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_REFUND_ORDER:
                $Request = new CaptureRefundRequest(
                    $getData(self::ATTR_PAYPAL_CAPTURE_ID)
                );
                break;

            // Billing
            case RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN:
                $Request = new PlanCreateRequest();
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN:
                $Request = new PlanUpdateRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_PLAN:
                $Request = new PlanGetRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT:
                $Request = new AgreementCreateRequest();
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_AGREEMENT:
                $Request = new PlanUpdateRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT:
                $Request = new AgreementExecuteRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT:
                $Request = new AgreementBillBalanceRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT:
                $Request = new AgreementCancelRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS:
                $Request = new PlanListRequest();

                $Request->page($getData('page'));
                $Request->pageSize($getData('page_size'));
                $Request->status($getData('status'));
                $Request->totalRequired($getData('total_required'));
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT:
                $Request = new AgreementGetRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT_TRANSACTIONS:
                $Request = new AgreementTransactionsRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID)
                );

                $startDate = $getData('start_date');
                $endDate   = $getData('end_date');

                if (!empty($startDate)) {
                    $Request->startDate($startDate);
                }

                if (!empty($endDate)) {
                    $Request->endDate($endDate);
                }
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_SALE_REFUND:
                $Request = new SaleRefundRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID)
                );
                break;

            default:
                $this->throwPayPalException();
        }

        if (!empty($body)) {
            $Request->body = $body;
        }

        try {
            $Response = $this->getPayPalClient()->execute($Request);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            $this->throwPayPalException();
        }

        // turn stdClass object to array
        return json_decode(json_encode($Response->result), true);
    }

    /**
     * Get PayPal Client for current payment process
     *
     * @return PayPalClient
     */
    protected function getPayPalClient()
    {
        if (!is_null($this->PayPalClient)) {
            return $this->PayPalClient;
        }

        if (Provider::getApiSetting('sandbox')) {
            $Environment = new SandboxEnvironment(
                Provider::getApiSetting('sandbox_client_id'),
                Provider::getApiSetting('sandbox_client_secret')
            );
        } else {
            $Environment = new ProductionEnvironment(
                Provider::getApiSetting('client_id'),
                Provider::getApiSetting('client_secret')
            );
        }

        $this->PayPalClient = new PayPalClient($Environment);

        return $this->PayPalClient;
    }
}
