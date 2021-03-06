/**
 * ExpressPaymentDisplay for PayPal
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressPaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',

    'Ajax',
    'Locale'

], function (QUIControl, QUIButton, QUIControlUtils, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/ExpressPaymentDisplay',

        Binds: [
            '$onImport',
            '$showErrorMsg',
            '$expressCheckout',
            '$showMsg'
        ],

        options: {
            orderhash: ''
        },

        initialize: function (options) {
            this.parent(options);

            this.$MsgElm = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var self = this;
            var Elm  = this.getElm();

            if (!Elm.getElement('.quiqqer-payment-paypal-content')) {
                return;
            }

            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$showMsg(QUILocale.get(pkg, 'ExpressPaymentDisplay.order.execute'));

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then(function (OrderProcess) {
                self.$OrderProcess = OrderProcess;

                (function () {
                    OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'ExpressPaymentDisplay.order.execute')
                    );
                }).delay(1000);

                var onError = function () {
                    OrderProcess.Loader.hide();

                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressPaymentDisplay.msg.error')
                    );

                    (function () {
                        OrderProcess.previous();
                    }).delay(5000);
                };

                self.$expressCheckout().then(function (success) {
                    if (success) {
                        OrderProcess.next();
                        return;
                    }

                    onError();
                }, onError);
            });
        },

        /**
         * Execute PayPal Order
         *
         * @return {Promise}
         */
        $expressCheckout: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_expressCheckout', resolve, {
                    'package': pkg,
                    orderHash: self.getAttribute('orderhash'),
                    onError  : reject
                })
            });
        },

        /**
         * Show error msg
         *
         * @param {String} msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p class="message-error">' + msg + '</p>'
            );
        },

        /**
         * Show normal msg
         *
         * @param {String} msg
         */
        $showMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<p>' + msg + '</p>'
            );
        }
    });
});