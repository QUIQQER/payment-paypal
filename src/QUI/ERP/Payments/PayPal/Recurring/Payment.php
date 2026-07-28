<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Invoice\InvoiceView;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Accounting\Payments\Order\Payment as OrderProcessStepPayments;
use QUI\ERP\Accounting\Payments\Transactions\Factory as TransactionFactory;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Accounting\Payments\Types\RecurringPaymentInterface;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment as BasePayment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\PayPalSystemException;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\Exception;
use QUI\ExceptionStack;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use QUI\Utils\Doctrine;

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
    const ATTR_PAYPAL_BILLING_PLAN_ID = 'paypal-BillingPlanId';
    const ATTR_PAYPAL_BILLING_AGREEMENT_ID = 'paypal-BillingAgreementId';
    const ATTR_PAYPAL_BILLING_AGREEMENT_TOKEN = 'paypal-BillingAgreementToken';
    const ATTR_PAYPAL_BILLING_AGREEMENT_APPROVAL_URL = 'paypal-BillingAgreementApprovalUrl';
    const ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID = 'paypal-BillingAgreementTransactionId';
    const ATTR_PAYPAL_SUBSCRIPTION_PRODUCT_ID = 'paypal-SubscriptionProductId';
    const ATTR_PAYPAL_SUBSCRIPTION_PLAN_ID = 'paypal-SubscriptionPlanId';
    const ATTR_PAYPAL_SUBSCRIPTION_ID = 'paypal-SubscriptionId';
    const ATTR_PAYPAL_SUBSCRIPTION_APPROVAL_URL = 'paypal-SubscriptionApprovalUrl';

    /**
     * PayPal REST API request types for Billing
     */
    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN = 'paypal-api-create_billing_plan';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN = 'paypal-api-update_billing_plan';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_PLAN = 'paypal-api-get_billing_plan';
    const PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS = 'paypal-api-list_billing_plans';

    const PAYPAL_REQUEST_TYPE_CREATE_BILLING_AGREEMENT = 'paypal-api-create_billing_agreement';
    const PAYPAL_REQUEST_TYPE_UPDATE_BILLING_AGREEMENT = 'paypal-api-update_billing_agreement';
    const PAYPAL_REQUEST_TYPE_EXECUTE_BILLING_AGREEMENT = 'paypal-api-execute_billing_agreement';
    const PAYPAL_REQUEST_TYPE_BILL_BILLING_AGREEMENT = 'paypal-api-bill_billing_agreement';
    const PAYPAL_REQUEST_TYPE_CANCEL_BILLING_AGREEMENT = 'paypal-api-cancel_billing_agreement';
    const PAYPAL_REQUEST_TYPE_SUSPEND_BILLING_AGREEMENT = 'paypal-api-suspend_billing_agreement';
    const PAYPAL_REQUEST_TYPE_RESUME_BILLING_AGREEMENT = 'paypal-api-resume_billing_agreement';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT = 'paypal-api-get_billing_agreement';
    const PAYPAL_REQUEST_TYPE_GET_BILLING_AGREEMENT_TRANSACTIONS = 'paypal-api-get_billing_agreement_transactions';

    const PAYPAL_REQUEST_TYPE_SALE_REFUND = 'paypal-api-sale_refund';

    /**
     * PayPal error codes
     */
    const PAYPAL_ERROR_NO_BILLING_AGREEMENT_TRANSACTION = 'no_billing_agreement_transaction';

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.recurring.title');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment.recurring.description');
    }

    /**
     * Does the payment ONLY support recurring payments (e.g. for subscriptions)?
     *
     * @return bool
     */
    public function supportsRecurringPaymentsOnly(): bool
    {
        return true;
    }

    /**
     * Create a PayPal Subscription
     *
     * @param AbstractOrder $Order
     * @return string - Approval URL
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     * @throws \Exception
     */
    public function createSubscription(AbstractOrder $Order): string
    {
        return Subscriptions::createSubscription($Order);
    }

    /**
     * Bills the balance for an agreement based on an Invoice
     *
     * @param Invoice $Invoice
     * @return void
     * @throws PayPalException
     * @throws Exception
     */
    public function captureSubscription(Invoice $Invoice): void
    {
        if ($Invoice->getPaymentDataEntry(self::ATTR_PAYPAL_SUBSCRIPTION_ID)) {
            Subscriptions::billSubscriptionInvoice($Invoice);
            return;
        }

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
    public function executeGatewayPayment(QUI\ERP\Accounting\Payments\Gateway\Gateway $Gateway): void
    {
        $Order = $Gateway->getOrder();

        if (!$Order instanceof AbstractOrder) {
            throw new QUI\Exception('The PayPal recurring gateway has no order.');
        }

        $OrderProcess = new QUI\ERP\Order\OrderProcess([
            'orderHash' => $Order->getUUID()
        ]);

        $goToBasket = false;

        if ($Gateway->isSuccessRequest()) {
            if (!empty($_REQUEST['subscription_id'])) {
                try {
                    Subscriptions::approveSubscription($Order, $_REQUEST['subscription_id']);

                    $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Finish([
                        'Order' => $Order
                    ]);

                    $Order->setSuccessfulStatus();
                } catch (PayPalException) {
                    $goToBasket = true;
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                    $goToBasket = true;
                }
            } elseif (empty($_REQUEST['token'])) {
                $goToBasket = true;
            } else {
                try {
                    BillingAgreements::executeBillingAgreement($Order, $_REQUEST['token']);

                    $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Finish([
                        'Order' => $Order
                    ]);

                    $Order->setSuccessfulStatus();
                } catch (PayPalException) {
                    $goToBasket = true;
                } catch (\Exception $Exception) {
                    QUI\System\Log::writeException($Exception);
                    $goToBasket = true;
                }
            }
        } elseif ($Gateway->isCancelRequest()) {
            $GoToStep = new OrderProcessStepPayments([
                'Order' => $Order
            ]);
        } else {
            $goToBasket = true;
        }

        if ($goToBasket) {
            $GoToStep = new QUI\ERP\Order\Controls\OrderProcess\Basket([
                'Order' => $Order
            ]);
        }

        if (!isset($GoToStep)) {
            $processingUrl = '/';
        } else {
            $processingUrl = $OrderProcess->getStepUrl($GoToStep->getName());
        }

        // Umleitung zur recurring return php
        //$Redirect = new RedirectResponse($processingUrl);

        $status = $goToBasket ? 'error' : 'success';
        $Project = QUI::getRewrite()->getProject();

        if ($Project === null) {
            throw new QUI\Exception('Could not determine the project for the PayPal redirect.');
        }

        $url = $Project->getVHost(true, true);
        $url .= URL_OPT_DIR . 'quiqqer/payment-paypal/bin/recurringReturn.php';
        $url .= '?orderHash=' . urlencode($Order->getUUID());
        $url .= '&status=' . $status;
        $url .= '&redirect=' . urlencode($processingUrl);

        $Redirect = new RedirectResponse($url);
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
     * @throws QUI\Exception|\Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null): string
    {
        $Control = new PaymentDisplay();
        $Control->setAttribute('Order', $Order);
        $Step?->addCSSClass('quiqqer-payment-paypal-recurring-step');

        $Step?->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step?->setContent($Engine->fetch(dirname(__FILE__, 2) . '/PaymentDisplay.Header.html'));

        return $Control->create();
    }

    /**
     * Can the Billing Agreement of this payment method be edited
     * regarding essential data like invoice frequency, amount etc.?
     *
     * @return bool
     */
    public function isSubscriptionEditable(): bool
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
    public function getSubscriptionIdByOrder(AbstractOrder $Order): bool | int | string
    {
        if ($Order->getPaymentDataEntry(self::ATTR_PAYPAL_SUBSCRIPTION_ID)) {
            return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_SUBSCRIPTION_ID);
        }

        return $Order->getPaymentDataEntry(self::ATTR_PAYPAL_BILLING_AGREEMENT_ID) ?? false;
    }

    /**
     * Cancel a Billing Agreement
     *
     * @param int|string $subscriptionId
     * @param string $reason (optional) - The reason why the billing agreement is being cancelled
     * @return void
     * @throws PayPalException
     */
    public function cancelSubscription(int | string $subscriptionId, string $reason = ''): void
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            Subscriptions::cancelSubscription((string)$subscriptionId, $reason);
            return;
        }

        BillingAgreements::cancelBillingAgreement($subscriptionId, $reason);
    }

    /**
     * Suspend a Subscription
     *
     * This *temporarily* suspends the automated collection of payments until explicitly resumed.
     *
     * @param int|string $subscriptionId
     * @param string|null $note (optional) - Suspension note
     * @return void
     * @throws PayPalException
     */
    public function suspendSubscription(int | string $subscriptionId, ?string $note = null): void
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            Subscriptions::suspendSubscription((string)$subscriptionId, $note);
            return;
        }

        BillingAgreements::suspendBillingAgreement($subscriptionId, $note);
    }

    /**
     * Resume a suspended Subscription
     *
     * This resumes automated collection of payments of a previously supsendes Subscription.
     *
     * @param int|string $subscriptionId
     * @param string|null $note (optional) - Resume note
     * @return void
     * @throws PayPalException
     */
    public function resumeSubscription(int | string $subscriptionId, ?string $note = null): void
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            Subscriptions::activateSubscription((string)$subscriptionId, $note);
            return;
        }

        BillingAgreements::resumeSubscription($subscriptionId, $note);
    }

    /**
     * Checks if a subscription is currently suspended
     *
     * @param int|string $subscriptionId
     * @return bool
     *
     * @throws PayPalException
     * @throws PayPalSystemException
     */
    public function isSuspended(int | string $subscriptionId): bool
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            return Subscriptions::isSuspended((string)$subscriptionId);
        }

        return BillingAgreements::isSuspended($subscriptionId);
    }

    /**
     * Sets a subscription as inactive (on the side of this QUIQQER system only!)
     *
     * IMPORTANT: This does NOT mean that the corresponding subscription at the payment provider
     * side is cancelled. If you want to do this please use cancelSubscription() !
     *
     * @param $subscriptionId
     * @return void
     */
    public function setSubscriptionAsInactive($subscriptionId): void
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            Subscriptions::setSubscriptionAsInactive((string)$subscriptionId);
            return;
        }

        BillingAgreements::setBillingAgreementAsInactive($subscriptionId);
    }

    /**
     * Return the extra text for the invoice
     *
     * @param Invoice|InvoiceTemporary|InvoiceView $Invoice
     * @return string
     */
    public function getInvoiceInformationText(Invoice | InvoiceTemporary | InvoiceView $Invoice): string
    {
        try {
            $Customer = $Invoice->getCustomer();

            if ($Customer === null) {
                return '';
            }

            return $Customer->getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.additional_invoice_text'
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return '';
        }
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
        float | int $amount,
        string $reason = ''
    ): void {
        $Process = new QUI\ERP\Process($Transaction->getGlobalProcessId());
        $Process->addHistory(
            Utils::getHistoryText('refund.billing_agreement.start', [
                'transactionId' => $Transaction->getTxId()
            ])
        );

        if (!$Transaction->getData(self::ATTR_PAYPAL_BILLING_AGREEMENT_TRANSACTION_ID)) {
            $Process->addHistory(
                Utils::getHistoryText('refund.billing_agreement.error.not_transaction')
            );

            $this->throwPayPalException(self::PAYPAL_ERROR_NO_BILLING_AGREEMENT_TRANSACTION);
        }

        $Payment = $Transaction->getPayment();

        if ($Payment === null) {
            $this->throwPayPalException();
        }

        // create a refund transaction
        $RefundTransaction = TransactionFactory::createPaymentRefundTransaction(
            $amount,
            $Transaction->getCurrency(),
            $refundHash,
            $Payment->getName(),
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
                self::PAYPAL_REQUEST_TYPE_SALE_REFUND,
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
                Utils::getHistoryText('refund.error.request', [
                    'reason' => $Exception->getMessage(),
                    'reasonCode' => $Exception->getCode(),
                    'transactionId' => $Transaction->getTxId()
                ])
            );

            $RefundTransaction->error();

            throw $Exception;
        }

        $response = Utils::requireApiResponse($response, ['state']);

        switch ($response['state']) {
            // SUCCESS
            case self::PAYPAL_REFUND_STATE_COMPLETED:
            case self::PAYPAL_REFUND_STATE_PENDING:
                $RefundTransaction->setData(self::ATTR_PAYPAL_REFUND_ID, $response['id']);
                $RefundTransaction->updateData();

                $Process->addHistory(
                    Utils::getHistoryText('refund', [
                        'refundId' => $response['id'],
                        'amount' => $response['amount']['total'],
                        'currency' => $response['amount']['currency']
                    ])
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
                    Utils::getHistoryText('refund.billing_agreement.error.state', [
                        'state' => $response['state']
                    ])
                );

                $this->throwPayPalException(self::PAYPAL_ERROR_ORDER_NOT_REFUNDED);
        }
    }

    /**
     * Checks if the subscription is active at the payment provider side
     *
     * @param int|string $subscriptionId
     * @return bool
     */
    public function isSubscriptionActiveAtPaymentProvider(int | string $subscriptionId): bool
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            return Subscriptions::isSubscriptionActiveAtPaymentProvider((string)$subscriptionId);
        }

        try {
            $billingAgreement = BillingAgreements::getBillingAgreementDetails((string)$subscriptionId);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        if (empty($billingAgreement['state'])) {
            return false;
        }

        return match ($billingAgreement['state']) {
            BillingAgreements::BILLING_AGREEMENT_STATE_ACTIVE,
            BillingAgreements::BILLING_AGREEMENT_STATE_SUSPENDED => true,
            default => false,
        };
    }

    /**
     * Checks if the subscription is active at QUIQQER
     *
     * @param int|string $subscriptionId - Payment provider subscription ID
     * @return bool
     */
    public function isSubscriptionActiveAtQuiqqer(int | string $subscriptionId): bool
    {
        if (Subscriptions::exists((string)$subscriptionId)) {
            return Subscriptions::isSubscriptionActiveAtQuiqqer((string)$subscriptionId);
        }

        try {
            $result = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('active'))
                ->from(Doctrine::quoteIdentifier(BillingAgreements::getBillingAgreementsTable()))
                ->where(Doctrine::quoteIdentifier('paypal_agreement_id') . ' = :subscriptionId')
                ->setParameter('subscriptionId', $subscriptionId)
                ->executeQuery()
                ->fetchOne();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return true;
        }

        if ($result === false) {
            return false;
        }

        return !empty($result);
    }

    /**
     * Get IDs of all subscriptions
     *
     * @param bool $includeInactive (optional) - Include inactive subscriptions [default: false]
     * @return int[]
     */
    public function getSubscriptionIds(bool $includeInactive = false): array
    {
        try {
            $QueryBuilder = QUI::getQueryBuilder()
                ->select(Doctrine::quoteIdentifier('paypal_agreement_id'))
                ->from(Doctrine::quoteIdentifier(BillingAgreements::getBillingAgreementsTable()));

            if (!$includeInactive) {
                $QueryBuilder
                    ->where(Doctrine::quoteIdentifier('active') . ' = :active')
                    ->setParameter('active', 1);
            }

            $result = $QueryBuilder
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return [];
        }

        return array_merge(
            $result,
            Subscriptions::getSubscriptionIds($includeInactive)
        );
    }

    /**
     * Get global processing ID of a subscription
     *
     * @param int|string $subscriptionId
     * @return string|false
     */
    public function getSubscriptionGlobalProcessingId(int | string $subscriptionId): bool | string
    {
        $subscriptionData = Subscriptions::getSubscriptionData((string)$subscriptionId);

        if (!empty($subscriptionData)) {
            return $subscriptionData['globalProcessId'] ?: false;
        }

        $data = BillingAgreements::getBillingAgreementData((string)$subscriptionId);

        if (empty($data)) {
            return false;
        }

        return $data['globalProcessId'] ?: false;
    }
}
