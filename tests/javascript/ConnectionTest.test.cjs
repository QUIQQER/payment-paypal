const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

function setup(steps, browserError = null, ajaxError = null) {
    const state = {calls: [], reports: [], steps: [], sdkCalls: 0};
    const sdk = {
        async getInstance(sandbox) {
            state.sdkCalls++;
            assert.equal(sandbox, false);
            return {async findEligibleMethods(options) {
                assert.equal(options.currencyCode, 'EUR');
                if (browserError) throw browserError;
                return {isEligible: method => method === 'paypal'};
            }};
        },
        reportError(...args) { state.reports.push(args); }
    };
    const ajax = {post(name, resolve, options) {
        state.calls.push({name, options});
        if (ajaxError) options.onError(ajaxError);
        else resolve({environment: 'production', currency: 'EUR', country: 'DE', testId: 'test-id', steps});
    }};
    let definition;
    runInNewContext(readFileSync(resolve(__dirname, '../../bin/controls/backend/ConnectionTest.js'), 'utf8'), {
        document: {createElement: () => ({textContent: '', children: [], append(...items) { this.children.push(...items); }})},
        Class: function (value) { return value; },
        define(name, deps, factory) { definition = factory({}, ajax, {get: (group, key) => key}, sdk); }
    });
    const inputs = {currency: {value: 'eur', reportValidity: () => true}, country: {value: 'de', reportValidity: () => true}};
    const control = {
        $Content: {querySelector: selector => inputs[selector.includes('currency') ? 'currency' : 'country']},
        $Button: {disabled: false},
        $ButtonIcon: {className: ''},
        $ButtonLabel: {textContent: ''},
        $Results: {textContent: '', replaceChildren() {}, setAttribute() {}, append() {}},
        $showStep: step => state.steps.push(step)
    };
    return {run: () => definition.$run.call(control), state, control, inputs};
}

test('server authorization error and browser SDK failure are reported separately', async () => {
    const error = Object.assign(new Error('private SDK message'), {name: 'SdkInitError'});
    const serverFailure = {operation: 'findEligibleMethods', ok: false, httpStatus: 403,
        paypalError: 'NOT_AUTHORIZED', debugId: '730b69995797f'};
    const {run, state, control} = setup([{operation: 'authentication', ok: true}, serverFailure], error);
    await run();
    assert.equal(state.steps[1].debugId, '730b69995797f');
    assert.equal(state.steps[2].operation, 'browser');
    assert.equal(state.steps[2].ok, false);
    assert.equal(state.steps[2].reason, 'browser_error');
    assert.equal(state.reports[0][0], error);
    assert.equal(state.reports[0][1], 'findEligibleMethods');
    assert.equal(control.$Button.disabled, false);
    assert.doesNotMatch(JSON.stringify(state.steps), /private/);
});

test('successful server response does not hide browser-only failure', async () => {
    const {run, state} = setup([{operation: 'findEligibleMethods', ok: true}], new Error('SDK failure'));
    await run();
    assert.equal(state.steps[0].ok, true);
    assert.equal(state.steps[1].ok, false);
});

test('browser test runs independently when server authentication fails', async () => {
    const {run, state} = setup([{operation: 'authentication', ok: false}]);
    await run();
    assert.equal(state.steps[0].ok, false);
    assert.equal(state.steps[1].ok, true);
    assert.equal(state.steps[1].paypalEligible, true);
    assert.equal(state.calls[0].options.currency, 'EUR');
    assert.equal(state.calls[0].options.country, 'DE');
    assert.equal(state.reports.length, 0);
});

test('rejected admin request stops before browser diagnostics and reenables button', async () => {
    const {run, state, control} = setup([], null, new Error('Permission denied'));
    await run();
    assert.equal(state.sdkCalls, 0);
    assert.equal(control.$Button.disabled, false);
    assert.equal(control.$Results.textContent, 'connectionTest.request_error');
});

test('invalid input and duplicate clicks do not send additional requests', async () => {
    const {run, state, inputs} = setup([]);
    inputs.currency.reportValidity = () => false;
    await run();
    assert.equal(state.calls.length, 0);
    inputs.currency.reportValidity = () => true;
    const first = run();
    await run();
    await first;
    assert.equal(state.calls.length, 1);
});
