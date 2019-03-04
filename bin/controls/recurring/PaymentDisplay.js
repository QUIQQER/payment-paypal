/**
 * PaymentDisplay for PayPal
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay',

        Binds: [
            '$onImport',
            '$loadBillingAgreementButton',
            '$showErrorMsg',
            '$showMsg'
        ],

        options: {
            sandbox   : true,
            orderhash : '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$PayPalBtnElm = null;
            this.$MsgElm       = null;
            this.$OrderProcess = null;

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

            this.$MsgElm       = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$PayPalBtnElm = Elm.getElement('#quiqqer-payment-paypal-btn-pay');

            this.$showMsg(QUILocale.get(pkg, 'PaymentDisplay.info'));

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then(function (OrderProcess) {
                self.$OrderProcess = OrderProcess;

                if (self.getAttribute('successful')) {
                    OrderProcess.next();
                    return;
                }

                self.$loadBillingAgreementButton();
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadBillingAgreementButton: function () {
            var PayPalButton = new QUIButton({

            }).inject(this.$PayPalBtnElm);
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