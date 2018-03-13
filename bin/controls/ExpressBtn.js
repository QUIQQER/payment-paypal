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
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/ExpressBtn.css'

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
            '$showErrorMsg',
            '$showLoader',
            '$hideLoader',
            '$toCheckout'
        ],

        options: {
            sandbox        : true,
            basketid       : false,
            productid      : false,
            context        : false,
            orderprocessurl: false,
            checkout       : false,
            displaysize    : '',
            displaycolor   : '',
            displayshape   : ''
        },

        initialize: function (options) {
            this.parent(options);

            this.$PayPalBtnElm  = null;
            this.$MsgElm        = null;
            this.$ContextParent = null; // this can be either an OrderProcess or a SmallBasket
            this.Loader         = new QUILoader();
            this.PageLoader     = new QUILoader();
            this.$hash          = false;
            this.$widgetsLoaded = false;

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

            this.PageLoader.inject(document.body);
            this.Loader.inject(Elm);
            this.Loader.show();

            self.$loadPayPalWidgets();

            // load context parent
            var contextParentControlSelector = false;

            switch (this.getAttribute('context')) {
                case 'basket':
                    contextParentControlSelector = '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]';
                    break;

                case 'smallbasket':
                    contextParentControlSelector = '.quiqqer-order-basket-small-container > .qui-control';
                    break;

                case 'product':
                    // @todo
                    break;
            }

            QUIControlUtils.getControlByElement(
                Elm.getParent(contextParentControlSelector)
            ).then(function (ContextControl) {
                self.$ContextParent = ContextControl;

                if (self.$widgetsLoaded) {
                    self.Loader.hide();
                }
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

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Button.render({
                env   : !this.getAttribute('sandbox') ? 'production' : 'sandbox',
                commit: false,

                style: {
                    label: 'checkout',
                    size : this.getAttribute('displaysize'),
                    shape: this.getAttribute('displayshape'),
                    color: this.getAttribute('displaycolor')
                },

                // payment() is called when the button is clicked
                payment: function () {
                    self.$showLoader(QUILocale.get(pkg, 'ExpressBtn.confirm_payment'));

                    return PayPalApi.createOrder(
                        self.$hash,
                        self.getAttribute('basketid')
                    ).then(function (Order) {
                        self.$hash = Order.hash;
                        return Order.payPalPaymentId;
                    }, function (Error) {
                        self.$hideLoader();
                        self.$showErrorMsg(Error.getMessage());
                    });
                },

                // onAuthorize() is called when the buyer approves the payment
                onAuthorize: function (data) {
                    self.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

                    PayPalApi.executeOrder(
                        self.$hash,
                        data.paymentID,
                        data.payerID,
                        true
                    ).then(function (success) {
                        if (success) {
                            self.$toCheckout();
                            return;
                        }

                        self.$hideLoader();

                        self.$showErrorMsg(
                            QUILocale.get(pkg, 'ExpressBtn.processing_error')
                        );
                    }, function (Error) {
                        self.$hideLoader();
                        self.$showErrorMsg(Error.getMessage());
                    });
                },

                onError: function () {
                    self.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressBtn.processing_error')
                    );

                    self.$renderPayPalBtn();
                }
            }, this.$PayPalBtnElm).then(function () {
                if (self.$ContextParent) {
                    self.Loader.hide();
                }
            });

            this.$widgetsLoaded = true;
        },

        /**
         * Show Loader of the contextual Order process
         *
         * @param {String} [msg] - Loader message
         */
        $showLoader: function (msg) {
            switch (this.getAttribute('context')) {
                case 'basket':
                    if (this.$ContextParent) {
                        this.$ContextParent.Loader.show(msg);
                    }
                    break;

                case 'smallbasket':
                    this.PageLoader.show(msg);
                    break;

                case 'product':

                    break;
            }
        },

        /**
         * Hide Loader of the contextual Order process
         */
        $hideLoader: function () {
            switch (this.getAttribute('context')) {
                case 'basket':
                    if (this.$ContextParent) {
                        this.$ContextParent.Loader.hide();
                    }
                    break;

                case 'smallbasket':
                    this.PageLoader.hide();
                    break;

                case 'product':

                    break;
            }
        },

        /**
         * Go to Checkout step
         */
        $toCheckout: function () {
            window.location = this.getAttribute('orderprocessurl');
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