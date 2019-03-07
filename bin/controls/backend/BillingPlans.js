/**
 * List of all contracts
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/BillingPlans
 * @author www.pcsg.de (Henning Leutz)
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/payment-paypal/bin/controls/backend/BillingPlans', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'package/quiqqer/payment-paypal/bin/PayPal',
    'Locale',

    'css!package/quiqqer/payment-paypal/bin/controls/backend/BillingPlans.css'

], function (QUIControl, QUILoader, QUIConfirm, QUIButton, Grid, PayPal, QUILocale) {
    "use strict";

    var lg = 'quiqqer/payment-paypal';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/payment-paypal/bin/controls/backend/BillingPlans',

        Binds: [
            'refresh',
            '$onCreate',
            '$onImport',
            '$onResize'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$Grid    = null;
            this.$Content = null;
            this.Loader   = new QUILoader();

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

            return PayPal.getBillingPlans({
                perPage: this.$Grid.options.perPage,
                page   : this.$Grid.options.page,
                sortBy : this.$Grid.options.sortBy,
                sortOn : this.$Grid.options.sortOn
            }).then(function (result) {
                this.Loader.hide();

                this.$Grid.setData(result);


            }.bind(this), function () {
                this.destroy();
            }.bind(this));
        },

        /**
         * Event Handling
         */

        $onImport: function () {
            this.$Content = new Element('div', {
                'class': 'quiqqer-payment-paypal-billingplans field-container-field'
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

            // Buttons

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
                sortOn           : 'c_date',
                sortBy           : 'DESC',

                accordion           : false,
                autoSectionToggle   : false,
                openAccordionOnClick: false,

                buttons    : [{
                    name     : 'delete',
                    text     : QUILocale.get(lg, 'controls.backend.BillingPlans.tbl.btn.delete'),
                    textimage: 'fa fa-trash',
                    events   : {
                        onClick: function (Btn) {
                            Btn.setAttribute('textimage', 'fa fa-spinner fa-spin');

                            self.$clickCreateContract(Btn).then(function () {
                                Btn.setAttribute('textimage', 'fa fa-plus');
                            });
                        }
                    }
                }],
                columnModel: [{
                    header   : QUILocale.get(lg, 'controls.backend.BillingPlans.tbl.id'),
                    dataIndex: 'id',
                    dataType : 'string',
                    width    : 200
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingPlans.tbl.name'),
                    dataIndex: 'name',
                    dataType : 'string',
                    width    : 250
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingPlans.tbl.description'),
                    dataIndex: 'description',
                    dataType : 'string',
                    width    : 250
                }, {
                    header   : QUILocale.get(lg, 'controls.backend.BillingPlans.tbl.create_time'),
                    dataIndex: 'create_time',
                    dataType : 'string',
                    width    : 150
                }]
            });

            this.$Grid.addEvents({
                onRefresh: this.refresh,

                onClick: function () {
                    var selected = this.getSelectedIndices();
                    console.log("click");
                },

                onDblClick: function (data) {
                    console.log("dbl click");
                }
            });

            this.$onResize();
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
         * Cancel contract dialog
         */
        $clickCancel: function () {
            new CancelDialog({
                contractId: this.$Grid.getSelectedData()[0].id
            }).open();
        }
    });
});
