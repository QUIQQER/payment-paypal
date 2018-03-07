<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;

/**
 * Execute PayPal payment for an Order
 *
 * @param string $paymentId - PayPal paymentID
 * @param string $payerId - PayPal payerID
 * @param string $orderHash - Unique order hash to identify Order
 * @return bool - success
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_executeOrder',
    function ($orderHash, $paymentId, $payerId) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new Payment();
            $Payment->executePayPalOrder($Order, $paymentId, $payerId);
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return true;
    },
    array('orderHash', 'paymentId', 'payerId')
);
