const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

function element() {
    return {textContent: '', children: [], append(...items) { this.children.push(...items); },
        remove() {}, replaceChildren() { this.children = []; }, setAttribute() {}};
}

function setup(results = {}, browserResults = {}) {
    const state = {calls: [], steps: [], browserCalls: []};
    const browser = {async run(config, currency, signal) {
        state.browserCalls.push({config, currency, signal});
        return browserResults[config.sandbox ? 'sandbox' : 'production']
            || {operation: 'browser', ok: true, paypalEligible: true};
    }};
    const ajax = {post(name, resolve, options) {
        state.calls.push({name, options});
        const environment = options.environment;
        const custom = results[environment];
        if (custom instanceof Error) {
            options.onError(custom);
        } else {
            resolve({environment, currency: 'EUR', country: 'DE', activeEnvironment: 'production',
                testId: environment + '-test', steps: [{operation: 'authentication', ok: true}],
                browserConfig: {sandbox: environment === 'sandbox', clientId: environment + '-client',
                    diagnosticsToken: 'session-token'}, ...custom});
        }
    }};
    let definition;
    runInNewContext(readFileSync(resolve(__dirname, '../../bin/controls/backend/ConnectionTest.js'), 'utf8'), {
        document: {createElement: element}, AbortController, setTimeout, clearTimeout,
        Class: function (value) { return value; },
        define(name, deps, factory) { definition = factory({}, ajax, {get: (group, key) => key}, browser); }
    });
    const inputs = {currency: {value: 'eur', reportValidity: () => true}, country: {value: 'de', reportValidity: () => true}};
    const control = {
        $Content: {querySelector: selector => inputs[selector.includes('currency') ? 'currency' : 'country']},
        $Button: {disabled: false}, $ButtonIcon: {}, $ButtonLabel: {}, $Results: element(),
        $showStep: (step, group) => state.steps.push({step, group})
    };
    return {run: () => definition.$run.call(control), state, control, inputs, definition};
}

test('both environments are requested explicitly and rendered separately', async () => {
    const {run, state, control} = setup();
    await run();
    assert.deepEqual(state.calls.map(call => call.options.environment), ['production', 'sandbox']);
    assert.deepEqual(state.browserCalls.map(call => call.config.clientId), ['production-client', 'sandbox-client']);
    assert.equal(control.$Results.children.length, 2);
    assert.match(control.$Results.children[0].children[0].textContent, /active/);
    assert.doesNotMatch(control.$Results.children[1].children[0].textContent, /active/);
    assert.notEqual(state.steps[0].group, state.steps[1].group);
    assert.equal(control.$Button.disabled, false);
});

test('a failed server request does not prevent the other environment test', async () => {
    const {run, state} = setup({production: new Error('Permission denied')});
    await run();
    assert.equal(state.browserCalls.length, 1);
    assert.equal(state.browserCalls[0].config.sandbox, true);
    assert.ok(state.steps.some(({step}) => step.reason === 'request_error'));
    assert.ok(state.steps.some(({step}) => step.operation === 'browser' && step.ok));
});

test('missing credentials skip the browser test and render a neutral status', async () => {
    const {run, state, definition} = setup({sandbox: {
        browserConfig: null, steps: [{operation: 'configuration', ok: false, reason: 'missing_credentials'}]
    }});
    await run();
    assert.equal(state.browserCalls.length, 1);
    const target = element();
    definition.$showStep({operation: 'configuration', ok: false, reason: 'missing_credentials'}, target);
    assert.match(target.children[0].className, /message-information/);
    assert.equal(target.children[0].children[0].children[1].textContent, 'connectionTest.not_configured');
});

test('server success cannot hide browser failure and results remain assigned to the environment', async () => {
    const {run, state, control} = setup({}, {production: {operation: 'browser', ok: false, reason: 'browser_error'}});
    await run();
    const failed = state.steps.find(({step}) => step.reason === 'browser_error');
    assert.equal(failed.group, control.$Results.children[0]);
    assert.ok(state.steps.some(({step, group}) => step.operation === 'browser' && step.ok
        && group === control.$Results.children[1]));
});

test('invalid input and duplicate clicks do not send additional requests', async () => {
    const {run, state, inputs} = setup();
    inputs.currency.reportValidity = () => false;
    await run();
    assert.equal(state.calls.length, 0);
    inputs.currency.reportValidity = () => true;
    const first = run();
    await run();
    await first;
    assert.equal(state.calls.length, 2);
});

test('cancellation prevents starting browser tests for late server responses', async () => {
    const {run, state, control} = setup();
    const pending = run();
    control.$Destroyed = true;
    control.$Abort.abort();
    await pending;
    assert.equal(state.browserCalls.length, 0);
});

test('mismatched environment cannot start a browser test', async () => {
    const {run, state} = setup({production: {browserConfig: {sandbox: true}}});
    await run();
    assert.equal(state.browserCalls.length, 1);
    assert.equal(state.browserCalls[0].config.sandbox, true);
    assert.ok(state.steps.some(({step}) => step.reason === 'request_error'));
});
