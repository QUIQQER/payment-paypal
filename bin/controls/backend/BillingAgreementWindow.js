/**
 * Show details of a PayPal Billing Agreement
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow', [

    'qui/controls/windows/Popup',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',

    'Locale',
    'Ajax',

    'package/quiqqer/payment-paypal/bin/PayPal',

    'css!package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow.css'

], function (QUIPopup, QUILoader, QUIButton, QUILocale, QUIAjax, PayPal) {
    "use strict";

    var lg = 'quiqqer/payment-paypal';

    return new Class({
        Extends: QUIPopup,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow',

        Binds: [
            '$onOpen',
            '$onSubmit'
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
            var self = this,
                CancelBtn;

            this.getElm().addClass('hklused-newsletter-mail');

            this.Loader.show();

            PayPal.getBillingAgreement(this.getAttribute('billingAgreementId')).then(function (BillingAgreement) {
                self.Loader.hide();
                self.setContent('<pre>' + JSON.stringify(BillingAgreement, null, 2) + '</pre>');

                if (BillingAgreement.state === 'Active') {
                    CancelBtn.enable();
                }
            }, function () {
                self.close();
            });

            CancelBtn = new QUIButton({
                text     : QUILocale.get(lg, 'controls.backend.BillingAgreementWindow.btn.cancel'),
                textimage: 'fa fa-ban',
                disabled : true,
                events   : {
                    onClick: function () {
                        self.Loader.show();

                        PayPal.cancelBillingAgreement(self.getAttribute('billingAgreementId')).then(function () {
                            self.close();
                        }, function () {
                            self.Loader.hide();
                        })
                    }
                }
            });

            this.addButton(CancelBtn);
        }
    });
});
