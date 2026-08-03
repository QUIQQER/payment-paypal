/**
 * PaymentDisplay for PayPal
 */
define('package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay', [

    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay.css'

], function (QUIControl, QUIButton, QUIControlUtils, PayPal, QUILocale) {
    "use strict";

    const lg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay',

        Binds: [
            '$onImport',
            '$advanceOrderProcess',
            '$hideLoader',
            '$loadBillingAgreementButton',
            '$resolveOrderProcess',
            '$showLoader',
            '$showErrorMsg',
            '$showMsg'
        ],

        options: {
            orderhash: '',
            successful: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$MsgElm = null;
            this.$OrderProcess = null;
            this.$OrderProcessPromise = null;
            this.$loaderActive = false;

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

            this.$MsgElm = Elm.getElement('.quiqqer-payment-paypal-message');
            this.$Content = Elm.getElement('.quiqqer-payment-paypal-content');

            this.$showMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.PaymentDisplay.info'));

            this.$resolveOrderProcess();

            if (this.getAttribute('successful')) {
                this.$advanceOrderProcess();
                return;
            }

            this.$loadBillingAgreementButton();
        },

        /**
         * Resolve the surrounding order process without blocking the PayPal UI.
         *
         * @return {Promise<QUIControl|null>}
         */
        $resolveOrderProcess: function () {
            if (this.$OrderProcess) {
                return Promise.resolve(this.$OrderProcess);
            }

            if (this.$OrderProcessPromise) {
                return this.$OrderProcessPromise;
            }

            const OrderProcessNode = this.getElm().getParent(
                '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
            );

            if (!OrderProcessNode) {
                return Promise.resolve(null);
            }

            const ControlPromise = QUIControlUtils.getControlByElement(OrderProcessNode).then(
                (OrderProcess) => OrderProcess || null,
                () => null
            );
            const TimeoutPromise = new Promise((resolve) => {
                setTimeout(() => resolve(null), 1000);
            });

            this.$OrderProcessPromise = Promise.race([ControlPromise, TimeoutPromise]).then((OrderProcess) => {
                this.$OrderProcess = OrderProcess;
                this.$OrderProcessPromise = null;

                return this.$OrderProcess;
            });

            return this.$OrderProcessPromise;
        },

        /**
         * Continue the classic or embedded order process.
         *
         * @return {Promise<void>}
         */
        $advanceOrderProcess: function () {
            return this.$resolveOrderProcess().then((OrderProcess) => {
                if (OrderProcess && typeof OrderProcess.next === 'function') {
                    OrderProcess.next();
                    return;
                }

                window.location.reload();
            });
        },

        /**
         * Show the order process loader when one is available.
         *
         * @param {String} message
         */
        $showLoader: function (message) {
            this.$loaderActive = true;

            this.$resolveOrderProcess().then((OrderProcess) => {
                if (this.$loaderActive && OrderProcess && OrderProcess.Loader) {
                    OrderProcess.Loader.show(message);
                }
            });
        },

        /**
         * Hide a previously displayed order process loader.
         */
        $hideLoader: function () {
            this.$loaderActive = false;

            this.$resolveOrderProcess().then((OrderProcess) => {
                if (OrderProcess && OrderProcess.Loader) {
                    OrderProcess.Loader.hide();
                }
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadBillingAgreementButton: function () {
            let popupClosedByScript = false;

            window.addEventListener("message", (event) => {
                if (event.origin !== window.location.origin) {
                    return;
                }

                if (
                    !event.data ||
                    event.data.source !== "quiqqer-payment-paypal-recurring" ||
                    event.data.orderHash !== this.getAttribute('orderhash')
                ) {
                    return;
                }

                popupClosedByScript = true;

                if (event.data.status !== "success") {
                    this.$showErrorMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.popup.payment.error'));
                    PayPalButton.enable();
                    return;
                }

                this.$advanceOrderProcess();
            });

            const imageUrl = URL_OPT_DIR + 'quiqqer/payment-paypal/bin/images/';

            const PayPalButton = new QUIButton({
                'class': 'quiqqer-payment-paypal-recurring-paymentdisplay-btn',
                disabled: true,
                text: '<img src="'+ imageUrl +'Paypal-Logo.svg" alt=""/><img src="'+ imageUrl +'Paypal.svg" alt=""/>',
                events: {
                    onClick: (Btn) => {
                        Btn.disable();
                        let popup = window.open(Btn.getAttribute('approvalUrl'), 'paypalWindow', 'width=600,height=800');

                        this.$Content.querySelectorAll('.content-message-error').forEach((node) => {
                            node.parentNode.removeChild(node);
                        });

                        if (!popup) {
                            Btn.enable();

                            new Element('div', {
                                'class': 'content-message-error',
                                html: QUILocale.get(lg, 'controls.recurring.PaymentDisplay.popup.open.error'),
                            }).inject(this.$Content);

                            return;
                        }

                        const checkPopupStatus = () => {
                            if (popup.closed) {
                                if (!popupClosedByScript) {
                                    Btn.enable();

                                    new Element('div', {
                                        'class': 'content-message-error',
                                        html: QUILocale.get(lg, 'controls.recurring.PaymentDisplay.popup.payment.error'),
                                    }).inject(this.$Content);
                                }

                                return;
                            }

                            setTimeout(checkPopupStatus, 500);
                        };

                        setTimeout(checkPopupStatus, 500);
                    }
                }
            }).inject(this.$Content);

            const ButtonText = new Element('div', {
                'class': 'quiqqer-payment-paypal-buttonText',
                html: QUILocale.get(lg, 'controls.recurring.PaymentDisplay.btn.text_create')
            }).inject(this.$Content);

            PayPalButton.getElm().classList.remove('qui-button'); // workaround -> nice button

            this.$showLoader(
                QUILocale.get(lg, 'controls.recurring.PaymentDisplay.Loader.create_billing_agreement')
            );

            PayPal.createBillingAgreement(this.getAttribute('orderhash')).then((Data) => {
                this.$hideLoader();

                if (!Data || !Data.approvalUrl) {
                    PayPalButton.destroy();
                    this.$showErrorMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.error'));
                    this.fireEvent('processingError', [this]);
                    return;
                }

                ButtonText.innerHTML = QUILocale.get(lg, 'controls.recurring.PaymentDisplay.btn.text');
                PayPalButton.setAttribute('approvalUrl', Data.approvalUrl);
                PayPalButton.enable();
            }, () => {
                this.$hideLoader();
                PayPalButton.destroy();
                this.$showErrorMsg(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.error'));
                this.fireEvent('processingError', [this]);
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
