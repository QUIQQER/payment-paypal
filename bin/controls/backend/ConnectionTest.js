/** Run explicit administration diagnostics without starting a payment. */
define('package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'package/quiqqer/payment-paypal/bin/classes/WebSdk',
    'css!qui/controls/messages/Message.css',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest.css'
], function (QUIControl, QUIAjax, Locale, WebSdk) {
    'use strict';

    const text = key => Locale.get('quiqqer/payment-paypal', 'connectionTest.' + key);

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest',
        Binds: ['$onImport', '$run'],

        initialize: function (options) {
            this.parent(options);
            this.addEvents({onImport: this.$onImport, onDestroy: () => this.$Content?.remove()});
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

            try {
                const result = await new Promise((resolve, reject) => {
                    QUIAjax.post('package_quiqqer_payment-paypal_ajax_testConnection', resolve, {
                        'package': 'quiqqer/payment-paypal',
                        currency: currency.value,
                        country: country.value,
                        onError: reject
                    });
                });

                this.$Results.replaceChildren();
                const summary = document.createElement('p');
                summary.className = 'quiqqer-paypal-connection-test-summary';
                summary.textContent = text(result.environment) + ' · ' + result.currency + ' / ' + result.country;
                this.$Results.append(summary);

                const testDetails = document.createElement('details');
                const testTitle = document.createElement('summary');
                testTitle.textContent = text('technical_details');
                const testId = document.createElement('p');
                testId.textContent = text('testId') + ': ' + result.testId;
                testDetails.append(testTitle, testId);

                for (const step of result.steps) {
                    this.$showStep(step);
                }

                // Exercise the same public SDK path as checkout, independently of server-token permissions.
                let operation = 'createInstance';

                try {
                    // Always test saved credentials, not the instance from an earlier test.
                    const sdk = await WebSdk.getInstance(result.environment === 'sandbox', {refresh: true});
                    operation = 'findEligibleMethods';
                    const methods = await sdk.findEligibleMethods({currencyCode: result.currency});
                    this.$showStep({operation: 'browser', ok: true, paypalEligible: methods.isEligible('paypal')});
                } catch (error) {
                    WebSdk.reportError(error, operation, result.environment === 'sandbox');
                    this.$showStep({operation: 'browser', ok: false, reason: 'browser_error'});
                }

                this.$Results.append(testDetails);
            } catch (_) {
                this.$Results.textContent = text('request_error');
            } finally {
                this.$Results.setAttribute('aria-busy', 'false');
                this.$Button.disabled = false;
                this.$ButtonIcon.className = 'fa fa-play';
                this.$ButtonLabel.textContent = text('start');
            }
        },

        $showStep: function (step) {
            const section = document.createElement('div');
            const unavailable = step.ok && step.paypalEligible === false;
            const status = !step.ok ? 'error' : unavailable ? 'attention' : 'success';
            section.className = 'messages-message message-' + status + ' quiqqer-paypal-connection-test-step';
            const title = document.createElement('p');
            title.className = 'quiqqer-paypal-connection-test-step-title';
            const strong = document.createElement('strong');
            strong.textContent = text(step.operation);
            const badge = document.createElement('span');
            badge.className = 'quiqqer-paypal-connection-test-status';
            badge.textContent = text(unavailable ? 'unavailable' : step.ok ? 'success' : 'failed');
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

            this.$Results.append(section);
        }
    });
});
