/**
 * List of all modern PayPal Subscriptions.
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/Subscriptions
 */
/* global QUI */
define('package/quiqqer/payment-paypal/bin/controls/backend/Subscriptions', [
    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',
    'package/quiqqer/payment-paypal/bin/PayPal',
    'package/quiqqer/payment-paypal/bin/controls/backend/SubscriptionWindow',
    'Locale',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/Subscriptions.css'
], function(
    QUIControl,
    QUILoader,
    QUIButton,
    Grid,
    PayPal,
    SubscriptionWindow,
    QUILocale
) {
    'use strict';

    const lg = 'quiqqer/payment-paypal';

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/backend/Subscriptions',

        Binds: [
            'refresh',
            '$onCreate',
            '$onImport',
            '$onResize',
            '$openDetails'
        ],

        initialize: function(options) {
            this.parent(options);

            this.$Content = null;
            this.$Grid = null;
            this.$GridContainer = null;
            this.$Info = null;
            this.$Panel = null;
            this.$SearchButton = null;
            this.$SearchInput = null;
            this.Loader = new QUILoader();

            this.addEvents({
                onCreate: this.$onCreate,
                onImport: this.$onImport,
                onResize: this.$onResize
            });
        },

        refresh: function() {
            if (!this.$Grid) {
                return Promise.resolve();
            }

            this.Loader.show();

            const search = this.$SearchInput.value.trim();

            return PayPal.getSubscriptionList({
                perPage: this.$Grid.options.perPage,
                page: this.$Grid.options.page,
                sortBy: this.$Grid.options.sortBy,
                sortOn: this.$Grid.options.sortOn,
                search: search || false
            }).then((result) => {
                const Buttons = this.$Grid.getAttribute('buttons');

                Buttons.details.disable();

                result.data.forEach((row) => {
                    const subscriptionData = row.subscription_data || {};
                    const status = subscriptionData.status || 'UNKNOWN';
                    const statusClass = status.toLowerCase().replace(/_/g, '-');
                    const customer = row.customer || {};
                    const name = customer.name || {};
                    const customerName = [
                        name.given_name || customer.firstname || '',
                        name.surname || customer.lastname || ''
                    ].join(' ').trim();
                    const email = customer.email_address || customer.email || '';

                    row.active_status = new Element('span', {
                        'class': row.active
                            ? 'fa fa-check quiqqer-payment-paypal-subscriptions-active'
                            : 'fa fa-close quiqqer-payment-paypal-subscriptions-inactive'
                    });
                    row.status_badge = new Element('span', {
                        'class': 'quiqqer-payment-paypal-subscriptions-status '
                            + 'status-' + statusClass,
                        text: status
                    });
                    row.customer_text = customerName && email
                        ? customerName + ' (' + email + ')'
                        : customerName || email || '—';
                    row.next_billing_time = subscriptionData.billing_info
                        && subscriptionData.billing_info.next_billing_time
                        ? subscriptionData.billing_info.next_billing_time
                        : '—';
                });

                this.$Grid.setData(result);
                this.Loader.hide();
            }).catch(() => {
                this.Loader.hide();
            });
        },

        $onImport: function() {
            this.$Content = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscriptions field-container-field'
            }).inject(this.getElm(), 'after');

            this.Loader.inject(this.$Content);

            this.$Content.getParent('form').setStyle('height', 'calc(100% - 40px)');
            this.$Content.getParent('table').setStyle('height', 'calc(100% - 40px)');
            this.$Content.getParent('tbody').setStyle('height', 'calc(100% - 40px)');
            this.$Content.getParent('.field-container').setStyle('height', 'calc(100% - 40px)');

            this.create();
            this.$onCreate();
            this.refresh();
        },

        $onCreate: function() {
            this.$Info = new Element('div', {
                'class': 'messages-message box message-information '
                    + 'quiqqer-payment-paypal-subscriptions-information',
                html: '<span class="fa fa-info-circle"></span>'
                    + '<div>'
                    + QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.information'
                    )
                    + '</div>'
            }).inject(this.$Content);

            const Toolbar = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscriptions-toolbar'
            }).inject(this.$Content);

            this.$SearchInput = new Element('input', {
                'class': 'quiqqer-payment-paypal-subscriptions-search',
                placeholder: QUILocale.get(
                    lg,
                    'controls.backend.Subscriptions.search.placeholder'
                ),
                events: {
                    keydown: (event) => {
                        if (event.key === 'Enter' || event.keyCode === 13) {
                            this.refresh();
                        }
                    }
                }
            }).inject(Toolbar);

            this.$SearchButton = new QUIButton({
                text: QUILocale.get(
                    lg,
                    'controls.backend.Subscriptions.search.button'
                ),
                textimage: 'fa fa-search',
                events: {
                    onClick: () => this.refresh()
                }
            }).inject(Toolbar);

            this.$GridContainer = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscriptions-grid'
            }).inject(this.$Content);

            this.$Grid = new Grid(this.$GridContainer, {
                pagination: true,
                multipleSelection: false,
                serverSort: true,
                sortOn: 'paypal_subscription_id',
                sortBy: 'DESC',
                accordion: false,
                autoSectionToggle: false,
                openAccordionOnClick: false,
                buttons: [{
                    name: 'details',
                    text: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.btn.details'
                    ),
                    textimage: 'fa fa-eye',
                    events: {
                        onClick: this.$openDetails
                    }
                }],
                columnModel: [{
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.active'
                    ),
                    dataIndex: 'active_status',
                    dataType: 'node',
                    className: 'grid-align-center',
                    width: 45
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.status'
                    ),
                    dataIndex: 'status_badge',
                    dataType: 'node',
                    width: 170
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.subscription_id'
                    ),
                    dataIndex: 'paypal_subscription_id',
                    dataType: 'string',
                    width: 145
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.plan_id'
                    ),
                    dataIndex: 'paypal_plan_id',
                    dataType: 'string',
                    width: 145
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.customer'
                    ),
                    dataIndex: 'customer_text',
                    dataType: 'string',
                    width: 190
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.next_billing'
                    ),
                    dataIndex: 'next_billing_time',
                    dataType: 'string',
                    width: 125
                }, {
                    header: QUILocale.get(
                        lg,
                        'controls.backend.Subscriptions.tbl.global_process_id'
                    ),
                    dataIndex: 'global_process_id',
                    dataType: 'string',
                    width: 155
                }]
            });

            this.$Grid.addEvents({
                onRefresh: this.refresh,
                onClick: () => {
                    const Buttons = this.$Grid.getAttribute('buttons');
                    const hasSelection = this.$Grid.getSelectedData().length === 1;

                    if (hasSelection) {
                        Buttons.details.enable();
                    } else {
                        Buttons.details.disable();
                    }
                },
                onDblClick: this.$openDetails
            });

            this.$Panel = QUI.Controls.getById(
                this.getElm().getParent('.qui-panel').get('data-quiid')
            );
            this.$Panel.addEvent('onResize', this.$onResize);
            this.$onResize();
        },

        $onResize: function() {
            if (!this.$Grid || !this.$Panel) {
                return;
            }

            const size = this.$Panel.getContent().getSize();
            const FieldContainer = this.$Content.getParent('.field-container');
            const width = Math.max(
                320,
                FieldContainer.getSize().x - 20
            );
            const infoHeight = this.$Info
                ? this.$Info.getSize().y + 10
                : 0;

            this.$Content.setStyle('width', width);
            const gridWidth = this.$Info
                ? this.$Info.getSize().x
                : width;

            this.$GridContainer.setStyle(
                'height',
                'calc(100% - ' + infoHeight + 'px)'
            );
            this.$Grid.setHeight(
                Math.max(280, size.y - 180 - infoHeight)
            );
            this.$Grid.setWidth(gridWidth);
            this.$Grid.resize();
        },

        $openDetails: function() {
            const selected = this.$Grid.getSelectedData();

            if (selected.length !== 1) {
                return;
            }

            new SubscriptionWindow({
                subscriptionId: selected[0].paypal_subscription_id,
                events: {
                    onUpdateSubscription: () => this.refresh()
                }
            }).open();
        }
    });
});
