<?php

/**
 * Execute PayPal payment for an Order
 *
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws PayPalException
 */

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_expressCheckout',
    function ($orderHash) {
        if (!class_exists('QUI\ERP\Order\Handler')) {
            return false;
        }

        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new PaymentExpress();
            $Payment->capturePayPalOrder($Order);
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    ['orderHash']
);
