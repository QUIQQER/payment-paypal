/**
 * PaymentDisplay for PayPal
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, PayPal, QUIAjax, QUILocale) {
    "use strict";

    var lg = 'quiqqer/payment-paypal';

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
            orderhash : '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

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

            this.$MsgElm  = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$Content = Elm.getElement('.quiqqer-payment-paypal-content');

            this.$showMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.PaymentDisplay.info'));

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
            var self = this;

            var PayPalButton = new QUIButton({
                'class'  : 'btn-primary button quiqqer-payment-paypal-recurring-paymentdisplay-btn',
                disabled : true,
                text     : QUILocale.get(lg, 'controls.recurring.PaymentDisplay.btn.text_create'),
                textimage: 'fa fa-spinner fa-spin',
                events   : {
                    onClick: function (Btn) {
                        Btn.disable();
                        window.location = Btn.getAttribute('approvalUrl');
                    }
                }
            }).inject(this.$Content);

            this.$OrderProcess.Loader.show(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.Loader.create_billing_agreement'));

            PayPal.createBillingAgreement(this.getAttribute('orderhash')).then(function (Data) {
                self.$OrderProcess.Loader.hide();

                PayPalButton.setAttribute(
                    'text',
                    QUILocale.get(lg, 'controls.recurring.PaymentDisplay.btn.text')
                );

                PayPalButton.setAttribute('textimage', 'fa fa-paypal');
                PayPalButton.setAttribute('approvalUrl', Data.approvalUrl);
                PayPalButton.enable();
            }, function () {
                PayPalButton.destroy();
                self.$showErrorMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.error'));
                self.fireEvent('processingError', [self]);
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