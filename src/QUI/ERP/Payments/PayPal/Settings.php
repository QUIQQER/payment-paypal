<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;

/**
 * PayPal package settings.
 */
class Settings extends QUI\Utils\Singleton
{
    /**
     * Return the required PayPal package configuration.
     *
     * @throws QUI\Exception
     */
    public static function getConfig(): QUI\Config
    {
        $Config = QUI::getPackage('quiqqer/payment-paypal')->getConfig();

        if ($Config === null) {
            throw new QUI\Exception('PayPal configuration is not available.');
        }

        return $Config;
    }
}
