/** Each administration test owns a fresh document and therefore a fresh PayPal SDK. */
define('package/quiqqer/payment-paypal/bin/classes/IsolatedConnectionTest', [
    'require',
    'package/quiqqer/payment-paypal/bin/PayPal'
], function (require, PayPalApi) {
    'use strict';

    return {
        run: function (config, currency, signal) {
            return new Promise((resolve, reject) => {
                if (signal?.aborted) {
                    reject(new Error('PayPal connection test cancelled.'));
                    return;
                }

                const frame = document.createElement('iframe');
                frame.title = 'PayPal connection diagnostics';
                frame.setAttribute('aria-hidden', 'true');
                frame.tabIndex = -1;
                frame.style.cssText = 'position:absolute;width:1px;height:1px;border:0;opacity:0;pointer-events:none';
                let finished = false;
                let started = false;
                let timer;
                const cleanup = () => {
                    finished = true;
                    clearTimeout(timer);
                    window.removeEventListener('message', receive);
                    signal?.removeEventListener('abort', cancel);
                    frame.remove();
                };
                const complete = result => {
                    if (finished) {
                        return;
                    }
                    cleanup();
                    resolve(result);
                };
                const report = payload => {
                    Promise.resolve().then(() => PayPalApi.logBrowserError(config.diagnosticsToken, payload)).catch(() => {});
                };
                const fail = reason => {
                    if (finished) {
                        return;
                    }
                    report({operation: 'loadSdk', reason: 'sdk_load_failed',
                        reportedEnvironment: config.sandbox ? 'sandbox' : 'production', sdkEnvironment: 'unknown'});
                    complete({operation: 'browser', ok: false, reason});
                };
                const cancel = () => {
                    if (!finished) {
                        cleanup();
                        reject(new Error('PayPal connection test cancelled.'));
                    }
                };
                const receive = event => {
                    if (finished || event.source !== frame.contentWindow || event.origin !== window.location.origin
                        || event.data?.channel !== 'paypal-connection-test') {
                        return;
                    }
                    const {type, data} = event.data;
                    if (type === 'ready' && !started) {
                        started = true;
                        // No client secret, OAuth token or session diagnostic token enters the child document.
                        frame.contentWindow.postMessage({channel: 'paypal-connection-test', type: 'start', data: {
                            clientId: config.clientId, sandbox: config.sandbox, currency
                        }}, window.location.origin);
                    } else if (type === 'log' && started && data && typeof data === 'object') {
                        report(data);
                    } else if (type === 'result' && started && typeof data?.ok === 'boolean') {
                        if (data.ok && typeof data.paypalEligible !== 'boolean') {
                            return;
                        }
                        complete(data.ok ? {operation: 'browser', ok: true, paypalEligible: data.paypalEligible}
                            : {operation: 'browser', ok: false, reason: 'browser_error'});
                    }
                };

                window.addEventListener('message', receive);
                signal?.addEventListener('abort', cancel, {once: true});
                frame.addEventListener('error', () => fail('browser_error'), {once: true});
                timer = setTimeout(() => fail('browser_timeout'), 60000);
                frame.src = require.toUrl('package/quiqqer/payment-paypal/bin/controls/backend/ConnectionTestFrame.html');
                document.body.appendChild(frame);
            });
        }
    };
});
