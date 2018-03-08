/**
 * PayPal JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/classes/PayPal', [

    'Ajax'

], function (QUIAjax) {
    "use strict";

    var pkg = 'quiqqer/payment-paypal';

    return new Class({

        Type: 'package/quiqqer/payment-paypal/bin/classes/PayPal',

        /**
         * Create PayPal Order
         *
         * @param {String} orderHash - Unique order hash
         * @return {Promise}
         */
        createOrder: function (orderHash) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_createOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    onError  : reject
                })
            });
        },

        /**
         * Execute PayPal Order
         *
         * @param {String} orderHash - Unique order hash
         * @param {String} paymentId - PayPal paymentID
         * @param {String} payerId - PayPal payerID
         * @return {Promise}
         */
        executeOrder: function (orderHash, paymentId, payerId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_executeOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    paymentId: paymentId,
                    payerId  : payerId,
                    onError  : reject
                })
            });
        }
    });
});