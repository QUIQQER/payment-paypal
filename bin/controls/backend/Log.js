/** Read PayPal logs with the same superuser restriction as the central log viewer. */
define('package/quiqqer/payment-paypal/bin/controls/backend/Log', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'css!qui/controls/messages/Message.css',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTest.css',
    'css!package/quiqqer/payment-paypal/bin/controls/backend/Log.css'
], function (QUIControl, Ajax, Locale) {
    'use strict';
    const text = key => Locale.get('quiqqer/payment-paypal', 'paypalLog.' + key);

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/payment-paypal/bin/controls/backend/Log',
        Binds: ['$onImport', '$refresh', '$fitHeight', '$onDestroy'],

        initialize: function (options) {
            this.parent(options);
            this.addEvents({onImport: this.$onImport, onDestroy: this.$onDestroy});
        },

        $onImport: function () {
            this.$Content = document.createElement('div');
            this.$Content.className = 'quiqqer-paypal-connection-test quiqqer-paypal-log';
            this.getElm().after(this.$Content);
            const description = document.createElement('p');
            description.textContent = text('description');
            this.$Content.append(description);
            const toolbar = document.createElement('div');
            toolbar.className = 'quiqqer-paypal-connection-test-fields';
            const label = document.createElement('label');
            label.className = 'quiqqer-paypal-connection-test-field';
            const caption = document.createElement('span');
            caption.textContent = text('date');
            this.$Select = document.createElement('select');
            this.$Select.setAttribute('data-name', 'log-file');
            this.$Select.addEventListener('change', this.$refresh);
            label.append(caption, this.$Select);
            this.$Button = document.createElement('button');
            this.$Button.type = 'button';
            this.$Button.className = 'btn btn-primary';
            this.$Button.textContent = text('refresh');
            this.$Button.addEventListener('click', this.$refresh);
            toolbar.append(label, this.$Button);
            this.$Content.append(toolbar);
            this.$Status = document.createElement('div');
            this.$Status.setAttribute('aria-live', 'polite');
            this.$Content.append(this.$Status);
            this.$Log = document.createElement('pre');
            this.$Log.className = 'quiqqer-paypal-log-content';
            this.$Log.tabIndex = 0;
            this.$Log.setAttribute('aria-label', text('title'));
            this.$Log.hidden = true;
            this.$Content.append(this.$Log);
            this.$Viewport = null;

            for (let parent = this.$Content.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                if (/(auto|scroll)/.test(window.getComputedStyle(parent).overflowY) && parent.clientHeight > 0) {
                    this.$Viewport = parent;
                    break;
                }
            }

            if (this.$Viewport) {
                this.$Viewport.scrollTop = 0;
                this.$ResizeObserver = new ResizeObserver(() => requestAnimationFrame(this.$fitHeight));
                this.$ResizeObserver.observe(this.$Viewport);
            }

            window.addEventListener('resize', this.$fitHeight);
            this.$refresh();
        },

        $onDestroy: function () {
            this.$ResizeObserver?.disconnect();
            window.removeEventListener('resize', this.$fitHeight);
            this.$Content?.remove();
        },

        $fitHeight: function () {
            if (!this.$Log?.isConnected || this.$Log.hidden) {
                return;
            }

            const bottom = this.$Viewport
                ? Math.min(window.innerHeight, this.$Viewport.getBoundingClientRect().bottom)
                : window.innerHeight;
            const top = this.$Log.getBoundingClientRect().top + (this.$Viewport?.scrollTop || 0);
            const height = Math.max(100, Math.floor(bottom - top - 40));
            this.$Content.style.setProperty('--_q-controlConf-logHeight', height + 'px');

            if (this.$Viewport) {
                // Account for padding and table spacing added by the surrounding settings panel.
                const overflow = this.$Viewport.scrollHeight - this.$Viewport.clientHeight;

                if (overflow > 0) {
                    this.$Content.style.setProperty('--_q-controlConf-logHeight', Math.max(100, height - overflow - 8) + 'px');
                }
            }
        },

        $refresh: async function () {
            if (this.$Button.disabled) {
                return;
            }

            this.$Button.disabled = true;
            this.$Select.disabled = true;
            this.$Status.className = 'messages-message message-information';
            this.$Status.textContent = text('loading');
            this.$Log.hidden = true;

            try {
                const result = await new Promise((resolve, reject) => {
                    Ajax.get('package_quiqqer_payment-paypal_ajax_getLog', resolve, {
                        'package': 'quiqqer/payment-paypal', file: this.$Select.value, onError: reject
                    });
                });
                this.$Select.replaceChildren();

                for (const file of result.files) {
                    const option = document.createElement('option');
                    option.value = file;
                    option.textContent = file.slice('paypal_api-'.length, -'.log'.length);
                    option.selected = file === result.selected;
                    this.$Select.append(option);
                }

                this.$Log.textContent = result.data;
                this.$Log.hidden = result.data === '';
                this.$Log.scrollTop = this.$Log.scrollHeight;
                this.$Status.className = 'messages-message ' + (result.truncated ? 'message-attention' : 'message-information');
                this.$Status.textContent = text(result.files.length === 0 ? 'empty'
                    : result.data === '' ? 'empty_file' : result.truncated ? 'truncated' : 'loaded');
                requestAnimationFrame(() => {
                    this.$fitHeight();
                    this.$Log.scrollTop = this.$Log.scrollHeight;
                });
            } catch (_) {
                this.$Log.textContent = '';
                this.$Status.className = 'messages-message message-error';
                this.$Status.textContent = text('error');
            } finally {
                this.$Button.disabled = false;
                this.$Select.disabled = false;
            }
        }
    });
});
