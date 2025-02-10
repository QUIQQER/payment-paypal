/**
 * PaymentDisplay for PayPal
 */
define('package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',

    'utils/Controls',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'Ajax',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay.css'

], function (QUI, QUIControl, QUIButton, QUIControlUtils, PayPal, QUIAjax, QUILocale) {
    "use strict";

    const lg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/recurring/PaymentDisplay',

        Binds: [
            '$onImport',
            '$loadBillingAgreementButton',
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

            QUIControlUtils.getControlByElement(
                Elm.getParent('[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]')
            ).then((OrderProcess) => {
                this.$OrderProcess = OrderProcess;

                if (this.getAttribute('successful')) {
                    OrderProcess.next();
                    return;
                }

                this.$loadBillingAgreementButton();
            });
        },

        /**
         * Load PayPal Pay widgets
         */
        $loadBillingAgreementButton: function () {
            let popupClosedByScript = false;

            window.addEventListener("message", function (event) {
                if (event.data.status === "paypal-success") {
                    console.log("Zahlung erfolgreich!");

                    popupClosedByScript = true;

                    const orderProcessNode = document.querySelector(
                        '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
                    );

                    if (orderProcessNode) {
                        const Order = QUI.Controls.getById(orderProcessNode.get('data-quiid'));

                        if (Order) {
                            Order.next();
                        }
                    } else {
                        window.location.reload();
                    }
                }
            });

            const imageUrl = URL_OPT_DIR + 'quiqqer/payment-paypal/bin/images/';

            const PayPalButton = new QUIButton({
                'class': 'btn btn-primary button quiqqer-payment-paypal-recurring-paymentdisplay-btn',
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
            }).inject(this.$Content)

            PayPalButton.getElm().classList.remove('qui-button'); // workaround -> nice button

            this.$OrderProcess.Loader.show(QUILocale.get(lg, 'controls.recurring.PaymentDisplay.Loader.create_billing_agreement'));

            PayPal.createBillingAgreement(this.getAttribute('orderhash')).then((Data) => {
                this.$OrderProcess.Loader.hide();
                ButtonText.innerHTML = QUILocale.get(lg, 'controls.recurring.PaymentDisplay.btn.text');
                PayPalButton.setAttribute('approvalUrl', Data.approvalUrl);
                PayPalButton.enable();
            }, () => {
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