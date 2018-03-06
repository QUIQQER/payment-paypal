<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Provider
 */

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Accounting\Payments\Api\AbstractPaymentProvider;

/**
 * Class Provider
 *
 * PaymentProvider class for PayPal
 */
class Provider extends AbstractPaymentProvider
{
    /**
     * @return array
     */
    public function getPaymentTypes()
    {
        return [
            Payment::class
        ];
    }

    /**
     * Get API setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getApiSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }

        return $Conf->get('api', $setting);
    }
}
