<?php

/**
 * Get PayPal API client ID
 *
 * @return string|false - Client ID or false on error
 * @throws PayPalException
 */

use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Provider;

QUI::$Ajax->registerFunction(
    'package_quiqqer_payment-paypal_ajax_getClientId',
    function () {
        try {
            if (Provider::getApiSetting('sandbox')) {
                return Provider::getApiSetting('sandbox_client_id');
            } else {
                return Provider::getApiSetting('client_id');
            }
        } catch (PayPalException $Exception) {
            throw $Exception;
        } catch (\Exception $Exception) {
            QUI\System\Log::writeException($Exception);
            return false;
        }
    },
    []
);
