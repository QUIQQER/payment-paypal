<?php

/**
 * This file contains QUI\ERP\Payments\Example\ExpressPaymentDisplay
 */

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Order\Utils\Utils as OrderUtils;
use QUI\ERP\Order\Controls\OrderProcess\Finish as FinishStep;
use QUI\ERP\Accounting\Payments\Order\Payment as PaymentStep;


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

//        $this->addCSSFile(dirname(__FILE__) . '/ExpressPaymentDisplay.css');
        $this->setJavaScriptControl('package/quiqqer/payment-paypal/bin/controls/ExpressPaymentDisplay');
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
        $Order  = $this->getAttribute('Order');
        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        $Engine->assign([
            'apiSetUp' => Provider::isApiSetUp(),
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/ExpressPaymentDisplay.html');
    }
}
