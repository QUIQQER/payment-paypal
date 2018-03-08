<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Order\AbstractOrder;

class PaymentExpress extends Payment
{
    /**
     * Error codes
     */
    const PAYPAL_ERROR_NO_PAYER_ADDRESS = 'no_payer_address';

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment_express.title');
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment_express.description');
    }

    /**
     * Is the payment be visible in the frontend?
     * Every payment method can determine this by itself (API for developers)
     *
     * @return bool
     */
    public function isVisible()
    {
        return false;
    }

    /**
     * Execute a PayPal Order
     *
     * @param string $paymentId - The paymentID from the user authorization of the Order
     * (this is used to verify if the QUIQQER ERP Order is actually the PayPal Order that is executed here)
     * @param string $payerId - The payerID from the user authorization of the Order
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\ERP\Exception
     * @throws QUI\Exception
     */
    public function executePayPalOrder(AbstractOrder $Order, $paymentId, $payerId)
    {
        parent::executePayPalOrder($Order, $paymentId, $payerId);

        $payerData = $Order->getPaymentDataEntry(self::ATTR_PAYPAL_PAYER_DATA);
        $payerInfo = $payerData['payer_info'];

        if (empty($payerInfo['shipping_address'])) {
            $this->throwPayPalException(self::PAYPAL_ERROR_NO_PAYER_ADDRESS);
        }
    }
}
