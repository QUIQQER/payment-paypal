<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use Symfony\Component\HttpFoundation\Response;
use QUI;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Payments\PayPal\PayPalException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use QUI\ERP\Accounting\Payments\Order\Payment as OrderProcessStepPayments;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Order\Handler as OrderHandler;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Accounting\Invoice\Invoice;

/**
 * Class Payment
 *
 * Main payment provider for PayPal billing
 */
class Payment extends BasePayment implements RecurringPaymentInterface
{
    const TBL_BILLING_AGREEMENTS = 'paypal_billing_agreements';

    /**
     * PayPal Order attribute for recurring payments
     */
    const ATTR_PAYPAL_BILLING_PLAN_ID                = 'paypal-BillingPlanId';
    const ATTR_PAYPAL_BILLING_AGREEMENT_ID           = 'paypal-BillingAgreementId';
    const ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN        = 'paypal-BillingAgreementToken';
    const ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL = 'paypal-BillingAgreementApprovalUrl';

    /**
     * PayPal REST API request types for Billing
     */
    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN       = 'paypal-api-create_billing_plan';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN       = 'paypal-api-update_billing_plan';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_PLAN          = 'paypal-api-get_billing_plan';
    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT  = 'paypal-api-create_billing_agreement';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_AGREEMENT  = 'paypal-api-update_billing_agreement';
    const PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT = 'paypal-api-execute_billing_agreement';
    const PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT    = 'paypal-api-bill_billing_agreement';

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.recurring.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.recurring.description');
    }

    /**
     * Does the payment support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPayments()
    {
        return true;
    }

    /**
     * Does the payment ONLY support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPaymentsOnly()
    {
        return true;
    }

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
    public function createBillingAgreement(AbstractOrder $Order)
    {
        $billingPlanId = BillingPlans::createBillingPlanFromOrder($Order);

        $Order->addHistory($this->getHistoryText('order.billing_plan_created', [
            'billingPlandId' => $billingPlanId
        ]));

        $Order->setPaymentData(self::ATTR_PAYPAL_BILLING_PLAN_ID, $billingPlanId);

        $this->saveOrder($Order);

        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL)) {
            return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL);
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
                'id' => $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_PLAN_ID)
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
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT, $body, $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        if (empty($response['links'])) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);

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
                $Order->addHistory($this->getHistoryText('order.billing_agreement_created', [
                    'approvalUrl' => $link['href']
                ]));

                $Order->setPaymentData(self::ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL, $link['href']);
                $this->saveOrder($Order);

                return $link['href'];
            }
        }

        $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
        $this->saveOrder($Order);

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
     * Execute a Billing Agreement
     *
     * @param AbstractOrder $Order
     * @param string $agreementToken
     * @return void
     * @throws PayPalException
     */
    protected function executeBillingAgreement(AbstractOrder $Order, string $agreementToken)
    {
        try {
            $response = $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT,
                [],
                [
                    self::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN => $agreementToken
                ]
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);

            QUI\System\Log::writeException($Exception);

            throw new PayPalException(
                QUI::getLocale()->get(
                    'quiqqer/payment-paypal',
                    'exception.Recurring.order.error'
                )
            );
        }

        $Order->addHistory($this->getHistoryText('order.billing_agreement_accepted', [
            'agreementToken' => $agreementToken,
            'agreementId'    => $response['id']
        ]));

        $Order->setPaymentData(self::ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN, $agreementToken);
        $Order->setPaymentData(self::ATTR_PAYPAL_BILLING_AGREEMENT_ID, $response['id']);
        $Order->setPaymentData(BasePayment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        $this->saveOrder($Order);

        // Save billing agreement reference in database
        try {
            QUI::getDataBase()->insert(
                $this->getBillingAgreementsTable(),
                [
                    'paypal_agreement_id' => $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_ID),
                    'paypal_plan_id'      => $response['id'],
                    'order_hash'          => $Order->getHash()
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
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws PayPalException
     */
    public function billBillingAgreementBalance(Invoice $Invoice)
    {
        $billingAgreementId = $Invoice->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_ID);

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

        $data = $this->getBillingAgreementData($billingAgreementId);

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

        $this->payPalApiRequest(
            self::PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT,
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
                self::ATTR_PAYPAL_BILLING_AGREEMENT_ID => $billingAgreementId
            ]
        );
    }

    /**
     * Execute the request from the payment provider
     *
     * @param QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway
     *
     * @throws QUI\ERP\Accounting\Payments\Exception
     */
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway)
    {
        $Order        = $Gateway->getOrder();
        $OrderProcess = new QUI\ERP\Order\OrderProcess([
            'orderHash' => $Order->getHash()
        ]);

        $goToBasket = false;

        if ($Gateway->isSuccessRequest()) {
            if (empty($_REQUEST['token'])) {
                $goToBasket = true;
            } else {
                try {
                    $this->executeBillingAgreement($Order, $_REQUEST['token']);

                    $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Finish([
                        'Order' => $Gateway->getOrder()
                    ]);
                } catch (\Exception $Exception) {
                    $goToBasket = true;
                }
            }
        } elseif ($Gateway->isCancelRequest()) {
            $GoToStep = new OrderProcessStepPayments([
                'Order' => $Gateway->getOrder()
            ]);
        }

        if ($goToBasket) {
            $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Basket([
                'Order' => $Gateway->getOrder()
            ]);
        }

        $processingUrl = $OrderProcess->getStepUrl($GoToStep->getName());

        // Umleitung zur Bestellung
        $Redirect = new RedirectResponse($processingUrl);
        $Redirect->setStatusCode(Response::HTTP_SEE_OTHER);

        echo $Redirect->getContent();
        $Redirect->send();
        exit;
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

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__, 2).'/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Can the Billing Agreement of this payment method be edited
     * regarding essential data like invoice frequency, amount etc.?
     *
     * @return bool
     */
    public function isBillingAgreementEditable()
    {
        return false;
    }

    /**
     * Get available data by Billing Agreement ID
     *
     * @param string $billingAgreementId - PayPal Billing Agreement ID
     * @return array|false
     */
    protected function getBillingAgreementData($billingAgreementId)
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
            'orderHash'     => $data['order_hash'],
            'billingPlanId' => $data['paypal_plan_id'],
        ];
    }

    /**
     * @return string
     */
    protected function getBillingAgreementsTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_AGREEMENTS);
    }
}
