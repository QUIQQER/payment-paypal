/**
 * Shared loader for the PayPal JavaScript Web SDK v6.
 */
define('package/quiqqer/payment-paypal/bin/classes/WebSdk', [

    'package/quiqqer/payment-paypal/bin/PayPal'

], function (PayPalApi) {
    'use strict';

    const scriptName = 'paypal-web-sdk-v6';
    let sdkPromise = null;
    let sdkEnvironment = null;
    let diagnosticsToken = null;
    let correlationId = null;
    const errorOperations = new WeakMap();

    const diagnosticCode = function (value) {
        return typeof value === 'string' && /^[A-Z][A-Z0-9_]{1,79}$/.test(value) ? value : undefined;
    };

    const errorMetadata = function (Error) {
        const result = {};
        const issues = new Set();
        const seen = new Set();
        const pending = [Error];

        // Read only known error containers, with bounded work even for cyclic SDK errors.
        for (let index = 0; index < pending.length && index < 20; index++) {
            const current = pending[index];

            if (!current || typeof current !== 'object' || seen.has(current)) {
                continue;
            }

            seen.add(current);
            const code = diagnosticCode(current.code);
            const name = diagnosticCode(current.name);
            const debugId = current.debugId ?? current.debug_id;
            const status = current.statusCode ?? current.status;

            if (code?.startsWith('ERR_')) {
                result.sdkErrorCode ??= code;
            }

            result.paypalError ??= name;

            if (typeof debugId === 'string' && /^[a-f0-9]{8,32}$/i.test(debugId)) {
                result.debugId ??= debugId;
            }

            if (Number.isInteger(status) && status >= 100 && status <= 599) {
                result.httpStatus ??= status;
            }

            if (Array.isArray(current.details)) {
                for (const detail of current.details.slice(0, 10)) {
                    const issue = diagnosticCode(detail?.issue);

                    if (issue && issues.size < 10) {
                        issues.add(issue);
                    }
                }
            }

            for (const key of ['cause', 'response', 'data', 'body', 'error']) {
                if (pending.length < 20 && current[key] && typeof current[key] === 'object') {
                    pending.push(current[key]);
                }
            }
        }

        if (issues.size) {
            result.paypalIssues = Array.from(issues);
        }

        return result;
    };

    const isSandbox = function (sandbox) {
        return sandbox === true || sandbox === 1 || sandbox === '1' || sandbox === 'true';
    };

    const getEnvironment = function (sandbox) {
        return isSandbox(sandbox) ? 'sandbox' : 'production';
    };

    const getSdkUrl = function (environment) {
        if (environment === 'sandbox') {
            return 'https://www.sandbox.paypal.com/web-sdk/v6/core';
        }

        return 'https://www.paypal.com/web-sdk/v6/core';
    };

    const assertSdkLoaded = function (resolve, reject) {
        if (window.paypal && typeof window.paypal.createInstance === 'function') {
            resolve(window.paypal);
            return;
        }

        reject(new Error('PayPal JavaScript Web SDK v6 could not be loaded.'));
    };

    const loadSdk = function (environment) {
        return new Promise(function (resolve, reject) {
            // The SDK is a page-global singleton, so verify the script even after a failed initialization.
            let Script = document.querySelector('[data-name="' + scriptName + '"]');

            if (Script && Script.src !== getSdkUrl(environment)) {
                reject(new Error('PayPal Web SDK environments cannot be mixed on one page.'));
                return;
            }

            if (window.paypal) {
                if (!Script) {
                    reject(new Error('PayPal Web SDK environment is unknown.'));
                    return;
                }

                assertSdkLoaded(resolve, reject);
                return;
            }

            let appendScript = false;

            if (!Script) {
                Script = document.createElement('script');
                Script.async = true;
                Script.src = getSdkUrl(environment);
                Script.setAttribute('data-name', scriptName);
                appendScript = true;
            }

            const timeout = setTimeout(function () {
                Script.remove();
                reject(new Error('PayPal JavaScript Web SDK v6 could not be loaded.'));
            }, 15000);

            Script.addEventListener('load', function () {
                clearTimeout(timeout);
                assertSdkLoaded(resolve, reject);
            }, {once: true});

            Script.addEventListener('error', function () {
                clearTimeout(timeout);
                Script.remove();
                reject(new Error('PayPal JavaScript Web SDK v6 could not be loaded.'));
            }, {once: true});

            if (appendScript) {
                document.body.appendChild(Script);
            }
        });
    };

    return {
        /** Send only diagnostic metadata; never send the raw SDK error, stack, URL or credentials. */
        reportError: function (Error, operation, sandbox) {
            if (!diagnosticsToken) {
                return;
            }

            const message = typeof Error?.message === 'string' ? Error.message : '';
            let reason = 'operation_failed';

            if (message.includes('missing clientToken or clientId auth')) {
                reason = 'missing_auth';
            } else if (message === 'PayPal client ID is missing.') {
                reason = 'missing_client_id';
            } else if (message.includes('environment')) {
                reason = 'environment_mismatch';
            } else if (message === 'PayPal JavaScript Web SDK v6 could not be loaded.') {
                reason = 'sdk_load_failed';
            } else if (message === 'PayPal is not eligible for this order.') {
                reason = 'not_eligible';
            }

            const Script = document.querySelector('[data-name="' + scriptName + '"]');
            const loadedEnvironment = Script?.src === getSdkUrl('sandbox') ? 'sandbox'
                : Script?.src === getSdkUrl('production') ? 'production' : 'unknown';
            let metadata = {};

            try {
                metadata = errorMetadata(Error);
            } catch (_) {
                // Unexpected SDK getters must not interfere with the original checkout error.
            }

            const payload = {
                operation: errorOperations.get(Error) || operation,
                reason: reason,
                reportedEnvironment: getEnvironment(sandbox),
                sdkEnvironment: loadedEnvironment,
                correlationId: correlationId,
                errorName: ['Error', 'TypeError', 'SdkInitError', 'SdkError', 'DevError', 'PaymentFlowError'].includes(Error?.name)
                    ? Error.name : undefined,
                ...metadata
            };

            // Logging must never prevent the checkout from displaying its original error.
            Promise.resolve().then(() => PayPalApi.logBrowserError(diagnosticsToken, payload)).catch(() => {});
        },

        /**
         * Return the shared PayPal v6 SDK instance.
         *
         * @param {Boolean|Number|String} sandbox
         * @return {Promise<Object>}
         */
        getInstance: function (sandbox) {
            const environment = getEnvironment(sandbox);

            if (sdkPromise && sdkEnvironment !== environment) {
                return Promise.reject(
                    new Error('PayPal Web SDK environments cannot be mixed on one page.')
                );
            }

            if (sdkPromise) {
                return sdkPromise;
            }

            sdkEnvironment = environment;
            let operation = 'sdkConfiguration';
            sdkPromise = PayPalApi.getSdkConfig().then(function (config) {
                diagnosticsToken = config.diagnosticsToken;
                correlationId = window.crypto?.randomUUID?.() || null;
                const clientId = config.clientId;

                if (typeof clientId !== 'string' || !clientId.trim()) {
                    throw new Error('PayPal client ID is missing.');
                }

                // Fetch credentials and environment together to detect stale checkout markup/configuration changes.
                if (typeof config.sandbox !== 'boolean' || getEnvironment(config.sandbox) !== environment) {
                    throw new Error('PayPal Web SDK environment does not match server configuration.');
                }

                operation = 'loadSdk';
                return loadSdk(environment).then(function (PayPal) {
                    operation = 'createInstance';
                    const options = {
                        clientId: clientId.trim(),
                        components: ['paypal-payments'],
                        pageType: 'checkout'
                    };

                    if (correlationId) {
                        options.clientMetadataId = correlationId;
                    }

                    return PayPal.createInstance(options);
                });
            }).catch(function (Error) {
                if (Error && (typeof Error === 'object' || typeof Error === 'function')) {
                    errorOperations.set(Error, operation);
                }

                sdkPromise = null;
                sdkEnvironment = null;
                throw Error;
            });

            return sdkPromise;
        }
    };
});
