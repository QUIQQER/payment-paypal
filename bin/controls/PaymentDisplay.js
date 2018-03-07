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
    'Locale'

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
            sellerid  : '',
            clientid  : '',
            orderhash : '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$orderReferenceId = false;
            this.$PayPalBtnElm     = null;
            this.$WalletElm        = null;
            this.$PayBtn           = null;
            this.$MsgElm           = null;
            this.$OrderProcess     = null;

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

            this.$showMsg(
                QUILocale.get(pkg, 'controls.PaymentDisplay.info')
            );

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

            this.$OrderProcess.Loader.hide();

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Button.render({
                env   : !this.getAttribute('sandbox') ? 'production' : 'sandbox',
                commit: true,

                style: {
                    label: 'pay',
                    size : 'large', // small | medium | large | responsive
                    shape: 'rect',   // pill | rect
                    color: 'gold'   // gold | blue | silver | black
                },

                // payment() is called when the button is clicked
                payment: function () {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
                    );

                    return self.$createOrder().then(function (payPalOrderId) {
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
                    });
                },

                onError: function() {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                    );

                    self.$showPayPalBtn();
                }
            }, '#quiqqer-payment-paypal-btn-pay');
        },

        /**
         * Start payment process
         *
         * @param {Object} Btn
         */
        $onPayBtnClick: function (Btn) {
            var self = this;

            Btn.disable();
            Btn.setAttribute('texticon', 'fa fa-spinner fa-spin');

            self.$WalletElm.addClass('quiqqer-payment-paypal__hidden');

            self.$OrderProcess.Loader.show(
                QUILocale.get(pkg, 'controls.PaymentDisplay.authorize_payment')
            );

            self.$authorizePayment().then(function (success) {
                if (success) {
                    self.$OrderProcess.next();
                    return;
                }

                self.$OrderProcess.Loader.hide();

                self.$showErrorMsg(
                    QUILocale.get(pkg, 'controls.PaymentDisplay.processing_error')
                );

                self.$showPayPalWallet(false);

                Btn.enable();
                Btn.setAttribute('texticon', 'fa fa-paypal');
            }, function (error) {
                self.$OrderProcess.Loader.hide();
                self.$showErrorMsg(error.getMessage());

                if (error.getAttribute('orderCancelled')) {
                    self.$orderReferenceId = false;
                }

                if (error.getAttribute('reRenderWallet')) {
                    self.$WalletElm.removeClass('quiqqer-payment-paypal__hidden');
                    self.$showPayPalWallet(false);

                    Btn.enable();
                    Btn.setAttribute('texticon', 'fa fa-paypal');

                    return;
                }

                // sign out
                paypal.Login.logout();
                Btn.destroy();

                self.$showErrorMsg(
                    QUILocale.get(pkg, 'controls.PaymentDisplay.fatal_error')
                );

                new QUIButton({
                    text    : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_reselect_payment.text'),
                    texticon: 'fa fa-credit-card',
                    events  : {
                        onClick: function () {
                            window.location.reload();
                        }
                    }
                }).inject(self.getElm().getElement('#quiqqer-payment-paypal-btn-pay'))
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
                })
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
        },

        /**
         * Log an PayPal Pay widget/processing error
         *
         * @param {Object} Error - PayPal Pay widget error
         * @return {Promise}
         */
        $logError: function (Error) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_logFrontendError', resolve, {
                    'package': pkg,
                    errorCode: Error.getErrorCode(),
                    errorMsg : Error.getErrorMessage(),
                    onError  : reject
                });
            });
        }
    });
});