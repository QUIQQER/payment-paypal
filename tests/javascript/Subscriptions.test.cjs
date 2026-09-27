const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

function setup(row, canManage = true) {
    const state = {calls: [], popups: [], refreshes: 0};
    const buttons = Object.fromEntries(['details', 'deleteUnassigned'].map(key => [key, {
        enabled: false,
        enable() { this.enabled = true; },
        disable() { this.enabled = false; }
    }]));
    const paypal = {
        async deleteMissingSubscription(id) { state.calls.push(['missing', id]); },
        async deleteUnassignedSubscription(id) { state.calls.push(['unassigned', id]); }
    };
    let definition;
    runInNewContext(readFileSync(resolve(__dirname, '../../bin/controls/backend/Subscriptions.js'), 'utf8'), {
        Class: function(value) { return value; },
        define(name, deps, factory) {
            definition = factory({}, {}, {}, function(options) {
                state.popups.push(options);
                this.open = () => {};
            }, {}, paypal, {}, {get: (group, key) => key}, {});
        }
    });
    const control = Object.assign({}, definition, {
        $CanManage: canManage,
        $Grid: {getSelectedData: () => row ? [row] : [], getAttribute: () => buttons},
        refresh() { state.refreshes++; }
    });
    return {control, buttons, state, paypal};
}

const pending = {paypal_subscription_id: 'I-TEST', account_context_valid: true,
    subscription_data: {status: 'APPROVAL_PENDING'}};

test('local cleanup is available for assigned pending subscriptions', () => {
    const {control, buttons} = setup(pending);
    control.$updateButtons();
    assert.equal(buttons.deleteUnassigned.enabled, true);
});

test('active subscriptions and users without manage permission cannot invoke cleanup', () => {
    for (const [row, permission] of [
        [{...pending, subscription_data: {status: 'ACTIVE'}}, true],
        [pending, false],
        [null, true]
    ]) {
        const {control, buttons, state} = setup(row, permission);
        control.$updateButtons();
        control.$deleteUnassigned();
        assert.equal(buttons.deleteUnassigned.enabled, false);
        assert.equal(state.popups.length, 0);
        assert.equal(state.calls.length, 0);
    }
});

test('pending cleanup requires confirmation and uses verification endpoint once', async () => {
    const {control, state} = setup(pending);
    control.$deleteUnassigned();
    assert.equal(state.calls.length, 0);
    assert.equal(state.popups[0].information, 'controls.backend.Subscriptions.delete_missing.information');
    const popup = {Loader: {show() {}, hide() {}}, close() {}};
    state.popups[0].events.onSubmit(popup);
    state.popups[0].events.onSubmit(popup);
    await new Promise(setImmediate);
    assert.deepEqual(state.calls, [['missing', 'I-TEST']]);
    assert.equal(state.refreshes, 1);
});

test('unassigned entries retain their existing cleanup path', async () => {
    const {control, state} = setup({...pending, account_context_valid: false});
    control.$deleteUnassigned();
    assert.equal(state.popups[0].information, 'controls.backend.Subscriptions.delete_unassigned.information');
    state.popups[0].events.onSubmit({Loader: {show() {}, hide() {}}, close() {}});
    await new Promise(setImmediate);
    assert.deepEqual(state.calls, [['unassigned', 'I-TEST']]);
});

test('rejected verification leaves confirmation open and allows retry', async () => {
    const {control, state, paypal} = setup(pending);
    paypal.deleteMissingSubscription = async () => { throw new Error('Still exists'); };
    control.$deleteUnassigned();
    let hidden = 0;
    const popup = {Loader: {show() {}, hide() { hidden++; }}, close() { assert.fail('Must stay open'); }};
    state.popups[0].events.onSubmit(popup);
    await new Promise(setImmediate);
    state.popups[0].events.onSubmit(popup);
    await new Promise(setImmediate);
    assert.equal(hidden, 2);
    assert.equal(state.refreshes, 0);
});
