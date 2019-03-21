<?php

/**
 * This file contains QUI\ERP\Payments\Example\ExpressPaymentDisplay
 */

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI;
use QUI\ERP\Order\Utils\Utils as OrderUtils;
use QUI\ERP\Order\Controls\OrderProcess\Finish as FinishStep;
use QUI\ERP\Accounting\Payments\Order\Payment as PaymentStep;
use QUI\ERP\Payments\PayPal\Provider;

/**
 * Class PaymentDisplay
 *
 * Display PayPal Billing payment process
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

        $this->setJavaScriptControl('package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay');

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
        $Order  = $this->getAttribute('Order');
        $this->setJavaScriptControlOption('orderhash', $Order->getHash());

        $Engine->assign([
            'apiSetUp' => Provider::isApiSetUp()
        ]);

        return $Engine->fetch(dirname(__FILE__).'/PaymentDisplay.html');
    }
}
