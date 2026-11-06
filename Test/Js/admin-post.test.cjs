/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const adminPost = load('Hryvinskyi_McpServer/js/admin-post');

const URL = 'https://shop.example/admin/mcpserver/section/on/';
const FIELDS = [['form_key', 'k3y'], ['section', 'Magento_Sales::sales']];

/**
 * A copy made in this realm: the module's objects come from the AMD loader's context, whose prototypes differ
 *
 * @param {*} value
 * @returns {*}
 */
const plain = (value) => JSON.parse(JSON.stringify(value));

/**
 * A fetch that answers 200 with an empty JSON object and counts its calls
 *
 * @returns {{fetch: function(): Promise<Object>, count: function(): number}}
 */
function countingFetch() {
    let calls = 0;

    return {
        fetch: () => {
            calls += 1;

            return Promise.resolve({status: 200, json: () => Promise.resolve({})});
        },
        count: () => calls
    };
}

/**
 * Call the post function, failing the test if it throws instead of returning a promise
 *
 * @param {function(string, Array<Array<string>>, ?AbortSignal): Promise<Object>} post
 * @param {?AbortSignal} signal
 * @returns {Promise<Object>}
 */
function postWithoutThrowing(post, signal) {
    let pending;

    assert.doesNotThrow(() => {
        pending = post(URL, FIELDS, signal);
    });

    return pending;
}

for (const [label, signals] of [
    ['an AbortSignal without timeout', {any: (list) => AbortSignal.any(list)}],
    ['an AbortSignal whose timeout throws', {
        timeout: () => {
            throw new TypeError('Illegal invocation');
        },
        any: (list) => AbortSignal.any(list)
    }],
    ['no AbortSignal at all', undefined]
]) {
    test(`with ${label} the post resolves failed instead of throwing, and nothing is sent`, async () => {
        const recorder = countingFetch();
        const post = adminPost.create({fetch: recorder.fetch, AbortSignal: signals});

        assert.deepEqual(plain(await postWithoutThrowing(post, new AbortController().signal)), {kind: 'failed'});
        assert.deepEqual(plain(await postWithoutThrowing(post, null)), {kind: 'failed'});
        assert.equal(recorder.count(), 0);
    });
}

test('a caller that already gave up reads aborted even when the deadline cannot be set up', async () => {
    const caller = new AbortController();
    caller.abort();
    const post = adminPost.create({fetch: countingFetch().fetch, AbortSignal: {}});

    assert.deepEqual(plain(await postWithoutThrowing(post, caller.signal)), {kind: 'aborted'});
});

test('a response that is not an object fails rather than rejecting', async () => {
    const post = adminPost.create({fetch: () => Promise.resolve(null), AbortSignal});

    assert.deepEqual(plain(await postWithoutThrowing(post, null)), {kind: 'failed'});
});

test('the deadline is the configured one', async () => {
    const asked = [];
    const post = adminPost.create({
        fetch: countingFetch().fetch,
        AbortSignal: {
            timeout: (ms) => {
                asked.push(ms);

                return AbortSignal.timeout(ms);
            },
            any: (list) => AbortSignal.any(list)
        },
        timeoutMs: 30000
    });

    assert.deepEqual(plain(await post(URL, FIELDS, null)), {kind: 'answered', status: 200, json: {}});
    assert.deepEqual(asked, [30000]);
});
