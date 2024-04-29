<?php

namespace QUI\ERP\Payments\PayPal;

use Exception;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\Users\User as QUIQQERUser;

use function mb_strtoupper;

class PaymentExpress extends Payment
{
    /**
     * Error codes
     */
    const PAYPAL_ERROR_NO_PAYER_ADDRESS = 'no_payer_address';
    const PAYPAL_ERROR_ADDRESS_NOT_VERIFIED = 'address_not_verified';

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment_express.title');
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getLocale()->get('quiqqer/payment-paypal', 'payment_express.description');
    }

    /**
     * Is the payment be visible in the frontend?
     * Every payment method can determine this by itself (API for developers)
     *
     * @param AbstractOrder $Order
     * @return bool
     */
    public function isVisible(AbstractOrder $Order): bool
    {
        $Payment = $Order->getPayment();

        if (empty($Payment)) {
            return false;
        }

        try {
            $PaymentType = $Payment->getPaymentType();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $PaymentType->getClass() === $this::getClass();
    }

    /**
     * This flag indicates whether the payment module can be created more than once
     *
     * @return bool
     */
    public function isUnique(): bool
    {
        return true;
    }

    /**
     * Set default shipping for express order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public function setDefaultShipping(AbstractOrder $Order): void
    {
        // Shipping address not required of shipping module not installed
        if (!QUI::getPackageManager()->isInstalled('quiqqer/shipping')) {
            return;
        }

        $DefaultExpressShipping = Utils::getDefaultExpressShipping($Order);

        if (!$DefaultExpressShipping) {
            return;
        }

        $Order->setShipping($DefaultExpressShipping);
        $this->saveOrder($Order);
    }

    /**
     * Execute a PayPal Order
     *
     * @param AbstractOrder $Order
     * @return void
     *
     * @throws PayPalException
     * @throws QUI\Exception
     */
    public function executePayPalOrder(AbstractOrder $Order): void
    {
        $payPalOrder = $this->getPayPalOrderDetails($Order);

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

        if (empty($payPalOrder['purchase_units'][0]['shipping'])) {
            $Order->addHistory(
                'PayPal Express :: Could not set invoice address because PayPal did not deliver address data'
            );

            $this->voidPayPalOrder($Order);
            $this->throwPayPalException(self::PAYPAL_ERROR_NO_PAYER_ADDRESS);
        }

        $Customer = $Order->getCustomer();
        $CustomerQuiqqerUser = QUI::getUsers()->get($Customer->getUUID());

        $InvoiceAddress = false;
        $PayPalQuiqqerAddress = $this->getQuiqqerAddressFromPayPalOrder($payPalOrder, $CustomerQuiqqerUser);

        // Check if $PayPalQuiqqerAddress already exists
        /** @var QUI\Users\Address $Address */
        foreach ($CustomerQuiqqerUser->getAddressList() as $Address) {
            if (
                $Address->getUUID() !== $PayPalQuiqqerAddress->getUUID() &&
                $Address->equals($PayPalQuiqqerAddress)
            ) {
                $InvoiceAddress = $Address;
                $PayPalQuiqqerAddress->delete();
                break;
            }
        }

        // Create new address with source "PayPal"
        if (!$InvoiceAddress) {
            $Order->addHistory(
                'PayPal Express :: PayPal Address found in QUIQQER User -> Adding PayPal QUIQQER Address'
            );

            $InvoiceAddress = $PayPalQuiqqerAddress;
            $StandardAddress = false;
            $SystemUser = QUI::getUsers()->getSystemUser();

            try {
                $StandardAddress = $CustomerQuiqqerUser->getStandardAddress();

                /*
                 * If user standard adress's name equals the PayPal address but the street
                 * address data is empty, then set PayPal adress data to standard address.
                 */
                if (
                    $StandardAddress->getAttribute('firstname') === $PayPalQuiqqerAddress->getAttribute('firstname') &&
                    $StandardAddress->getAttribute('lastname') === $PayPalQuiqqerAddress->getAttribute('lastname')
                ) {
                    $checkAttributes = [
                        'street_no',
                        'zip',
                        'city',
                        'country'
                    ];

                    $setPayPalAddressDataToStandardAddress = true;

                    foreach ($checkAttributes as $attribute) {
                        if (!empty($StandardAddress->getAttribute($attribute))) {
                            $setPayPalAddressDataToStandardAddress = false;
                            break;
                        }
                    }

                    if ($setPayPalAddressDataToStandardAddress) {
                        $StandardAddress->setAttributes([
                            'street_no' => $PayPalQuiqqerAddress->getAttribute('street_no'),
                            'zip' => $PayPalQuiqqerAddress->getAttribute('zip'),
                            'city' => $PayPalQuiqqerAddress->getAttribute('city'),
                            'country' => $PayPalQuiqqerAddress->getAttribute('country')
                        ]);

                        $StandardAddress->save(QUI::getUsers()->getSystemUser());

                        $PayPalQuiqqerAddress->delete();

                        $InvoiceAddress = $StandardAddress;
                    }
                }
            } catch (QUI\Users\Exception) {
                // nothing, no default address set
            } catch (Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }

            // Set the PayPal address as default address if no default address previously set
            if (!$StandardAddress) {
                $CustomerQuiqqerUser->setAttribute('address', $InvoiceAddress->getUUID());
                $CustomerQuiqqerUser->save($SystemUser);

                $Order->addHistory('PayPal Express :: PayPal QUIQQER Address set as default address');
            }

            $Order->addHistory('PayPal Express :: PayPal QUIQQER address successfully created');
        }

        $Order->setInvoiceAddress($InvoiceAddress);
        $ShippingAddress = new QUI\ERP\Address($InvoiceAddress->getAttributes(), $Customer);
        $Order->setDeliveryAddress($ShippingAddress);

        $Order->addHistory(
            'PayPal Express :: Order invoice address set (QUIQQER address ID: #' . $InvoiceAddress->getUUID() . ')'
        );

        $this->saveOrder($Order);
        $this->setDefaultShipping($Order);

        $this->updatePayPalOrder($Order);
    }

    /**
     * Parse a QUIQQER Address from PayPal payer info
     *
     * Due to the nature of the QUIQQER Address API this method has to create an actual
     * User address in the database. If you do not need the address this method returns later on,
     * delete it by calling $Address->delete()
     *
     * @param array $payPalOrder
     * @param QUIQQERUser $QuiqqerUser
     * @return QUI\Users\Address
     *
     * @throws QUI\Exception
     */
    protected function getQuiqqerAddressFromPayPalOrder(array $payPalOrder, QUIQQERUser $QuiqqerUser): QUI\Users\Address
    {
        $SystemUser = QUI::getUsers()->getSystemUser();

        // Create Address
        $shipping = $payPalOrder['purchase_units'][0]['shipping'];
        $streetParts = [];

        if (!empty($shipping['address']['address_line_1'])) {
            $streetParts[] = $shipping['address']['address_line_1'];
        }

        if (!empty($shipping['address']['address_line_2'])) {
            $streetParts[] = $shipping['address']['address_line_2'];
        }

        $city = !empty($shipping['address']['admin_area_2']) ? $shipping['address']['admin_area_2'] : '';

        if (!empty($shipping['address']['admin_area_1'])) {
            $city .= ', ' . $shipping['address']['admin_area_1'];
        }

        $Address = $QuiqqerUser->addAddress([
            'firstname' => !empty($payPalOrder['payer']['name']['given_name']) ? $payPalOrder['payer']['name']['given_name'] : '',
            'lastname' => !empty($payPalOrder['payer']['name']['surname']) ? $payPalOrder['payer']['name']['surname'] : '',
            'street_no' => implode(' ', $streetParts),
            'zip' => !empty($shipping['address']['postal_code']) ? $shipping['address']['postal_code'] : '',
            'city' => $city,
            'country' => !empty($shipping['address']['country_code']) ? mb_strtoupper(
                $shipping['address']['country_code']
            ) : ''
        ], $SystemUser);

        if (!empty($payPalOrder['payer']['email_address'])) {
            $Address->addMail($payPalOrder['payer']['email_address']);
        }

        $Address->setCustomDataEntry('source', 'PayPal');
        $Address->save($SystemUser);

        // reload Address from DB to set correct attributes
        return new QUI\Users\Address($QuiqqerUser, $Address->getUUID());
    }

    /**
     * If the Payment method is a payment gateway, it can return a gateway display
     *
     * @param AbstractOrder $Order
     * @param QUI\ERP\Order\Controls\OrderProcess\Processing $Step
     * @return string
     *
     * @throws QUI\Exception|Exception
     */
    public function getGatewayDisplay(AbstractOrder $Order, $Step = null): string
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
