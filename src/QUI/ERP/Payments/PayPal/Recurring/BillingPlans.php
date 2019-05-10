<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Provider;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use QUI\ERP\Plans\Utils as ErpPlansUtils;
use QUI\ERP\Products\Handler\Products as ProductsHandler;
use QUI\ERP\Payments\PayPal\Utils;
use QUI\ERP\Products\Product\Product;
use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Payments\PayPal\PayPalException;

/**
 * Class BillingPlans
 *
 * Handler for PayPal Billing Plans
 */
class BillingPlans
{
    /**
     * Billing Plan tables
     */
    const TBL_BILLING_PLANS = 'paypal_billing_plans';

    /**
     * @var QUI\ERP\Payments\PayPal\Payment
     */
    protected static $Payment = null;

    /**
     * @param AbstractOrder $Order
     * @return string - PayPal Billing Plan ID
     * @throws QUI\ERP\Exception
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\Exception
     */
    public static function createBillingPlanFromOrder(AbstractOrder $Order)
    {
        if ($Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID)) {
            return $Order->getPaymentDataEntry(RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID);
        }

        $billingPlanId = self::getBillingPlanIdByOrder($Order);

        if ($billingPlanId !== false) {
            return $billingPlanId;
        }

        if (!ErpPlansUtils::isPlanOrder($Order)) {
            throw new QUI\ERP\Accounting\Payments\Exception(
                'Order #'.$Order->getHash().' contains no plan products.'
            );
        }

        // Create new Billing Plan
        $PlanProduct = false;

        /** @var QUI\ERP\Accounting\Article $Article */
        foreach ($Order->getArticles() as $Article) {
            if ($PlanProduct === false && ErpPlansUtils::isPlanArticle($Article)) {
                $PlanProduct = ProductsHandler::getProduct($Article->getId());
            }
        }

        // Read name and description from PlanProduct (= Product that contains subscription plan information)
        $Locale = $Order->getCustomer()->getLocale();

        $name        = $PlanProduct->getTitle($Locale);
        $description = $PlanProduct->getDescription($Locale);

        if (empty($description)) {
            $description = $name;
        }

        $body = [
            'name'        => $name,
            'description' => $description
        ];

        // Parse billing plan details from order
        $planDetails = ErpPlansUtils::getPlanDetailsFromOrder($Order);

        // Determine plan type
        $autoExtend   = !empty($planDetails['auto_extend']);
        $body['type'] = $autoExtend ? 'INFINITE' : 'FIXED';

        // Determine payment definitions
        $body['payment_definitions'] = self::parsePaymentDefinitionsFromOrder($Order, $PlanProduct);

        // Merchant preferences
        $Gateway = new Gateway();

        $body['merchant_preferences'] = [
            'cancel_url' => $Gateway->getGatewayUrl(),
            'return_url' => $Gateway->getGatewayUrl()
        ];

        // Create Billing Plan
        $response = self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_CREATE_BILLING_PLAN,
            $body,
            $Order
        );

        $billingPlanId = $response['id'];

        // Save reference in database
        QUI::getDataBase()->insert(
            self::getBillingPlansTable(),
            [
                'paypal_id'           => $billingPlanId,
                'identification_hash' => self::getIdentificationHash($Order)
            ]
        );

        // Activate new billing plan
        self::activateBillingPlan($billingPlanId);

        return $billingPlanId;
    }

    public static function updateBillingPlan(AbstractOrder $Order)
    {
        // todo
    }

    /**
     * Activate a Billing Plan
     *
     * @param string $billingPlanId
     * @return void
     * @throws PayPalException
     */
    public static function activateBillingPlan($billingPlanId)
    {
        self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
            [
                [
                    'op'    => 'replace',
                    'path'  => '/',
                    'value' => [
                        'state' => 'ACTIVE'
                    ]
                ]
            ],
            [
                RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID => $billingPlanId
            ]
        );
    }

    /**
     * Delete a Billing Plan
     *
     * @param string $billingPlanId
     * @return void
     * @throws PayPalException
     */
    public static function deleteBillingPlan($billingPlanId)
    {
        self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
            [
                [
                    'op'    => 'replace',
                    'path'  => '/',
                    'value' => [
                        'state' => 'DELETED'
                    ]
                ]
            ],
            [
                RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID => $billingPlanId
            ]
        );
    }

    /**
     * Get list of all PayPal Billing Plans
     *
     * @param int $page (optional) - Start page of list [min: 0]
     * @param int $pageSize (optional) - Number of plans per page [range: 1 to 20]
     * @return array
     * @throws PayPalException
     */
    public static function getBillingPlanList($page = 0, $pageSize = 10)
    {
        if ($page < 0) {
            $page = 0;
        }

        if ($pageSize > 20) {
            $pageSize = 20;
        } elseif ($pageSize < 1) {
            $pageSize = 1;
        }

        return self::payPalApiRequest(
            RecurringPayment::PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS,
            [],
            [
                'page'           => $page,
                'page_size'      => $pageSize,
                'status'         => 'ACTIVE',
                'total_required' => 'yes'
            ]
        );
    }

    /**
     * Get PayPal Billing Plan ID based on the articles of an order has already been created
     *
     * @param AbstractOrder $Order
     * @return string|false - ID or false if no Billing Plan exists
     */
    protected static function getBillingPlanIdByOrder(AbstractOrder $Order)
    {
        try {
            $result = QUI::getDataBase()->fetch([
                'select' => ['paypal_id'],
                'from'   => self::getBillingPlansTable(),
                'where'  => [
                    'identification_hash' => self::getIdentificationHash($Order)
                ]
            ]);
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        if (empty($result)) {
            return false;
        }

        return $result[0]['paypal_id'];
    }

    /**
     * Get identification hash for a Billing Plan
     *
     * @param AbstractOrder $Order
     * @return string
     * @throws QUI\Exception
     */
    protected static function getIdentificationHash(AbstractOrder $Order)
    {
        $productIds = [];

        /** @var QUI\ERP\Accounting\Article $Article */
        foreach ($Order->getArticles() as $Article) {
            $productIds[] = (int)$Article->getId();
        }

        // sort IDs ASC
        sort($productIds);

        $lang         = $Order->getCustomer()->getLang();
        $totalSum     = $Order->getPriceCalculation()->getSum()->get();
        $hashedString = $lang.$totalSum.implode(',', $productIds);

        if (Provider::getApiSetting('sandbox')) {
            $hashedString .= '_sandbox';
        } else {
            $hashedString .= '_production';
        }

        return hash('sha256', $hashedString);
    }

    /**
     * Parse PayPal Billing Plan "payment_definition" details from an Order
     *
     * @see https://developer.paypal.com/docs/api/payments.billing-plans/v1/#definition-payment_definition
     *
     * @param AbstractOrder $Order
     * @param Product $PlanProduct - Product that contains plan information
     * @return array
     * @throws QUI\ERP\Payments\PayPal\PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    protected static function parsePaymentDefinitionsFromOrder(AbstractOrder $Order, Product $PlanProduct)
    {
        $paymentDefinitions   = [];
        $planDetails          = ErpPlansUtils::getPlanDetailsFromProduct($PlanProduct);
        $invoiceIntervalParts = explode('-', $planDetails['invoice_interval']);

        $paymentDefinition = [
            'name'               => QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'recurring.payment_definition.name',
                [
                    'planTitle' => $PlanProduct->getTitle(),
                    'url'       => Utils::getProjectUrl()
                ]
            ),
            'type'               => 'REGULAR',
            'frequency_interval' => $invoiceIntervalParts[0], // e.g. "1"
            'frequency'          => mb_strtoupper($invoiceIntervalParts[1]) // e.g. "MONTH"
        ];

        // Calculate cycles
        $autoExtend = !empty($planDetails['auto_extend']);

        if ($autoExtend) {
            $paymentDefinition['cycles'] = 0;
        } else {
            try {
                $DurationInterval = ErpPlansUtils::parseIntervalFromDuration($planDetails['duration_interval']);
                $InvoiceInterval  = ErpPlansUtils::parseIntervalFromDuration($planDetails['invoice_interval']);

                $Start = new \DateTime();
                $End   = clone $Start;
                $End->add($DurationInterval)->sub(new \DateInterval('P1D'));
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);

                throw new QUI\ERP\Payments\PayPal\PayPalException(
                    QUI::getLocale()->get(
                        'quiqqer/payment-paypal',
                        'exception.Recurring.order.error'
                    )
                );
            }

            $cycles = 0;

            while ($Start <= $End) {
                $Start->add($InvoiceInterval);
                $cycles++;
            }

            $paymentDefinition['cycles'] = $cycles;
        }

        // Amount
        $PriceCalculation = $Order->getPriceCalculation();
        $amountNetTotal   = Utils::formatPrice($PriceCalculation->getNettoSum()->get());
        $amountTaxTotal   = Utils::formatPrice($PriceCalculation->getVatSum()->get());

        $paymentDefinition['amount'] = [
            'value'    => $amountNetTotal,
            'currency' => $Order->getCurrency()->getCode()
        ];

        $paymentDefinition['charge_models'] = [];

        // Tax
        $paymentDefinition['charge_models'][] = [
            'type'   => 'TAX',
            'amount' => [
                'value'    => $amountTaxTotal,
                'currency' => $Order->getCurrency()->getCode()
            ]
        ];

        // Shipping
        // @todo LATER: Add shipping costs (requires separate quiqqer/shipping module)

        $paymentDefinitions[] = $paymentDefinition;

        // @todo LATER: Additional payment definition (e.g. for a TRIAL period)

        return $paymentDefinitions;
    }

    /**
     * @return string
     */
    public static function getBillingPlansTable()
    {
        return QUI::getDBTableName(self::TBL_BILLING_PLANS);
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
}
