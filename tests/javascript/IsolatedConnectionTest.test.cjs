const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {resolve} = require('node:path');
const {runInNewContext} = require('node:vm');
const {test} = require('node:test');

function setup() {
    const listeners = new Set();
    const timers = new Set();
    const state = {frames: [], reports: []};
    const window = {location: {origin: 'https://shop.example'},
        addEventListener: (name, fn) => listeners.add(fn), removeEventListener: (name, fn) => listeners.delete(fn)};
    const document = {createElement() {
        const frame = {style: {}, setAttribute() {}, addEventListener() {}, removed: false, messages: [],
            remove() { this.removed = true; }};
        frame.contentWindow = {postMessage: (message, origin) => frame.messages.push({message, origin})};
        return frame;
    }, body: {appendChild: frame => state.frames.push(frame)}};
    let module;
    runInNewContext(readFileSync(resolve(__dirname, '../../bin/classes/IsolatedConnectionTest.js'), 'utf8'), {
        window, document,
        setTimeout: fn => { timers.add(fn); return fn; }, clearTimeout: fn => timers.delete(fn),
        define(name, deps, factory) { module = factory({toUrl: path => path}, {
            logBrowserError: (token, payload) => { state.reports.push({token, payload}); return Promise.resolve(true); }
        }); }
    });
    const send = (frame, type, data, origin = window.location.origin) => {
        for (const fn of Array.from(listeners)) fn({source: frame.contentWindow, origin,
            data: {channel: 'paypal-connection-test', type, data}});
    };
    return {module, state, send, listeners, timers};
}

const config = sandbox => ({sandbox, clientId: sandbox ? 'sandbox-client' : 'live-client', diagnosticsToken: 'private-session-token'});

test('simultaneous environments and repeated tests own separate frames and expose no session token', async () => {
    const {module, state, send, listeners, timers} = setup();
    const live = module.run(config(false), 'EUR');
    const sandbox = module.run(config(true), 'EUR');
    for (const frame of state.frames) send(frame, 'ready');
    assert.equal(state.frames[0].messages[0].message.data.clientId, 'live-client');
    assert.equal(state.frames[1].messages[0].message.data.clientId, 'sandbox-client');
    assert.doesNotMatch(JSON.stringify(state.frames.map(frame => frame.messages)), /private-session-token/);
    send(state.frames[1], 'result', {ok: false});
    send(state.frames[0], 'result', {ok: true, paypalEligible: true});
    assert.equal((await live).ok, true);
    assert.equal((await sandbox).ok, false);
    assert.ok(state.frames.every(frame => frame.removed));
    assert.equal(listeners.size, 0);
    assert.equal(timers.size, 0);
    const again = module.run({...config(false), clientId: 'replacement-client'}, 'EUR');
    send(state.frames[2], 'ready');
    assert.equal(state.frames[2].messages[0].message.data.clientId, 'replacement-client');
    send(state.frames[2], 'result', {ok: true, paypalEligible: false});
    assert.equal((await again).paypalEligible, false);
});

test('foreign messages and malformed results are ignored', async () => {
    const {module, state, send} = setup();
    const pending = module.run(config(false), 'EUR');
    const frame = state.frames[0];
    send(frame, 'ready', null, 'https://attacker.example');
    send({contentWindow: {}}, 'ready');
    assert.equal(frame.messages.length, 0);
    send(frame, 'ready');
    send(frame, 'ready');
    assert.equal(frame.messages.length, 1);
    send(frame, 'result', {ok: true});
    assert.equal(frame.removed, false);
    send(frame, 'result', {ok: false});
    await pending;
});

test('timeouts are bounded, logged and clean up the frame', async () => {
    const {module, state, timers, listeners} = setup();
    const pending = module.run(config(true), 'EUR');
    Array.from(timers)[0]();
    assert.equal((await pending).reason, 'browser_timeout');
    await Promise.resolve();
    assert.equal(state.reports[0].payload.reportedEnvironment, 'sandbox');
    assert.equal(state.frames[0].removed, true);
    assert.equal(listeners.size, 0);
});

test('closing diagnostics cancels and removes a pending frame', async () => {
    const {module, state, listeners, timers} = setup();
    const abort = new AbortController();
    const pending = module.run(config(false), 'EUR', abort.signal);
    abort.abort();
    await assert.rejects(pending, /cancelled/);
    assert.equal(state.frames[0].removed, true);
    assert.equal(listeners.size, 0);
    assert.equal(timers.size, 0);
});

test('frame log is forwarded using the parent session token', async () => {
    const {module, state, send} = setup();
    const pending = module.run(config(false), 'EUR');
    send(state.frames[0], 'ready');
    send(state.frames[0], 'log', {operation: 'findEligibleMethods', reportedEnvironment: 'production'});
    send(state.frames[0], 'result', {ok: false});
    await pending;
    assert.equal(state.reports[0].token, 'private-session-token');
    assert.equal(state.reports[0].payload.operation, 'findEligibleMethods');
});
