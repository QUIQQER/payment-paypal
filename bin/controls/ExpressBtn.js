/**
 * Button for PayPal Express checkout
 *
 * @author Patrick Müller (www.pcsg.de)
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressBtn', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',
    'qui/controls/loader/Loader',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/ExpressBtn.css'

], function(QUI, QUIControl, QUIButton, QUIConfirm, QUILoader, QUIControlUtils, PayPalApi, QUIAjax, QUILocale) {
    'use strict';

    const pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/ExpressBtn',

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
            sandbox: true,
            basketid: false,
            productid: false,
            context: false,
            orderhash: false,
            orderprocessurl: false,
            checkout: false,
            displaysize: '',
            displaycolor: '',
            displayshape: ''
        },

        initialize: function(options) {
            this.parent(options);

            this.$PayPalBtnElm = null;
            this.$MsgElm = null;
            this.$ContextParent = null; // this can be either an OrderProcess or a SmallBasket
            this.$hash = false;
            this.$widgetsLoaded = false;
            this.$ErrorPopup = null;

            this.Loader = new QUILoader();
            this.PageLoader = new QUILoader();

            this.addEvents({
                onImport: this.$onImport,
                onInject: this.$onInject,
                onDestroy: this.$onDestroy
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function() {
            if (this.getAttribute('checkout')) {
                this.$toCheckout();
            }

            const Elm = this.getElm();

            Elm.addClass('quiqqer-payment-paypal-express');

            Elm.set(
                'html',
                '<div class="quiqqer-payment-paypal-express-msg"></div>' +
                '<div class="quiqqer-payment-paypal-express-btn"></div>'
            );

            this.$hash = this.getAttribute('orderhash');
            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-express-msg');
            this.$PayPalBtnElm = Elm.getElement('.quiqqer-payment-paypal-express-btn');

            const PageLoaderElm = document.body.getElement('.quiqqer-payment-paypal-express-pageloader');

            if (PageLoaderElm) {
                this.PageLoader = QUI.Controls.getById(PageLoaderElm.get('data-quiid'));
            } else {
                this.PageLoader.getElm().addClass('quiqqer-payment-paypal-express-pageloader');
                this.PageLoader.inject(document.body);
            }

            this.Loader.inject(Elm);
            this.Loader.show();

            // this.$loadPayPalWidgets(); // can be re-enabled if PayPal fixes their JavaScript SDK
            this.$loadPayPalWidgetsV1();

            // load context parent
            let contextParentControlSelector = false;

            switch (this.getAttribute('context')) {
                case 'basket':
                    contextParentControlSelector = '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]';
                    break;

                case 'smallbasket':
                    contextParentControlSelector = '.quiqqer-order-basket-small-container > .qui-control';
                    break;

                case 'simple-checkout':
                    contextParentControlSelector = '.quiqqer-simple-checkout';
                    break;

                case 'product':
                    // @todo
                    break;
            }

            QUIControlUtils.getControlByElement(
                Elm.getParent(contextParentControlSelector)
            ).then((ContextControl) => {
                this.$ContextParent = ContextControl;

                if (this.$widgetsLoaded) {
                    this.Loader.hide();
                }
            }, () => {
                // @todo error handling
                console.error('OrderProcess not found.');
            });
        },

        /**
         * Event: onInject
         */
        $onInject: function() {
            this.create();
            this.$onImport();
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadPayPalWidgets: function() {
            if (document.id('paypal-checkout-api')) {
                this.$renderPayPalBtn();
                return;
            }

            Promise.all([
                PayPalApi.getClientId(),
                PayPalApi.getOrderDetails()
            ]).then((result) => {
                const OrderDetails = result[1];
                let widgetUrl = 'https://www.paypal.com/sdk/js?client-id=' + result[0];

                widgetUrl += '&currency=' + OrderDetails.currency;
                widgetUrl += '&intent=capture';
                widgetUrl += '&commit=false';

                widgetUrl += '&disable-funding=card,credit,venmo,sepa,bancontact,eps,giropay,ideal,mybank';
                widgetUrl += ',p24,sofort';

                //widgetUrl += '&disable-card=card,credit,venmo,sepa,bancontact,eps,giropay,ideal,mybank';
                //widgetUrl += ',p24,sofort';

                new Element('script', {
                    async: 'async',
                    src: widgetUrl,
                    id: 'paypal-checkout-api'
                }).inject(document.body);

                this.$renderPayPalBtn();
            });
        },

        /**
         * Load PayPal Pay widgets using the old checkout.js SDK
         */
        $loadPayPalWidgetsV1: function() {
            const widgetUrl = 'https://www.paypalobjects.com/api/checkout.js';

            if (document.id('paypal-checkout-api')) {
                this.$renderPayPalBtnV1();
                return;
            }

            new Element('script', {
                async: 'async',
                src: widgetUrl,
                id: 'paypal-checkout-api'
            }).inject(document.body);

            this.$renderPayPalBtnV1();
        },

        /**
         * Show PayPal Pay Button widget (btn)
         */
        $renderPayPalBtn: function() {
            if (typeof paypal === 'undefined') {
                (() => this.$renderPayPalBtn()).delay(200);
                return;
            }

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');

            paypal.Buttons({
                style: {
                    tagline: false,
                    label: 'paypal',
                    size: this.getAttribute('displaysize'),
                    shape: this.getAttribute('displayshape'),
                    color: this.getAttribute('displaycolor')
                },

                // createOrder() is called when the button is clicked
                createOrder: () => {
                    this.$showLoader(QUILocale.get(pkg, 'ExpressBtn.confirm_payment'));

                    return PayPalApi.createOrder(
                        this.$hash,
                        this.getAttribute('basketid'),
                        true
                    ).then((Order) => {
                        this.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, (Error) => {
                        this.$hideLoader();
                        this.$showErrorMsg(Error.getMessage());
                    });
                },

                onCancel: () => {
                    this.$hideLoader();
                },

                // onApprove() is called when the buyer approves the payment
                onApprove: () => {
                    this.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

                    PayPalApi.executeOrder(this.$hash, true).then((success) => {
                        if (success) {
                            this.$toCheckout();
                            return;
                        }

                        this.$hideLoader();

                        this.$showErrorMsg(
                            QUILocale.get(pkg, 'ExpressBtn.processing_error')
                        );
                    }, (Error) => {
                        this.$hideLoader();
                        this.$showErrorMsg(Error.getMessage());
                    });
                },

                onError: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressBtn.processing_error')
                    );

                    this.$renderPayPalBtn();
                }
            }).render(this.$PayPalBtnElm).then(() => {
                if (this.$ContextParent) {
                    this.Loader.hide();
                }

                window.paypalV1ButtonRendered = false;
            });

            this.$widgetsLoaded = true;
        },

        /**
         * Show PayPal Pay Button widget (btn) using the old checkout.js SDK
         */
        $renderPayPalBtnV1: function() {
            if (typeof paypal === 'undefined') {
                (() => this.$renderPayPalBtnV1()).delay(200);
                return;
            }

            // re-display if button was previously rendered and hidden
            this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
            this.$PayPalBtnElm.set('html', '');
            
            paypal.Button.render({
                env: !this.getAttribute('sandbox') ? 'production' : 'sandbox',
                commit: false,

                style: {
                    tagline: false,
                    label: 'paypal',
                    size: this.getAttribute('displaysize'),
                    shape: this.getAttribute('displayshape'),
                    color: this.getAttribute('displaycolor')
                },

                // payment() is called when the button is clicked
                payment: () => {
                    this.$showLoader(QUILocale.get(pkg, 'ExpressBtn.confirm_payment'));

                    return PayPalApi.createOrder(
                        this.$hash,
                        this.getAttribute('basketid'),
                        true
                    ).then((Order) => {
                        this.$hash = Order.hash;
                        return Order.payPalOrderId;
                    }, (Error) => {
                        this.$hideLoader();
                        this.$showErrorMsg(Error.getMessage());
                    });
                },

                onCancel: () => {
                    this.$hideLoader();
                },

                // onAuthorize() is called when the buyer approves the payment
                onAuthorize: () => {
                    this.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

                    PayPalApi.executeOrder(this.$hash, true).then((success) => {
                        if (success) {
                            this.$toCheckout();
                            return;
                        }

                        this.$hideLoader();

                        this.$showErrorMsg(
                            QUILocale.get(pkg, 'ExpressBtn.processing_error')
                        );
                    }, (Error) => {
                        this.$hideLoader();
                        this.$showErrorMsg(Error.getMessage());
                    });
                },

                onError: () => {
                    this.$showErrorMsg(
                        QUILocale.get(pkg, 'ExpressBtn.processing_error')
                    );

                    this.$renderPayPalBtnV1();
                }
            }, this.$PayPalBtnElm).then(() => {
                if (this.$ContextParent) {
                    this.Loader.hide();
                }

                window.paypalV1ButtonRendered = true;
            });

            this.$widgetsLoaded = true;
        },


        /**
         * Show Loader of the contextual Order process
         *
         * @param {String} [msg] - Loader message
         */
        $showLoader: function(msg) {
            switch (this.getAttribute('context')) {
                case 'basket':
                    if (this.$ContextParent) {
                        this.$ContextParent.Loader.show(msg);
                    }
                    break;

                case 'smallbasket':
                case 'simple-checkout':
                    this.PageLoader.show(msg);
                    break;

                case 'product':

                    break;
            }
        },

        /**
         * Hide Loader of the contextual Order process
         */
        $hideLoader: function() {
            switch (this.getAttribute('context')) {
                case 'basket':
                    if (this.$ContextParent) {
                        this.$ContextParent.Loader.hide();
                    }
                    break;

                case 'smallbasket':
                case 'simple-checkout':
                    this.PageLoader.hide();
                    break;

                case 'product':

                    break;
            }
        },

        /**
         * Go to Checkout step
         */
        $toCheckout: function() {
            window.location = this.getAttribute('orderprocessurl');
        },

        /**
         * Show error msg
         *
         * @param {String} msg
         */
        $showErrorMsg: function(msg) {
            if (this.$ErrorPopup) {
                this.$ErrorPopup.close();
            }

            this.$ErrorPopup = new QUIConfirm({
                maxHeight: 300,
                autoclose: false,

                information: msg,
                title: QUILocale.get(pkg, 'ExpressBtn.error.title'),
                texticon: 'fa fa-exclamation-triangle',
                text: QUILocale.get(pkg, 'ExpressBtn.error.text_title'),
                icon: 'fa fa-exclamation-triangle',

                cancel_button: false,
                ok_button: {
                    text: false,
                    textimage: 'icon-ok fa fa-check'
                },

                events: {
                    onSubmit: function(Popup) {
                        Popup.close();
                    }
                }
            });

            this.$ErrorPopup.open();
        },

        /**
         * Show normal msg
         *
         * @param {String} msg
         */
        $showMsg: function(msg) {
            this.$MsgElm.set(
                'html',
                '<span>' + msg + '</span>'
            );
        }
    });
});
