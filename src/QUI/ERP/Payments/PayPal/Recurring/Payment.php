<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Plans\Utils as ERPPlansUtils;
use QUI\ERP\Payments\PayPal\PayPalException;

/**
 * Class Payment
 *
 * Main payment provider for PayPal billing
 */
class Payment extends BasePayment
{
    /**
     * PayPal Order attribute for recurring payments
     */
    const ATTR_PAYPAL_BILLING_PLAN_ID                = 'paypal-BillingPlanId';
    const ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL = 'paypal-BillingAgreementApprovalUrl';

    /**
     * PayPal REST API request types for Billing
     */
    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN = 'paypal-api-create_billing_plan';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN = 'paypal-api-update_billing_plan';

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
        $this->createBillingPlan($Order);
        $this->activateBillingPlan($Order);

        $Order->addHistory($this->getHistoryText('order.create_billing_agreement'));

        $Customer = $Order->getCustomer();

        $body = [
            'name'        => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.name',
                [
                    'orderReference' => $Order->getPrefixedId()
                ]
            ),
            'description' => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_agreement.description',
                [
                    'orderReference' => $Order->getPrefixedId(),
                    'url'            => QUI::getRewrite()->getRequestUri()
                ]
            ),
            'payer'       => [
                'payment_method' => 'paypal',
                'payer_info'     => [
                    'email'      => $Customer->getAttribute('email'),
                    'first_name' => $Customer->getAttribute('firstname'),
                    'last_name'  => $Customer->getAttribute('lastname')
                ]
            ],
            'plan'        => [
                'id' => $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_PLAN_ID)
            ]
        ];

        // Determine start date
        $Now = new \DateTime();
//        $Now->add(new \DateInterval('P24H'));

        $body['start_date'] = $Now->format('Y-m-dTH:i:sZ');

        try {
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN, $body, $Order);
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
     * Create a PayPal Billing Plan
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    protected function createBillingPlan(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_PLAN_ID)) {
            return;
        }

        $Order->addHistory($this->getHistoryText('order.create_billing_plan'));

        $body = [
            'name'        => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_plan.name',
                [
                    'orderReference' => $Order->getPrefixedId()
                ]
            ),
            'description' => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.billing_plan.description',
                [
                    'orderReference' => $Order->getPrefixedId(),
                    'url'            => QUI::getRewrite()->getRequestUri()
                ]
            )
        ];

        // Parse billing plan details from order
        $planDetails = ERPPlansUtils::getPlanDetailsFromOrder($Order);

        // Determine plan type
        $autoExtend   = !empty($planDetails['auto_extend']);
        $body['type'] = $autoExtend ? 'INFINITE' : 'FIXED';

        // Determine payment definitions
        $body['payment_definitions'] = $this->parsePaymentDefinitionFromOrder($Order);

        // Merchant preferences
        $Gateway = new Gateway();
        $Gateway->setOrder($Order);

        $body['merchans_preferences'] = [
            'cancel_url' => $Gateway->getCancelUrl(),
            'return_url' => $Gateway->getGatewayUrl()
        ];

        try {
            $response = $this->payPalApiRequest(self::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN, $body, $Order);
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }

        $Order->setPaymentData(self::ATTR_PAYPAL_BILLING_PLAN_ID, $response['id']);
        $this->saveOrder($Order);
    }

    /**
     * Activate a Billing Plan
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws PayPalException
     */
    protected function activateBillingPlan(AbstractOrder $Order)
    {
        try {
            $this->payPalApiRequest(
                self::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
                [
                    'op'    => 'replace',
                    'path'  => '/',
                    'value' => [
                        'state' => 'ACTIVE'
                    ]
                ],
                $Order
            );
        } catch (PayPalException $Exception) {
            $Order->addHistory('PayPal :: PayPal API ERROR. Please check error logs.');
            $this->saveOrder($Order);
            throw $Exception;
        }
    }

    /**
     * Parse PayPal Billing Plan "payment_definition" details from an Order
     *
     * @see https://developer.paypal.com/docs/api/payments.billing-plans/v1/#definition-payment_definition
     *
     * @param AbstractOrder $Order
     * @return array
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    protected function parsePaymentDefinitionFromOrder(AbstractOrder $Order)
    {
        $planDetails          = ERPPlansUtils::getPlanDetailsFromOrder($Order);
        $invoiceIntervalParts = explode('-', $planDetails['invoice_interval']);

        $paymentDefinition = [
            'name'               => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.payment_definition.name',
                [
                    'orderReference' => $Order->getPrefixedId()
                ]
            ),
            'type'               => 'REGULAR',
            'frequency_interval' => $invoiceIntervalParts[0], // e.g. "1"
            'frequency'          => mb_strtoupper($invoiceIntervalParts[1]) // e.g. "MONTH"
        ];

        // Calculate cycles
        $autoExtend = !empty($planFields['auto_extend']);

        if ($autoExtend) {
            $paymentDefinition['cycles'] = 0;
        } else {
            try {
                $DurationInterval = ERPPlansUtils::parseIntervalFromDuration($planDetails['duration_interval']);
                $InvoiceInterval  = ERPPlansUtils::parseIntervalFromDuration($planDetails['invoice_interval']);
                $cycles           = 0;

                $Start = new \DateTime();
                $End   = $Start->add($DurationInterval)->sub(new \DateInterval('P1D'));
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);

                $Order->addHistory($this->getHistoryText('order.read_billing_plan.error'));

                throw new QUI\ERP\Payments\PayPal\PayPalException(
                    QUI::getLocale()->get(
                        'quiqqer/payment-paypal',
                        'exception.Recurring.order.error'
                    )
                );
            }

            while ($Start <= $End) {
                $Start->add($InvoiceInterval);
                $cycles++;
            }

            $paymentDefinition['cycles'] = $cycles;
        }

        // Amount
        $PriceCalculation = $Order->getPriceCalculation();
        $amountNetTotal   = $this->formatPrice($PriceCalculation->getNettoSum()->get());
        $amountTaxTotal   = $this->formatPrice($PriceCalculation->getVatSum()->get());

        $paymentDefinition['amount'] = [
            'value'    => $amountNetTotal,
            'currency' => $Order->getCurrency()->getCode()
        ];

        $paymentDefinition['charge_models'] = [
            'type'   => 'TAX',
            'amount' => [
                'value'    => $amountTaxTotal,
                'currency' => $Order->getCurrency()->getCode()
            ]
        ];

        return $paymentDefinition;
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
        $Control = new ExpressPaymentDisplay();
        $Control->setAttribute('Order', $Order);

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
}
