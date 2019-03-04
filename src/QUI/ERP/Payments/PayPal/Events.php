<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use Quiqqer\Engine\Collector;
use QUI\ERP\Order\Basket\Basket;
use QUI\ERP\Order\Basket\BasketGuest;
use QUI\ERP\Order\Utils\Utils as OrderUtils;
use QUI\ERP\Order\Controls\OrderProcess\Checkout as CheckoutStep;
use QUI\ERP\Accounting\Payments\Exceptions\PaymentCanNotBeUsed;

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
     * @param BasketGuest $Basket
     * @return void
     *
     * @throws QUI\Exception
     */
    public static function templateOrderProcessBasketEnd(Collector $Collector, $Basket)
    {
        $PaymentExpress = Provider::getPayPalExpressPayment();

        if (!$PaymentExpress || !$PaymentExpress->isActive()) {
            return;
        }

        if (!($Basket instanceof Basket)
            && !($Basket instanceof QUI\ERP\Order\Basket\BasketOrder)
        ) {
            return;
        }

        $Project      = QUI::getProjectManager()->getStandard();
        $CheckoutStep = new CheckoutStep();
        $checkout     = 0;

        if ($Basket->hasOrder()) {
            $Order = $Basket->getOrder();

            if ($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_PAYMENT_ID)) {
                $checkout = 1;
            }
        }

        $Collector->append(
            '<div data-qui="package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader"
                  data-qui-options-context="basket"
                  data-qui-options-basketid="'.$Basket->getId().'"
                  data-qui-options-checkout="'.$checkout.'"
                  data-qui-options-displaysize="'.Provider::getWidgetsSetting('btn_express_size').'"
                  data-qui-options-displaycolor="'.Provider::getWidgetsSetting('btn_express_color').'"
                  data-qui-options-displayshape="'.Provider::getWidgetsSetting('btn_express_shape').'"
                  data-qui-options-orderprocessurl="'.OrderUtils::getOrderProcessUrl($Project, $CheckoutStep).'">
            </div>'
        );
    }

    /**
     * Template event quiqqer/order: onQuiqqer::order::basketSmall::end
     *
     * @param Collector $Collector
     * @param BasketGuest $Basket
     * @return void
     *
     * @throws QUI\Exception
     */
    public static function templateOrderBasketSmallEnd(Collector $Collector, $Basket)
    {
        $PaymentExpress = Provider::getPayPalExpressPayment();

        if (!$PaymentExpress || !$PaymentExpress->isActive()) {
            return;
        }

        if (!($Basket instanceof Basket)) {
            return;
        }

        // do not display PayPal button if basket has no articles
        if (!$Basket->count()) {
            return;
        }

        $Project      = QUI::getProjectManager()->getStandard();
        $CheckoutStep = new CheckoutStep();
        $checkout     = 0;

        if ($Basket->hasOrder()) {
            $Order = $Basket->getOrder();

            if ($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_PAYMENT_ID)) {
                $checkout = 1;
            }
        }

        $Collector->append(
            '<div data-qui="package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader"
                  data-qui-options-context="smallbasket"
                  data-qui-options-basketid="'.$Basket->getId().'"
                  data-qui-options-checkout="'.$checkout.'"
                  data-qui-options-displaysize="'.Provider::getWidgetsSetting('btn_express_size_smallbasket').'"
                  data-qui-options-displaycolor="'.Provider::getWidgetsSetting('btn_express_color').'"
                  data-qui-options-displayshape="'.Provider::getWidgetsSetting('btn_express_shape').'"
                  data-qui-options-orderprocessurl="'.OrderUtils::getOrderProcessUrl($Project, $CheckoutStep).'">
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
    public static function onPaymentsCreateBegin($paymentClass)
    {
        if ($paymentClass === QUI\ERP\Payments\PayPal\Recurring\Payment::class
            && !QUI::getPackageManager()->isInstalled('quiqqer/erp-plans')) {
            throw new PaymentCanNotBeUsed(
                QUI::getLocale()->get(
                    'quiqqer/payments-paypal',
                    'exception.onPaymentsCreateBegin.erp_plans_missing'
                )
            );
        }
    }
}
