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
            '$showPayPalPayBtn',
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
            this.$AuthBtnElm       = null;
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

            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-message');

            this.$showMsg(
                QUILocale.get(pkg, 'controls.PaymentDisplay.info')
            );

            //QUIControlUtils.getControlByElement(
            //    Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            //).then(function (OrderProcess) {
            //    self.$OrderProcess = OrderProcess;
            //
            //    if (self.getAttribute('successful')) {
            //        OrderProcess.next();
            //        return;
            //    }
            //
            //    self.$loadPayPalWidgets();
            //});
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPayPalWidgets: function () {
            var widgetUrl = "https://www.paypalobjects.com/api/checkout.js";

            if (typeof paypal !== 'undefined') {
                this.$showPayPalPayBtn();
                return;
            }

            this.$OrderProcess.Loader.show();

            if (typeof window.onPayPalPaymentsReady === 'undefined') {
                window.onPayPalPaymentsReady = this.$showPayPalPayBtn;
            }

            if (typeof window.onPayPalLoginReady === 'undefined') {
                window.onPayPalLoginReady = this.$onPayPalLoginReady;
            }

            new Element('script', {
                async: "async",
                src  : widgetUrl
            }).inject(document.body);
        },

        /**
         * Execute if PayPal Login has loaded
         */
        $onPayPalLoginReady: function () {
            paypal.Login.setClientId(this.getAttribute('clientid'));
        },

        /**
         * Show PayPal Pay authentication widget (btn)
         */
        $showPayPalPayBtn: function () {
            var self = this;

            this.$OrderProcess.Loader.hide();

            // re-display if button was previously rendered and hidden
            this.$AuthBtnElm.removeClass('quiqqer-payment-paypal__hidden');

            OffPayPalPayments.Button(
                'quiqqer-payment-paypal-btn',
                this.getAttribute('sellerid'),
                {
                    type : 'PwA',
                    color: this.$AuthBtnElm.get('data-color'),
                    size : this.$AuthBtnElm.get('data-size'),

                    authorization: function () {
                        paypal.Login.authorize({
                            popup: true,
                            scope: 'payments:widget'
                        }, function (Response) {
                            if (Response.error) {
                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.login_error')
                                );

                                return;
                            }

                            self.$accessToken = Response.access_token;

                            self.$AuthBtnElm.addClass('quiqqer-payment-paypal__hidden');
                            self.$showPayPalWallet(true);
                        });
                    },

                    onError: function (Error) {
                        switch (Error.getErrorCode()) {
                            // handle errors on the shop side (most likely misconfiguration)
                            case 'InvalidAccountStatus':
                            case 'InvalidSellerId':
                            case 'InvalidParameterValue':
                            case 'MissingParameter':
                            case 'UnknownError':
                                self.$AuthBtnElm.addClass('quiqqer-payment-paypal__hidden');

                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.configuration_error')
                                );

                                self.$logError(Error);
                                break;

                            default:
                                self.$showErrorMsg(
                                    QUILocale.get(pkg, 'controls.PaymentDisplay.login_error')
                                );
                        }
                    }
                }
            );
        },

        /**
         * Show PayPal Pay Wallet widget
         *
         * @param {Boolean} [showInfoMessage] - Show info message
         */
        $showPayPalWallet: function (showInfoMessage) {
            var self = this;

            if (showInfoMessage) {
                this.$showMsg(
                    QUILocale.get(pkg, 'controls.PaymentDisplay.wallet_info')
                );
            }

            this.$WalletElm.set('html', '');

            var Options = {
                sellerId       : this.getAttribute('sellerid'),
                design         : {
                    designMode: 'responsive'
                },
                onPaymentSelect: function () {
                    self.$PayBtn.enable();
                },
                onError        : function (Error) {
                    switch (Error.getErrorCode()) {
                        // handle errors on the shop side (most likely misconfiguration)
                        case 'InvalidAccountStatus':
                        case 'InvalidSellerId':
                        case 'InvalidParameterValue':
                        case 'MissingParameter':
                        case 'UnknownError':
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.configuration_error')
                            );

                            self.$logError(Error);
                            break;

                        case 'AddressNotModifiable':
                        case 'BuyerNotAssociated':
                        case 'BuyerSessionExpired':
                        case 'PaymentMethodNotModifiable':
                        case 'StaleOrderReference':
                            self.$AuthBtnElm.removeClass('quiqqer-payment-paypal__hidden');
                            self.$orderReferenceId = false;
                            self.$showErrorMsg(Error.getErrorMessage());
                            break;

                        default:
                            self.$showErrorMsg(
                                QUILocale.get(pkg, 'controls.PaymentDisplay.wallet_error')
                            );
                    }
                }
            };

            if (!this.$orderReferenceId) {
                Options.onOrderReferenceCreate = function (orderReference) {
                    self.$orderReferenceId = orderReference.getPayPalOrderReferenceId();
                }
            } else {
                Options.paypalOrderReferenceId = this.$orderReferenceId;
            }

            if (!this.$PayBtn) {
                var PayBtnElm = this.getElm().getElement('#quiqqer-payment-paypal-btn-pay');

                this.$PayBtn = new QUIButton({
                    disabled: true,
                    text    : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.text', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    alt     : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.title', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    title   : QUILocale.get(pkg, 'controls.PaymentDisplay.btn_pay.title', {
                        display_price: PayBtnElm.get('data-price')
                    }),
                    texticon: 'fa fa-paypal',
                    events  : {
                        onClick: this.$onPayBtnClick
                    }
                }).inject(PayBtnElm);
            }

            // rendet wallet widget
            new OffPayPalPayments.Widgets.Wallet(Options).bind('quiqqer-payment-paypal-wallet');
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
         * Start the payment process
         *
         * @return {Promise}
         */
        $authorizePayment: function () {
            var self = this;

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_payment-paypal_ajax_authorizePayment', resolve, {
                    'package'       : pkg,
                    orderHash       : self.getAttribute('orderhash'),
                    orderReferenceId: self.$orderReferenceId,
                    onError         : reject
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