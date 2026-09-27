const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

const root = resolve(__dirname, '../..');
const source = readFileSync(resolve(root, 'bin/classes/WebSdk.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup(sandbox = false) {
    const state = {scripts: [], instances: [], reports: [], configCalls: 0, failLoad: false};
    const config = {sandbox, clientId: 'public-client-id', diagnosticsToken: 'session-report-token'};
    const sdk = {findEligibleMethods: () => Promise.resolve({isEligible: () => true})};
    const paypal = {createInstance(options) { state.instances.push(options); return Promise.resolve(sdk); }};
    const window = {crypto: {randomUUID: () => '11111111-2222-4333-8444-555555555555'}};
    const api = {
        getSdkConfig() { state.configCalls++; return Promise.resolve(config); },
        logBrowserError(token, payload) { state.reports.push({token, payload}); return Promise.resolve(true); }
    };
    const document = {
        querySelector: () => state.scripts[0] || null,
        createElement() {
            const listeners = {};
            return {
                src: '',
                setAttribute() {},
                addEventListener(event, handler) { listeners[event] = handler; },
                remove() { state.scripts = state.scripts.filter(script => script !== this); },
                fire(event) { listeners[event]?.(); }
            };
        },
        body: {
            appendChild(script) {
                state.scripts.push(script);
                queueMicrotask(() => {
                    if (state.failLoad) {
                        script.fire('error');
                    } else {
                        window.paypal = paypal;
                        script.fire('load');
                    }
                });
            }
        }
    };
    let WebSdk;
    runInNewContext(source, {
        window, document, setTimeout, clearTimeout,
        define: (name, deps, factory) => { WebSdk = factory(api); }
    });
    return {WebSdk, state, config, sdk, window, api};
}

test('disabled flags, including the old empty HTML attribute, load production', async () => {
    for (const flag of [false, 0, '0', '', 'false', null, undefined]) {
        const {WebSdk, state} = setup(false);
        await WebSdk.getInstance(flag);
        assert.equal(state.scripts[0].src, 'https://www.paypal.com/web-sdk/v6/core');
        assert.equal(state.instances[0].clientId, 'public-client-id');
        assert.equal(state.instances[0].clientToken, undefined);
    }
});

test('explicit enabled flags load sandbox', async () => {
    for (const flag of [true, 1, '1', 'true']) {
        const {WebSdk, state} = setup(true);
        await WebSdk.getInstance(flag);
        assert.equal(state.scripts[0].src, 'https://www.sandbox.paypal.com/web-sdk/v6/core');
    }
});

test('simultaneous controls share one configured SDK instance', async () => {
    const {WebSdk, state} = setup();
    const [first, second] = await Promise.all([WebSdk.getInstance(0), WebSdk.getInstance('0')]);
    assert.equal(first, second);
    assert.equal(state.configCalls, 1);
    assert.equal(state.instances.length, 1);
});

test('stale page environment is rejected before loading SDK or sending client ID to PayPal', async () => {
    const {WebSdk, state} = setup(false);
    let error;
    try { await WebSdk.getInstance(true); } catch (caught) { error = caught; }
    assert.match(error.message, /environment does not match/);
    assert.equal(state.scripts.length, 0);
    assert.equal(state.instances.length, 0);
    WebSdk.reportError(error, 'findEligibleMethods', true);
    await flush();
    assert.equal(state.reports[0].payload.operation, 'sdkConfiguration');
    assert.equal(state.reports[0].payload.reason, 'environment_mismatch');
});

test('missing client ID reports failure before SDK creation and retry can recover', async () => {
    const {WebSdk, config, state} = setup();
    config.clientId = '  ';
    await assert.rejects(WebSdk.getInstance(0), /client ID is missing/);
    assert.equal(state.instances.length, 0);
    config.clientId = 'public-client-id';
    await WebSdk.getInstance(0);
    assert.equal(state.instances.length, 1);
});

test('a previously loaded sandbox SDK cannot be reused for production after initialization fails', async () => {
    const {WebSdk, config, state} = setup(true);
    await WebSdk.getInstance(true);
    await assert.rejects(WebSdk.getInstance(false), /environments cannot be mixed/);
    assert.equal(state.instances.length, 1);

    const retry = setup(true);
    retry.window.paypal = {createInstance() { return Promise.reject(new Error('initialization failed')); }};
    retry.state.scripts.push({src: 'https://www.sandbox.paypal.com/web-sdk/v6/core'});
    await assert.rejects(retry.WebSdk.getInstance(true), /initialization failed/);
    retry.config.sandbox = false;
    await assert.rejects(retry.WebSdk.getInstance(false), /environments cannot be mixed/);
});

test('script failure can be retried', async () => {
    const {WebSdk, state} = setup();
    state.failLoad = true;
    await assert.rejects(WebSdk.getInstance(0), /could not be loaded/);
    assert.equal(state.scripts.length, 0);
    state.failLoad = false;
    await WebSdk.getInstance(0);
    assert.equal(state.instances.length, 1);
});

test('the reported eligibility error is useful and contains no raw error data', async () => {
    const {WebSdk, state} = setup();
    await WebSdk.getInstance(0);
    const error = Object.assign(new Error(
        'something went wrong when fetching eligible methods: missing clientToken or clientId auth secret-token'
    ), {name: 'SdkInitError', debugId: 'abcdef1234567', statusCode: 401, clientSecret: 'private-secret'});
    WebSdk.reportError(error, 'findEligibleMethods', '0');
    await flush();
    const report = state.reports[0];
    assert.equal(report.token, 'session-report-token');
    assert.equal(report.payload.reason, 'missing_auth');
    assert.equal(report.payload.operation, 'findEligibleMethods');
    assert.equal(report.payload.sdkEnvironment, 'production');
    assert.equal(report.payload.httpStatus, 401);
    assert.equal(report.payload.debugId, 'abcdef1234567');
    assert.equal(report.payload.correlationId, state.instances[0].clientMetadataId);
    assert.doesNotMatch(JSON.stringify(report.payload), /secret|public-client-id|stack/);
});

test('logging failure does not become an unhandled rejection', async () => {
    const {WebSdk, api} = setup();
    await WebSdk.getInstance(0);
    api.logBrowserError = () => Promise.reject(new Error('logging offline'));
    WebSdk.reportError(new Error('payment failed'), 'executeOrder', 0);
    await flush();
});

test('nested API errors preserve authorization details without raw response data', async () => {
    const {WebSdk, state} = setup();
    await WebSdk.getInstance(0);
    WebSdk.reportError({
        name: 'SdkInitError', code: 'ERR_INIT_FIND_ELIGIBLE_METHODS',
        cause: {response: {status: 403, data: {
            name: 'NOT_AUTHORIZED', debug_id: '730b69995797f',
            message: 'private response secret-token',
            details: [{issue: 'NOT_AUTHORIZED', description: 'private data'}, {issue: 'NOT_AUTHORIZED'}],
            headers: {Authorization: 'Bearer secret-token'}
        }}}
    }, 'findEligibleMethods', false);
    await flush();
    const payload = state.reports[0].payload;
    assert.equal(payload.sdkErrorCode, 'ERR_INIT_FIND_ELIGIBLE_METHODS');
    assert.equal(payload.paypalError, 'NOT_AUTHORIZED');
    assert.deepEqual(Array.from(payload.paypalIssues), ['NOT_AUTHORIZED']);
    assert.equal(payload.httpStatus, 403);
    assert.equal(payload.debugId, '730b69995797f');
    assert.doesNotMatch(JSON.stringify(payload), /private|secret|Authorization|description|message/);
});

test('current SDK eligibility wrapper logs its code without inventing discarded API details', async () => {
    const {WebSdk, state} = setup();
    await WebSdk.getInstance(0);
    // Current SDK catches the pixel error and throws a fresh error without cause/response/details.
    WebSdk.reportError({
        name: 'SdkInitError', code: 'ERR_INIT_FIND_ELIGIBLE_METHODS',
        message: 'something went wrong when fetching eligible methods'
    }, 'findEligibleMethods', false);
    await flush();
    const payload = state.reports[0].payload;
    assert.equal(payload.sdkErrorCode, 'ERR_INIT_FIND_ELIGIBLE_METHODS');
    for (const key of ['httpStatus', 'debugId', 'paypalError', 'paypalIssues']) {
        assert.equal(payload[key], undefined);
    }
});

test('cyclic errors and excessive or malformed issues are bounded and filtered', async () => {
    const {WebSdk, state} = setup();
    await WebSdk.getInstance(0);
    const error = {
        name: 'private@example.com', code: 'ERR_secret-token', status: '403', debug_id: 'private data',
        details: [{issue: 'private@example.com'}, ...Array.from({length: 100}, (_, i) => ({issue: `ISSUE_${i}`}))]
    };
    error.cause = error;
    WebSdk.reportError(error, 'findEligibleMethods', false);
    await flush();
    const payload = state.reports[0].payload;
    assert.equal(payload.paypalIssues.length, 9);
    assert.doesNotMatch(JSON.stringify(payload), /private|secret|403|ISSUE_99/);
});

test('throwing nested SDK getters do not interrupt failure reporting', async () => {
    const {WebSdk, state} = setup();
    await WebSdk.getInstance(0);
    WebSdk.reportError({name: 'SdkError', get cause() { throw new Error('unavailable'); }}, 'executeOrder', false);
    await flush();
    assert.equal(state.reports.length, 1);
    assert.equal(state.reports[0].payload.errorName, 'SdkError');
});

for (const name of ['PaymentDisplay', 'ExpressBtn']) {
    test(`${name} reports SDK errors once and preserves the checkout error UI`, () => {
        let methods;
        const reports = [];
        runInNewContext(readFileSync(resolve(root, `bin/controls/${name}.js`), 'utf8'), {
            Class: function (definition) { return definition; },
            define(moduleName, dependencies, factory) {
                methods = factory(...dependencies.map(dependency => {
                    if (dependency.endsWith('/WebSdk')) return {reportError: (...args) => reports.push(args)};
                    if (dependency === 'Locale') return {get: () => 'Payment failed'};
                    return {};
                }));
            }
        });
        const messages = [];
        const control = {
            $flowErrorHandled: false, $paypalOperation: 'findEligibleMethods',
            getAttribute: () => 0, $OrderProcess: {Loader: {hide() {}}},
            $hideLoader() {}, $showErrorMsg: message => messages.push(message), fireEvent() {}
        };
        methods.$handleProcessingError.call(control, new Error('missing auth'));
        methods.$handleProcessingError.call(control, new Error('duplicate callback'));
        assert.equal(reports.length, 1);
        assert.equal(reports[0][1], 'findEligibleMethods');
        assert.deepEqual(messages, ['Payment failed']);
        control.$flowErrorHandled = false;
        control.$paypalOperation = 'executeOrder';
        methods.$handleApiError.call(control, new Error('capture failed'));
        assert.equal(reports.length, 2);
        assert.equal(reports[1][1], 'executeOrder');
    });
}
