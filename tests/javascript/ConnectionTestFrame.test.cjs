const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

function setup(failure = false) {
    const state = {messages: [], configs: [], environments: [], reports: []};
    let receive;
    const parent = {postMessage: (data, origin) => state.messages.push({data, origin})};
    const window = {parent, location: {origin: 'https://shop.example'},
        addEventListener: (name, fn) => { receive = fn; }};
    runInNewContext(readFileSync(resolve(__dirname, '../../bin/controls/backend/ConnectionTestFrame.js'), 'utf8'), {window});
    window.define('WebSdk', [], api => ({
        async getInstance(sandbox) {
            state.environments.push(sandbox);
            state.configs.push(await api.getSdkConfig());
            return {async findEligibleMethods(options) {
                assert.equal(options.currencyCode, 'EUR');
                if (failure) throw new Error('private-token');
                return {isEligible: method => method === 'paypal'};
            }};
        },
        reportError(error, operation, sandbox) {
            state.reports.push({operation, sandbox});
            Promise.resolve().then(() => api.logBrowserError('isolated-test', {operation, reason: 'operation_failed'}));
        }
    }));
    return {state, window, receive, send: data => receive({source: parent, origin: window.location.origin,
        data: {channel: 'paypal-connection-test', type: 'start', data}})};
}

test('frame initializes exactly once with the selected environment and current client ID', async () => {
    for (const sandbox of [false, true]) {
        const {state, send} = setup();
        await send({sandbox, clientId: 'current-client', currency: 'EUR'});
        await send({sandbox: !sandbox, clientId: 'other-client', currency: 'EUR'});
        assert.deepEqual(state.environments, [sandbox]);
        assert.equal(state.configs[0].clientId, 'current-client');
        assert.equal(state.messages[0].data.type, 'ready');
        assert.equal(state.messages[1].data.data.ok, true);
    }
});

test('frame reports sanitized error before completion and never forwards raw error text', async () => {
    const {state, send} = setup(true);
    await send({sandbox: true, clientId: 'current-client', currency: 'EUR'});
    assert.deepEqual(state.messages.map(message => message.data.type), ['ready', 'log', 'result']);
    assert.equal(state.reports[0].operation, 'findEligibleMethods');
    assert.equal(state.reports[0].sandbox, true);
    assert.doesNotMatch(JSON.stringify(state.messages), /private-token/);
});

test('frame rejects foreign senders, origins and malformed test configurations', async () => {
    const {state, send, receive, window} = setup();
    const data = {channel: 'paypal-connection-test', type: 'start',
        data: {sandbox: true, clientId: 'client', currency: 'EUR'}};
    await receive({source: {}, origin: window.location.origin, data});
    await receive({source: window.parent, origin: 'https://foreign.example', data});
    await send({sandbox: 'true', clientId: 'client', currency: 'EUR'});
    await send({sandbox: true, clientId: '', currency: 'EUR'});
    assert.equal(state.configs.length, 0);
});
