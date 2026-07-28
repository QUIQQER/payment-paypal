/**
 * Show details of a PayPal Billing Agreement
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow
 * @author www.pcsg.de (Patrick Müller)
 *
 * @event onCancelBillingAgreement [this]
 */
define('package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow', [

    'qui/controls/windows/Popup',
    'qui/controls/windows/Confirm',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',

    'Locale',
    'Ajax',

    'package/quiqqer/payment-paypal/bin/PayPal',

    'css!package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow.css'

], function (QUIPopup, QUIConfirm, QUILoader, QUIButton, QUILocale, QUIAjax, PayPal) {
    "use strict";

    const lg = 'quiqqer/payment-paypal';

    return new Class({
        Extends: QUIPopup,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow',

        Binds: [
            '$onOpen',
            '$confirmCancel'
        ],

        options: {
            billingAgreementId: false,

            maxWidth : 900,	// {integer} [optional]max width of the window
            maxHeight: 900,	// {integer} [optional]max height of the window
            content  : false,	// {string} [optional] content of the window
            icon     : 'fa fa-paypal',
            title    : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.title'),

            // buttons
            buttons         : true, // {bool} [optional] show the bottom button line
            closeButton     : true, // {bool} show the close button
            titleCloseButton: true  // {bool} show the title close button
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onOpen: this.$onOpen
            });
        },

        /**
         * Event: onOpen
         *
         * Build content
         */
        $onOpen: function () {
            let CancelBtn;

            this.getElm().addClass('quiqqer-payment-paypal-backend-billingagreementwindow');

            this.Loader.show(
                QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.loading_data')
            );

            PayPal.getBillingAgreement(this.getAttribute('billingAgreementId')).then((BillingAgreement) => {
                this.Loader.hide();

                if (!BillingAgreement) {
                    this.setContent(
                        QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.load_error')
                    );

                    return;
                }

                this.setContent('<pre>' + JSON.stringify(BillingAgreement, null, 2) + '</pre>');

                let isActive;

                switch (BillingAgreement.state) {
                    case 'Active':
                    case 'Suspended':
                        isActive = true;
                        break;

                    default:
                        isActive = false;
                }

                if (isActive) {
                    CancelBtn.enable();
                } else if (BillingAgreement.quiqqer_data.active) {
                    new Element('div', {
                        'class': 'messages-message message-error box',
                        html   : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.inactive_warning')
                    }).inject(this.getContent(), 'top');
                }
            }, () => {
                this.close();
            });

            CancelBtn = new QUIButton({
                text     : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.btn.cancel'),
                textimage: 'fa fa-ban',
                disabled : true,
                events   : {
                    onClick: this.$confirmCancel
                }
            });

            this.addButton(CancelBtn);
        },

        /**
         * Confirm Billing Agreement cancellation
         */
        $confirmCancel: function () {
            new QUIConfirm({
                maxHeight: 300,
                maxWidth : 600,
                autoclose: false,

                information: QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.cancel.information'),
                title      : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.cancel.title'),
                texticon   : 'fa fa-ban',
                text       : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.cancel.text'),
                icon       : 'fa fa-ban',

                cancel_button: {
                    text     : false,
                    textimage: 'icon-remove fa fa-remove'
                },
                ok_button    : {
                    text     : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.cancel.confirm'),
                    textimage: 'icon-ok fa fa-check'
                },

                events: {
                    onSubmit: (Popup) => {
                        Popup.Loader.show();

                        PayPal.cancelBillingAgreement(this.getAttribute('billingAgreementId')).then(() => {
                            this.close();
                            Popup.close();
                            this.fireEvent('cancelBillingAgreement', [this]);
                        }, () => {
                            Popup.Loader.hide();
                        });
                    }
                }
            }).open();
        }
    });
});
