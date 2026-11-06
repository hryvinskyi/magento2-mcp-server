/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {getEventListeners} = require('node:events');
const {load} = require('./amd.cjs');

const tokenRequest = load('Hryvinskyi_McpServer/js/token-request');

const URL = 'https://shop.example/admin/hryvinskyi_mcp/server/integrationToken/';
const FORM_KEY = 'k3y&=+ /';
const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

/**
 * A response the fake fetch answers with
 *
 * @param {number} status
 * @param {function(): Promise<*>} json
 * @returns {Object}
 */
function response(status, json) {
    return {status, ok: status >= 200 && status < 300, json};
}

/**
 * @param {number} status
 * @param {*} body
 * @returns {Object}
 */
function jsonResponse(status, body) {
    return response(status, () => Promise.resolve(body));
}

/**
 * A fetch that records every call and answers with what `answer` returns for it
 *
 * @param {function(string, Object): Promise<Object>} answer
 * @returns {{fetch: function(string, Object): Promise<Object>, calls: Array<{url: string, init: Object}>}}
 */
function recordingFetch(answer) {
    const calls = [];

    return {
        calls,
        fetch: (url, init) => {
            calls.push({url, init});

            return answer(url, init);
        }
    };
}

/**
 * A fetch that never answers until the request's signal aborts, then rejects as the browser does
 *
 * @returns {{fetch: function(string, Object): Promise<Object>, calls: Array<{url: string, init: Object}>}}
 */
function hangingFetch() {
    return recordingFetch((url, init) => new Promise((resolve, reject) => {
        if (init.signal.aborted) {
            reject(init.signal.reason);

            return;
        }
        init.signal.addEventListener('abort', () => reject(init.signal.reason), {once: true});
    }));
}

/**
 * @param {function(string, Object): Promise<Object>} fetch
 * @param {Object} [overrides]
 * @returns {function(string, ?AbortSignal): Promise<Object>}
 */
function requester(fetch, overrides = {}) {
    return tokenRequest.create({fetch, AbortSignal, url: URL, formKey: FORM_KEY, ...overrides});
}

/**
 * @param {Object} result
 * @returns {Object} A plain copy, for deepEqual across the module's realm
 */
function plain(result) {
    return {...result};
}

test('the token is asked for with a same-origin, uncached form POST carrying the form key', async () => {
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, {token: TOKEN})));

    await requester(recorder.fetch)('7', null);

    assert.equal(recorder.calls.length, 1);
    const {url, init} = recorder.calls[0];
    assert.equal(url, URL);
    assert.equal(init.method, 'POST');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.deepEqual({...init.headers}, {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
    });
    assert.equal(init.body, 'form_key=k3y%26%3D%2B%20%2F&integration_id=7&isAjax=true');
    assert.deepEqual(Object.fromEntries(new URLSearchParams(init.body)),
        {form_key: FORM_KEY, integration_id: '7', isAjax: 'true'});
    assert.ok(init.signal, 'Every request carries a signal');
});

test('a 200 with a valid token gives the token', async () => {
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, {token: TOKEN})));

    assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: true, token: TOKEN});
});

for (const [label, body] of [
    ['a token with a space', {token: 'abc 123'}],
    ['a token with a quote', {token: 'abc"123'}],
    ['an empty token', {token: ''}],
    ['a token that is not a string', {token: 12345}],
    ['no token', {error: 'Nope'}],
    ['null', null],
    ['an array', [TOKEN]],
    ['a bare string', TOKEN]
]) {
    test(`a 200 with ${label} fails`, async () => {
        const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, body)));

        assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
    });
}

test('a 200 whose body is not JSON fails', async () => {
    const recorder = recordingFetch(() => Promise.resolve(response(200, () => Promise.reject(
        new SyntaxError('Unexpected token < in JSON')
    ))));

    assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
});

/**
 * A response whose body is HTML, as Magento's own pages are: reading it as JSON rejects, as the browser's does
 *
 * @param {number} status
 * @returns {Object}
 */
function htmlResponse(status) {
    return response(status, () => Promise.reject(new SyntaxError('Unexpected token < in JSON')));
}

for (const status of [400, 403, 404, 409]) {
    test(`a ${status} with the endpoint's JSON error is a refusal`, async () => {
        const recorder = recordingFetch(() => Promise.resolve(jsonResponse(status, {error: 'Not ready.'})));

        assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'refused'});
    });
}

test('a 404 page of a store that does not route the endpoint yet fails, so a ready integration is not blamed',
    async () => {
        const recorder = recordingFetch(() => Promise.resolve(htmlResponse(404)));

        assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
    });

test('a 403 access-denied page without JSON fails', async () => {
    const recorder = recordingFetch(() => Promise.resolve(htmlResponse(403)));

    assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
});

for (const [label, body] of [
    ['an error that is not a string', {error: 404}],
    ['no error', {message: 'Not found'}],
    ['a token instead of an error', {token: TOKEN}],
    ['null', null],
    ['an array', [{error: 'Not ready.'}]],
    ['a bare string', 'Not ready.']
]) {
    test(`a 409 with ${label} fails`, async () => {
        const recorder = recordingFetch(() => Promise.resolve(jsonResponse(409, body)));

        assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
    });
}

test('a refusal never carries a token, even when the body holds one beside the error', async () => {
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(409, {error: 'Not ready.', token: TOKEN})));

    const result = await requester(recorder.fetch)('7', null);

    assert.deepEqual(plain(result), {ok: false, kind: 'refused'});
    assert.equal(JSON.stringify(result).includes(TOKEN), false);
});

for (const status of [500, 502, 302, 401, 0]) {
    test(`a ${status} fails`, async () => {
        const recorder = recordingFetch(() => Promise.resolve(jsonResponse(status, {token: TOKEN})));

        assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
    });
}

test('a network error fails, and the error does not escape', async () => {
    const recorder = recordingFetch(() => Promise.reject(new TypeError('Failed to fetch')));

    assert.deepEqual(plain(await requester(recorder.fetch)('7', null)), {ok: false, kind: 'failed'});
});

test('a fetch that throws before returning a promise fails', async () => {
    const fetch = () => {
        throw new TypeError('Illegal invocation');
    };

    assert.deepEqual(plain(await requester(fetch)('7', null)), {ok: false, kind: 'failed'});
});

test('the caller\'s abort ends the request as aborted', async () => {
    const recorder = hangingFetch();
    const caller = new AbortController();
    const pending = requester(recorder.fetch)('7', caller.signal);

    caller.abort();

    assert.deepEqual(plain(await pending), {ok: false, kind: 'aborted'});
    assert.equal(recorder.calls[0].init.signal.aborted, true, 'The fetch itself was aborted');
});

test('an answer that arrives after the caller gave up is aborted, never the token', async () => {
    const caller = new AbortController();
    let answer;
    const recorder = recordingFetch(() => new Promise((resolve) => {
        answer = resolve;
    }));
    const pending = requester(recorder.fetch)('7', caller.signal);

    caller.abort();
    answer(jsonResponse(200, {token: TOKEN}));

    assert.deepEqual(plain(await pending), {ok: false, kind: 'aborted'});
});

test('no answer before the deadline fails', async () => {
    const recorder = hangingFetch();
    const caller = new AbortController();

    const result = await requester(recorder.fetch, {timeoutMs: 5})('7', caller.signal);

    assert.deepEqual(plain(result), {ok: false, kind: 'failed'});
    assert.equal(caller.signal.aborted, false);
    assert.equal(recorder.calls[0].init.signal.aborted, true);
});

test('the deadline holds without a caller signal too', async () => {
    const recorder = hangingFetch();

    assert.deepEqual(plain(await requester(recorder.fetch, {timeoutMs: 5})('7', null)), {ok: false, kind: 'failed'});
});

test('a timeout that is not a positive number falls back to ten seconds', async () => {
    const asked = [];
    const signals = {
        timeout: (ms) => {
            asked.push(ms);

            return AbortSignal.timeout(ms);
        },
        any: (list) => AbortSignal.any(list)
    };
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, {token: TOKEN})));

    for (const timeoutMs of [undefined, 0, -1, NaN, '5']) {
        await requester(recorder.fetch, {AbortSignal: signals, timeoutMs})('7', null);
    }

    assert.deepEqual(asked, [10000, 10000, 10000, 10000, 10000]);
});

/**
 * An AbortSignal without `any`, as in a browser that predates it
 *
 * @returns {{timeout: function(number): AbortSignal}}
 */
function signalsWithoutAny() {
    return {timeout: (ms) => AbortSignal.timeout(ms)};
}

test('without AbortSignal.any the caller\'s abort still aborts the fetch, as aborted', async () => {
    const recorder = hangingFetch();
    const caller = new AbortController();
    const pending = requester(recorder.fetch, {AbortSignal: signalsWithoutAny()})('7', caller.signal);

    caller.abort();

    assert.deepEqual(plain(await pending), {ok: false, kind: 'aborted'});
    assert.equal(recorder.calls[0].init.signal.aborted, true);
});

test('without AbortSignal.any the deadline still fails the request', async () => {
    const recorder = hangingFetch();
    const caller = new AbortController();

    const result = await requester(recorder.fetch, {AbortSignal: signalsWithoutAny(), timeoutMs: 5})(
        '7',
        caller.signal
    );

    assert.deepEqual(plain(result), {ok: false, kind: 'failed'});
});

test('without AbortSignal.any a caller signal aborted beforehand aborts at once', async () => {
    const recorder = hangingFetch();
    const caller = new AbortController();
    caller.abort();

    const result = await requester(recorder.fetch, {AbortSignal: signalsWithoutAny()})('7', caller.signal);

    assert.deepEqual(plain(result), {ok: false, kind: 'aborted'});
    assert.equal(recorder.calls[0].init.signal.aborted, true);
});

test('without AbortSignal.any the listeners on the caller\'s signal are removed once the request settles', async () => {
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, {token: TOKEN})));
    const caller = new AbortController();
    const request = requester(recorder.fetch, {AbortSignal: signalsWithoutAny()});

    await request('7', caller.signal);
    await request('8', caller.signal);

    assert.equal(getEventListeners(caller.signal, 'abort').length, 0);
});

test('the token appears in no field of a failed result', async () => {
    const recorder = recordingFetch(() => Promise.resolve(jsonResponse(200, {token: 'abc 123'})));

    const result = await requester(recorder.fetch)('7', null);

    assert.equal(JSON.stringify(result).includes('abc'), false);
});
