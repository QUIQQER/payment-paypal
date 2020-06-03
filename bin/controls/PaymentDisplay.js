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

    var pkg = 'quiqqer/payment-paypal';

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
            var self = this;

            if (typeof paypal !== 'undefined') {
                this.$renderPayPalBtn();
                return;
            }

            Promise.all([
                PayPalApi.getClientId(),
                PayPalApi.getOrderDetails()
            ]).then(function (result) {
                var OrderDetails = result[1];

                var widgetUrl = "https://www.paypal.com/sdk/js?client-id=" + result[0];

                widgetUrl += '&currency=' + OrderDetails.currency;
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

                self.$renderPayPalBtn();
            });
        },

        /**
         * Show PayPal Pay Button widget (btn)
         */
        $renderPayPalBtn: function () {
            var self = this;

            if (typeof paypal === 'undefined') {
                (function () {
                    this.$renderPayPalBtn();
                }).delay(200, this);
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
                createOrder: function () {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
                    );

                    return PayPalApi.createOrder(
                        self.getAttribute('orderhash'),
                        self.getAttribute('basketid'),
                        false
                    ).then(function (Order) {
                        self.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, function (Error) {
                        self.$OrderProcess.Loader.hide();
                        self.$showErrorMsg(Error.getMessage());
                        self.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');

                        self.fireEvent('processingError', [self]);
                    });
                },

                // onApprove() is called when the buyer approves the payment
                onApprove: function (data) {
                    self.$OrderProcess.Loader.show(
                        QUILocale.get(pkg, 'PaymentDisplay.execute_payment')
                    );

                    PayPalApi.executeOrder(
                        self.$hash,
                        data.orderID,
                        data.payerID,
                        false
                    ).then(function (success) {
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

                    self.$renderPayPalBtn();

                    self.fireEvent('processingError', [self]);
                },

                onError: function () {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'PaymentDisplay.processing_error')
                    );

                    self.$renderPayPalBtn();

                    self.fireEvent('processingError', [self]);
                }
            }).render(self.$PayPalBtnElm).then(function () {
                self.$OrderProcess.resize();
                self.$OrderProcess.Loader.hide();
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