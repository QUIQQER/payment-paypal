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
     * @throws QUI\ERP\Order\ProcessingException
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->addCSSFile(dirname(__FILE__) . '/PaymentDisplay.css');

        $this->addCSSClass('quiqqer-payment-paypal');
        $this->setJavaScriptControl('package/quiqqer/payment-paypal/bin/controls/PaymentDisplay');
        $this->setJavaScriptControlOption('sandbox', boolval(Provider::getApiSetting('sandbox')));

//        if (Provider::isApiSetUp() === false) {
//            throw new QUI\ERP\Order\ProcessingException([
//                'quiqqer/payment-paypal',
//                'exception.message.missing.setup'
//            ]);
//        }
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
        $Order = $this->getAttribute('Order');
        $PriceCalculation = $Order->getPriceCalculation();

        $Engine->assign([
            'display_price' => $PriceCalculation->getSum()->formatted(),
            'apiSetUp' => Provider::isApiSetUp(),
            'btn_size' => Provider::getWidgetsSetting('btn_size'),
            'btn_color' => Provider::getWidgetsSetting('btn_color'),
            'btn_shape' => Provider::getWidgetsSetting('btn_shape')
        ]);

        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        // Check if an PayPal authorization already exists (i.e. Order is successful / can be processed)
        $this->setJavaScriptControlOption('successful', $Order->isSuccessful());

        return $Engine->fetch(dirname(__FILE__) . '/PaymentDisplay.html');
    }
}
