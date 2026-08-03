/**
 * PaymentDisplay for PayPal
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, PayPalApi, QUIAjax, QUILocale) {
    "use strict";

    const pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/PaymentDisplay',

        Binds: [
            '$onImport',
            '$renderPayPalBtn',
            '$onPayPalLoginReady',
            '$showPayPalWallet',
            '$showErrorMsg',
            '$onPayBtnClick'
        ],

        options: {
            sandbox   : true,
            orderhash : '',
            currency  : '',
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
            const Elm = this.getElm();

            if (!Elm.getElement('.quiqqer-payment-paypal-content')) {
                return;
            }

            this.$MsgElm       = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$PayPalBtnElm = Elm.getElement('#quiqqer-payment-paypal-btn-pay');

            this.$showMsg(QUILocale.get(pkg, 'PaymentDisplay.info'));

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then((OrderProcess) => {
                this.$OrderProcess = OrderProcess;

                if (this.getAttribute('successful')) {
                    OrderProcess.next();
                    return;
                }

                this.$loadPayPalWidgets();
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPayPalWidgets: function () {
            /*
             * In case the express button was shown in a previous step in the order process,
             * the new button cannot be rendered currently. Thus we have to load the button in
             * the legacy way.
             */
            if (typeof paypal !== 'undefined') {
                if (!('paypalV1ButtonRendered' in window) || !window.paypalV1ButtonRendered) {
                    this.$renderPayPalBtn();
                } else {
                    this.$renderPayPalBtnV1();
                }

                return;
            }

            PayPalApi.getClientId().then((clientId) => {
                let widgetUrl = "https://www.paypal.com/sdk/js?client-id=" + clientId;

                widgetUrl += '&currency=' + encodeURIComponent(this.getAttribute('currency'));
                widgetUrl += '&intent=capture';
                widgetUrl += '&commit=true';

                widgetUrl += '&disable-funding=card,credit,venmo,sepa,bancontact,eps,giropay,ideal,mybank';
                widgetUrl += ',p24,sofort';

                //widgetUrl += '&disable-card=card,credit,venmo,sepa,bancontact,eps,giropay,ideal,mybank';
                //widgetUrl += ',p24,sofort';

                new Element('script', {
                    async: "async",
                    src  : widgetUrl,
                    id   : 'paypal-checkout-api'
                }).inject(document.body);

                this.$renderPayPalBtn();
            });
        },

        /**
         * Show PayPal Pay Button widget (btn)
         */
        $renderPayPalBtn: function () {
            if (typeof paypal === 'undefined') {
                (() => this.$renderPayPalBtn()).delay(200);

                return;
            }

            this.$OrderProcess.Loader.show();

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Buttons({
                style: {
                    label: 'pay',
                    size : this.$PayPalBtnElm.get('data-size'),
                    shape: this.$PayPalBtnElm.get('data-shape'),
                    color: this.$PayPalBtnElm.get('data-color')
                },

                // createOrder() is called when the button is clicked
                createOrder: () => {
                    this.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
                    );

                    return PayPalApi.createOrder(
                        this.getAttribute('orderhash'),
                        this.getAttribute('basketid'),
                        false
                    ).then((Order) => {
                        this.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, (Error) => {
                        this.$OrderProcess.Loader.hide();
                        this.$showErrorMsg(Error.getMessage());
                        this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');

                        this.fireEvent('processingError', [this]);
                        throw Error;
                    });
                },

                // onApprove() is called when the buyer approves the payment
                onApprove: () => {
                    this.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.execute_payment')
                    );

                    PayPalApi.executeOrder(this.$hash, false).then((success) => {
                        if (success) {
                            this.$OrderProcess.next();
                            return;
                        }

                        this.$OrderProcess.Loader.hide();

                        this.$showErrorMsg(
                            QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                        );
                    }, (Error) => {
                        this.$OrderProcess.Loader.hide();
                        this.$showErrorMsg(Error.getMessage());

                        this.fireEvent('processingError', [this]);
                    });
                },

                onCancel: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.user_cancel')
                    );

                    this.$renderPayPalBtn();

                    this.fireEvent('processingError', [this]);
                },

                onError: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                    );

                    this.$renderPayPalBtn();

                    this.fireEvent('processingError', [this]);
                }
            }).render(this.$PayPalBtnElm).then(() => {
                this.$OrderProcess.resize();
                this.$OrderProcess.Loader.hide();

                window.paypalV1ButtonRendered = false;
            });
        },

        /**
         * Show PayPal Pay Button widget using the old checkout.js SDK
         */
        $renderPayPalBtnV1: function () {
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
                payment: () => {
                    this.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
                    );

                    return PayPalApi.createOrder(
                        this.getAttribute('orderhash'),
                        this.getAttribute('basketid'),
                        false
                    ).then((Order) => {
                        this.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, (Error) => {
                        this.$OrderProcess.Loader.hide();
                        this.$showErrorMsg(Error.getMessage());
                        this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');

                        this.fireEvent('processingError', [this]);
                        throw Error;
                    });
                },

                // onAuthorize() is called when the buyer approves the payment
                onAuthorize: () => {
                    this.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.execute_payment')
                    );

                    PayPalApi.executeOrder(this.$hash, false).then((success) => {
                        if (success) {
                            this.$OrderProcess.next();
                            return;
                        }

                        this.$OrderProcess.Loader.hide();

                        this.$showErrorMsg(
                            QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                        );
                    }, (Error) => {
                        this.$OrderProcess.Loader.hide();
                        this.$showErrorMsg(Error.getMessage());

                        this.fireEvent('processingError', [this]);
                    });
                },

                onCancel: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.user_cancel')
                    );

                    this.$renderPayPalBtnV1();

                    this.fireEvent('processingError', [this]);
                },

                onError: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                    );

                    this.$renderPayPalBtnV1();

                    this.fireEvent('processingError', [this]);
                }
            }, this.$PayPalBtnElm).then(() => {
                this.$OrderProcess.resize();
                this.$OrderProcess.Loader.hide();

                window.paypalV1ButtonRendered = true;
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
