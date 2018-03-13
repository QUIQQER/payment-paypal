<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Order\AbstractOrder;

class PaymentExpress extends Payment
{
    /**
     * Error codes
     */
    const PAYPAL_ERROR_NO_PAYER_ADDRESS     = 'no_payer_address';
    const PAYPAL_ERROR_ADDRESS_NOT_VERIFIED = 'address_not_verified';

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
     * This flag indicates whether the payment module can be created more than once
     *
     * @return bool
     */
    public function isUnique()
    {
        return true;
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

        /**
         * SET ORDER PAYMENT
         */
        $Order->addHistory('PayPal Express :: Set Order Payment');

        $Payment = Provider::getPayPalExpressPayment();

        if (!$Payment) {
            $Order->addHistory(
                'PayPal Express :: Could not set Order Payment because a PayPal Express'
                . ' payment method does not exist'
            );

            $this->voidPayPalOrder($Order);
            $this->throwPayPalException();
        }

        $Order->setPayment($Payment->getId());
        $Order->addHistory(
            'PayPal Express :: Order Payment successfully set (Payment ID: #' . $Payment->getId() . ')'
        );

        /**
         * SET ORDER INVOICE ADDRESS
         */
        $Order->addHistory('PayPal Express :: Set Order invoice address');

        if (empty($payerInfo['shipping_address'])) {
            $Order->addHistory(
                'PayPal Express :: Could not set invoice address because PayPal did not deliver address data'
            );

            $this->voidPayPalOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_NO_PAYER_ADDRESS);
        }

        $payPalAddressData = $payerInfo['shipping_address'];

        // do not allow checkout with addresses that are not yet confirmed by PayPal
        if (empty($payerData['status'])
            || $payerData['status'] !== 'VERIFIED') {
            $Order->addHistory(
                'PayPal Express :: Could not set invoice address because PayPal account was not VERIFIED'
            );

            $this->voidPayPalOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_ADDRESS_NOT_VERIFIED);
        }

        $Customer            = $Order->getCustomer();
        $CustomerQuiqqerUser = QUI::getUsers()->get($Customer->getId());

        $PayPalAddress = false;

        /** @var QUI\Users\Address $Address */
        foreach ($CustomerQuiqqerUser->getAddressList() as $Address) {
            if ($Address->getCustomDataEntry('source') === 'PayPal') {
                $PayPalAddress = $Address;
                break;
            }
        }

        // Create new address with source "PayPal"
        if (!$PayPalAddress) {
            $Order->addHistory(
                'PayPal Express :: No PayPal QUIQQER Address found in QUIQQER User -> Adding PayPal QUIQQER Address'
            );

            $SystemUser  = QUI::getUsers()->getSystemUser();
            $streetParts = [];

            if (!empty($payPalAddressData['line1'])) {
                $streetParts[] = $payPalAddressData['line1'];
            }

            if (!empty($payPalAddressData['line2'])) {
                $streetParts[] = $payPalAddressData['line2'];
            }

            $PayPalAddress = $CustomerQuiqqerUser->addAddress([
                'salutation' => !empty($payerInfo['salutation']) ? $payerInfo['salutation'] : '',
                'firstname'  => !empty($payerInfo['first_name']) ? $payerInfo['first_name'] : '',
                'lastname'   => !empty($payerInfo['last_name']) ? $payerInfo['last_name'] : '',
                'mail'       => !empty($payerInfo['email']) ? $payerInfo['email'] : '',
                'street_no'  => implode(' ', $streetParts),
                'zip'        => !empty($payPalAddressData['postal_code']) ? $payPalAddressData['postal_code'] : '',
                'city'       => !empty($payPalAddressData['city']) ? $payPalAddressData['city'] : '',
                'country'    => !empty($payPalAddressData['country_code']) ? mb_strtoupper($payPalAddressData['country_code']) : ''
            ], $SystemUser);

            $userPrimaryAddressId = false;

            try {
                $userPrimaryAddressId = $CustomerQuiqqerUser->getStandardAddress()->getId();
            } catch (QUI\Users\Exception $Exception) {
                // nothing, no default address set
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            // Set the PayPal address as default address if no default address previously set
            if (!$userPrimaryAddressId) {
                $CustomerQuiqqerUser->setAttribute('address', $userPrimaryAddressId);
                $CustomerQuiqqerUser->save($SystemUser);

                $Order->addHistory('PayPal Express :: PayPal QUIQQER Address set as default address');
            }

            if (!empty($payPalAddressData['phone'])) {
                $PayPalAddress->addPhone([
                    'type' => 'tel',
                    'no'   => $payPalAddressData['phone']
                ]);
            }

            $PayPalAddress->setCustomDataEntry('source', 'PayPal');
            $PayPalAddress->save($SystemUser);

            $Order->addHistory('PayPal Express :: PayPal QUIQQER address successfully created');
        }

        $Order->setInvoiceAddress($PayPalAddress);
        $Order->addHistory(
            'PayPal Express :: Order invoice address set (QUIQQER address ID: #' . $PayPalAddress->getId() . ')'
        );

        $this->saveOrder($Order);
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
     *
     * @throws QUI\Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null)
    {
        $Control = new ExpressPaymentDisplay();
        $Control->setAttribute('Order', $Order);

        $Step->setTitle(
            QUI::getLocale()->get(
                'quiqqer/payment-paypal',
                'payment.step.title'
            )
        );

        $Engine = QUI::getTemplateManager()->getEngine();
        $Step->setContent($Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.Header.html'));

        return $Control->create();
    }
}
