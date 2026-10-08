/* Isolated diagnostics document. Never creates an order or payment session. */
(function () {
    'use strict';

    let sdk;
    let config;
    let started = false;
    const send = (type, data) => window.parent.postMessage({channel: 'paypal-connection-test', type, data}, window.location.origin);

    // Load the checkout SDK wrapper in this document, with a narrowly scoped API bridge.
    window.define = function (name, dependencies, factory) {
        sdk = factory({
            getSdkConfig: () => Promise.resolve({...config, diagnosticsToken: 'isolated-test'}),
            logBrowserError: (token, payload) => {
                send('log', payload);
                return Promise.resolve(true);
            }
        });
        send('ready');
    };

    window.addEventListener('message', async function (event) {
        if (event.source !== window.parent || event.origin !== window.location.origin || started || !sdk) {
            return;
        }

        const message = event.data;
        if (message?.channel !== 'paypal-connection-test' || message.type !== 'start') {
            return;
        }

        const data = message.data;
        if (typeof data?.sandbox !== 'boolean' || typeof data.clientId !== 'string'
            || !data.clientId.trim() || !/^[A-Z]{3}$/.test(data.currency)) {
            return;
        }

        started = true;
        config = {sandbox: data.sandbox, clientId: data.clientId};
        let operation = 'createInstance';
        try {
            const instance = await sdk.getInstance(config.sandbox);
            operation = 'findEligibleMethods';
            const methods = await instance.findEligibleMethods({currencyCode: data.currency});
            send('result', {operation: 'browser', ok: true, paypalEligible: methods.isEligible('paypal')});
        } catch (error) {
            sdk.reportError(error, operation, config.sandbox);
            // The wrapper queues the sanitized diagnostic report; send it before completing the frame.
            await Promise.resolve();
            send('result', {operation: 'browser', ok: false, reason: 'browser_error'});
        }
    });
}());
