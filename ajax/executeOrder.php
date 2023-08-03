<?php

/**
 * Execute PayPal payment for an Order
 *
 * @param string $orderHash - Unique order hash to identify Order
 * @param bool $express (optional) - PayPal Express flag
 * @return bool - success
 * @throws PayPalException
 */

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_executeOrder',
    function ($orderHash, $express = false) {
        $orderHash = Orthos::clear($orderHash);
        $express = boolval($express);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            /**
             * Authorization and capturing are only executed
             * if the user finalizes the Order by clicking
             * "Pay now" in the QUIQQER ERP Shop (not the PayPal popup)
             *
             * With Express checkout this step has not been completed yet here
             * so these operations are only executed here if it is a
             * normal PayPal checkout
             */
            if ($express) {
                $Payment = new PaymentExpress();
                $Payment->executePayPalOrder($Order);
            } else {
                $Payment = new Payment();
                $Payment->capturePayPalOrder($Order);
            }
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return true;
    },
    ['orderHash', 'express']
);
