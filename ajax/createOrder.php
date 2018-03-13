<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;

/**
 * Create PayPal payment for an Order
 *
 * @param int $basketId - Basket ID
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_createOrder',
    function ($basketId) {
        $basketId = (int)$basketId;

        try {
            $Basket = Handler::getInstance()->getBasketById($basketId);
            $Basket->updateOrder();

            $Order   = $Basket->getOrder();
            $Payment = new Payment();
            $Payment->createPayPalOrder($Order);
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return [
            'payPalPaymentId' => $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_PAYMENT_ID),
            'hash'            => $Order->getHash()
        ];
    },
    ['basketId']
);