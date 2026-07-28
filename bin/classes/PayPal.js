/**
 * PayPal JavaScript API
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/classes/PayPal', [

    'Ajax'

], function(QUIAjax) {
    'use strict';

    const pkg = 'quiqqer/payment-paypal';

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
        createOrder: function(orderHash, basketId, express) {
            return new Promise(function(resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_createOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    basketId: basketId || 0,
                    express: express ? 1 : 0,
                    onError: reject
                });
            });
        },

        /**
         * Execute PayPal Order
         *
         * @param {String} orderHash - Unique order hash
         * @param {Boolean} [express] - PayPal express payment
         * @return {Promise}
         */
        executeOrder: function(orderHash, express) {
            return new Promise(function(resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_executeOrder', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    express: express ? 1 : 0,
                    onError: reject
                });
            });
        },

        /**
         * Create PayPal Billing Agreement for Order
         *
         * @param {String} orderHash - Unique order hash
         * @return {Promise}
         */
        createBillingAgreement: function(orderHash) {
            return new Promise(function(resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_createBillingAgreement', resolve, {
                    'package': pkg,
                    orderHash: orderHash,
                    onError: reject
                });
            });
        },

        /**
         * Create PayPal Order
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getBillingPlans: function(SearchParams) {
            return new Promise(function(resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingPlans', resolve, {
                    'package': pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError: reject
                });
            });
        },

        /**
         * Create PayPal Order
         *
         * @param {String} billingPlanId - PayPal Billing Plan ID
         * @return {Promise}
         */
        deleteBillingPlan: function(billingPlanId) {
            return new Promise(function(resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_deleteBillingPlan', resolve, {
                    'package': pkg,
                    billingPlanId: billingPlanId,
                    onError: reject
                });
            });
        },

        /**
         * Get PayPal Billing Agreement details
         *
         * @param {String} billingAgreementId - PayPal Billing Agreement ID
         * @return {Promise}
         */
        getBillingAgreement: function(billingAgreementId) {
            return new Promise(function(resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreement', resolve, {
                    'package': pkg,
                    billingAgreementId: billingAgreementId,
                    onError: reject
                });
            });
        },

        /**
         * Get PayPal Billing Agreement list
         *
         * @param {Object} SearchParams - Grid search params
         * @return {Promise}
         */
        getBillingAgreementList: function(SearchParams) {
            return new Promise(function(resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_recurring_getBillingAgreementList', resolve, {
                    'package': pkg,
                    searchParams: JSON.encode(SearchParams),
                    onError: reject
                });
            });
        },

        /**
         * Cancel a PayPal Billing Agreement
         *
         * @param {String} billingAgreementId - PayPal Billing Agreement ID
         * @return {Promise}
         */
        cancelBillingAgreement: function(billingAgreementId) {
            return new Promise(function(resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_recurring_cancelBillingAgreement', resolve, {
                    'package': pkg,
                    billingAgreementId: billingAgreementId,
                    onError: reject
                });
            });
        },

        /**
         * Get locally known PayPal Subscriptions.
         *
         * @param {Object} searchParams - Grid search parameters
         * @return {Promise}
         */
        getSubscriptionList: function(searchParams) {
            return new Promise((resolve, reject) => {
                QUIAjax.get(
                    'package_quiqqer_payment-paypal_ajax_recurring_getSubscriptionList',
                    resolve,
                    {
                        'package': pkg,
                        searchParams: JSON.encode(searchParams),
                        onError: reject
                    }
                );
            });
        },

        /**
         * Get PayPal Subscription details.
         *
         * @param {String} subscriptionId
         * @return {Promise}
         */
        getSubscription: function(subscriptionId) {
            return new Promise((resolve, reject) => {
                QUIAjax.get(
                    'package_quiqqer_payment-paypal_ajax_recurring_getSubscription',
                    resolve,
                    {
                        'package': pkg,
                        subscriptionId: subscriptionId,
                        onError: reject
                    }
                );
            });
        },

        /**
         * Suspend a PayPal Subscription.
         *
         * @param {String} subscriptionId
         * @return {Promise}
         */
        suspendSubscription: function(subscriptionId) {
            return new Promise((resolve, reject) => {
                QUIAjax.post(
                    'package_quiqqer_payment-paypal_ajax_recurring_suspendSubscription',
                    resolve,
                    {
                        'package': pkg,
                        subscriptionId: subscriptionId,
                        onError: reject
                    }
                );
            });
        },

        /**
         * Activate a suspended PayPal Subscription.
         *
         * @param {String} subscriptionId
         * @return {Promise}
         */
        activateSubscription: function(subscriptionId) {
            return new Promise((resolve, reject) => {
                QUIAjax.post(
                    'package_quiqqer_payment-paypal_ajax_recurring_activateSubscription',
                    resolve,
                    {
                        'package': pkg,
                        subscriptionId: subscriptionId,
                        onError: reject
                    }
                );
            });
        },

        /**
         * Cancel a PayPal Subscription.
         *
         * @param {String} subscriptionId
         * @return {Promise}
         */
        cancelSubscription: function(subscriptionId) {
            return new Promise((resolve, reject) => {
                QUIAjax.post(
                    'package_quiqqer_payment-paypal_ajax_recurring_cancelSubscription',
                    resolve,
                    {
                        'package': pkg,
                        subscriptionId: subscriptionId,
                        onError: reject
                    }
                );
            });
        },

        /**
         * Get PayPal API client ID
         *
         * @return {Promise}
         */
        getClientId: function() {
            return new Promise(function(resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_getClientId', resolve, {
                    'package': pkg,
                    onError: reject
                });
            });
        },

        /**
         * Get some necessary order details for setting up PayPal API
         *
         * @return {Promise}
         */
        getOrderDetails: function() {
            return new Promise(function(resolve, reject) {
                QUIAjax.get('package_quiqqer_payment-paypal_ajax_getOrderDetails', resolve, {
                    'package': pkg,
                    onError: reject
                });
            });
        }
    });
});
