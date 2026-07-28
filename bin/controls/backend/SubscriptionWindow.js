/**
 * Display and manage a modern PayPal Subscription.
 *
 * @module package/quiqqer/payment-paypal/bin/controls/backend/SubscriptionWindow
 */
define('package/quiqqer/payment-paypal/bin/controls/backend/SubscriptionWindow', [
    'qui/controls/windows/Popup',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'package/quiqqer/payment-paypal/bin/PayPal',
    'Locale',
    'Permissions',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/SubscriptionWindow.css'
], function(QUIPopup, QUIConfirm, QUIButton, PayPal, QUILocale, Permissions) {
    'use strict';

    const lg = 'quiqqer/payment-paypal';

    return new Class({
        Extends: QUIPopup,
        Type: 'package/quiqqer/payment-paypal/bin/controls/backend/SubscriptionWindow',

        Binds: [
            '$onOpen'
        ],

        options: {
            subscriptionId: false,
            maxWidth: 960,
            maxHeight: 840,
            icon: 'fa fa-paypal',
            title: QUILocale.get(
                lg,
                'controls.backend.SubscriptionWindow.title'
            ),
            buttons: true,
            closeButton: true,
            titleCloseButton: true
        },

        initialize: function(options) {
            this.parent(options);

            this.$ActionButtons = {};
            this.$CanManage = false;

            this.addEvents({
                onOpen: this.$onOpen
            });
        },

        $onOpen: function() {
            this.getElm().addClass(
                'quiqqer-payment-paypal-backend-subscriptionwindow'
            );

            this.Loader.show(
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.loading'
                )
            );

            Permissions.hasPermission(
                'quiqqer.payments.paypal.subscriptions.manage'
            ).then((canManage) => {
                this.$CanManage = canManage;

                if (canManage) {
                    this.$createActionButtons();
                }

                this.$load();
            }).catch(() => {
                this.$load();
            });
        },

        $createActionButtons: function() {
            this.$ActionButtons.suspend = new QUIButton({
                text: QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.btn.suspend'
                ),
                textimage: 'fa fa-pause',
                disabled: true,
                events: {
                    onClick: () => this.$confirmAction('suspend')
                }
            });
            this.$ActionButtons.activate = new QUIButton({
                text: QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.btn.activate'
                ),
                textimage: 'fa fa-play',
                disabled: true,
                events: {
                    onClick: () => this.$confirmAction('activate')
                }
            });
            this.$ActionButtons.cancel = new QUIButton({
                text: QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.btn.cancel'
                ),
                textimage: 'fa fa-ban',
                disabled: true,
                events: {
                    onClick: () => this.$confirmAction('cancel')
                }
            });

            this.addButton(this.$ActionButtons.suspend);
            this.addButton(this.$ActionButtons.activate);
            this.addButton(this.$ActionButtons.cancel);
        },

        $load: function() {
            this.$disableActionButtons();
            this.Loader.show(
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.loading'
                )
            );

            return PayPal.getSubscription(
                this.getAttribute('subscriptionId')
            ).then((result) => {
                this.Loader.hide();

                if (!result) {
                    this.setContent(
                        QUILocale.get(
                            lg,
                            'controls.backend.SubscriptionWindow.not_found'
                        )
                    );
                    return;
                }

                this.$render(result);
                this.$configureActionButtons(result);
            }).catch(() => {
                this.Loader.hide();
                this.setContent(
                    QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.load_error'
                    )
                );
            });
        },

        $render: function(result) {
            const localData = result.local || {};
            const providerData = result.provider
                || localData.subscriptionData
                || {};
            const status = providerData.status || 'UNKNOWN';
            const Content = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details'
            });
            const Header = new Element('header', {
                'class': 'quiqqer-payment-paypal-subscription-details-header'
            }).inject(Content);

            new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-heading',
                html: '<span class="fa fa-paypal"></span>'
            }).inject(Header);
            new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-title',
                html: '<strong></strong><small></small>'
            }).inject(Header);
            Header.getElement('strong').set(
                'text',
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.subscription'
                )
            );
            Header.getElement('small').set('text', result.id);

            new Element('span', {
                'class': 'quiqqer-payment-paypal-subscription-status '
                    + 'status-' + status.toLowerCase().replace(/_/g, '-'),
                text: status
            }).inject(Header);

            if (!result.providerAvailable) {
                new Element('div', {
                    'class': 'messages-message message-warning box',
                    html: QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.provider_unavailable'
                    )
                }).inject(Content);
            }

            const Overview = this.$createSection(
                Content,
                'fa fa-info-circle',
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.section.overview'
                )
            );
            const OverviewGrid = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-grid'
            }).inject(Overview);

            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.plan_id'
                ),
                providerData.plan_id || localData.planId
            );
            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.global_process_id'
                ),
                localData.globalProcessId
            );
            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.created'
                ),
                providerData.create_time
            );
            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.updated'
                ),
                providerData.status_update_time || providerData.update_time
            );
            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.next_billing'
                ),
                providerData.billing_info
                    ? providerData.billing_info.next_billing_time
                    : null
            );
            this.$addValue(
                OverviewGrid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.local_active'
                ),
                localData.active
                    ? QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.value.yes'
                    )
                    : QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.value.no'
                    )
            );

            this.$renderCustomer(Content, providerData, localData);
            this.$renderBilling(Content, providerData);
            this.$renderTransactions(Content, result.transactions || []);

            this.setContent('');
            Content.inject(this.getContent());
        },

        $renderCustomer: function(Content, providerData, localData) {
            const customer = providerData.subscriber || localData.customer || {};
            const name = customer.name || {};
            const customerName = [
                name.given_name || customer.firstname || '',
                name.surname || customer.lastname || ''
            ].join(' ').trim();
            const Section = this.$createSection(
                Content,
                'fa fa-user',
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.section.customer'
                )
            );
            const Grid = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-grid'
            }).inject(Section);

            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.customer'
                ),
                customerName
            );
            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.email'
                ),
                customer.email_address || customer.email
            );
            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.payer_id'
                ),
                customer.payer_id
            );
        },

        $renderBilling: function(Content, providerData) {
            const billingInfo = providerData.billing_info || {};
            const lastPayment = billingInfo.last_payment || {};
            const Section = this.$createSection(
                Content,
                'fa fa-credit-card',
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.section.billing'
                )
            );
            const Grid = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-grid'
            }).inject(Section);

            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.outstanding'
                ),
                this.$formatAmount(billingInfo.outstanding_balance)
            );
            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.last_payment'
                ),
                this.$formatAmount(lastPayment.amount)
            );
            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.last_payment_time'
                ),
                lastPayment.time
            );
            this.$addValue(
                Grid,
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.field.failed_payments'
                ),
                billingInfo.failed_payments_count
            );
        },

        $renderTransactions: function(Content, transactions) {
            const Section = this.$createSection(
                Content,
                'fa fa-exchange',
                QUILocale.get(
                    lg,
                    'controls.backend.SubscriptionWindow.section.transactions'
                )
            );

            if (!transactions.length) {
                new Element('p', {
                    'class': 'quiqqer-payment-paypal-subscription-empty',
                    text: QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.transactions.empty'
                    )
                }).inject(Section);
                return;
            }

            const Wrapper = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-transactions'
            }).inject(Section);
            const Table = new Element('table').inject(Wrapper);
            const Head = new Element('thead').inject(Table);
            const HeadRow = new Element('tr').inject(Head);
            const columns = [
                'id',
                'date',
                'status',
                'amount',
                'quiqqer'
            ];

            columns.forEach((column) => {
                new Element('th', {
                    text: QUILocale.get(
                        lg,
                        'controls.backend.SubscriptionWindow.transactions.' + column
                    )
                }).inject(HeadRow);
            });

            const Body = new Element('tbody').inject(Table);

            transactions.forEach((transaction) => {
                const data = transaction.paypal_transaction_data || {};
                const Row = new Element('tr').inject(Body);
                const values = [
                    transaction.paypal_transaction_id,
                    transaction.paypal_transaction_date,
                    data.status,
                    this.$formatAmount(data.amount),
                    transaction.quiqqer_transaction_id
                ];

                values.forEach((value) => {
                    new Element('td', {
                        text: this.$displayValue(value)
                    }).inject(Row);
                });
            });
        },

        $createSection: function(Content, icon, title) {
            const Section = new Element('section', {
                'class': 'quiqqer-payment-paypal-subscription-details-section'
            }).inject(Content);

            new Element('h3', {
                html: '<span class="' + icon + '"></span>'
            }).inject(Section).appendText(title);

            return Section;
        },

        $addValue: function(Container, label, value) {
            const Field = new Element('div', {
                'class': 'quiqqer-payment-paypal-subscription-details-field'
            }).inject(Container);

            new Element('span', {
                text: label
            }).inject(Field);
            new Element('strong', {
                text: this.$displayValue(value)
            }).inject(Field);
        },

        $displayValue: function(value) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return '—';
            }

            return String(value);
        },

        $formatAmount: function(amount) {
            if (!amount || typeof amount !== 'object') {
                return null;
            }

            const value = amount.value || amount.total;
            const currency = amount.currency_code || amount.currency;

            if (!value) {
                return null;
            }

            return currency ? value + ' ' + currency : value;
        },

        $configureActionButtons: function(result) {
            const providerData = result.provider || {};
            const status = providerData.status || '';

            if (!this.$CanManage || !result.providerAvailable) {
                return;
            }

            if (status === 'ACTIVE') {
                this.$ActionButtons.suspend.enable();
                this.$ActionButtons.cancel.enable();
                return;
            }

            if (status === 'SUSPENDED') {
                this.$ActionButtons.activate.enable();
                this.$ActionButtons.cancel.enable();
                return;
            }

            if (status === 'APPROVED' || status === 'APPROVAL_PENDING') {
                this.$ActionButtons.cancel.enable();
            }
        },

        $disableActionButtons: function() {
            Object.values(this.$ActionButtons).forEach(
                (Button) => Button.disable()
            );
        },

        $confirmAction: function(action) {
            const icons = {
                activate: 'fa fa-play',
                cancel: 'fa fa-ban',
                suspend: 'fa fa-pause'
            };
            const methods = {
                activate: 'activateSubscription',
                cancel: 'cancelSubscription',
                suspend: 'suspendSubscription'
            };
            const prefix = 'controls.backend.SubscriptionWindow.action.'
                + action;

            new QUIConfirm({
                maxHeight: 360,
                maxWidth: 620,
                autoclose: false,
                information: QUILocale.get(lg, prefix + '.information'),
                title: QUILocale.get(lg, prefix + '.title'),
                texticon: icons[action],
                text: QUILocale.get(lg, prefix + '.text'),
                icon: icons[action],
                cancel_button: {
                    text: false,
                    textimage: 'icon-remove fa fa-remove'
                },
                ok_button: {
                    text: QUILocale.get(lg, prefix + '.confirm'),
                    textimage: 'icon-ok fa fa-check'
                },
                events: {
                    onSubmit: (Popup) => {
                        Popup.Loader.show();

                        PayPal[methods[action]](
                            this.getAttribute('subscriptionId')
                        ).then(() => {
                            Popup.close();
                            this.fireEvent('updateSubscription', [this]);
                            this.$load();
                        }).catch(() => {
                            Popup.Loader.hide();
                        });
                    }
                }
            }).open();
        }
    });
});
