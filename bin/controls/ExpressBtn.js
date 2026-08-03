/**
 * Button for PayPal Express checkout
 */
define('package/quiqqer/payment-paypal/bin/controls/ExpressBtn', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/windows/Confirm',
    'qui/controls/loader/Loader',
    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',
    'package/quiqqer/payment-paypal/bin/classes/WebSdk',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/ExpressBtn.css'

], function (QUI, QUIControl, QUIConfirm, QUILoader, QUIControlUtils, PayPalApi, WebSdk, QUILocale) {
    'use strict';

    const pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/ExpressBtn',

        Binds: [
            '$onDestroy',
            '$onImport',
            '$onInject',
            '$onPayBtnClick',
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
            currency: '',
            displaysize: '',
            displaycolor: '',
            displayshape: ''
        },

        initialize: function (options) {
            this.parent(options);

            this.$PayPalBtnElm = null;
            this.$MsgElm = null;
            this.$ContextParent = null;
            this.$hash = false;
            this.$widgetsLoaded = false;
            this.$ErrorPopup = null;
            this.$PaymentSession = null;
            this.$flowErrorHandled = false;

            this.Loader = new QUILoader();
            this.PageLoader = new QUILoader();

            this.addEvents({
                onDestroy: this.$onDestroy,
                onImport: this.$onImport,
                onInject: this.$onInject
            });
        },

        /**
         * Event: onImport
         */
        $onImport: function () {
            if (this.getAttribute('checkout')) {
                this.$toCheckout();
                return;
            }

            const Elm = this.getElm();

            Elm.addClass('quiqqer-payment-paypal-express');
            Elm.set(
                'html',
                '<div class="quiqqer-payment-paypal-express-msg" data-name="message"></div>' +
                '<div class="quiqqer-payment-paypal-express-btn" data-name="button-container">' +
                '<paypal-button data-name="paypal-button" type="pay" hidden></paypal-button>' +
                '</div>'
            );

            this.$hash = this.getAttribute('orderhash');
            this.$MsgElm = Elm.getElement('[data-name="message"]');
            this.$PayPalBtnElm = Elm.getElement('[data-name="paypal-button"]');

            const PageLoaderElm = document.body.getElement(
                '.quiqqer-payment-paypal-express-pageloader'
            );

            if (PageLoaderElm) {
                this.PageLoader = QUI.Controls.getById(PageLoaderElm.get('data-quiid'));
            } else {
                this.PageLoader.getElm().addClass('quiqqer-payment-paypal-express-pageloader');
                this.PageLoader.inject(document.body);
            }

            this.Loader.inject(Elm);
            this.Loader.show();
            this.$loadPayPalWidgets();
            this.$loadContextParent();
        },

        /**
         * Event: onInject
         */
        $onInject: function () {
            this.create();
            this.$onImport();
        },

        /**
         * Remove the button listener when the control is destroyed.
         */
        $onDestroy: function () {
            if (this.$PayPalBtnElm) {
                this.$PayPalBtnElm.removeEventListener('click', this.$onPayBtnClick);
            }
        },

        /**
         * Resolve the control which owns the contextual loader.
         */
        $loadContextParent: function () {
            let selector = false;

            switch (this.getAttribute('context')) {
                case 'basket':
                    selector = '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]';
                    break;

                case 'smallbasket':
                    selector = '.quiqqer-order-basket-small-container > .qui-control';
                    break;

                case 'simple-checkout':
                    selector = '.quiqqer-simple-checkout';
                    break;
            }

            if (!selector) {
                return;
            }

            const Parent = this.getElm().getParent(selector);

            if (!Parent) {
                return;
            }

            QUIControlUtils.getControlByElement(Parent).then((ContextControl) => {
                this.$ContextParent = ContextControl;

                if (this.$widgetsLoaded) {
                    this.Loader.hide();
                }
            }, () => {
                console.error('PayPal Express context control not found.');
            });
        },

        /**
         * Initialize the v6 SDK, check eligibility and display the PayPal button.
         */
        $loadPayPalWidgets: function () {
            const currency = String(this.getAttribute('currency')).toUpperCase();

            if (!currency) {
                this.Loader.hide();
                this.$showErrorMsg(QUILocale.get(pkg, 'ExpressBtn.processing_error'));
                return;
            }

            WebSdk.getInstance(this.getAttribute('sandbox')).then((Sdk) => {
                return Sdk.findEligibleMethods({currencyCode: currency}).then((Methods) => {
                    if (!Methods.isEligible('paypal')) {
                        throw new Error('PayPal is not eligible for this order.');
                    }

                    this.$PaymentSession = Sdk.createPayPalOneTimePaymentSession({
                        onApprove: () => this.$executeOrder(),
                        onCancel: () => this.$handleCancel(),
                        onError: (Error) => this.$handleProcessingError(Error)
                    });

                    this.$PayPalBtnElm.addEventListener('click', this.$onPayBtnClick);
                    this.$PayPalBtnElm.removeAttribute('hidden');
                    this.$widgetsLoaded = true;

                    if (this.$ContextParent) {
                        this.Loader.hide();
                    }
                });
            }).catch((Error) => {
                this.Loader.hide();
                this.$handleProcessingError(Error);
            });
        },

        /**
         * Start a PayPal v6 one-time payment session.
         */
        $onPayBtnClick: function () {
            if (!this.$PaymentSession) {
                return;
            }

            this.$flowErrorHandled = false;
            this.$showLoader(QUILocale.get(pkg, 'ExpressBtn.confirm_payment'));

            const orderPromise = PayPalApi.createOrder(
                this.$hash,
                this.getAttribute('basketid'),
                true
            ).then((Order) => {
                if (!Order || !Order.hash || !Order.payPalOrderId) {
                    throw new Error('PayPal order could not be created.');
                }

                this.$hash = Order.hash;
                return {orderId: Order.payPalOrderId};
            }).catch((Error) => {
                this.$handleApiError(Error);
                throw Error;
            });

            this.$PaymentSession.start(
                {presentationMode: 'auto'},
                orderPromise
            ).catch((Error) => {
                this.$handleProcessingError(Error);
            });
        },

        /**
         * Execute the approved Express order.
         *
         * @return {Promise<Object>}
         */
        $executeOrder: function () {
            this.$PayPalBtnElm.addClass('quiqqer-payment-paypal__hidden');

            return PayPalApi.executeOrder(this.$hash, true).then((success) => {
                if (!success) {
                    throw new Error('PayPal Express order could not be executed.');
                }

                this.$toCheckout();
                return {success: true};
            }).catch((Error) => {
                this.$PayPalBtnElm.removeClass('quiqqer-payment-paypal__hidden');
                this.$handleApiError(Error);
                throw Error;
            });
        },

        /**
         * Handle a buyer cancellation.
         */
        $handleCancel: function () {
            this.$flowErrorHandled = true;
            this.$hideLoader();
        },

        /**
         * Handle a PayPal or SDK processing error.
         *
         * @param {Error|Object} Error
         */
        $handleProcessingError: function (Error) {
            if (this.$flowErrorHandled) {
                return;
            }

            console.error('PayPal Web SDK v6 Express payment error', Error);

            this.$flowErrorHandled = true;
            this.$hideLoader();
            this.$showErrorMsg(QUILocale.get(pkg, 'ExpressBtn.processing_error'));
        },

        /**
         * Handle an error returned by a QUIQQER AJAX call.
         *
         * @param {Error|Object} Error
         */
        $handleApiError: function (Error) {
            if (this.$flowErrorHandled) {
                return;
            }

            this.$flowErrorHandled = true;
            this.$hideLoader();

            if (Error && typeof Error.getMessage === 'function') {
                this.$showErrorMsg(Error.getMessage());
                return;
            }

            this.$showErrorMsg(QUILocale.get(pkg, 'ExpressBtn.processing_error'));
        },

        /**
         * Show loader of the contextual order process.
         *
         * @param {String} [msg]
         */
        $showLoader: function (msg) {
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
            }
        },

        /**
         * Hide loader of the contextual order process.
         */
        $hideLoader: function () {
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
            }
        },

        /**
         * Go to the checkout step.
         */
        $toCheckout: function () {
            window.location = this.getAttribute('orderprocessurl');
        },

        /**
         * Show an error popup.
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
                    onSubmit: function (Popup) {
                        Popup.close();
                    }
                }
            });

            this.$ErrorPopup.open();
        }
    });
});
