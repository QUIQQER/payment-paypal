/**
 * Button for PayPal Express checkout
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressBtn', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale'

], function (QUIControl, QUIButton, QUILoader, QUIControlUtils, PayPalApi, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/ExpressBtn',

        Binds: [
            '$onImport',
            '$onInject',
            '$renderPayPalBtn',
            '$onPayPalLoginReady',
            '$showPayPalWallet',
            '$showErrorMsg',
            '$onPayBtnClick'
        ],

        options: {
            sandbox   : true,
            baskethash: false,
            productid : false
        },

        initialize: function (options) {
            this.parent(options);

            this.$PayPalBtnElm = null;
            this.$MsgElm       = null;
            this.$OrderProcess = null;
            this.Loader        = new QUILoader();
            this.$hash         = false;

            this.addEvents({
                onImport: this.$onImport,
                onInject: this.$onInject
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            var self = this;
            var Elm  = this.getElm();

            Elm.addClass('quiqqer-payment-paypal-express');

            Elm.set(
                'html',
                '<div class="quiqqer-payment-paypal-express-msg"></div>' +
                '<div class="quiqqer-payment-paypal-express-btn"></div>'
            );

            this.$hash         = this.getAttribute('baskethash');
            this.$MsgElm       = Elm.getElement('.quiqqer-payment-paypal-express-msg');
            this.$PayPalBtnElm = Elm.getElement('.quiqqer-payment-paypal-express-btn');

            this.Loader.inject(Elm);
            this.Loader.show();

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then(function (OrderProcess) {
                self.$OrderProcess = OrderProcess;

                OrderProcess.saveCurrentStep().then(function() {
                    self.Loader.hide();
                    self.$loadPayPalWidgets();
                });
            }, function () {
                // @todo error handling
                console.error('OrderProcess not found.');
            });
        },

        /**
         * Event: onInject
         */
        $onInject: function () {
            this.create();
            this.$onImport();
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPayPalWidgets: function () {
            var self      = this;
            var widgetUrl = "https://www.paypalobjects.com/api/checkout.js";

            if (typeof paypal !== 'undefined') {
                this.$renderPayPalBtn();
                return;
            }

            this.$OrderProcess.Loader.show();

            new Element('script', {
                async: "async",
                src  : widgetUrl
            }).inject(document.body);

            var checkPayPayLoaded = setInterval(function () {
                if (typeof paypal === 'undefined') {
                    return;
                }

                clearInterval(checkPayPayLoaded);
                self.$renderPayPalBtn();
            }, 200);
        },

        /**
         * Show PayPal Pay Button widget (btn)
         */
        $renderPayPalBtn: function () {
            var self = this;

            this.$OrderProcess.Loader.hide();

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Button.render({
                env   : !this.getAttribute('sandbox') ? 'production' : 'sandbox',
                commit: false,

                style: {
                    label: 'checkout',
                    size : 'medium',
                    shape: 'pill',
                    color: 'gold'
                },

                // payment() is called when the button is clicked
                payment: function () {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'ExpressBtn.confirm_payment')
                    );

                    return PayPalApi.createOrder(self.$hash).then(function (payPalOrderId) {
                        self.$OrderProcess.Loader.hide();
                        return payPalOrderId;
                    }, function (Error) {
                        self.$OrderProcess.Loader.hide();
                        self.$showErrorMsg(Error.getMessage());
                    });
                },

                // onAuthorize() is called when the buyer approves the payment
                onAuthorize: function (data) {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'ExpressBtn.execute_payment')
                    );

                    self.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

                    PayPalApi.executeOrder(
                        self.$hash,
                        data.paymentID,
                        data.payerID
                    ).then(function (success) {
                        if (success) {
                            self.$OrderProcess.next();
                            return;
                        }

                        self.$OrderProcess.Loader.hide();

                        self.$showErrorMsg(
                            QUILocale.get(pkg, 'ExpressBtn.processing_error')
                        );
                    }, function (Error) {
                        self.$OrderProcess.Loader.hide();
                        self.$showErrorMsg(Error.getMessage());
                    });
                },

                onError: function () {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressBtn.processing_error')
                    );

                    self.$renderPayPalBtn();
                }
            }, '.' + this.$PayPalBtnElm.get('class'));
        },

        /**
         * Show error msg
         *
         * @param {String} msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set(
                'html',
                '<span class="message-error">' + msg + '</span>'
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
                '<span>' + msg + '</span>'
            );
        }
    });
});