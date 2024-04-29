<?php

namespace QUI\ERP\Payments\PayPal;

use DateInterval;
use QUI;
use QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed;
use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Basket\Basket;
use QUI\ERP\Order\Basket\BasketGuest;
use QUI\ERP\Order\Basket\BasketOrder;
use QUI\ERP\Order\Controls\OrderProcess\Checkout as CheckoutStep;
use QUI\ERP\Order\Exception;
use QUI\ERP\Order\OrderInterface;
use QUI\ERP\Order\Utils\Utils as OrderUtils;
use QUI\ERP\Payments\PayPal\Payment as PayPalPayment;
use QUI\ERP\Plans\Utils as ErpPlanUtils;
use QUI\Smarty\Collector;

use function class_exists;

/**
 * Class Events
 *
 * Global Event Handler for quiqqer/payment-paypal
 */
class Events
{
    /**
     * Template event quiqqer/order: onQuiqqer::order::orderProcessBasketEnd
     *
     * @param Collector $Collector
     * @param Basket|BasketGuest|BasketOrder $Basket
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\ERP\Accounting\Payments\Exception
     * @throws QUI\Exception
     */
    public static function templateOrderProcessBasketEnd(
        Collector $Collector,
        Basket|BasketGuest|BasketOrder $Basket,
        QUI\ERP\Order\AbstractOrder $Order
    ): void {
        // Check if order is a plan order
        if (class_exists('\\QUI\\ERP\\Plans\\Utils') && ERPPlansUtils::isPlanOrder($Order)) {
            return;
        }

        if (!Provider::getPaymentSetting('display_express_basket')) {
            return;
        }

        $PaymentExpress = Provider::getPayPalExpressPayment();

        if (!$PaymentExpress || !$PaymentExpress->isActive()) {
            return;
        }

        if (
            !($Basket instanceof Basket)
            && !($Basket instanceof BasketOrder)
            && !($Basket instanceof BasketGuest)
        ) {
            return;
        }

        $Project = QUI::getProjectManager()->getStandard();
        $CheckoutStep = new CheckoutStep();
        $checkout = 0;
        $orderHash = $Order->getUUID();
        $Payment = $Order->getPayment();

        if (
            $Order->getPaymentDataEntry(PayPalPayment::ATTR_PAYPAL_PAYMENT_ID)
            && $Payment
            && $Payment->getPaymentType() instanceof PaymentExpress
        ) {
            $checkout = 1;
        }

        $sandbox = Provider::getApiSetting('sandbox') ? 1 : 0;

        $Collector->append(
            '<div data-qui="package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader"
                  data-qui-options-context="basket"
                  data-qui-options-basketid="' . $Basket->getId() . '"
                  data-qui-options-sandbox="' . $sandbox . '"
                  data-qui-options-orderhash="' . $orderHash . '"
                  data-qui-options-checkout="' . $checkout . '"
                  data-qui-options-displaysize="' . Provider::getWidgetsSetting('btn_express_size') . '"
                  data-qui-options-displaycolor="' . Provider::getWidgetsSetting('btn_express_color') . '"
                  data-qui-options-displayshape="' . Provider::getWidgetsSetting('btn_express_shape') . '"
                  data-qui-options-orderprocessurl="' . OrderUtils::getOrderProcessUrl($Project, $CheckoutStep) . '">
            </div>'
        );
    }

    /**
     * @throws Exception
     * @throws QUI\Exception
     * @throws QUI\ERP\Accounting\Payments\Exception
     * @throws QUI\Database\Exception
     */
    public static function templateOrderSimpleExpressButtons(
        Collector $Collector,
        QUI\ERP\Order\AbstractOrder $Order
    ): void {
        // Check if order is a plan order
        if (class_exists('\\QUI\\ERP\\Plans\\Utils') && ERPPlansUtils::isPlanOrder($Order)) {
            return;
        }

        if (!Provider::getPaymentSetting('display_express_basket')) {
            return;
        }

        $PaymentExpress = Provider::getPayPalExpressPayment();

        if (!$PaymentExpress || !$PaymentExpress->isActive()) {
            return;
        }

        $Project = QUI::getProjectManager()->getStandard();
        $CheckoutStep = new CheckoutStep();
        $checkout = 0;
        $orderHash = $Order->getUUID();
        $Payment = $Order->getPayment();

        if (
            $Order->getPaymentDataEntry(PayPalPayment::ATTR_PAYPAL_PAYMENT_ID)
            && $Payment
            && $Payment->getPaymentType() instanceof PaymentExpress
        ) {
            $checkout = 1;
        }

        $sandbox = Provider::getApiSetting('sandbox') ? 1 : 0;

        $Collector->append(
            '<div data-qui="package/quiqqer/payment-paypal/bin/controls/ExpressBtn"
                  data-qui-options-context="simple-checkout"
                  data-qui-options-orderid="' . $Order->getUUID() . '"
                  data-qui-options-sandbox="' . $sandbox . '"
                  data-qui-options-orderhash="' . $orderHash . '"
                  data-qui-options-checkout="' . $checkout . '"
                  data-qui-options-displaysize="' . Provider::getWidgetsSetting('btn_express_size') . '"
                  data-qui-options-displaycolor="' . Provider::getWidgetsSetting('btn_express_color') . '"
                  data-qui-options-displayshape="' . Provider::getWidgetsSetting('btn_express_shape') . '"
                  data-qui-options-orderprocessurl="' . OrderUtils::getOrderProcessUrl($Project, $CheckoutStep) . '">
            </div>'
        );
    }

    /**
     * Template event quiqqer/order: onQuiqqer::order::basketSmall::end
     *
     * @param Collector $Collector
     * @param Basket|BasketOrder|BasketGuest $Basket $Basket
     * @return void
     *
     * @throws Exception
     * @throws QUI\Database\Exception
     * @throws QUI\ERP\Accounting\Payments\Exception
     * @throws QUI\Exception
     */
    public static function templateOrderBasketSmallEnd(
        Collector $Collector,
        Basket|BasketOrder|BasketGuest $Basket
    ): void {
        if (!($Basket instanceof Basket)) {
            return;
        }

        if (!Provider::getPaymentSetting('display_express_smallbasket')) {
            return;
        }

        // Do not show PayPal Express button in mini basket for guest users until
        // guest orders are implemented.
        if (QUI::getUsers()->isNobodyUser(QUI::getUserBySession())) {
            return;
        }

        if (class_exists('\\QUI\\ERP\\Plans\\Utils')) {
            try {
                $Basket->updateOrder();
                $Order = $Basket->getOrder();
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);

                return;
            }

            if (ErpPlanUtils::isPlanOrder($Order)) {
                return;
            }
        }

        $PaymentExpress = Provider::getPayPalExpressPayment();

        if (!$PaymentExpress || !$PaymentExpress->isActive()) {
            return;
        }

        // do not display PayPal button if basket has no articles
        if (!$Basket->count()) {
            return;
        }

        $Project = QUI::getProjectManager()->getStandard();
        $CheckoutStep = new CheckoutStep();
        $checkout = 0;

        if ($Basket->hasOrder()) {
            $Order = $Basket->getOrder();
            $Payment = $Order->getPayment();

            if (
                $Order->getPaymentDataEntry(PayPalPayment::ATTR_PAYPAL_PAYMENT_ID)
                && $Payment
                && $Payment->getPaymentType() instanceof PaymentExpress
            ) {
                $checkout = 1;
            }
        }

        $Collector->append(
            '<div data-qui="package / quiqqer / payment - paypal / bin / controls / ExpressBtnLoader"
                  data-qui-options-context="smallbasket"
                  data-qui-options-basketid="' . $Basket->getId() . '"
                  data-qui-options-checkout="' . $checkout . '"
                  data-qui-options-displaysize="' . Provider::getWidgetsSetting('btn_express_size_smallbasket') . '"
                  data-qui-options-displaycolor="' . Provider::getWidgetsSetting('btn_express_color') . '"
                  data-qui-options-displayshape="' . Provider::getWidgetsSetting('btn_express_shape') . '"
                  data-qui-options-orderprocessurl="' . OrderUtils::getOrderProcessUrl($Project, $CheckoutStep) . '">
            </div>'
        );
    }

    /**
     * quiqqer/payments: onPaymentsCreateBegin
     *
     * Check if a PayPal payment can be created
     *
     * @param string $paymentClass
     * @return void
     * @throws QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed
     */
    public static function onPaymentsCreateBegin(string $paymentClass): void
    {
        if (
            $paymentClass === QUI\ERP\Payments\PayPal\Recurring\Payment::class
            && !QUI::getPackageManager()->isInstalled('quiqqer/erp-plans')
        ) {
            throw new PaymentCanNotBeUsed(
                QUI::getLocale()->get(
                    'quiqqer/payments-paypal',
                    'exception.onPaymentsCreateBegin.erp_plans_missing'
                )
            );
        }
    }

    /**
     * quiqqer/payments: onPaymentsCanUsedInOrder
     *
     * PayPal for recurring payments cannot be used on Orders that contain a subscription plan
     * product with an invoice interval greater than 1 year (12 months).
     *
     * @param Payment $Payment
     * @param OrderInterface $Order
     * @throws QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed
     */
    public static function onPaymentsCanUsedInOrder(Payment $Payment, OrderInterface $Order): void
    {
        if (!QUI::getPackageManager()->isInstalled('quiqqer/erp-plans')) {
            return;
        }

        try {
            $PaymentType = $Payment->getPaymentType();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return;
        }

        if (!($PaymentType instanceof QUI\ERP\Payments\PayPal\Recurring\Payment)) {
            return;
        }

        $planDetails = ErpPlanUtils::getPlanDetailsFromOrder($Order);

        if (empty($planDetails['invoice_interval'])) {
            return;
        }

        try {
            $InvoiceInterval = ErpPlanUtils::parseIntervalFromDuration(
                $planDetails['invoice_interval']
            );

            $OneYearInterval = new DateInterval('P1Y');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return;
        }

        if (ErpPlanUtils::compareDateIntervals($InvoiceInterval, $OneYearInterval) === 1) {
            throw new PaymentCanNotBeUsed();
        }
    }
}
