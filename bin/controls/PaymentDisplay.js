/**
 * PaymentDisplay for PayPal
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, QUIAjax, QUILocale) {
    "use strict";

    var pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/PaymentDisplay',

        Binds: [
            '$onImport',
            '$showPayPalBtn',
            '$onPayPalLoginReady',
            '$showPayPalWallet',
            '$showErrorMsg',
            '$onPayBtnClick'
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

                self.$loadPayPalWidgets();
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPayPalWidgets: function () {
            var self      = this;
            var widgetUrl = "https://www.paypalobjects.com/api/checkout.js";

            if (typeof paypal !== 'undefined') {
                this.$showPayPalBtn();
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
                self.$showPayPalBtn();
            }, 200);
        },

        /**
         * Show PayPal Pay Button widget (btn)
         */
        $showPayPalBtn: function () {
            var self = this;

            this.$OrderProcess.Loader.show();

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            window.paypal.Button.render({
                env   : !this.getAttribute('sandbox') ? 'production' : 'sandbox',
                commit: true,

                style: {
                    label: 'pay',
                    size : this.$PayPalBtnElm.get('data-size'),
                    shape: this.$PayPalBtnElm.get('data-shape'),
                    color: this.$PayPalBtnElm.get('data-color')
                },

                // payment() is called when the button is clicked
                payment: function () {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
                    );

                    return self.$createOrder().then(function (Order) {
                        self.$OrderProcess.Loader.hide();
                        return Order.payPalPaymentId;
                    }, function (Error) {
                        self.$OrderProcess.Loader.hide();
                        self.$showErrorMsg(Error.getMessage());
                        self.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');

                        self.fireEvent('processingError', [self]);
                    });
                },

                // onAuthorize() is called when the buyer approves the payment
                onAuthorize: function (data) {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.execute_payment')
                    );

                    self.$executeOrder(data.paymentID, data.payerID).then(function (success) {
                        if (success) {
                            self.$OrderProcess.next();
                            return;
                        }

                        self.$OrderProcess.Loader.hide();

                        self.$showErrorMsg(
                            QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                        );
                    }, function (Error) {
                        self.$OrderProcess.Loader.hide();
                        self.$showErrorMsg(Error.getMessage());

                        self.fireEvent('processingError', [self]);
                    });
                },

                onCancel: function () {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.user_cancel')
                    );

                    self.$showPayPalBtn();

                    self.fireEvent('processingError', [self]);
                },

                onError: function () {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                    );

                    self.$showPayPalBtn();

                    self.fireEvent('processingError', [self]);
                }
            }, self.$PayPalBtnElm).then(function () {
                self.$OrderProcess.resize();
                self.$OrderProcess.Loader.hide();
            });
        },

        /**
         * Create PayPal Order
         *
         * @return {Promise}
         */
        $createOrder: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_createOrder', resolve, {
                    'package': pkg,
                    orderHash: self.getAttribute('orderhash'),
                    onError  : reject
                });
            });
        },

        /**
         * Execute PayPal Order
         *
         * @param {String} paymentId - PayPal paymentID
         * @param {String} payerId - PayPal payerID
         * @return {Promise}
         */
        $executeOrder: function (paymentId, payerId) {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_executeOrder', resolve, {
                    'package': pkg,
                    orderHash: self.getAttribute('orderhash'),
                    paymentId: paymentId,
                    payerId  : payerId,
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