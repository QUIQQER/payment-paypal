<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use Quiqqer\Engine\Collector;
use QUI\ERP\Order\Basket\Basket;
use QUI\ERP\Order\Basket\BasketGuest;
use QUI\ERP\Order\Utils\Utils as OrderUtils;
use QUI\ERP\Order\Controls\OrderProcess\Checkout as CheckoutStep;

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
        if (!Provider::getPaymentSetting('display_express_basket')) {
            return;
        }

        if (!($Basket instanceof Basket)) {
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
                  data-qui-options-basketid="' . $Basket->getId() . '"
                  data-qui-options-checkout="' . $checkout . '"
                  data-qui-options-orderprocessurl="' . OrderUtils::getOrderProcessUrl($Project, $CheckoutStep) . '">
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
        if (!Provider::getPaymentSetting('display_express_smallbasket')) {
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
                  data-qui-options-basketid="' . $Basket->getId() . '"
                  data-qui-options-checkout="' . $checkout . '"
                  data-qui-options-orderprocessurl="' . OrderUtils::getOrderProcessUrl($Project, $CheckoutStep) . '">
            </div>'
        );
    }
}
