/** Run explicit administration diagnostics without starting a payment. */
define('package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'package/quiqqer/payment-paypal/bin/classes/IsolatedConnectionTest',
    'css!qui/controls/messages/Message.css',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest.css'
], function (QUIControl, QUIAjax, Locale, BrowserTest) {
    'use strict';

    const text = key => Locale.get('quiqqer/payment-paypal', 'connectionTest.' + key);

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest',
        Binds: ['$onImport', '$run'],

        initialize: function (options) {
            this.parent(options);
            this.addEvents({onImport: this.$onImport, onDestroy: () => {
                this.$Destroyed = true;
                this.$Abort?.abort();
                this.$Content?.remove();
            }});
        },

        $onImport: function () {
            this.$Content = document.createElement('div');
            this.$Content.className = 'quiqqer-paypal-connection-test';
            this.getElm().after(this.$Content);

            const info = document.createElement('div');
            info.className = 'messages-message message-information quiqqer-paypal-connection-test-intro';
            info.textContent = text('description');
            this.$Content.append(info);
            const fields = document.createElement('div');
            fields.className = 'quiqqer-paypal-connection-test-fields';
            this.$Content.append(fields);

            for (const [key, value, pattern] of [['currency', 'EUR', '[A-Z]{3}'], ['country', 'DE', '[A-Z]{2}']]) {
                const label = document.createElement('label');
                label.className = 'quiqqer-paypal-connection-test-field';
                const caption = document.createElement('span');
                caption.textContent = text(key);
                label.append(caption);
                const input = document.createElement('input');
                input.type = 'text';
                input.required = true;
                input.value = value;
                input.pattern = pattern;
                input.maxLength = value.length;
                input.setAttribute('data-name', key);
                label.append(input);
                fields.append(label);
            }

            this.$Button = document.createElement('button');
            this.$Button.type = 'button';
            this.$Button.className = 'btn btn-primary';
            this.$ButtonIcon = document.createElement('span');
            this.$ButtonIcon.className = 'fa fa-play';
            this.$ButtonIcon.setAttribute('aria-hidden', 'true');
            this.$ButtonLabel = document.createElement('span');
            this.$ButtonLabel.textContent = text('start');
            this.$Button.append(this.$ButtonIcon, this.$ButtonLabel);
            this.$Button.addEventListener('click', this.$run);
            fields.append(this.$Button);
            const hint = document.createElement('p');
            hint.className = 'quiqqer-paypal-connection-test-hint';
            hint.textContent = text('hint');
            this.$Content.append(hint);
            this.$Results = document.createElement('div');
            this.$Results.className = 'quiqqer-paypal-connection-test-results';
            this.$Results.setAttribute('aria-live', 'polite');
            this.$Content.append(this.$Results);
            const details = document.createElement('div');
            details.className = 'messages-message message-information';
            details.textContent = text('help');
            this.$Content.append(details);
        },

        $run: async function () {
            if (this.$Button.disabled) {
                return;
            }

            const currency = this.$Content.querySelector('[data-name="currency"]');
            const country = this.$Content.querySelector('[data-name="country"]');

            for (const input of [currency, country]) {
                input.value = input.value.trim().toUpperCase();

                if (!input.reportValidity()) {
                    return;
                }
            }

            this.$Button.disabled = true;
            this.$ButtonIcon.className = 'fa fa-spinner fa-spin';
            this.$ButtonLabel.textContent = text('running');
            this.$Results.textContent = text('running');
            this.$Results.setAttribute('aria-busy', 'true');

            this.$Abort = new AbortController();
            const signal = this.$Abort.signal;
            this.$Results.replaceChildren();

            try {
                await Promise.all(['production', 'sandbox'].map(async environment => {
                    const group = document.createElement('section');
                    group.className = 'quiqqer-paypal-connection-test-environment';
                    const heading = document.createElement('h3');
                    heading.textContent = text(environment);
                    group.append(heading);
                    const pending = document.createElement('p');
                    pending.textContent = text('running');
                    group.append(pending);
                    this.$Results.append(group);

                    try {
                        const result = await new Promise((resolve, reject) => {
                            let timer;
                            const finish = (callback, value) => {
                                clearTimeout(timer);
                                signal.removeEventListener('abort', abort);
                                callback(value);
                            };
                            const abort = () => finish(reject, new Error('Test cancelled.'));
                            signal.addEventListener('abort', abort, {once: true});
                            timer = setTimeout(() => finish(reject, new Error('Test request timed out.')), 90000);
                            try {
                                QUIAjax.post('package_quiqqer_payment-paypal_ajax_testConnection',
                                    result => finish(resolve, result), {
                                        'package': 'quiqqer/payment-paypal',
                                        currency: currency.value,
                                        country: country.value,
                                        environment: environment,
                                        onError: error => finish(reject, error)
                                    });
                            } catch (error) {
                                finish(reject, error);
                            }
                        });
                        if (signal.aborted) {
                            return;
                        }
                        pending.remove();
                        if (result.environment !== environment) {
                            throw new Error('Unexpected PayPal test environment.');
                        }
                        if (result.activeEnvironment === environment) {
                            heading.textContent += ' · ' + text('active');
                        }
                        for (const step of result.steps) {
                            this.$showStep(step, group);
                        }

                        const details = document.createElement('details');
                        const summary = document.createElement('summary');
                        summary.textContent = text('technical_details');
                        const id = document.createElement('p');
                        id.textContent = text('testId') + ': ' + result.testId;
                        details.append(summary, id);
                        group.append(details);

                        if (result.browserConfig === null) {
                            return;
                        }
                        if (result.browserConfig?.sandbox !== (environment === 'sandbox')) {
                            throw new Error('Unexpected PayPal browser configuration.');
                        }
                        const browserStep = await BrowserTest.run(result.browserConfig, result.currency, signal);
                        if (!signal.aborted) {
                            this.$showStep(browserStep, group);
                        }
                    } catch (_) {
                        if (!signal.aborted) {
                            pending.remove();
                            this.$showStep({operation: 'configuration', ok: false, reason: 'request_error'}, group);
                        }
                    }
                }));
            } finally {
                if (!this.$Destroyed) {
                    this.$Results.setAttribute('aria-busy', 'false');
                    this.$Button.disabled = false;
                    this.$ButtonIcon.className = 'fa fa-play';
                    this.$ButtonLabel.textContent = text('start');
                }
            }
        },

        $showStep: function (step, target = this.$Results) {
            const section = document.createElement('div');
            const unavailable = step.ok && step.paypalEligible === false;
            const missing = step.reason === 'missing_credentials';
            const status = missing ? 'information' : !step.ok ? 'error' : unavailable ? 'attention' : 'success';
            section.className = 'messages-message message-' + status + ' quiqqer-paypal-connection-test-step';
            const title = document.createElement('p');
            title.className = 'quiqqer-paypal-connection-test-step-title';
            const strong = document.createElement('strong');
            strong.textContent = text(step.operation);
            const badge = document.createElement('span');
            badge.className = 'quiqqer-paypal-connection-test-status';
            badge.textContent = text(missing ? 'not_configured' : unavailable ? 'unavailable' : step.ok ? 'success' : 'failed');
            title.append(strong, badge);
            section.append(title);

            const details = document.createElement('dl');
            details.className = 'quiqqer-paypal-connection-test-metadata';

            for (const key of ['httpStatus', 'paypalError', 'paypalIssues', 'debugId', 'transportErrorCode']) {
                if (step[key] !== undefined) {
                    const label = document.createElement('dt');
                    label.textContent = text(key);
                    const value = document.createElement('dd');
                    value.textContent = Array.isArray(step[key]) ? step[key].join(', ') : step[key];
                    details.append(label, value);
                }
            }

            if (details.children.length) {
                const disclosure = document.createElement('details');
                const summary = document.createElement('summary');
                summary.textContent = text('technical_details');
                disclosure.append(summary, details);
                section.append(disclosure);
            }

            if (typeof step.paypalEligible === 'boolean') {
                const item = document.createElement('p');
                item.textContent = text(step.paypalEligible ? 'eligible' : 'not_eligible');
                section.append(item);
            }

            if (step.reason) {
                const item = document.createElement('p');
                item.textContent = text(step.reason);
                section.append(item);
            }

            target.append(section);
        }
    });
});
