<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use Quiqqer\Engine\Collector;
use QUI\ERP\Order\Basket\Basket;

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
     * @param Basket $Basket
     * @return void
     *
     * @throws QUI\Exception
     */
    public static function templateOrderProcessBasketEnd(Collector $Collector, Basket $Basket)
    {
        if (!Provider::getPaymentSetting('show_express_btn')) {
            return;
        }

        $Basket->updateOrder();

        $Collector->append(
            '<div data-qui="package/quiqqer/payment-paypal/bin/controls/ExpressBtnLoader"
                  data-qui-options-context="basket"
                  data-qui-options-baskethash="' . $Basket->getHash() . '">
            </div>'
        );
    }
}
