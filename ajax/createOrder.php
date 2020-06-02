<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;

/**
 * Create PayPal payment for an Order
 *
 * @param string $orderHash
 * @param int $basketId - Basket ID
 * @param bool $express (optional) - PayPal Express flag
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_createOrder',
    function ($orderHash, $basketId, $express = false) {
        try {
            if (!empty($orderHash)) {
                $orderHash = Orthos::clear($orderHash);
                $Order     = Handler::getInstance()->getOrderByHash($orderHash);
            } elseif (!empty($basketId)) {
                $Basket = new QUI\ERP\Order\Basket\Basket((int)$basketId);

                $Order = Handler::getInstance()->getLastOrderInProcessFromUser(
                    QUI::getUserBySession()
                );

                $Basket->toOrder($Order);
            } else {
                return false;
            }

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
    ['orderHash', 'basketId']
);
