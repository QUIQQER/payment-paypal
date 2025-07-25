<?php

namespace QUI\ERP\Payments\PayPal;

use Exception;
use PayPalCheckoutSdk\Core\PayPalHttpClient as PayPalClientV2;
use PayPalCheckoutSdk\Core\ProductionEnvironment as PayPalProductionEnvironmentV2;
use PayPalCheckoutSdk\Core\SandboxEnvironment as PayPalSandboxEnvironmentV2;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest as PayPalOrderCaptureRequestV2;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest as PayPalOrdersCreateRequestV2;
use PayPalCheckoutSdk\Orders\OrdersGetRequest as PayPalOrderGetRequestV2;
use PayPalCheckoutSdk\Orders\OrdersPatchRequest as PayPalOrdersPatchRequestV2;
use QUI;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Accounting\Payments\Payments;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Payments\PayPal\PhpSdk\Core\PayPalHttpClient as PayPalClient;
use QUI\ERP\Payments\PayPal\PhpSdk\Core\ProductionEnvironment;
use QUI\ERP\Payments\PayPal\PhpSdk\Core\SandboxEnvironment;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementBillBalanceRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementCancelRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementCreateRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementExecuteRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementGetRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementReActivateRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementSuspendRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements\AgreementTransactionsRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingPlans\PlanCreateRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingPlans\PlanGetRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingPlans\PlanListRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingPlans\PlanUpdateRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\Payments\CaptureRefundRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\Payments\OrderAuthorizeRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\Payments\OrderVoidRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\Payments\PaymentExecuteRequest;
use QUI\ERP\Payments\PayPal\PhpSdk\v1\Payments\SaleRefundRequest;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\Utils\User as ERPUserUtils;
use QUI\ExceptionStack;

use function boolval;
use function get_class;
use function is_array;
use function json_decode;
use function json_encode;
use function json_last_error;
use function mb_strlen;
use function mb_substr;
use function rtrim;

use const JSON_ERROR_NONE;

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
    const ATTR_PAYPAL_PAYMENT_ID = 'paypal-PaymentId';
    const ATTR_PAYPAL_PAYER_ID = 'paypal-PayerId';
    const ATTR_PAYPAL_ORDER_ID = 'paypal-OrderId';
    const ATTR_PAYPAL_AUTHORIZATION_ID = 'paypal-AuthorizationId';
    const ATTR_PAYPAL_CAPTURE_ID = 'paypal-CaptureId';
    const ATTR_PAYPAL_PAYMENT_SUCCESSFUL = 'paypal-PaymentSuccessful';
    const ATTR_PAYPAL_PAYER_DATA = 'paypal-PayerData';
    const ATTR_PAYPAL_REFUND_ID = 'paypal-RefundIds';
    const ATTR_PAYPAL_ORDER_DOES_NOT_EXIST = 'paypal-OrderDoesNotExist';

    /**
     * PayPal Order states
     */
    const PAYPAL_ORDER_STATE_PENDING = 'pending';
    const PAYPAL_ORDER_STATE_AUTHORIZED = 'authorized';
    const PAYPAL_ORDER_STATE_CAPTURED = 'captured';
    const PAYPAL_ORDER_STATE_COMPLETED = 'COMPLETED';
    const PAYPAL_ORDER_STATE_VOIDED = 'voided';

    /**
     * PayPal Capture states
     */
    const PAYPAL_CAPTURE_STATE_COMPLETED = 'COMPLETED';
    const PAYPAL_CAPTURE_STATE_PENDING = 'PENDING';

    /**
     * PayPal Refund states
     */
    const PAYPAL_REFUND_STATE_PENDING = 'pending';
    const PAYPAL_REFUND_STATE_COMPLETED = 'completed';

    /**
     * PayPal REST API request types
     */
    const PAYPAL_REQUEST_TYPE_GET_ORDER = 'paypal-api-get_order';
    const PAYPAL_REQUEST_TYPE_CREATE_ORDER = 'paypal-api-create_order';
    const PAYPAL_REQUEST_TYPE_UPDATE_ORDER = 'paypal-api-update_order';
    const PAYPAL_REQUEST_TYPE_EXECUTE_ORDER = 'paypal-api-execute_order';
    const PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER = 'paypal-api-authorize_order';
    const PAYPAL_REQUEST_TYPE_CAPTURE_ORDER = 'paypal-api-capture_order';
    const PAYPAL_REQUEST_TYPE_VOID_ORDER = 'paypal-api-void_oder';
    const PAYPAL_REQUEST_TYPE_REFUND_ORDER = 'paypal-api-refund_order';

    /**
     * Error codes
     */
    const PAYPAL_ERROR_GENERAL_ERROR = 'general_error';
    const PAYPAL_ERROR_ORDER_NOT_APPROVED = 'order_not_approved';
    const PAYPAL_ERROR_ORDER_NOT_AUTHORIZED = 'order_not_authorized';
    const PAYPAL_ERROR_ORDER_NOT_CAPTURED = 'order_not_captured';
    const PAYPAL_ERROR_ORDER_NOT_REFUNDED = 'order_not_refunded';
    const PAYPAL_ERROR_ORDER_NOT_REFUNDED_ORDER_NOT_CAPTURED = 'order_not_refunded_order_not_captured';

    /**
     * Error codes returned from PayPal API
     */
    const PAYPAL_API_EXCEPTION_MESSAGE_RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    /**
     * PayPal PHP REST Client (v1)
     *
     * @var PayPalClient|null
     */
    protected ?PayPalClient $PayPalClient = null;

    /**
     * PayPal PHP REST Client (v2)
     *
     * @var PayPalClientV2|null
     */
    protected ?PayPalClientV2 $PayPalClientV2 = null;

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.title');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.description');
    }

    /**
     * Return the payment icon (the URL path)
     * Can be overwritten
     *
     * @return string
     */
    public function getIcon(): string
    {
        return Payments::getInstance()->getHost() .
            URL_OPT_DIR .
            'quiqqer/payment-paypal/bin/images/Payment.png';
    }

    /**
     * Is the payment process successful?
     * This method returns the payment success type
     *
     * @param string $hash - Vorgangsnummer - hash number - procedure number
     * @return bool
     */
    public function isSuccessful(string $hash): bool
    {
        try {
            $Order = OrderHandler::getInstance()->getOrderByHash($hash);
        } catch (Exception $Exception) {
            QUI\System\Log::addError(
                'PayPal :: Cannot check if payment process for Order #' . $hash . ' is successful'
                . ' -> ' . $Exception->getMessage()
            );

            return false;
        }

        return (bool)$Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL);
    }

    /**
     * Is the payment a gateway payment?
     *
     * @return bool
     */
    public function isGateway(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function refundSupport(): bool
    {
        return true;
    }

    /**
     * Execute a refund
     *
     * @param QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
     * @param float|int $amount
     * @param string $message
     * @param false|string $hash - if a new hash will be used
     * @throws QUI\ERP\Accounting\Payments\Transactions\RefundException
     */
    public function refund(
        Transaction $Transaction,
        float|int $amount,
        string $message = '',
        $hash = false
    ): void {
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
        } catch (Exception $Exception) {
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
     * @throws QUI\Exception|Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null): string
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
        $Step->setContent($Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.Header.html'));

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
    public function createPayPalOrder(AbstractOrder $Order): void
    {
        $Order->addHistory('PayPal :: Create Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_ID)) {
            $Order->addHistory('PayPal :: Order already created - updating with new details');
            $this->updatePayPalOrder($Order);
            return;
        }

        $body = $this->getPayPalDataForOrder($Order);

        try {
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_CREATE_ORDER, $body, $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        $Order->addHistory('PayPal :: Order successfully created');
        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_ID, $response['id']);
        $this->saveOrder($Order);
    }

    /**
     * Update a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public function updatePayPalOrder(AbstractOrder $Order): void
    {
        $Order->addHistory('PayPal :: Update Order');

        if (!$Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)) {
            $Order->addHistory('PayPal :: Order cannot be updated since it has not been created yet');
            return;
        }

        $payPalDataForOrder = $this->getPayPalDataForOrder($Order);
        $purchaseUnit = $payPalDataForOrder['purchase_units'][0];

        $body = [
            [
                'op' => 'replace',
                'path' => '/purchase_units/@reference_id==\'' . $Order->getUUID() . '\'',
                'value' => $purchaseUnit
            ]
        ];

        try {
            $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_UPDATE_ORDER, $body, $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        $Order->addHistory('PayPal :: Order successfully updated');
        $this->saveOrder($Order);
    }

    /**
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @internal This method is currently not called in the order process, since PayPal Orders are captured
     * immediately after they are executed
     *
     * Authorize a PayPal Order
     *
     */
    public function authorizePayPalOrder(AbstractOrder $Order): void
    {
        $Order->addHistory('PayPal :: Authorize Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_AUTHORIZATION_ID)) {
            $Order->addHistory('PayPal :: Order already authorized');
            $this->saveOrder($Order);
            return;
        }

        $PriceCalculation = $Order->getPriceCalculation();
        $amountTotal = Utils::formatPrice($PriceCalculation->getSum()->get());

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER,
                [
                    'amount' => [
                        'total' => $amountTotal,
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

        if (
            empty($response['state'])
            || $response['state'] !== 'authorized'
        ) {
            if (empty($response['reason_code'])) {
                $Order->addHistory(
                    'PayPal :: Order was not authorized by PayPal because of an unknown error'
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order was not authorized by PayPal. Reason: "' . $response['reason_code'] . '"'
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
    public function capturePayPalOrder(AbstractOrder $Order): void
    {
        $Order->addHistory('PayPal :: Capture Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL)) {
            $Order->addHistory('PayPal :: Order already captured');
            $this->saveOrder($Order);
            return;
        }

        $this->updatePayPalOrder($Order);

        $PriceCalculation = $Order->getPriceCalculation();
//        $amountTotal      = Utils::formatPrice($PriceCalculation->getSum()->get());
        $captureId = false;
        $captured = false;
        $pending = false;
        $amount = false;

        $checkOrderCaptureStatus = function ($payPalOrderData) use (&$captureId, &$captured, &$amount, &$pending) {
            if (empty($payPalOrderData['purchase_units'][0]['payments']['captures'])) {
                return;
            }

            $captureData = $payPalOrderData['purchase_units'][0]['payments']['captures'][0];

            switch ($captureData['status']) {
                case self::PAYPAL_CAPTURE_STATE_COMPLETED:
                    $captured = true;
                    break;

                case self::PAYPAL_CAPTURE_STATE_PENDING:
                    $captured = true;
                    $pending = true;
                    break;
            }

//            $captured = !empty($captureData['status']) &&
//                        $captureData['status'] === self::PAYPAL_ORDER_STATE_COMPLETED &&
//                        $captureData['final_capture'];

            $captureId = $captureData['id'];
            $amount = $captureData['amount'];
        };

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER,
                [],
                $Order
            );

            $checkOrderCaptureStatus($response);
        } catch (PayPalException) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);

            // it may happen that the capture was actually completed and the PHP SDK just
            // threw an exception
            $orderDetails = $this->getPayPalOrderDetails($Order);
            $checkOrderCaptureStatus($orderDetails);

            if ($captured) {
                $Order->addHistory(
                    'PayPal :: Order capture REST request failed. But Order capture was still completed on PayPal site.'
                    . ' Continuing payment process.'
                );
            }

            $this->saveOrder($Order);
        }

        if (!$captured) {
            $captureFailReason = '';

            if (!empty($payPalOrderData['purchase_units'][0]['payments']['captures'][0]['status_details'])) {
                $captureFailReason = json_encode(
                    $payPalOrderData['purchase_units'][0]['payments']['captures'][0]['status_details']
                );
            }

            if (!empty($response['reason_code'])) {
                $Order->addHistory(
                    'PayPal :: Order capture was not completed by PayPal. Reason code: "' . $response['reason_code'] . '"'
                    . ' | Capture failed because: ' . $captureFailReason
                );
            } else {
                $Order->addHistory(
                    'PayPal :: Order capture was not completed by PayPal. Unknown reason code.'
                    . ' | Capture failed because: ' . $captureFailReason
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

        $Order->addHistory('PayPal :: Order capture request was successful. Checking capture status...');

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

        if (!$pending) {
            $Transaction = Gateway::getInstance()->purchase(
                (float)$amount['value'],
                QUI\ERP\Currency\Handler::getCurrency($amount['currency_code']),
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

            $Order->addHistory(
                'PayPal :: Order capture was completed. Transaction ' . $Transaction->getTxId() . ' added.'
            );

            // so we get the new order status if transaction change stuff
            $Order->refresh();
        } else {
            $Order->addHistory(
                'PayPal :: Order capture was not completed immediately.'
                . ' Payment is PENDING and has to be added manually to the order or checked via cronjob.'
            );
        }

        $Order->addHistory('PayPal :: Gateway purchase completed.');
        $this->saveOrder($Order);
    }

    /**
     * Refund partial or full payment of an Order
     *
     * @param Transaction $Transaction
     * @param string $refundHash - Hash of the refund Transaction
     * @param float|int $amount - The amount to be refunded
     * @param string $reason (optional) - The reason for the refund [default: none; max. 255 characters]
     * @return void
     *
     * @throws PayPalException
     * @throws PayPalSystemException
     * @throws QUI\Database\Exception
     * @throws QUI\ERP\Accounting\Payments\Transactions\Exception
     * @throws ExceptionStack
     */
    public function refundPayment(
        Transaction $Transaction,
        string $refundHash,
        float|int $amount,
        string $reason = ''
    ): void {
        $Process = new QUI\ERP\Process($Transaction->getGlobalProcessId());
        $Process->addHistory('PayPal :: Start refund for transaction #' . $Transaction->getTxId());

        if (!$Transaction->getData(self::ATTR_PAYPAL_CAPTURE_ID)) {
            $Process->addHistory(
                'PayPal :: Transaction cannot be refunded because it is not yet captured / completed.'
            );

            $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_REFUNDED_ORDER_NOT_CAPTURED);
        }

        // create a refund transaction
        $RefundTransaction = TransactionFactory::createPaymentRefundTransaction(
            $amount,
            $Transaction->getCurrency(),
            $refundHash,
            $Transaction->getPayment()->getName(),
            [
                'isRefund' => 1,
                'message' => $reason
            ],
            null,
            false,
            $Transaction->getGlobalProcessId()
        );

        $RefundTransaction->pending();

        $Currency = $Transaction->getCurrency();
        $AmountCalc = new CalculationValue($amount, $Currency, 2);
        $amountRefunded = Utils::formatPrice($AmountCalc->get());

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_REFUND_ORDER,
                [
                    'amount' => [
                        'total' => $amountRefunded,
                        'currency' => $Currency->getCode()
                    ],
                    'reason' => mb_substr($reason, 0, 30)
                ],
                $Transaction
            );
        } catch (PayPalException $Exception) {
            $Process->addHistory(
                'PayPal :: Refund operation failed.'
                . ' Reason: "' . $Exception->getMessage() . '".'
                . ' ReasonCode: "' . $Exception->getCode() . '".'
                . ' Transaction #' . $Transaction->getTxId()
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
                            'amount' => $response['amount']['total'],
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
                    . ' Refund state: ' . $response['state']
                );

                $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_REFUNDED);
        }
    }

    /**
     * Prepare order data to be sent to PayPal API (create/update)
     *
     * @param AbstractOrder $Order
     * @return array
     * @throws QUI\ERP\Exception
     * @throws QUI\ERP\Order\Basket\Exception
     * @throws QUI\Exception
     */
    protected function getPayPalDataForOrder(AbstractOrder $Order): array
    {
        $isNetto = ERPUserUtils::isNettoUser($Order->getCustomer());

        // Basic payment data
        $transactionData = [
            'reference_id' => $Order->getUUID(),
            'description' => $this->getLocale()->get(
                'quiqqer/payment-paypal',
                'Payment.order.create.description',
                [
                    'orderId' => $Order->getPrefixedId()
                ]
            )
        ];

        $Order->recalculate();
        $PriceCalculation = $Order->getPriceCalculation();
        $currencyCode = $Order->getCurrency()->getCode();

        $amount = [
            'currency_code' => $currencyCode,
            'value' => Utils::formatPrice($PriceCalculation->getSum()->get()),
        ];

        // Shipping data
        $ShippingCost = Utils::getShippingCostsByOrder($Order);
        $shippingAddress = Utils::getPayPalShippingAddressDataByOrder($Order);

        $taxTotal = Utils::formatPrice($PriceCalculation->getVatSum()->get());

        $amountDetails = [
            'subtotal' => Utils::formatPrice($PriceCalculation->getSubSum()->get()),
            'tax' => $taxTotal
        ];

        if ($isNetto) {
            $amount['breakdown']['item_total'] = [
                'value' => Utils::formatPrice($PriceCalculation->getNettoSubSum()->get()),
                'currency_code' => $currencyCode
            ];

            $amount['breakdown']['tax_total'] = [
                'value' => $taxTotal,
                'currency_code' => $currencyCode
            ];
        } else {
            $amount['breakdown']['item_total'] = [
                'value' => Utils::formatPrice($PriceCalculation->getSubSum()->get()),
                'currency_code' => $currencyCode
            ];
        }

        if (!empty($ShippingCost)) {
            $shippingCost = Utils::formatPrice($ShippingCost->getSum());
            $amountDetails['shipping'] = $shippingCost;

            $amount['breakdown']['shipping'] = [
                'value' => $shippingCost,
                'currency_code' => $currencyCode
            ];
        }

        $amount['details'] = $amountDetails;

        // Article List
        $displayItemList = boolval(Provider::getPaymentSetting('display_paypal_basket'));

        /** @var QUI\ERP\Products\Interfaces\PriceFactorInterface $PriceFactor */
        foreach ($Order->getArticles()->getPriceFactors() as $PriceFactor) {
            if ($ShippingCost && $PriceFactor === $ShippingCost) {
                continue;
            }

            /**
             * If other price factors than the shipping cost price factor are found
             * the detailed basket is NOT sent to PayPal since the exact category
             * of the price factor (e.g. article, tax, insurance etc.)
             * cannot be determined at the moment.
             */
            $displayItemList = false;

            break;
        }

        if ($displayItemList) {
            $items = [];

            foreach ($PriceCalculation->getArticles() as $OrderArticle) {
                $articleData = $OrderArticle->toArray();
                $calculated = $articleData['calculated'];
                $FactorPrice = new CalculationValue($calculated['price']); // unit price

                // Article name
                $articleName = $OrderArticle->getTitle();

                if (empty($articleName)) {
                    $articleName = $OrderArticle->getArticleNo();
                }

                if (empty($articleName)) {
                    $articleName = '#' . $OrderArticle->getId();
                }

                $item = [
                    'name' => $articleName,
                    'quantity' => $OrderArticle->getQuantity(),
                    'unit_amount' => [
                        'value' => Utils::formatPrice($FactorPrice->get()),
                        'currency_code' => $currencyCode
                    ]
                ];

                // Optional: product description
                $description = $OrderArticle->getDescription();

                if (!empty($description)) {
                    $description = mb_substr($description, 0, 127);

                    if (mb_strlen($description) === 127) {
                        $description = mb_substr($description, 0, 124) . '...';
                    }

                    $item['description'] = $description;
                }

                // Optional: product article no.
                $articleNo = $OrderArticle->getArticleNo();

                if (!empty($articleNo)) {
                    $item['sku'] = $articleNo;
                }

                $items[] = $item;
            }

            $transactionData['items'] = $items;

            if ($shippingAddress !== false) {
                $transactionData['shipping']['address'] = $shippingAddress;
            }
        } else {
            unset($amount['breakdown']);
            unset($amount['details']);
        }

        $transactionData['amount'] = $amount;

        // Return URLs
        $Gateway = new Gateway();
        $Gateway->setOrder($Order);

        // Payer data
        $payer = [
            'payment_method' => 'paypal'
        ];

        if (QUI::getPackageManager()->isInstalled('quiqqer/shipping')) {
            $DeliveryAddress = $Order->getDeliveryAddress();

            $payer['name'] = [
                'given_name' => $DeliveryAddress->getAttribute('firstname'),
                'surname' => $DeliveryAddress->getAttribute('lastname') ?: ''
            ];

            $shippingAddress = [
                'address_line_1' => $DeliveryAddress->getAttribute('street_no') ?: '',
                'admin_area_2' => $DeliveryAddress->getAttribute('city') ?: '',
                'postal_code' => $DeliveryAddress->getAttribute('zip') ?: '',
            ];

            try {
                $ShippingCountry = $DeliveryAddress->getCountry();
                $shippingAddress['country_code'] = $ShippingCountry->getCode();
            } catch (Exception $Exception) {
                QUI\System\Log::writeDebugException($Exception);
            }

            $requiredFields = [
                'address_line_1',
                'admin_area_2',
                'postal_code'
            ];

            foreach ($requiredFields as $requiredField) {
                if (empty($shippingAddress[$requiredField])) {
                    $shippingAddress = false;
                    break;
                }
            }

            if ($shippingAddress && !empty($DeliveryAddress->getName())) {
                $transactionData['shipping'] = [
                    'name' => [
                        'full_name' => $DeliveryAddress->getName(),
                    ],
                    'type' => 'SHIPPING',
                    'address' => $shippingAddress
                ];
            }
        }

        return [
            'intent' => 'CAPTURE',
            'payer' => $payer,
            'purchase_units' => [$transactionData],
            'redirect_urls' => [
                'return_url' => rtrim($Gateway->getSuccessUrl(), '?'),
                'cancel_url' => rtrim($Gateway->getCancelUrl(), '?')
            ]
        ];
    }

    /**
     * Get details of a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return array|bool - false if details cannot be fetched (e.g. if Order has not been created with PayPal);
     * details otherwise
     * @throws PayPalSystemException
     */
    protected function getPayPalOrderDetails(AbstractOrder $Order): bool|array
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
        } catch (PayPalException) {
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
     * @throws PayPalException|QUI\Exception
     */
    protected function voidPayPalOrder(AbstractOrder $Order): void
    {
        $Order->addHistory('PayPal :: Void Order');

        if (!$Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)) {
            $Order->addHistory(
                'PayPal :: Order cannot be voided because it has not been created yet'
                . ' or was voided before'
            );

            $this->saveOrder($Order);
            return;
        }

        $Order->addHistory(
            'PayPal :: Order voided.'
        );

        $this->saveOrder($Order);

        /*
         * PayPal Orders that are captured immediately and do not go with to authorize -> capture later
         * process cannot be voided directly.
         *
         * In this case it is ok to just leave the order be.
         */

//        try {
//            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_VOID_ORDER, [], $Order);
//        } catch (PayPalException $Exception) {
//            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
//            $this->saveOrder($Order);
//
//            throw $Exception;
//        }
//
//        if (empty($response['state'])
//            || $response['state'] !== 'voided') {
//            if (empty($response['reason_code'])) {
//                $Order->addHistory(
//                    'PayPal :: Order could not be voided because of an unknown reason.'
//                );
//            } else {
//                $Order->addHistory(
//                    'PayPal :: Order could not be voided. Reason: "'.$response['reason_code'].'"'
//                );
//            }
//
//            $this->saveOrder($Order);
//            $this->throwPayPalException();
//        }
//
//        // reset payment data so the order can be created again
//        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_ID, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_DATA, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_AUTHORIZATION_ID, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_PAYER_ID, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_CAPTURE_ID, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_ID, null);
//        $Order->setPaymentData(self::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, false);
//
//        $Order->addHistory('PayPal :: Order successfully voided');
//        $this->saveOrder($Order);
    }

    /**
     * Throw PayPalException for specific PayPal API Error
     *
     * @param string $errorCode (optional) - default: general error message
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return never
     *
     * @throws PayPalException
     */
    protected function throwPayPalException(
        string $errorCode = self::PAYPAL_ERROR_GENERAL_ERROR,
        array $exceptionAttributes = []
    ): never {
        $L = $this->getLocale();
        $lg = 'quiqqer/payment-paypal';
        $msg = $L->get($lg, 'payment.error_msg.' . $errorCode);

        $Exception = new PayPalException($msg);
        $Exception->setAttributes($exceptionAttributes);

        throw $Exception;
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws QUI\Exception
     */
    protected function saveOrder(AbstractOrder $Order): void
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Make a PayPal REST API request
     *
     * @param string $request - Request type (see self::PAYPAL_REQUEST_TYPE_*)
     * @param array $body - Request data
     * @param array|AbstractOrder|Transaction $TransactionObj - Object that contains necessary request data
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @param bool $throwSystemException (optional) - API errors are thrown as special PayPalSystemException for *internal* handling
     *
     * @return bool|array|null - Response body or false on error
     *
     * @throws PayPalException
     * @throws PayPalSystemException
     */
    public function payPalApiRequest(
        string $request,
        array $body,
        Transaction|AbstractOrder|array $TransactionObj,
        bool $throwSystemException = false
    ): null|bool|array {
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

        $apiV2 = false;

        switch ($request) {
            case self::PAYPAL_REQUEST_TYPE_GET_ORDER:
                $Request = new PayPalOrderGetRequestV2(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );

                $apiV2 = true;
                break;

            case self::PAYPAL_REQUEST_TYPE_CREATE_ORDER:
//                $Request = new PaymentCreateRequest();
                $Request = new PayPalOrdersCreateRequestV2();
                $Request->prefer('return=representation');    // return full order representation

                $apiV2 = true;
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
                $Request = new PayPalOrderCaptureRequestV2(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );
                $Request->prefer('return=representation');    // return full order representation

                $apiV2 = true;
                break;

            case self::PAYPAL_REQUEST_TYPE_UPDATE_ORDER:
                $Request = new PayPalOrdersPatchRequestV2(
                    $getData(self::ATTR_PAYPAL_ORDER_ID)
                );

                $apiV2 = true;
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

            case RecurringPayment::PAYPAL_REQUEST_TYPE_SUSPEND_BILLING_AGREEMENT:
                $Request = new AgreementSuspendRequest(
                    $getData(RecurringPayment::ATTR_PAYPAL_BILLING_AGREEMENT_ID)
                );
                break;

            case RecurringPayment::PAYPAL_REQUEST_TYPE_RESUME_BILLING_AGREEMENT:
                $Request = new AgreementReActivateRequest(
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
                $endDate = $getData('end_date');

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
            if ($apiV2) {
                $Response = $this->getPayPalClientV2()->execute($Request);
            } else {
                $Response = $this->getPayPalClient()->execute($Request);
            }
        } catch (Exception $Exception) {
            $message = $Exception->getCode() . " :: \n\n";
            $message .= $Exception->getMessage() . "\n";
            $message .= $Exception->getTraceAsString();

            QUI\System\Log::write(
                $message,
                QUI\System\Log::LEVEL_WARNING,
                [
                    'paypalRequestClass' => get_class($Request),
                    'requestBody' => $Request->body,
                    'transactionObject' => get_class($TransactionObj)
                ],
                'paypal_api'
            );

            if ($throwSystemException) {
                throw new PayPalSystemException(
                    $Exception->getMessage(),
                    $Exception->getCode(),
                    [
                        'request' => $request
                    ]
                );
            }

            $this->throwPayPalException();
        }

        // turn stdClass object to array
        return json_decode(json_encode($Response->result), true);
    }

    /**
     * Get PayPal Client for current payment process
     *
     * @return PayPalClient|null
     */
    protected function getPayPalClient(): ?PayPalClient
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

    /**
     * Get PayPal Client for current payment process
     *
     * @return PayPalClientV2|null
     */
    protected function getPayPalClientV2(): ?PayPalClientV2
    {
        if (!is_null($this->PayPalClientV2)) {
            return $this->PayPalClientV2;
        }

        if (Provider::getApiSetting('sandbox')) {
            $Environment = new PayPalSandboxEnvironmentV2(
                Provider::getApiSetting('sandbox_client_id'),
                Provider::getApiSetting('sandbox_client_secret')
            );
        } else {
            $Environment = new PayPalProductionEnvironmentV2(
                Provider::getApiSetting('client_id'),
                Provider::getApiSetting('client_secret')
            );
        }

        $this->PayPalClientV2 = new PayPalClientV2($Environment);

        return $this->PayPalClientV2;
    }

    # region Cron

    /**
     * Checks pending captures of unpaid or not-yet-fully-paid PayPal orders.
     *
     * @return void
     */
    public function checkPendingCaptures(): void
    {
        // Determine payment type IDs
        $payments = Payments::getInstance()->getPayments([
            'select' => ['id'],
            'where' => [
                'payment_type' => self::class
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

        $OrderHandler = OrderHandler::getInstance();

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['id'],
                'from' => $OrderHandler->table(),
                'where' => [
                    'payment_id' => [
                        'type' => 'IN',
                        'value' => $paymentTypeIds
                    ],
                    'paid_status' => [
                        'type' => 'IN',
                        'value' => [
                            QUI\ERP\Constants::PAYMENT_STATUS_OPEN,
                            QUI\ERP\Constants::PAYMENT_STATUS_PART
                        ]
                    ]
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return;
        }

        foreach ($result as $row) {
            try {
                $Order = $OrderHandler->get($row['id']);

                // Some order entities do not exist any longer at PayPal - we do not have to check these
                if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST)) {
                    continue;
                }

                try {
                    $payPalOrderData = $this->payPalApiRequest(
                        self::PAYPAL_REQUEST_TYPE_GET_ORDER,
                        [],
                        $Order,
                        true
                    );
                } catch (PayPalSystemException $Exception) {
                    // Check if Order does not exist anymore at PayPal
                    $exMsg = $Exception->getMessage();
                    $exMsg = json_decode($exMsg, true);

                    if (
                        is_array($exMsg) &&
                        json_last_error() === JSON_ERROR_NONE &&
                        !empty($exMsg['name']) &&
                        $exMsg['name'] == self::PAYPAL_API_EXCEPTION_MESSAGE_RESOURCE_NOT_FOUND
                    ) {
                        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST, true);

                        $Order->addHistory(
                            QUI::getSystemLocale()->get(
                                'quiqqer/payment-paypal',
                                'history.order.no_longer_exists_at_paypal'
                            )
                        );

                        $this->saveOrder($Order);
                    }

                    continue;
                }

                if (!$payPalOrderData) {
                    continue;
                }

                if (empty($payPalOrderData['purchase_units'][0]['payments']['captures'])) {
                    continue;
                }

                $amountTotal = 0;
                $amountCurrencyCode = false;

                foreach ($payPalOrderData['purchase_units'][0]['payments']['captures'] as $capture) {
                    if ($capture['status'] !== 'COMPLETED') {
                        continue 2;
                    }

                    $amountTotal += $capture['amount']['value'];
                    $amountCurrencyCode = $capture['amount']['currency_code'];
                }

                // Add transaction
                $Transaction = Gateway::getInstance()->purchase(
                    (float)$amountTotal,
                    QUI\ERP\Currency\Handler::getCurrency($amountCurrencyCode),
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

                $Order->addHistory(
                    'PayPal :: Pending order capture was completed. Transaction ' . $Transaction->getTxId() . ' added.'
                );
                $this->saveOrder($Order);
            } catch (Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    # endregion
}
