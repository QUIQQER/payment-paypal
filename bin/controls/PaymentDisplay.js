/**
 * PaymentDisplay for PayPal
 */
define('package/quiqqer/payment-paypal/bin/controls/PaymentDisplay', [

    'qui/controls/Control',
    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',
    'package/quiqqer/payment-paypal/bin/classes/WebSdk',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/PaymentDisplay.css'

], function (QUIControl, QUIControlUtils, PayPalApi, WebSdk, QUILocale) {
    'use strict';

    const pkg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/PaymentDisplay',

        Binds: [
            '$onDestroy',
            '$onImport',
            '$onPayBtnClick'
        ],

        options: {
            sandbox: true,
            orderhash: '',
            currency: '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$PayPalBtnElm = null;
            this.$MsgElm = null;
            this.$OrderProcess = null;
            this.$PaymentSession = null;
            this.$hash = null;
            this.$flowErrorHandled = false;

            this.addEvents({
                onDestroy: this.$onDestroy,
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

            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$PayPalBtnElm = Elm.getElement('[data-name="paypal-button"]');

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
         * Remove the button listener when the control is destroyed.
         */
        $onDestroy: function () {
            if (this.$PayPalBtnElm) {
                this.$PayPalBtnElm.removeEventListener('click', this.$onPayBtnClick);
            }
        },

        /**
         * Initialize the v6 SDK, check eligibility and display the PayPal button.
         */
        $loadPayPalWidgets: function () {
            this.$OrderProcess.Loader.show();

            WebSdk.getInstance(this.getAttribute('sandbox')).then((Sdk) => {
                return Sdk.findEligibleMethods({
                    currencyCode: String(this.getAttribute('currency')).toUpperCase()
                }).then((Methods) => {
                    if (!Methods.isEligible('paypal')) {
                        throw new Error('PayPal is not eligible for this order.');
                    }

                    this.$PaymentSession = Sdk.createPayPalOneTimePaymentSession({
                        onApprove: () => this.$executeOrder(),
                        onCancel: () => this.$handleCancel(),
                        onError: () => this.$handleProcessingError()
                    });

                    this.$PayPalBtnElm.addEventListener('click', this.$onPayBtnClick);
                    this.$PayPalBtnElm.removeAttribute('hidden');
                    this.$OrderProcess.resize();
                    this.$OrderProcess.Loader.hide();
                });
            }).catch(() => {
                this.$OrderProcess.Loader.hide();
                this.$handleProcessingError();
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
            this.$OrderProcess.Loader.show(
                QUILocale.get(pkg, 'PaymentDisplay.confirm_payment')
            );

            const orderPromise = PayPalApi.createOrder(
                this.getAttribute('orderhash'),
                this.getAttribute('basketid'),
                false
            ).then((Order) => {
                if (!Order || !Order.hash || !Order.payPalOrderId) {
                    throw new Error('PayPal order could not be created.');
                }

                this.$hash = Order.hash;
                return Order.payPalOrderId;
            }).catch((Error) => {
                this.$handleApiError(Error);
                throw Error;
            });

            this.$PaymentSession.start(
                {presentationMode: 'auto'},
                orderPromise
            ).catch(() => {
                this.$handleProcessingError();
            });
        },

        /**
         * Capture the approved PayPal order on the server.
         *
         * @return {Promise<Object>}
         */
        $executeOrder: function () {
            this.$OrderProcess.Loader.show(
                QUILocale.get(pkg, 'PaymentDisplay.execute_payment')
            );

            return PayPalApi.executeOrder(this.$hash, false).then((success) => {
                if (!success) {
                    throw new Error('PayPal order could not be captured.');
                }

                this.$OrderProcess.next();
                return {success: true};
            }).catch((Error) => {
                this.$handleApiError(Error);
                throw Error;
            });
        },

        /**
         * Handle a buyer cancellation.
         */
        $handleCancel: function () {
            this.$flowErrorHandled = true;
            this.$OrderProcess.Loader.hide();
            this.$showErrorMsg(QUILocale.get(pkg, 'PaymentDisplay.user_cancel'));
            this.fireEvent('processingError', [this]);
        },

        /**
         * Handle a PayPal or SDK processing error.
         */
        $handleProcessingError: function () {
            if (this.$flowErrorHandled) {
                return;
            }

            this.$flowErrorHandled = true;
            this.$OrderProcess.Loader.hide();
            this.$showErrorMsg(QUILocale.get(pkg, 'PaymentDisplay.processing_error'));
            this.fireEvent('processingError', [this]);
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
            this.$OrderProcess.Loader.hide();

            if (Error && typeof Error.getMessage === 'function') {
                this.$showErrorMsg(Error.getMessage());
            } else {
                this.$showErrorMsg(QUILocale.get(pkg, 'PaymentDisplay.processing_error'));
            }

            this.fireEvent('processingError', [this]);
        },

        /**
         * Show error message.
         *
         * @param {String} msg
         */
        $showErrorMsg: function (msg) {
            this.$MsgElm.set('html', '');

            new Element('p', {
                'class': 'message-error',
                text: msg
            }).inject(this.$MsgElm);
        },

        /**
         * Show normal message.
         *
         * @param {String} msg
         */
        $showMsg: function (msg) {
            this.$MsgElm.set('html', '');
            new Element('p', {text: msg}).inject(this.$MsgElm);
        }
    });
});
