<?php

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\Utils\Security\Orthos;

/**
 * Create PayPal payment for an Order
 *
 * @param string $orderHash - Unique order hash to identify Order
 * @return string - PayPal Order/Payment ID
 * @throws PayPalException
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_createOrder',
    function ($orderHash) {
        $orderHash = Orthos::clear($orderHash);

        try {
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            // @todo entfernen
            $CurrentInvoiceAdress = $Order->getInvoiceAddress();
            \QUI\System\Log::writeRecursive($CurrentInvoiceAdress);

            $Payment = new Payment();
            $Payment->createPayPalOrder($Order);
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_PAYMENT_ID);
    },
    array('orderHash')
);
