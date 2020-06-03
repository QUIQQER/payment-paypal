/**
 * Button for PayPal Express checkout
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressBtn', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',
    'qui/controls/loader/Loader',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/ExpressBtn.css'

], function (QUIControl, QUIButton, QUIConfirm, QUILoader, QUIControlUtils, PayPalApi, QUIAjax, QUILocale) {
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
            orderhash      : false,
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
            this.$hash          = false;
            this.$widgetsLoaded = false;
            this.$ErrorPopup    = null;

            this.Loader     = new QUILoader();
            this.PageLoader = new QUILoader();

            this.addEvents({
                onImport : this.$onImport,
                onInject : this.$onInject,
                onDestroy: this.$onDestroy
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            if (this.getAttribute('checkout')) {
                this.$toCheckout();
            }

            var self = this;
            var Elm  = this.getElm();

            Elm.addClass('quiqqer-payment-paypal-express');

            Elm.set(
                'html',
                '<div class="quiqqer-payment-paypal-express-msg"></div>' +
                '<div class="quiqqer-payment-paypal-express-btn"></div>'
            );

            this.$hash         = this.getAttribute('orderhash');
            this.$MsgElm       = Elm.getElement('.quiqqer-payment-paypal-express-msg');
            this.$PayPalBtnElm = Elm.getElement('.quiqqer-payment-paypal-express-btn');

            var PageLoaderElm = document.body.getElement('.quiqqer-payment-paypal-express-pageloader');

            if (PageLoaderElm) {
                this.PageLoader = QUI.Controls.getById(PageLoaderElm.get('data-quiid'));
            } else {
                this.PageLoader.getElm().addClass('quiqqer-payment-paypal-express-pageloader');
                this.PageLoader.inject(document.body);
            }

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
            var self = this;

            if (document.id('paypal-checkout-api')) {
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
                widgetUrl += '&commit=false';

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
            if (typeof paypal === 'undefined') {
                (function () {
                    this.$renderPayPalBtn();
                }).delay(200, this);
                return;
            }

            var self = this;

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Buttons({
                style: {
                    label: 'checkout',
                    size : this.getAttribute('displaysize'),
                    shape: this.getAttribute('displayshape'),
                    color: this.getAttribute('displaycolor')
                },

                // createOrder() is called when the button is clicked
                createOrder: function () {
                    self.$showLoader(QUILocale.get(pkg, 'ExpressBtn.confirm_payment'));

                    return PayPalApi.createOrder(
                        self.$hash,
                        self.getAttribute('basketid'),
                        true
                    ).then(function (Order) {
                        self.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, function (Error) {
                        self.$hideLoader();
                        self.$showErrorMsg(Error.getMessage());
                    });
                },

                onCancel: function () {
                    self.$hideLoader();
                },

                // onApprove() is called when the buyer approves the payment
                onApprove: function (data) {
                    self.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

                    PayPalApi.executeOrder(
                        self.$hash,
                        data.orderID,
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
            }).render(this.$PayPalBtnElm).then(function () {
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
            if (this.$ErrorPopup) {
                this.$ErrorPopup.close();
            }

            this.$ErrorPopup = new QUIConfirm({
                maxHeight: 300,
                autoclose: false,

                information: msg,
                title      : QUILocale.get(pkg, 'ExpressBtn.error.title'),
                texticon   : 'fa fa-exclamation-triangle',
                text       : QUILocale.get(pkg, 'ExpressBtn.error.text_title'),
                icon       : 'fa fa-exclamation-triangle',

                cancel_button: false,
                ok_button    : {
                    text     : false,
                    textimage: 'icon-ok fa fa-check'
                },

                events: {
                    onSubmit: function (Popup) {
                        Popup.close();
                    }
                }
            }).open();
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