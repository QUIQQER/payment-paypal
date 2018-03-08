/**
 * PayPal JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/PayPal', [

    'package/quiqqer/payment-paypal/bin/classes/PayPal'

], function (PayPalApi) {
    "use strict";
    return new PayPalApi();
});