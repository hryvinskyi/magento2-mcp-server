/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {getEventListeners} = require('node:events');
const {createHttpTransport} = require('../../../extension/server/http-transport');

const URL = 'https://shop.example/rest/all/V1/mcp';
const TOKEN = 'fake-token-for-tests';
const SESSION = 'a'.repeat(32);
const NO_SESSION = {sessionId: '', protocolVersion: ''};

/**
 * A transport over a fake fetch that records every call and answers with what the test scripts
 *
 * @param {function(Object): (Response|Promise<Response>)} answer Builds the answer from the recorded call
 * @param {{timeout?: function(number): AbortSignal}} options
 * @returns {{transport: Object, calls: Object[], logs: string[], deadlines: number[]}}
 */
function scripted(answer, {timeout} = {}) {
    const calls = [];
    const logs = [];
    const deadlines = [];
    const transport = createHttpTransport({
        url: URL,
        token: TOKEN,
        fetch: async (url, init) => {
            const call = {url, ...init};
            calls.push(call);
            if (init.signal.aborted) {
                throw init.signal.reason;
            }

            return answer(call);
        },
        timeout: (milliseconds) => {
            deadlines.push(milliseconds);

            return timeout === undefined ? new AbortController().signal : timeout(milliseconds);
        },
        timeoutMs: 300000,
        log: (text) => logs.push(text)
    });

    return {transport, calls, logs, deadlines};
}

/**
 * A JSON-RPC answer
 *
 * @param {number} status
 * @param {Object} body
 * @param {Record<string, string>} headers
 * @returns {Response}
 */
function jsonRpc(status, body, headers = {}) {
    return new Response(JSON.stringify(body), {status, headers: {'Content-Type': 'application/json', ...headers}});
}

test('a message is posted with the token, the content types and the session headers, never following a redirect', async () => {
    const {transport, calls, deadlines} = scripted(() => jsonRpc(200, {jsonrpc: '2.0', id: 1, result: {}}));
    await transport.post('{"jsonrpc":"2.0","id":1,"method":"ping"}', {sessionId: SESSION, protocolVersion: '2025-06-18'});

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, URL);
    assert.equal(calls[0].method, 'POST');
    assert.equal(calls[0].body, '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    assert.equal(calls[0].redirect, 'manual');
    assert.deepEqual({...calls[0].headers}, {
        'Authorization': `Bearer ${TOKEN}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json, text/event-stream',
        'Mcp-Session-Id': SESSION,
        'MCP-Protocol-Version': '2025-06-18'
    });
    assert.deepEqual(deadlines, [300000]);
});

test('a message without a session carries no session headers', async () => {
    const {transport, calls} = scripted(() => jsonRpc(200, {jsonrpc: '2.0', id: 1, result: {}}));
    await transport.post('{}', NO_SESSION);

    assert.equal('Mcp-Session-Id' in calls[0].headers, false);
    assert.equal('MCP-Protocol-Version' in calls[0].headers, false);
});

for (const status of [200, 400, 404]) {
    test(`a JSON-RPC body at HTTP ${status} is an answer`, async () => {
        const body = {jsonrpc: '2.0', id: null, error: {code: -32600, message: 'Refused'}};
        const {transport} = scripted(() => jsonRpc(status, body));
        const outcome = await transport.post('{}', NO_SESSION);

        assert.deepEqual({...outcome}, {kind: 'answer', status, text: JSON.stringify(body), sessionId: ''});
    });
}

test('the session id the store announces is part of the answer', async () => {
    const {transport} = scripted(() => jsonRpc(200, {jsonrpc: '2.0', id: 1, result: {}}, {'Mcp-Session-Id': SESSION}));
    const outcome = await transport.post('{}', NO_SESSION);

    assert.equal(outcome.kind, 'answer');
    assert.equal(outcome.sessionId, SESSION);
});

test('a batch of JSON-RPC answers is an answer', async () => {
    const {transport} = scripted(() => jsonRpc(200, [{jsonrpc: '2.0', id: 1, result: {}}, {jsonrpc: '2.0', id: 2, result: {}}]));

    assert.equal((await transport.post('[]', NO_SESSION)).kind, 'answer');
});

test('202, and a 204 without a body, are accepted', async () => {
    for (const status of [202, 204]) {
        const {transport} = scripted(() => new Response(null, {status}));

        assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'accepted'});
    }
});

test('Magento\'s own 401 is an unauthorized failure', async () => {
    const {transport} = scripted(() => new Response('{"message":"The consumer isn\'t authorized to access %resources."}', {status: 401}));

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'unauthorized', status: 401});
});

test('a 403 without a JSON-RPC body is a forbidden failure', async () => {
    const {transport} = scripted(() => new Response('<html>Access denied</html>', {status: 403}));

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'forbidden', status: 403});
});

test('any other answer that is not JSON-RPC is an HTTP failure with its status', async () => {
    const {transport} = scripted(() => new Response('<html>Internal Server Error</html>', {status: 500}));

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'http', status: 500});
});

test('a JSON body that is not JSON-RPC is an HTTP failure', async () => {
    const {transport} = scripted(() => new Response('{"message":"Too many requests"}', {status: 429}));

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'http', status: 429});
});

test('a redirect is a failure naming only the host it points to', async () => {
    const {transport, calls} = scripted(() => new Response('', {
        status: 302,
        headers: {Location: 'https://www.shop.example/rest/all/V1/mcp?secret=1'}
    }));

    assert.deepEqual(
        {...(await transport.post('{}', NO_SESSION))},
        {kind: 'failed', reason: 'redirect', status: 302, location: 'www.shop.example'}
    );
    assert.equal(calls[0].redirect, 'manual');
});

test('a relative redirect names the endpoint\'s own host', async () => {
    const {transport} = scripted(() => new Response('', {status: 301, headers: {Location: '/other/path'}}));

    assert.equal((await transport.post('{}', NO_SESSION)).location, 'shop.example');
});

test('the deadline passing is a timeout failure', async () => {
    const expired = () => {
        const controller = new AbortController();
        controller.abort(new DOMException('The operation timed out.', 'TimeoutError'));

        return controller.signal;
    };
    const {transport} = scripted(() => jsonRpc(200, {jsonrpc: '2.0', id: 1, result: {}}), {timeout: expired});

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'timeout'});
});

test('a deadline that passes while the body is read is a timeout failure', async () => {
    const controller = new AbortController();
    const {transport} = scripted(() => ({
        status: 200,
        headers: new Headers(),
        text: () => {
            controller.abort(new DOMException('The operation timed out.', 'TimeoutError'));

            return Promise.reject(controller.signal.reason);
        }
    }), {timeout: () => controller.signal});

    assert.deepEqual({...(await transport.post('{}', NO_SESSION))}, {kind: 'failed', reason: 'timeout'});
});

test('a cancelled message is an aborted failure', async () => {
    const caller = new AbortController();
    const {transport, calls} = scripted((call) => new Promise((resolve, reject) => {
        call.signal.addEventListener('abort', () => reject(call.signal.reason));
    }));
    const pending = transport.post('{}', NO_SESSION, caller.signal);
    await new Promise((resolve) => setImmediate(resolve));
    caller.abort();

    assert.deepEqual({...(await pending)}, {kind: 'failed', reason: 'aborted'});
    assert.equal(calls[0].signal.aborted, true);
});

test('a fetch that rejects is a network failure, keeping only the system error code', async () => {
    const transport = createHttpTransport({
        url: URL,
        token: TOKEN,
        fetch: () => Promise.reject(new TypeError(`fetch failed for Bearer ${TOKEN}`, {
            cause: Object.assign(new Error(`connect ECONNREFUSED ${TOKEN}`), {code: 'ECONNREFUSED'})
        })),
        timeout: () => new AbortController().signal,
        timeoutMs: 300000,
        log: () => undefined
    });
    const outcome = await transport.post('{}', NO_SESSION);

    assert.deepEqual({...outcome}, {kind: 'failed', reason: 'network', detail: 'ECONNREFUSED'});
    assert.equal(JSON.stringify(outcome).includes(TOKEN), false);
});

test('ending a session sends DELETE with the token and the session headers, within its own deadline', async () => {
    const {transport, calls, logs, deadlines} = scripted(() => new Response(null, {status: 204}));
    await transport.end(SESSION, '2025-06-18');

    assert.equal(calls.length, 1);
    assert.equal(calls[0].method, 'DELETE');
    assert.equal(calls[0].redirect, 'manual');
    assert.deepEqual({...calls[0].headers}, {
        'Authorization': `Bearer ${TOKEN}`,
        'Mcp-Session-Id': SESSION,
        'MCP-Protocol-Version': '2025-06-18'
    });
    assert.deepEqual(deadlines, [10000]);
    assert.equal(logs.length, 1);
    assert.equal(logs[0].includes(TOKEN), false);
});

test('ending a session takes a shorter deadline when given one, and only logs a failure', async () => {
    const {transport, logs, deadlines} = scripted(() => Promise.reject(new TypeError('fetch failed')));
    await transport.end(SESSION, '2025-06-18', 2000);

    assert.deepEqual(deadlines, [2000]);
    assert.equal(logs.length, 1);
    assert.match(logs[0], /network/);
});

test('the caller\'s signal does not collect a listener per message', async () => {
    const caller = new AbortController();
    const {transport} = scripted(() => jsonRpc(200, {jsonrpc: '2.0', id: 1, result: {}}));
    for (let index = 0; index < 3; index += 1) {
        await transport.post('{}', NO_SESSION, caller.signal);
    }

    assert.equal(getEventListeners(caller.signal, 'abort').length, 0);
});
