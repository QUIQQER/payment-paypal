<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Payment
 */

namespace QUI\ERP\Payments\PayPal;

use PayPal\v1\Payments\OrderAuthorizeRequest;
use PayPal\v1\Payments\OrderCaptureRequest;
use PayPal\v1\Payments\OrderGetRequest;
use PayPal\v1\Payments\OrderVoidRequest;
use PayPal\v1\Payments\PaymentExecuteRequest;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use PayPal\Core\PayPalHttpClient as PayPalClient;
use PayPal\Core\ProductionEnvironment;
use PayPal\Core\SandboxEnvironment;
use PayPal\v1\Payments\PaymentCreateRequest;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Utils\User as ERPUserUtils;
use QUI\ERP\Accounting\CalculationValue;

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

    /**
     * PayPal Order states
     */
    const PAYPAL_ORDER_STATE_PENDING    = 'pending';
    const PAYPAL_ORDER_STATE_AUTHORIZED = 'authorized';
    const PAYPAL_ORDER_STATE_CAPTURED   = 'captured';
    const PAYPAL_ORDER_STATE_COMPLETED  = 'completed';
    const PAYPAL_ORDER_STATE_VOIDED     = 'voided';

    /**
     * PayPal REST API request types
     */
    const PAYPAL_REQUEST_TYPE_GET_ORDER       = 'paypal-api-get_order';
    const PAYPAL_REQUEST_TYPE_CREATE_ORDER    = 'paypal-api-create_order';
    const PAYPAL_REQUEST_TYPE_EXECUTE_ORDER   = 'paypal-api-execute_order';
    const PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER = 'paypal-api-authorize_order';
    const PAYPAL_REQUEST_TYPE_CAPTURE_ORDER   = 'paypal-api-capture_order';
    const PAYPAL_REQUEST_TYPE_VOID_ORDER      = 'paypal-api-void_oder';

    /**
     * Error codes
     */
    const PAYPAL_ERROR_GENERAL_ERROR        = 'general_error';
    const PAYPAL_ERROR_ORDER_NOT_APPROVED   = 'order_not_approved';
    const PAYPAL_ERROR_ORDER_NOT_AUTHORIZED = 'order_not_authorized';
    const PAYPAL_ERROR_ORDER_NOT_CAPTURED   = 'order_not_captured';

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
        return URL_OPT_DIR.'quiqqer/payment-paypal/bin/images/Payment.png';
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
     */
    public function refund(QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction)
    {
        // @todo
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
        $amount = [
            'currency' => $currencyCode,
            'total'    => $PriceCalculation->getSum()->precision(2)->get()
        ];

        // @todo always add subtotal if shipping costs are avaiable / included
        if ($isNetto) {
            $amount['details'] = [
                'subtotal' => $PriceCalculation->getNettoSum()->precision(2)->get(),
                'tax'      => $PriceCalculation->getVatSum()->precision(2)->get()
            ];
        }

        $transactionData = [
            'reference_id' => $Order->getHash(),
            'amount'       => $amount,
            'description'  => $this->getLocale()->get(
                'quiqqer/payment-paypal',
                'Payment.order.create.description', [
                    'orderId' => $Order->getPrefixedId()
                ]
            )
        ];

        // Article List
        if (Provider::getPaymentSetting('display_paypal_basket')) {
            $items = [];

            /** @var QUI\ERP\Accounting\Article $OrderArticle */
            foreach ($PriceCalculation->getArticles() as $OrderArticle) {
                $articleData = $OrderArticle->toArray();
                $calculated  = $articleData['calculated'];
                $FactorPrice = new CalculationValue($calculated['price']); // unit price

                $item = [
                    'name'        => $OrderArticle->getTitle(),
                    'description' => $OrderArticle->getDescription(),
                    'quantity'    => $OrderArticle->getQuantity(),
                    'price'       => $FactorPrice->precision(2)->get(),
                    'sku'         => $OrderArticle->getArticleNo(),
                    'currency'    => $currencyCode
                ];

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

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER,
                [
                    'amount' => [
                        'total'    => $PriceCalculation->getSum()->precision(2)->get(),
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
        $captureId        = false;

        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER,
                [
                    'amount'           => [
                        'total'    => $PriceCalculation->getSum()->precision(2)->get(),
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
        $this->saveOrder($Order);

        $Order->setSuccessfulStatus();

        // Gateway purchase
        $Order->addHistory('PayPal :: Set Gateway purchase');

        Gateway::getInstance()->purchase(
            $amount['total'],
            new QUI\ERP\Currency\Currency($amount['currency']),
            $Order,
            $this
        );

        $Order->addHistory('PayPal :: Gateway purchase completed and Order payment finished');
        $this->saveOrder($Order);
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
     * Throw AmazonPayException for specific Amazon API Error
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
     * @param AbstractOrder $Order - The QUIQQER ERP Order the request is intended for
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @return array|false - Response body or false on error
     *
     * @throws PayPalException
     */
    protected function payPalApiRequest($request, $body, AbstractOrder $Order)
    {
        switch ($request) {
            case self::PAYPAL_REQUEST_TYPE_GET_ORDER:
                $Request = new OrderGetRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_CREATE_ORDER:
                $Request = new PaymentCreateRequest();
                break;

            case self::PAYPAL_REQUEST_TYPE_EXECUTE_ORDER:
                $Request = new PaymentExecuteRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYMENT_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_AUTHORIZE_ORDER:
                $Request = new OrderAuthorizeRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_CAPTURE_ORDER:
                $Request = new OrderCaptureRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            case self::PAYPAL_REQUEST_TYPE_VOID_ORDER:
                $Request = new OrderVoidRequest(
                    $Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)
                );
                break;

            default:
                $this->throwPayPalException();
        }

        $Request->body = $body;

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

        $clientId     = Provider::getApiSetting('client_id');
        $clientSecret = Provider::getApiSetting('client_secret');

        if (Provider::getApiSetting('sandbox')) {
            $Environment = new SandboxEnvironment($clientId, $clientSecret);
        } else {
            $Environment = new ProductionEnvironment($clientId, $clientSecret);
        }

        $this->PayPalClient = new PayPalClient($Environment);

        return $this->PayPalClient;
    }
}
