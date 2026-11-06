/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {createBridge} = require('../../../extension/server/bridge');
const {createHttpTransport} = require('../../../extension/server/http-transport');
const {messages} = require('../../../extension/server/messages');

const FIRST = 'a'.repeat(32);
const SECOND = 'b'.repeat(32);
const THIRD = 'c'.repeat(32);
const NO_SESSION = {sessionId: '', protocolVersion: ''};
const TOKEN = 'fake-token-for-tests';

const INITIALIZE = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';
const INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
const LIST = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
const CALL = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"slow"}}';
const LOST = {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Session not found'}};

/**
 * An `answer` outcome
 *
 * @param {number} status
 * @param {Object} body
 * @param {string} sessionId
 * @returns {{kind: string, status: number, text: string, sessionId: string}}
 */
function answer(status, body, sessionId = '') {
    return {kind: 'answer', status, text: JSON.stringify(body), sessionId};
}

/**
 * The answer to `initialize` that opens a session
 *
 * @param {string} sessionId
 * @param {string} version
 * @returns {Object}
 */
function opened(sessionId, version = '2025-06-18') {
    return answer(200, {jsonrpc: '2.0', id: 0, result: {protocolVersion: version}}, sessionId);
}

/**
 * The store's answer to a request it handled
 *
 * @param {number|string} id
 * @returns {Object}
 */
function result(id) {
    return answer(200, {jsonrpc: '2.0', id, result: {for: id}});
}

/**
 * A promise the test settles when it wants
 *
 * @returns {{promise: Promise<Object>, resolve: function(Object): void}}
 */
function deferred() {
    let resolve;
    const promise = new Promise((settle) => {
        resolve = settle;
    });

    return {promise, resolve};
}

/**
 * Let every promise callback that is ready run
 *
 * @returns {Promise<void>}
 */
function flush() {
    return new Promise((resolve) => setImmediate(resolve));
}

/**
 * A bridge over a fake transport that answers each POST with the next scripted outcome
 *
 * A script entry is an outcome, a promise of one, or a function of the call returning either.
 *
 * @param {Array<Object|Promise<Object>|function(Object): (Object|Promise<Object>)>} script
 * @returns {{bridge: Object, posts: Object[], ends: Array<Array>, written: string[], logs: string[]}}
 */
function scripted(script) {
    const posts = [];
    const ends = [];
    const written = [];
    const logs = [];
    const transport = {
        post(body, session, signal) {
            const call = {body, session: {...session}, signal};
            posts.push(call);
            const next = script.shift();
            assert.notEqual(next, undefined, `nothing scripted for ${body}`);

            return Promise.resolve(typeof next === 'function' ? next(call) : next);
        },
        end(sessionId, protocolVersion, deadlineMs) {
            ends.push([sessionId, protocolVersion, deadlineMs]);

            return Promise.resolve();
        }
    };
    const bridge = createBridge({
        transport,
        write: (text) => written.push(text),
        log: (text) => logs.push(text),
        host: 'shop.example'
    });

    return {bridge, posts, ends, written, logs};
}

test('initialize keeps the session and its version, and the next line carries both', async () => {
    const {bridge, posts, written} = scripted([opened(FIRST), result(1)]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(LIST);

    assert.deepEqual(posts[0].session, NO_SESSION);
    assert.equal(posts[0].body, INITIALIZE);
    assert.deepEqual(posts[1].session, {sessionId: FIRST, protocolVersion: '2025-06-18'});
    assert.deepEqual(written.map((line) => JSON.parse(line).id), [0, 1]);
});

test('an accepted notification writes nothing', async () => {
    const {bridge, written} = scripted([opened(FIRST), {kind: 'accepted'}]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(INITIALIZED);

    assert.equal(written.length, 1);
});

test('an answer is written as one line of compact JSON', async () => {
    const pretty = {kind: 'answer', status: 200, text: '{\n  "jsonrpc": "2.0",\n  "id": 1,\n  "result": {}\n}', sessionId: ''};
    const {bridge, written} = scripted([pretty]);
    await bridge.handle(LIST);

    assert.deepEqual(written, ['{"jsonrpc":"2.0","id":1,"result":{}}']);
});

test('a failure answers a batch with one line holding an error for each of its requests, and logs it', async () => {
    const {bridge, written, logs} = scripted([{kind: 'failed', reason: 'unauthorized', status: 401}]);
    await bridge.handle(`[${LIST},${INITIALIZED},${CALL}]`);

    assert.equal(written.length, 1);
    assert.deepEqual(JSON.parse(written[0]), [1, 2].map((id) => ({
        jsonrpc: '2.0',
        id,
        error: {code: -32000, message: messages.unauthorized({}), data: {reason: 'unauthorized', status: 401}}
    })));
    assert.equal(logs.length, 1);
    assert.match(logs[0], /unauthorized \(HTTP 401\)/);
});

test('a failure answers a batch holding one request with an array, and a batch of notifications with nothing', async () => {
    const failed = {kind: 'failed', reason: 'timeout'};
    const {bridge, written} = scripted([failed, failed]);

    await bridge.handle(`[${LIST}]`);
    assert.equal(written.length, 1);
    assert.deepEqual(JSON.parse(written[0]).map((error) => error.id), [1]);

    written.length = 0;
    await bridge.handle(`[${INITIALIZED}]`);
    assert.deepEqual(written, []);
});

test('a failure without a status names the store\'s host and leaves the status out', async () => {
    const {bridge, written} = scripted([{kind: 'failed', reason: 'network', detail: 'ECONNREFUSED'}]);
    await bridge.handle(LIST);

    assert.deepEqual(JSON.parse(written[0]).error, {
        code: -32000,
        message: 'Could not reach the store at shop.example.',
        data: {reason: 'network'}
    });
});

test('a failure answering a notification writes nothing', async () => {
    const {bridge, written, logs} = scripted([{kind: 'failed', reason: 'timeout'}]);
    await bridge.handle(INITIALIZED);

    assert.deepEqual(written, []);
    assert.equal(logs.length, 1);
});

test('a lost session is opened again once, and only the resent line\'s answer is written', async () => {
    const {bridge, posts, written} = scripted([
        opened(FIRST), {kind: 'accepted'}, answer(404, LOST),
        opened(SECOND, '2025-11-25'), {kind: 'accepted'}, result(1)
    ]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(INITIALIZED);
    await bridge.handle(LIST);

    assert.deepEqual(posts.map((call) => [call.body, call.session.sessionId, call.session.protocolVersion]), [
        [INITIALIZE, '', ''],
        [INITIALIZED, FIRST, '2025-06-18'],
        [LIST, FIRST, '2025-06-18'],
        [INITIALIZE, '', ''],
        [INITIALIZED, SECOND, '2025-11-25'],
        [LIST, SECOND, '2025-11-25']
    ]);
    assert.deepEqual(written.map((line) => JSON.parse(line).id), [0, 1]);
    assert.deepEqual(JSON.parse(written[1]).result, {for: 1});
});

test('a line that finds the new session lost too gets its original answer, addressed to its id', async () => {
    const {bridge, posts, written, logs} = scripted([
        opened(FIRST), answer(404, LOST), opened(SECOND), answer(404, LOST)
    ]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(LIST);

    assert.equal(posts.length, 4);
    assert.deepEqual(JSON.parse(written[1]), {jsonrpc: '2.0', id: 1, error: LOST.error});
    assert.match(logs.join('\n'), /lost as well/);
});

test('a replay whose answer opens no session writes the original answer', async () => {
    const refused = answer(403, {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled'}});
    const {bridge, posts, written} = scripted([opened(FIRST), answer(404, LOST), refused]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(LIST);

    assert.equal(posts.length, 3);
    assert.deepEqual(JSON.parse(written[1]), {jsonrpc: '2.0', id: 1, error: LOST.error});
});

test('a 404 to a line sent without a session is written as it came, with no replay', async () => {
    const {bridge, posts, written} = scripted([answer(404, LOST)]);
    await bridge.handle(LIST);

    assert.equal(posts.length, 1);
    assert.deepEqual(JSON.parse(written[0]), {jsonrpc: '2.0', id: 1, error: LOST.error});
});

test('a whole-body refusal is addressed to the request, to a batch as one array of its requests, and to no notification', async () => {
    const disabled = {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'The MCP server is disabled.', data: {x: 1}}};
    const {bridge, written} = scripted([answer(403, disabled), answer(403, disabled), answer(403, disabled)]);

    await bridge.handle(LIST);
    assert.deepEqual(written.map((line) => JSON.parse(line)), [{jsonrpc: '2.0', id: 1, error: disabled.error}]);

    written.length = 0;
    await bridge.handle(`[${LIST},${INITIALIZED},${CALL}]`);
    assert.equal(written.length, 1);
    assert.deepEqual(JSON.parse(written[0]), [
        {jsonrpc: '2.0', id: 1, error: disabled.error},
        {jsonrpc: '2.0', id: 2, error: disabled.error}
    ]);

    written.length = 0;
    await bridge.handle(INITIALIZED);
    assert.deepEqual(written, []);
});

test('a whole-body refusal of a line holding no JSON-RPC message is written as it came', async () => {
    const invalid = {jsonrpc: '2.0', id: null, error: {code: -32600, message: 'Invalid Request'}};
    const {bridge, written} = scripted([answer(400, invalid)]);
    await bridge.handle('{"foo":1}');

    assert.deepEqual(written.map((line) => JSON.parse(line)), [invalid]);
});

test('a whole-body refusal of a batch holding only notifications writes nothing', async () => {
    const disabled = {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled'}};
    const {bridge, written} = scripted([answer(403, disabled)]);
    await bridge.handle(`[${INITIALIZED}]`);

    assert.deepEqual(written, []);
});

test('an error with the id null that is not a whole-body refusal is written as it came', async () => {
    const answers = [
        {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled', data: 'x'}},
        {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled', data: [1]}},
        {jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled', data: null}},
        {jsonrpc: '2.0', id: null, error: {code: -32001.5, message: 'Disabled'}},
        {jsonrpc: '2.0', id: null, error: {code: '-32001', message: 'Disabled'}},
        {jsonrpc: '2.0', id: null, error: {code: -32001, message: 7}},
        {jsonrpc: '2.0', id: null, error: {code: -32001}},
        {jsonrpc: '1.0', id: null, error: {code: -32001, message: 'Disabled'}},
        {id: null, error: {code: -32001, message: 'Disabled'}},
        [{jsonrpc: '2.0', id: null, error: {code: -32001, message: 'Disabled'}}]
    ];
    const {bridge, written} = scripted(answers.map((body) => answer(403, body)));

    for (const body of answers) {
        written.length = 0;
        await bridge.handle(LIST);
        assert.deepEqual(written.map((line) => JSON.parse(line)), [body], JSON.stringify(body));
    }
});

test('an initialize line always goes without the session headers', async () => {
    const {bridge, posts} = scripted([opened(FIRST), opened(SECOND)]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(INITIALIZE);

    assert.deepEqual(posts[1].session, NO_SESSION);
});

test('an initialize answered with an error keeps the previous session', async () => {
    const refused = answer(200, {jsonrpc: '2.0', id: 0, error: {code: -32602, message: 'Bad params'}});
    const {bridge, posts} = scripted([opened(FIRST), refused, result(1)]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(INITIALIZE);
    await bridge.handle(LIST);

    assert.deepEqual(posts[2].session, {sessionId: FIRST, protocolVersion: '2025-06-18'});
});

test('a line that is not JSON is answered with a parse error and never sent', async () => {
    const {bridge, posts, written} = scripted([]);
    await bridge.handle('{not json');

    assert.equal(posts.length, 0);
    assert.deepEqual(written, ['{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}']);
});

test('a message over the size limit is answered with an error that names no request', () => {
    const {bridge, written} = scripted([]);
    bridge.rejectOversize();

    assert.deepEqual(JSON.parse(written[0]), {
        jsonrpc: '2.0',
        id: null,
        error: {code: -32600, message: messages.oversize({}), data: {reason: 'oversize'}}
    });
});

test('before a session is open, a line waits for the one before it', async () => {
    const slow = deferred();
    const {bridge, posts, written} = scripted([slow.promise, result(1)]);
    const first = bridge.handle(INITIALIZE);
    const second = bridge.handle(LIST);
    await flush();

    assert.equal(posts.length, 1);

    slow.resolve(opened(FIRST));
    await Promise.all([first, second]);

    assert.equal(posts.length, 2);
    assert.equal(posts[1].session.sessionId, FIRST);
    assert.deepEqual(written.map((line) => JSON.parse(line).id), [0, 1]);
});

test('once a session is open, a fast answer is written while a slow call is still in flight', async () => {
    const slow = deferred();
    const {bridge, written} = scripted([opened(FIRST), slow.promise, result(1)]);
    await bridge.handle(INITIALIZE);
    const call = bridge.handle(CALL);
    await bridge.handle(LIST);

    assert.deepEqual(written.map((line) => JSON.parse(line).id), [0, 1]);

    slow.resolve(result(2));
    await call;

    assert.deepEqual(written.map((line) => JSON.parse(line).id), [0, 1, 2]);
});

test('an initialize waits for the lines in flight, and lines after it wait for its answer', async () => {
    const slow = deferred();
    const reopening = deferred();
    const {bridge, posts} = scripted([opened(FIRST), slow.promise, reopening.promise, result(1)]);
    await bridge.handle(INITIALIZE);
    const call = bridge.handle(CALL);
    const again = bridge.handle(INITIALIZE);
    const list = bridge.handle(LIST);
    await flush();

    assert.equal(posts.length, 2);

    slow.resolve(result(2));
    await call;
    await flush();

    assert.equal(posts.length, 3);
    assert.deepEqual(posts[2].session, NO_SESSION);

    reopening.resolve(opened(SECOND));
    await Promise.all([again, list]);

    assert.equal(posts[3].body, LIST);
    assert.equal(posts[3].session.sessionId, SECOND);
});

test('lines that meet the same lost session together share one replay and are each sent once more', async () => {
    const first = deferred();
    const second = deferred();
    const {bridge, posts, written} = scripted([
        opened(FIRST), first.promise, second.promise, opened(SECOND),
        (call) => result(JSON.parse(call.body).id), (call) => result(JSON.parse(call.body).id)
    ]);
    await bridge.handle(INITIALIZE);
    const call = bridge.handle(CALL);
    const list = bridge.handle(LIST);
    await flush();
    first.resolve(answer(404, LOST));
    second.resolve(answer(404, LOST));
    await Promise.all([call, list]);

    const bodies = posts.map((post) => post.body);
    assert.equal(bodies.filter((body) => body === INITIALIZE).length, 2);
    assert.equal(bodies.filter((body) => body === CALL).length, 2);
    assert.equal(bodies.filter((body) => body === LIST).length, 2);
    assert.deepEqual(posts.slice(4).map((post) => post.session.sessionId), [SECOND, SECOND]);
    assert.deepEqual(written.map((line) => JSON.parse(line).id).sort(), [0, 1, 2]);
});

test('a line arriving while a lost session is being opened again waits for it', async () => {
    const reopening = deferred();
    const {bridge, posts} = scripted([opened(FIRST), answer(404, LOST), reopening.promise, result(1), result(2)]);
    await bridge.handle(INITIALIZE);
    const list = bridge.handle(LIST);
    await flush();
    const call = bridge.handle(CALL);
    await flush();

    assert.equal(posts.length, 3);

    reopening.resolve(opened(SECOND));
    await Promise.all([list, call]);

    assert.deepEqual(posts.slice(3).map((post) => post.session.sessionId), [SECOND, SECOND]);
});

test('abort cancels the lines in flight, writes nothing for them and ends the session once, quickly', async () => {
    const {bridge, posts, written, ends} = scripted([
        opened(FIRST),
        (call) => new Promise((resolve) => {
            call.signal.addEventListener('abort', () => resolve({kind: 'failed', reason: 'aborted'}));
        })
    ]);
    await bridge.handle(INITIALIZE);
    const call = bridge.handle(CALL);
    await flush();
    await bridge.abort();
    await call;
    await bridge.close();

    assert.equal(posts[1].signal.aborted, true);
    assert.equal(written.length, 1);
    assert.deepEqual(ends, [[FIRST, '2025-06-18', 2000]]);
});

test('an answer that comes back after abort is not written', async () => {
    const late = deferred();
    const {bridge, written} = scripted([opened(FIRST), late.promise]);
    await bridge.handle(INITIALIZE);
    const call = bridge.handle(CALL);
    const aborting = bridge.abort();
    late.resolve(result(2));
    await Promise.all([call, aborting]);

    assert.equal(written.length, 1);
});

test('close waits for the lines in flight, then ends the session once', async () => {
    const slow = deferred();
    const {bridge, ends, written} = scripted([opened(FIRST), slow.promise]);
    await bridge.handle(INITIALIZE);
    bridge.handle(CALL);
    const closing = bridge.close();
    await flush();

    assert.deepEqual(ends, []);

    slow.resolve(result(2));
    await closing;
    await bridge.close();

    assert.equal(written.length, 2);
    assert.deepEqual(ends, [[FIRST, '2025-06-18', undefined]]);
});

test('close without a session ends nothing', async () => {
    const {bridge, ends} = scripted([]);
    await bridge.close();

    assert.deepEqual(ends, []);
});

test('no written line or log names the access token, whatever the store answers', async () => {
    const answers = [
        () => new Response('{"message":"The consumer isn\'t authorized."}', {status: 401}),
        () => new Response('', {status: 302, headers: {Location: `https://elsewhere.example/?token=${TOKEN}`}}),
        () => Promise.reject(new TypeError(`fetch failed: Bearer ${TOKEN}`)),
        () => new Response(`<html>${'x'.repeat(10)}</html>`, {status: 500})
    ];
    const written = [];
    const logs = [];
    const bridge = createBridge({
        transport: createHttpTransport({
            url: 'https://shop.example/rest/all/V1/mcp',
            token: TOKEN,
            fetch: () => answers.shift()(),
            timeout: () => new AbortController().signal,
            timeoutMs: 300000,
            log: (text) => logs.push(text)
        }),
        write: (text) => written.push(text),
        log: (text) => logs.push(text),
        host: 'shop.example'
    });
    for (let id = 1; id <= 4; id += 1) {
        await bridge.handle(`{"jsonrpc":"2.0","id":${id},"method":"tools/list"}`);
    }

    assert.equal(written.length, 4);
    assert.deepEqual(written.map((line) => JSON.parse(line).error.data.reason), ['unauthorized', 'redirect', 'network', 'http']);
    assert.match(JSON.parse(written[1]).error.message, /elsewhere\.example;/);
    assert.equal([...written, ...logs].some((text) => text.includes(TOKEN)), false);
});

test('a kept notifications/initialized is not sent again when the client never sent one', async () => {
    const {bridge, posts} = scripted([opened(FIRST), answer(404, LOST), opened(THIRD), result(1)]);
    await bridge.handle(INITIALIZE);
    await bridge.handle(LIST);

    assert.deepEqual(posts.map((post) => post.body), [INITIALIZE, LIST, INITIALIZE, LIST]);
    assert.equal(posts[3].session.sessionId, THIRD);
});
