<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Payment
 */

namespace QUI\ERP\Payments\PayPal;

use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use PayPal\Core\PayPalHttpClient as PayPalClient;
use AmazonPay\ResponseInterface;
use PayPal\Core\ProductionEnvironment;
use PayPal\Core\SandboxEnvironment;
use PayPal\v1\Payments\PaymentCreateRequest;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Handler as OrderHandler;

/**
 * Class Payment
 */
class Payment extends QUI\ERP\Accounting\Payments\Api\AbstractPayment
{
    /**
     * Amazon API Order attributes
     */
    const ATTR_AUTHORIZATION_REFERENCE_IDS = 'paypal-AuthorizationReferenceIds';
    const ATTR_AMAZON_AUTHORIZATION_ID     = 'paypal-AmazonAuthorizationId';
    const ATTR_AMAZON_CAPTURE_ID           = 'paypal-AmazonCaptureId';
    const ATTR_AMAZON_ORDER_REFERENCE_ID   = 'paypal-OrderReferenceId';
    const ATTR_CAPTURE_REFERENCE_IDS       = 'paypal-CaptureReferenceIds';
    const ATTR_ORDER_AUTHORIZED            = 'paypal-OrderAuthorized';
    const ATTR_ORDER_CAPTURED              = 'paypal-OrderCaptures';
    const ATTR_ORDER_REFERENCE_SET         = 'paypal-OrderReferenceSet';
    const ATTR_RECONFIRM_ORDER             = 'paypal-ReconfirmOrder';

    const ATTR_PAYPAL_ORDER_ID     = 'paypal-OrderId';
    const ATTR_PAYPAL_APPROVAL_URL = 'paypal-ApprovalUrl';

    /**
     * Setting options
     */
    const SETTING_ARTICLE_TYPE_MIXED    = 'mixed';
    const SETTING_ARTICLE_TYPE_PHYSICAL = 'physical';
    const SETTING_ARTICLE_TYPE_DIGITAL  = 'digital';

    /**
     * PayPal PHP REST Client
     *
     * @var PayPalClient
     */
    protected $PayPalClient = null;

    /**
     * Current Order that is being processed
     *
     * @var AbstractOrder
     */
    protected $Order = null;

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
                'PayPal :: Cannot check if payment process for Order #' . $hash . ' is successful'
                . ' -> ' . $Exception->getMessage()
            );

            return false;
        }

        return $Order->getPaymentDataEntry(self::ATTR_ORDER_AUTHORIZED);
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
     * Execute the request from the payment provider
     *
     * @param QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway
     * @return void
     *
     * @throws QUI\ERP\Accounting\Payments\Transactions\Exception
     * @throws QUI\Exception
     */
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway)
    {
        $AmazonPay = $this->getPayPalClient();
        $Order     = $Gateway->getOrder();

        $Order->addHistory('PayPal :: Check if payment from Amazon was successful');

        $paypalCaptureId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_CAPTURE_ID);

        $Response = $AmazonPay->getCaptureDetails([
            'paypal_capture_id' => $paypalCaptureId
        ]);

        try {
            $response = $this->getResponseData($Response);
        } catch (AmazonPayException $Exception) {
            $Order->addHistory(
                'PayPal :: An error occurred while trying to validate the Capture -> ' . $Exception->getMessage()
            );

            $Order->update(QUI::getUsers()->getSystemUser());
            return;
        }

        // check the amount that has already been captured
        $PriceCalculation   = $Order->getPriceCalculation();
        $targetSum          = $PriceCalculation->getSum()->precision(2)->get();
        $targetCurrencyCode = $Order->getCurrency()->getCode();

        $captureData        = $response['GetCaptureDetailsResult']['CaptureDetails'];
        $actualSum          = $captureData['CaptureAmount']['Amount'];
        $actualCurrencyCode = $captureData['CaptureAmount']['CurrencyCode'];

        if ($actualSum < $targetSum) {
            $Order->addHistory(
                'PayPal :: The amount that was captured from Amazon was less than the'
                . ' total sum of the order. Total sum: ' . $targetSum . ' ' . $targetCurrencyCode
                . ' | Actual sum captured by Amazon: ' . $actualSum . ' ' . $actualCurrencyCode
            );

            $Order->update(QUI::getUsers()->getSystemUser());
            return;
        }

        // book payment in QUIQQER ERP
        $Order->addHistory('Amazo Pay :: Finalize Order payment');

        $Gateway->purchase(
            $actualSum,
            new QUI\ERP\Currency\Currency($actualCurrencyCode),
            $Order,
            $this
        );

        $Order->addHistory('Amazo Pay :: Closing OrderReference');
        $this->closeOrderReference($Order);
        $Order->addHistory('Amazo Pay :: Order successfully paid');

        $Order->update(QUI::getUsers()->getSystemUser());
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
     */
    public function createPayPalOrder(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Create Order');

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID)) {
            $Order->addHistory('PayPal :: Order already created');
            return;
        }

        $PriceCalculation = $Order->getPriceCalculation();
        $currencyCode     = $Order->getCurrency()->getCode();

        // Basic payment data
        $transactionData = [
            'amount'      => [
                'currency' => $currencyCode,
                'total'    => $PriceCalculation->getSum()->precision(2)->get(),
                'details'  => [
                    'subtotal' => $PriceCalculation->getSubSum()->precision(2)->get(),
                    'tax'      => $PriceCalculation->getVatSum()->precision(2)->get()
                ]
            ],
            'description' => $this->getLocale()->get(
                'quiqqer/payment-paypal',
                'Payment.order.create.description', [
                    'orderId' => $Order->getId()
                ]
            )
        ];

        // Article List
        $items = [];

        /** @var QUI\ERP\Accounting\Article $OrderArticle */
        foreach ($Order->getArticles()->getArticles() as $OrderArticle) {
            $items[] = [
                'name'     => $OrderArticle->getTitle(),
                'quantity' => $OrderArticle->getQuantity(),
                'price'    => $OrderArticle->getPrice()->getPrice(),
                'sku'      => $OrderArticle->getId(),
                'currency' => $currencyCode
            ];
        }

        $transactionData['item_list']['items'] = $items;

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

        $response = $this->payPalApiRequest($body);

        $Order->addHistory('PayPal :: Order successfully created');
        $Order->setPaymentData(self::ATTR_PAYPAL_ORDER_ID, $response['id']);

        if (!empty($response['links'])) {
            foreach ($response['links'] as $link) {
                if ($link['rel'] === 'approval_url') {
                    $Order->setPaymentData(self::ATTR_PAYPAL_APPROVAL_URL, $link['href']);
                    break;
                }
            }
        }

        $this->saveOrder($Order);
    }

    /**
     * Check if a PayPal Order has been created
     *
     * @param AbstractOrder $Order
     * @return bool
     */
    public function isPayPalOrderCreated(AbstractOrder $Order)
    {
        return !!$Order->getPaymentDataEntry(self::ATTR_PAYPAL_ORDER_ID);
    }

    /**
     * Authorize the payment for an Order with Amazon
     *
     * @param string $orderReferenceId
     * @param AbstractOrder $Order
     *
     * @throws AmazonPayException
     * @throws QUI\ERP\Exception
     */
    public function authorizePayment($orderReferenceId, AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Authorize payment');

        if ($Order->getPaymentDataEntry(self::ATTR_ORDER_AUTHORIZED)) {
            $Order->addHistory('PayPal :: Authorization already exist');
            return;
        }

        $AmazonPay        = $this->getPayPalClient();
        $PriceCalculation = $Order->getPriceCalculation();
        $reconfirmOrder   = $Order->getPaymentDataEntry(self::ATTR_RECONFIRM_ORDER);

        // Re-confirm Order after previously declined Authorization because of "InvalidPaymentMethod"
        if ($reconfirmOrder) {
            $Order->addHistory(
                'PayPal :: Re-confirm Order after declined Authorization because of "InvalidPaymentMethod"'
            );

            $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

            $Response = $AmazonPay->confirmOrderReference([
                'paypal_order_reference_id' => $orderReferenceId
            ]);

            $this->getResponseData($Response); // check response data

            $Order->setPaymentData(self::ATTR_RECONFIRM_ORDER, false);

            $Order->addHistory('PayPal :: OrderReference re-confirmed');
        } elseif (!$Order->getPaymentDataEntry(self::ATTR_ORDER_REFERENCE_SET)) {
            $Order->addHistory(
                'PayPal :: Setting details of the Order to PayPal API'
            );

            $Response = $AmazonPay->setOrderReferenceDetails([
                'paypal_order_reference_id' => $orderReferenceId,
                'amount'                    => $PriceCalculation->getSum()->precision(2)->get(),
                'currency_code'             => $Order->getCurrency()->getCode(),
                'seller_order_id'           => $Order->getId()
            ]);

            $response              = $this->getResponseData($Response);
            $orderReferenceDetails = $response['SetOrderReferenceDetailsResult']['OrderReferenceDetails'];

            if (isset($orderReferenceDetails['Constraints']['Constraint']['ConstraintID'])) {
                $Order->addHistory(
                    'PayPal :: An error occurred while setting the details of the Order: "'
                    . $orderReferenceDetails['Constraints']['Constraint']['ConstraintID'] . '""'
                );

                $this->throwAmazonPayException(
                    $orderReferenceDetails['Constraints']['Constraint']['ConstraintID'],
                    [
                        'reRenderWallet' => 1
                    ]
                );
            }

            $AmazonPay->confirmOrderReference([
                'paypal_order_reference_id' => $orderReferenceId
            ]);

            $Order->setPaymentData(self::ATTR_ORDER_REFERENCE_SET, true);
            $Order->update(QUI::getUsers()->getSystemUser());
        }

        $Order->addHistory('PayPal :: Requesting new Authorization');

        $authorizationReferenceId = $this->getNewAuthorizationReferenceId($Order);

        $Response = $AmazonPay->authorize([
            'paypal_order_reference_id'  => $orderReferenceId,
            'authorization_amount'       => $PriceCalculation->getSum()->precision(2)->get(),
            'currency_code'              => $Order->getCurrency()->getCode(),
            'authorization_reference_id' => $authorizationReferenceId,
            'transaction_timeout'        => 0  // get authorization status synchronously
        ]);

        $response = $this->getResponseData($Response);

        // save reference ids in $Order
        $authorizationDetails  = $response['AuthorizeResult']['AuthorizationDetails'];
        $paypalAuthorizationId = $authorizationDetails['AmazonAuthorizationId'];

        $this->addAuthorizationReferenceIdToOrder($authorizationReferenceId, $Order);
        $Order->setPaymentData(self::ATTR_AMAZON_AUTHORIZATION_ID, $paypalAuthorizationId);
        $Order->setPaymentData(self::ATTR_AMAZON_ORDER_REFERENCE_ID, $orderReferenceId);

        $Order->update(QUI::getUsers()->getSystemUser());

        // check Authorization
        $Order->addHistory('PayPal :: Checking Authorization status');

        $status = $authorizationDetails['AuthorizationStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Open':
                // everything is fine
                $Order->addHistory(
                    'PayPal :: Authorization is OPEN an can be used for capturing'
                );

                $Order->setPaymentData(self::ATTR_ORDER_AUTHORIZED, true);
                $Order->update(QUI::getUsers()->getSystemUser());
                break;

            case 'Declined':
                $reason = $status['ReasonCode'];

                switch ($reason) {
                    case 'InvalidPaymentMethod':
                        $Order->addHistory(
                            'PayPal :: Authorization was DECLINED. User has to choose another payment method.'
                            . ' ReasonCode: "' . $reason . '"'
                        );

                        $Order->setPaymentData(self::ATTR_RECONFIRM_ORDER, true);
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason, [
                            'reRenderWallet' => 1
                        ]);
                        break;

                    case 'TransactionTimedOut':
                        $Order->addHistory(
                            'PayPal :: Authorization was DECLINED. User has to choose another payment method.'
                            . ' ReasonCode: "' . $reason . '"'
                        );

                        $AmazonPay->cancelOrderReference([
                            'paypal_order_reference_id' => $orderReferenceId,
                            'cancelation_reason'        => 'Order #' . $Order->getHash() . ' could not be authorized :: TransactionTimedOut'
                        ]);

                        $Order->setPaymentData(self::ATTR_ORDER_REFERENCE_SET, false);
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason, [
                            'reRenderWallet' => 1,
                            'orderCancelled' => 1
                        ]);
                        break;

                    default:
                        $Order->addHistory(
                            'PayPal :: Authorization was DECLINED. OrderReference has to be closed. Cannot use PayPal for this Order.'
                            . ' ReasonCode: "' . $reason . '"'
                        );

                        $Response = $AmazonPay->getOrderReferenceDetails([
                            'paypal_order_reference_id' => $orderReferenceId
                        ]);

                        $response              = $Response->toArray();
                        $orderReferenceDetails = $response['GetOrderReferenceDetailsResult']['OrderReferenceDetails'];
                        $orderReferenceStatus  = $orderReferenceDetails['OrderReferenceStatus']['State'];

                        if ($orderReferenceStatus === 'Open') {
                            $AmazonPay->cancelOrderReference([
                                'paypal_order_reference_id' => $orderReferenceId,
                                'cancelation_reason'        => 'Order #' . $Order->getHash() . ' could not be authorized'
                            ]);

                            $Order->setPaymentData(self::ATTR_AMAZON_ORDER_REFERENCE_ID, false);
                        }

                        $Order->clearPayment();
                        $Order->update(QUI::getUsers()->getSystemUser());

                        $this->throwAmazonPayException($reason);
                }
                break;

            default:
                $reason = $status['ReasonCode'];

                $Order->addHistory(
                    'PayPal :: Authorization cannot be used because it is in state "' . $state . '".'
                    . ' ReasonCode: "' . $reason . '"'
                );

                $this->throwAmazonPayException($reason);
        }
    }

    /**
     * Capture the actual payment for an Order
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws AmazonPayException
     * @throws QUI\ERP\Exception
     */
    public function capturePayment(AbstractOrder $Order)
    {
        $Order->addHistory('PayPal :: Capture payment');

        if ($Order->getPaymentDataEntry(self::ATTR_ORDER_CAPTURED)) {
            $Order->addHistory('PayPal :: Capture is already completed');
            return;
        }

        $AmazonPay        = $this->getPayPalClient();
        $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        if (empty($orderReferenceId)) {
            $Order->addHistory(
                'PayPal :: Capture failed because the Order has no AmazonOrderReferenceId'
            );

            throw new AmazonPayException([
                'quiqqer/payment-paypal',
                'exception.Payment.capture.not_authorized',
                [
                    'orderHash' => $Order->getHash()
                ]
            ]);
        }

        try {
            $this->authorizePayment($orderReferenceId, $Order);
        } catch (AmazonPayException $Exception) {
            $Order->addHistory(
                'PayPal :: Capture failed because the Order has no OPEN Authorization'
            );

            throw new AmazonPayException([
                'quiqqer/payment-paypal',
                'exception.Payment.capture.not_authorized',
                [
                    'orderHash' => $Order->getHash()
                ]
            ]);
        } catch (\Exception $Exception) {
            $Order->addHistory(
                'PayPal :: Capture failed because of an error: ' . $Exception->getMessage()
            );

            QUI\System\Log::writeException($Exception);
            return;
        }

        $PriceCalculation   = $Order->getPriceCalculation();
        $sum                = $PriceCalculation->getSum()->precision(2)->get();
        $captureReferenceId = $this->getNewCaptureReferenceId($Order);

        $Response = $AmazonPay->capture([
            'paypal_authorization_id' => $Order->getPaymentDataEntry(self::ATTR_AMAZON_AUTHORIZATION_ID),
            'capture_amount'          => $sum,
            'currency_code'           => $Order->getCurrency()->getCode(),
            'capture_reference_id'    => $captureReferenceId,
            'seller_capture_note'     => $this->getLocale()->get(
                'quiqqer/payment-paypal',
                'payment.capture.seller_capture_note',
                [
                    'orderId' => $Order->getId()
                ]
            )
        ]);

        $response = $this->getResponseData($Response);

        $captureDetails  = $response['CaptureResult']['CaptureDetails'];
        $paypalCaptureId = $captureDetails['AmazonCaptureId'];

        $this->addCaptureReferenceIdToOrder($paypalCaptureId, $Order);
        $Order->setPaymentData(self::ATTR_AMAZON_CAPTURE_ID, $paypalCaptureId);
        $Order->update(QUI::getUsers()->getSystemUser());

        // Check Capture
        $Order->addHistory('PayPal :: Checking Capture status');

        $status = $captureDetails['CaptureStatus'];
        $state  = $status['State'];

        switch ($state) {
            case 'Completed':
                $Order->addHistory(
                    'PayPal :: Capture is COMPLETED -> ' . $sum . ' ' . $Order->getCurrency()->getCode()
                );

                $Order->setPaymentData(self::ATTR_ORDER_CAPTURED, true);
                $Order->update(QUI::getUsers()->getSystemUser());
                break;

            default:
                $reason = $status['ReasonCode'];

                $Order->addHistory(
                    'PayPal :: Capture operation failed with state "' . $state . '".'
                    . ' ReasonCode: "' . $reason . '"'
                );

                // @todo Change order status to "problems with PayPalment"

                $this->throwAmazonPayException($reason);
                break;
        }
    }

    /**
     * Set the PayPal OrderReference to status CLOSED
     *
     * @param AbstractOrder $Order
     * @param string $reason (optional) - Close reason [default: "Order #hash completed"]
     * @return void
     */
    protected function closeOrderReference(AbstractOrder $Order, $reason = null)
    {
        $AmazonPay        = $this->getPayPalClient();
        $orderReferenceId = $Order->getPaymentDataEntry(self::ATTR_AMAZON_ORDER_REFERENCE_ID);

        $AmazonPay->closeOrderReference([
            'paypal_order_reference_id' => $orderReferenceId,
            'closure_reason'            => $reason ?: 'Order #' . $Order->getHash() . ' completed'
        ]);
    }

    /**
     * Check if the PayPal API response is OK
     *
     * @param ResponseInterface $Response - PayPal API Response
     * @return array
     * @throws AmazonPayException
     */
    protected function getResponseData(ResponseInterface $Response)
    {
        $response = $Response->toArray();

        if (!empty($response['Error']['Code'])) {
            $this->throwAmazonPayException($response['Error']['Code']);
        }

        return $response;
    }

    /**
     * Throw AmazonPayException for specific Amazon API Error
     *
     * @param string $errorCode
     * @param array $exceptionAttributes (optional) - Additional Exception attributes that may be relevant for the Frontend
     * @return string
     *
     * @throws AmazonPayException
     */
    protected function throwAmazonPayException($errorCode, $exceptionAttributes = [])
    {
        $L   = $this->getLocale();
        $lg  = 'quiqqer/payment-paypal';
        $msg = $L->get($lg, 'payment.error_msg.general_error');

        switch ($errorCode) {
            case 'InvalidPaymentMethod':
            case 'PaymentMethodNotAllowed':
            case 'TransactionTimedOut':
            case 'AmazonRejected':
            case 'ProcessingFailure':
            case 'MaxCapturesProcessed':
                $msg = $L->get($lg, 'payment.error_msg.' . $errorCode);
                break;
        }

        $Exception = new AmazonPayException($msg);
        $Exception->setAttributes($exceptionAttributes);

        throw $Exception;
    }

    /**
     * Generate a unique, random Authorization Reference ID to identify
     * authorization transactions for an order
     *
     * @param AbstractOrder $Order
     * @return string
     */
    protected function getNewAuthorizationReferenceId(AbstractOrder $Order)
    {
        return mb_substr('a_' . $Order->getId() . '_' . uniqid(), 0, 32);
    }

    /**
     * Add an AuthorizationReferenceId to current Order
     *
     * @param string $authorizationReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addAuthorizationReferenceIdToOrder($authorizationReferenceId, AbstractOrder $Order)
    {
        $authorizationReferenceIds = $Order->getPaymentDataEntry(self::ATTR_AUTHORIZATION_REFERENCE_IDS);

        if (empty($authorizationReferenceIds)) {
            $authorizationReferenceIds = [];
        }

        $authorizationReferenceIds[] = $authorizationReferenceId;

        $Order->setPaymentData(self::ATTR_AUTHORIZATION_REFERENCE_IDS, $authorizationReferenceIds);
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Generate a unique, random CaptureReferenceId to identify
     * captures for an order
     *
     * @param AbstractOrder $Order
     * @return string
     */
    protected function getNewCaptureReferenceId(AbstractOrder $Order)
    {
        return mb_substr('c_' . $Order->getId() . '_' . uniqid(), 0, 32);
    }

    /**
     * Add an CaptureReferenceId to current Order
     *
     * @param string $captureReferenceId
     * @param AbstractOrder $Order
     * @return void
     */
    protected function addCaptureReferenceIdToOrder($captureReferenceId, AbstractOrder $Order)
    {
        $captureReferenceIds = $Order->getPaymentDataEntry(self::ATTR_CAPTURE_REFERENCE_IDS);

        if (empty($captureReferenceIds)) {
            $captureReferenceIds = [];
        }

        $captureReferenceIds[] = $captureReferenceId;

        $Order->setPaymentData(self::ATTR_CAPTURE_REFERENCE_IDS, $captureReferenceIds);
        $Order->update(QUI::getUsers()->getSystemUser());
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
     * Make a REST API request to the PayPal API
     *
     * @param array $body - Request data
     * @return array|false - Response body or false on error
     *
     * @throws PayPalException
     */
    protected function payPalApiRequest($body)
    {
        $Request       = new PaymentCreateRequest();
        $Request->body = $body;

        try {
            $Response = $this->getPayPalClient()->execute($Request);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            throw new PayPalException([
                'quiqqer/payment-paypal',
                'payment.error_msg.general_error'
            ]);
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
