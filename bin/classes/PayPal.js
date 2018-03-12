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
         * @param {Number} [basketId] - Basket ID
         * @return {Promise}
         */
        createOrder: function (orderHash, basketId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_createOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    basketId : basketId || 0,
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
         * @param {Boolean} [express] - PayPal express payment
         * @return {Promise}
         */
        executeOrder: function (orderHash, paymentId, payerId, express) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_executeOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    paymentId: paymentId,
                    payerId  : payerId,
                    express  : express ? 1 : 0,
                    onError  : reject
                })
            });
        }
    });
});