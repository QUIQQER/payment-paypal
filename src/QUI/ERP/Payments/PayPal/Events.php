<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Accounting\Payments\Gateway\Gateway;
use AmazonPay\IpnHandler;
use QUI\ERP\Order\Handler as OrderHandler;

/**
 * Class Events
 *
 * Global Event Handler for quiqqer/payment-paypal
 */
class Events
{
    /**
     * quiqqer/payments: onPaymentsGatewayReadRequest
     *
     * Read request to the central payment gateway and check
     * if it is PayPal request
     *
     * @param Gateway $Gateway
     * @return void
     */
    public static function onPaymentsGatewayReadRequest(Gateway $Gateway)
    {
        $headers = getallheaders();
        $body    = file_get_contents('php://input');

        \QUI\System\Log::writeRecursive($headers);
        \QUI\System\Log::writeRecursive($body);
        \QUI\System\Log::writeRecursive($_REQUEST);

        // now the Gateway can call executeGatewayPayment() of the
        // payment method that is assigned to the Order
    }

    /**
     * quiqqer/order: onQuiqqerOrderSuccessful
     *
     * Check if funds have to be captured as soon as the order is successful
     *
     * @param QUI\ERP\Order\AbstractOrder $Order
     * @return void
     */
    public static function onQuiqqerOrderSuccessful(QUI\ERP\Order\AbstractOrder $Order)
    {
//        // determine if payment has to be captured now or later
//        $articleType = Provider::getPaymentSetting('article_type');
//        $capture     = false;
//
//        switch ($articleType) {
//            case Payment::SETTING_ARTICLE_TYPE_PHYSICAL:
//                // later
//                break;
//            case Payment::SETTING_ARTICLE_TYPE_DIGITAL:
//                // now
//                $capture = true;
//                break;
//
//            default:
//                $capture = true;
//            // determine by order article type
//            // @todo
//        }
//
//        if (!$capture) {
//            return;
//        }
//
//        try {
//            $Payment = new Payment();
//            $Payment->capturePayment($Order);
//        } catch (AmazonPayException $Exception) {
//            // nothing, capturePayment() marks Order as problematic
//        } catch (\Exception $Exception) {
//            QUI\System\Log::writeException($Exception);
//        }
    }
}
