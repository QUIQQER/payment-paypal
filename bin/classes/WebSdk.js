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

    const isSandbox = function (sandbox) {
        return sandbox !== false && sandbox !== 0 && sandbox !== '0';
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
            if (window.paypal) {
                assertSdkLoaded(resolve, reject);
                return;
            }

            let Script = document.querySelector('[data-name="' + scriptName + '"]');
            let appendScript = false;

            if (!Script) {
                Script = document.createElement('script');
                Script.async = true;
                Script.src = getSdkUrl(environment);
                Script.setAttribute('data-name', scriptName);
                appendScript = true;
            }

            Script.addEventListener('load', function () {
                assertSdkLoaded(resolve, reject);
            }, {once: true});

            Script.addEventListener('error', function () {
                Script.remove();
                reject(new Error('PayPal JavaScript Web SDK v6 could not be loaded.'));
            }, {once: true});

            if (appendScript) {
                document.body.appendChild(Script);
            }
        });
    };

    return {
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
            sdkPromise = Promise.all([
                loadSdk(environment),
                PayPalApi.getClientId()
            ]).then(function (result) {
                const PayPal = result[0];
                const clientId = result[1];

                if (!clientId) {
                    throw new Error('PayPal client ID is missing.');
                }

                return PayPal.createInstance({
                    clientId: clientId,
                    components: ['paypal-payments'],
                    pageType: 'checkout'
                });
            }).catch(function (Error) {
                sdkPromise = null;
                sdkEnvironment = null;
                throw Error;
            });

            return sdkPromise;
        }
    };
});
