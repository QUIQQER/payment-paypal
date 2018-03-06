<?php

/**
 * This file contains QUI\ERP\Payments\Example\PaymentDisplay
 */

namespace QUI\ERP\Payments\PayPal;

use QUI;

/**
 * Class PaymentDisplay
 *
 * Display PayPal payment process
 */
class PaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->addCSSFile(dirname(__FILE__) . '/PaymentDisplay.css');

        $this->setJavaScriptControl('package/quiqqer/payment-paypal/bin/controls/PaymentDisplay');
        $this->setJavaScriptControlOption('sandbox', boolval(Provider::getApiSetting('sandbox')));
//        $this->setJavaScriptControlOption('clientid', Provider::getApiSetting('merchant_id'));
//        $this->setJavaScriptControlOption('clientid', Provider::getApiSetting('client_id'));
    }

    /**
     * Return the body of the control
     * Here you can integrate the payment form, or forwarding functionality to the gateway
     *
     * @return string
     * @throws QUI\Exception
     */
    public function getBody()
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        /* @var $Order QUI\ERP\Order\OrderInProcess */
        $Order            = $this->getAttribute('Order');
        $PriceCalculation = $Order->getPriceCalculation();

        /** @var Payment $Payment */
        $Payment = $Order->getPayment()->getPaymentType();

        if (!$Payment->isPayPalOrderCreated($Order)) {
            $Payment->createPayPalOrder($Order);
        }

        $Engine->assign([
            'display_price'     => $PriceCalculation->getSum()->formatted(),
            'apiSetUp'          => $this->isApiSetUp(),
            'payPalOrderId'     => $Order->getPaymentDataEntry($Payment::ATTR_PAYPAL_ORDER_ID),
            'payPalApprovalUrl' => $Order->getPaymentDataEntry($Payment::ATTR_PAYPAL_APPROVAL_URL)
        ]);

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        // Check if an PayPal authorization already exists (i.e. Order is successful / can be processed)
        $this->setJavaScriptControlOption('successful', $Order->isSuccessful());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }

    /**
     * Check if the PayPal API settings are correct
     *
     * @return bool
     * @throws QUI\Exception
     */
    protected function isApiSetUp()
    {
        $Conf        = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        $apiSettings = $Conf->getSection('api');

        foreach ($apiSettings as $k => $v) {
            if (empty($v)) {
                QUI\System\Log::addError(
                    'Your PayPal API credentials seem to be (partially) missing.'
                    . ' PayPal CAN NOT be used at the moment. Please enter all your'
                    . ' API credentials. See https://dev.quiqqer.com/quiqqer/payment-paypal/wikis/api-configuration'
                    . ' for further instructions.'
                );

                return false;
            }
        }

        return true;
    }
}
