<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Order\AbstractOrder;

/**
 * Class Utils
 *
 * Utility methods for quiqqer/payment-paypal
 */
class Utils
{
    /**
     * Format a price for PayPal API use
     *
     * @param float $amount
     * @return string - Amount with trailing zeroes
     */
    public static function formatPrice($amount)
    {
        $AmountValue     = new CalculationValue($amount, null, 2);
        $amount          = $AmountValue->get();
        $formattedAmount = sprintf("%.2f", $amount);

        if (mb_strpos($formattedAmount, '.00') !== false) {
            return (string)(float)$formattedAmount;
        }

        return $formattedAmount;
    }

    /**
     * Get base URL (with host) for current Project
     *
     * @return string
     */
    public static function getProjectUrl()
    {
        try {
            $url = QUI::getRewrite()->getProject()->get(1)->getUrlRewrittenWithHost();
            return rtrim($url, '/');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return '';
        }
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     */
    public static function saveOrder(AbstractOrder $Order)
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Get translated history text
     *
     * @param string $context
     * @param array $data (optional) - Additional data for translation
     * @return string
     */
    public static function getHistoryText(string $context, $data = [])
    {
        return QUI::getLocale()->get('quiqqer/payment-paypal', 'history.'.$context, $data);
    }
}
