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
         * @param {Boolean} [express] - PayPal express payment
         * @return {Promise}
         */
        createOrder: function (orderHash, basketId, express) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_createOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    basketId : basketId || 0,
                    express  : express ? 1 : 0,
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
        },

        /**
         * Create PayPal Billing Agreement for Order
         *
         * @param {String} orderHash - Unique order hash
         * @return {Promise}
         */
        createBillingAgreement: function (orderHash) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_createBillingAgreement', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    onError  : reject
                })
            });
        },

        /**
         * Create PayPal Order
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getBillingPlans: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingPlans', resolve, {
                    'package'   : pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                })
            });
        },

        /**
         * Create PayPal Order
         *
         * @param {String} billingPlanId - PayPal Billing Plan ID
         * @return {Promise}
         */
        deleteBillingPlan: function (billingPlanId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_deleteBillingPlan', resolve, {
                    'package'    : pkg,
                    billingPlanId: billingPlanId,
                    onError      : reject
                })
            });
        },

        /**
         * Get PayPal Billing Agreement details
         *
         * @param {String} billingAgreementId - PayPal Billing Agreement ID
         * @return {Promise}
         */
        getBillingAgreement: function (billingAgreementId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreement', resolve, {
                    'package'         : pkg,
                    billingAgreementId: billingAgreementId,
                    onError           : reject
                })
            });
        },

        /**
         * Get PayPal Billing Agreement list
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getBillingAgreementList: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreementList', resolve, {
                    'package'   : pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                })
            });
        },

        /**
         * Cancel a PayPal Billing Agreement
         *
         * @param {String} billingAgreementId - PayPal Billing Agreement ID
         * @return {Promise}
         */
        cancelBillingAgreement: function (billingAgreementId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_cancelBillingAgreement', resolve, {
                    'package'         : pkg,
                    billingAgreementId: billingAgreementId,
                    onError           : reject
                })
            });
        }
    });
});