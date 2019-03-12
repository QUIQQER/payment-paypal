<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use Symfony\Component\HttpFoundation\Response;
use QUI;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PayPalException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use QUI\ERP\Accounting\Payments\Order\Payment as OrderProcessStepPayments;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Accounting\Invoice\Invoice;

/**
 * Class Payment
 *
 * Main payment provider for PayPal billing
 */
class Payment extends BasePayment implements RecurringPaymentInterface
{
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
    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN = 'paypal-api-create_billing_plan';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN = 'paypal-api-update_billing_plan';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_PLAN    = 'paypal-api-get_billing_plan';
    const PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS  = 'paypal-api-list_billing_plans';

    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT  = 'paypal-api-create_billing_agreement';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_AGREEMENT  = 'paypal-api-update_billing_agreement';
    const PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT = 'paypal-api-execute_billing_agreement';
    const PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT    = 'paypal-api-bill_billing_agreement';
    const PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT  = 'paypal-api-cancel_billing_agreement';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT     = 'paypal-api-get_billing_agreement';

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
        return BillingAgreements::createBillingAgreement($Order);
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
        BillingAgreements::billBillingAgreementBalance($Invoice);
    }

    /**
     * Execute the request from the payment provider
     *
     * @param QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway
     *
     * @throws QUI\ERP\Order\Basket\Exception
     * @throws QUI\Exception
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
                    BillingAgreements::executeBillingAgreement($Order, $_REQUEST['token']);

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
        } else {
            $goToBasket = true;
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
     * Check if a Billing Agreement is associated with an order and
     * return its ID (= identification at the payment method side; e.g. PayPal)
     *
     * @param AbstractOrder $Order
     * @return int|string|false - ID or false of no ID associated
     */
    public function getBillingAgreementIdByOrder(AbstractOrder $Order)
    {
        return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_ID);
    }

    /**
     * Cancel a Billing Agreement
     *
     * @param int|string $billingAgreementId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PayPalException
     */
    public function cancelBillingAgreement($billingAgreementId, $reason = '')
    {
        BillingAgreements::cancelBillingAgreement($billingAgreementId, $reason);
    }
}
