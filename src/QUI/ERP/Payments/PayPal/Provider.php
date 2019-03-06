<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Provider
 */

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Accounting\Payments\Api\AbstractPaymentProvider;
use QUI\ERP\Accounting\Payments\Types\Factory as PaymentsFactory;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;

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
            Payment::class,
            PaymentExpress::class,
            RecurringPayment::class
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

    /**
     * Get Payment setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getPaymentSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('payment', $setting);
    }

    /**
     * Get Widgets setting
     *
     * @param string $setting - Setting name
     * @return string|number|false
     */
    public static function getWidgetsSetting($setting)
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('widgets', $setting);
    }

    /**
     * Get PayPal Express payment
     *
     * @return QUI\ERP\Accounting\Payments\Types\Payment|false
     */
    public static function getPayPalExpressPayment()
    {
        $payments = PaymentsFactory::getInstance()->getChildren([
            'where' => ['payment_type' => PaymentExpress::class]
        ]);

        if (empty($payments)) {
            return false;
        }

        return current($payments);
    }

    /**
     * Check if the PayPal API settings are correct
     *
     * @return bool
     */
    public static function isApiSetUp()
    {
        try {
            $Conf        = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
            $apiSettings = $Conf->getSection('api');
        } catch (QUI\Exception $Exception) {
            return false;
        }

        foreach ($apiSettings as $k => $v) {
            switch ($k) {
                case 'sandbox':
                    continue 2;
                    break;
            }

            if (empty($v)) {
                QUI\System\Log::addError(
                    'Your PayPal API credentials seem to be (partially) missing.'
                    .' PayPal CAN NOT be used at the moment. Please enter all your'
                    .' API credentials. See https://dev.quiqqer.com/quiqqer/payment-paypal/wikis/api-configuration'
                    .' for further instructions.'
                );

                return false;
            }
        }

        return true;
    }
}
