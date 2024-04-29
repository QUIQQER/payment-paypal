<?php

/**
 * This file contains QUI\ERP\Payments\Example\ExpressPaymentDisplay
 */

namespace QUI\ERP\Payments\PayPal;

use QUI;

/**
 * Class ExpressPaymentDisplay
 *
 * Display PayPal Express payment process (just a loader and info)
 */
class ExpressPaymentDisplay extends QUI\Control
{
    /**
     * Constructor
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setJavaScriptControl('package/quiqqer/payment-paypal/bin/controls/ExpressPaymentDisplay');
    }

    /**
     * Return the body of the control
     * Here you can integrate the payment form, or forwarding functionality to the gateway
     *
     * @return string
     * @throws QUI\Exception
     */
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $Order = $this->getAttribute('Order');
        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        $Engine->assign([
            'apiSetUp' => Provider::isApiSetUp(),
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/ExpressPaymentDisplay.html');
    }
}
