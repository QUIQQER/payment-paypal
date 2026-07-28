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

    const pkg = 'quiqqer/payment-paypal';

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
            const Elm = this.getElm();

            if (!Elm.getElement('.quiqqer-payment-paypal-content')) {
                return;
            }

            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$showMsg(QUILocale.get(pkg, 'ExpressPaymentDisplay.order.execute'));

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then((OrderProcess) => {
                this.$OrderProcess = OrderProcess;

                (() => {
                    OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'ExpressPaymentDisplay.order.execute')
                    );
                }).delay(1000);

                const onError = () => {
                    OrderProcess.Loader.hide();

                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressPaymentDisplay.msg.error')
                    );

                    (() => OrderProcess.previous()).delay(5000);
                };

                this.$expressCheckout().then((success) => {
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
            return new Promise((resolve, reject) => {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_expressCheckout', resolve, {
                    'package': pkg,
                    orderHash: this.getAttribute('orderhash'),
                    onError  : reject
                });
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
