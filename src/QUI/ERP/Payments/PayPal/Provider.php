<?php

/**
 * This file contains QUI\ERP\Payments\PayPal\Provider
 */

namespace QUI\ERP\Payments\PayPal;

use Exception;
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
    public function getPaymentTypes(): array
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
     * @return bool|string
     */
    public static function getApiSetting(string $setting): bool|string
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('api', $setting);
    }

    /**
     * Get Payment setting
     *
     * @param string $setting - Setting name
     * @return bool|string
     */
    public static function getPaymentSetting(string $setting): bool|string
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('payment', $setting);
    }

    /**
     * Get Widgets setting
     *
     * @param string $setting - Setting name
     * @return array|bool|string
     */
    public static function getWidgetsSetting(string $setting): bool|array|string
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return false;
        }

        return $Conf->get('widgets', $setting);
    }

    /**
     * Get PayPal Express payment
     *
     * @return QUI\ERP\Accounting\Payments\Types\Payment|false
     * @throws QUI\Database\Exception
     */
    public static function getPayPalExpressPayment(): QUI\ERP\Accounting\Payments\Types\Payment|bool
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
    public static function isApiSetUp(): bool
    {
        try {
            $Conf = QUI::getPackage('quiqqer/payment-paypal')->getConfig();
            $apiSettings = $Conf->getSection('api');
        } catch (QUI\Exception $Exception) {
            return false;
        }

        $isSetup = true;

        if ($apiSettings['sandbox']) {
            if (empty($apiSettings['sandbox_client_id']) || empty($apiSettings['sandbox_client_secret'])) {
                $isSetup = false;
            }
        } else {
            if (empty($apiSettings['client_id']) || empty($apiSettings['client_secret'])) {
                $isSetup = false;
            }
        }

        if (!$isSetup) {
            QUI\System\Log::addError(
                'Your PayPal API credentials seem to be (partially) missing.'
                . ' PayPal CAN NOT be used at the moment. Please enter all your'
                . ' API credentials. See https://dev.quiqqer.com/quiqqer/payment-paypal/wikis/api-configuration'
                . ' for further instructions.'
            );

            return false;
        }

        return true;
    }
}
