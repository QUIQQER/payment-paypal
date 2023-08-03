<?php

/**
 * Create PayPal billing plans and agreements for plan orders
 *
 * @param int $basketId - Basket ID
 * @return array - PayPal Order/Payment ID and Order hash
 * @throws PayPalException
 */

use QUI\ERP\Order\Handler;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\Utils\Security\Orthos;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_recurring_createBillingAgreement',
    function ($orderHash) {
        try {
            $orderHash = Orthos::clear($orderHash);
            $Order = Handler::getInstance()->getOrderByHash($orderHash);

            $Payment = new RecurringPayment();
            $approvalUrl = $Payment->createSubscription($Order);
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return [
            'approvalUrl' => $approvalUrl,
            'hash' => $Order->getHash()
        ];
    },
    ['orderHash']
);
