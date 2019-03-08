/**
 * List of all PayPal Billing Agreements
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreements
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreements', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'package/quiqqer/payment-paypal/bin/PayPal',

    'package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreementWindow',

    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreements.css'

], function (QUIControl, QUILoader, QUIConfirm, QUIButton, Grid, PayPal, BillingAgreementWindow, QUILocale) {
    "use strict";

    var lg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/backend/BillingAgreements',

        Binds: [
            'refresh',
            '$onCreate',
            '$onImport',
            '$onResize',
            '$clickDetails'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Grid    = null;
            this.$Content = null;
            this.Loader   = new QUILoader();

            this.$search      = false;
            this.$SearchInput = null;

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize,
                onImport: this.$onImport
            });
        },

        /**
         * Refresh the grid
         */
        refresh: function () {
            if (!this.$Grid) {
                return;
            }

            this.Loader.show();

            var search = this.$SearchInput.value.trim();

            if (search === '') {
                search = false;
            }

            return PayPal.getBillingAgreementList({
                perPage: this.$Grid.options.perPage,
                page   : this.$Grid.options.page,
                sortBy : this.$Grid.options.sortBy,
                sortOn : this.$Grid.options.sortOn,
                search : search
            }).then(function (result) {
                var TableButtons = this.$Grid.getAttribute('buttons');
                TableButtons.details.disable();

                for (var i = 0, len = result.data.length; i < len; i++) {
                    var Row      = result.data[i];
                    var Customer = JSON.decode(Row.customer);

                    Row.customer_text = Customer.firstname + " " + Customer.lastname + " (" + Customer.id + ")";
                }

                this.$Grid.setData(result);
                this.Loader.hide();
            }.bind(this), function () {
                this.destroy();
            }.bind(this));
        },

        /**
         * Event Handling
         */

        $onImport: function () {
            this.$Content = new Element('div', {
                'class': 'quiqqer-payment-paypal-billingagreements field-container-field'
            }).inject(this.getElm(), 'after');

            this.Loader.inject(this.$Content);

            this.$Content.getParent('form').setStyle('height', '100%');
            this.$Content.getParent('table').setStyle('height', '100%');
            this.$Content.getParent('tbody').setStyle('height', '100%');
            this.$Content.getParent('.field-container').setStyle('height', '100%');

            this.create();
            this.$onCreate();
            this.refresh();
        },

        /**
         * event : on panel create
         */
        $onCreate: function () {
            var self = this;

            // Search
            this.$SearchInput = new Element('input', {
                'class'    : 'quiqqer-payment-paypal-billingagreements-search',
                placeholder: QUILocale.get(lg, 'controls.backend.BillingAgreements.search.placeholder'),
                events     : {
                    keydown: function (event) {
                        if (typeof event !== 'undefined' &&
                            event.code === 13) {
                            self.refresh();
                        }
                    }
                }
            }).inject(this.$Content);

            // Grid
            var Container = new Element('div', {
                style: {
                    height: '100%',
                    width : '100%'
                }
            }).inject(this.$Content);

            this.$Grid = new Grid(Container, {
                pagination       : true,
                multipleSelection: true,
                serverSort       : true,
                sortOn           : 'paypal_agreement_id',
                sortBy           : 'DESC',

                accordion           : false,
                autoSectionToggle   : false,
                openAccordionOnClick: false,

                buttons    : [{
                    name     : 'details',
                    text     : QUILocale.get(lg, 'controls.backend.BillingAgreements.tbl.btn.details'),
                    textimage: 'fa fa-paypal',
                    events   : {
                        onClick: this.$clickDetails
                    }
                }],
                columnModel: [{
                    header   : QUILocale.get(lg, 'controls.backend.BillingAgreements.tbl.paypal_agreement_id'),
                    dataIndex: 'paypal_agreement_id',
                    dataType : 'string',
                    width    : 150
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingAgreements.tbl.paypal_plan_id'),
                    dataIndex: 'paypal_plan_id',
                    dataType : 'string',
                    width    : 225
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingAgreements.tbl.customer_text'),
                    dataIndex: 'customer_text',
                    dataType : 'string',
                    width    : 250
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingAgreements.tbl.global_process_id'),
                    dataIndex: 'global_process_id',
                    dataType : 'string',
                    width    : 250
                }]
            });

            this.$Grid.addEvents({
                onRefresh: this.refresh,

                onClick: function () {
                    var TableButtons = self.$Grid.getAttribute('buttons'),
                        selected     = self.$Grid.getSelectedData().length;

                    if (!Object.getLength(TableButtons)) {
                        return;
                    }

                    if (selected === 1) {
                        TableButtons.details.enable();
                    } else {
                        TableButtons.details.disable();
                    }
                },

                onDblClick: this.$clickDetails
            });

            this.$onResize();
            //
            //new BillingAgreementWindow({
            //    billingAgreementId: 'I-58NBPN3G4S8J'
            //}).open();
        },

        /**
         * event : on panel resize
         */
        $onResize: function () {
            if (!this.$Grid) {
                return;
            }

            var size = this.$Content.getSize();

            this.$Grid.setHeight(size.y - 20);
            this.$Grid.setWidth(size.x - 20);
            this.$Grid.resize();
        },

        /**
         * Delete Billing Plan dialog
         */
        $clickDetails: function () {
            new BillingAgreementWindow({
                billingAgreementId: this.$Grid.getSelectedData()[0].paypal_agreement_id
            }).open();
        }
    });
});
