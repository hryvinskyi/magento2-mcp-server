/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const sectionSwitch = load('Hryvinskyi_McpServer/js/section-switch');

/**
 * A copy made in this realm: the module's objects come from the AMD loader's context, whose prototypes differ
 *
 * @param {*} value
 * @returns {*}
 */
const plain = (value) => JSON.parse(JSON.stringify(value));

const URL = 'https://shop.example/admin/mcpserver/section/off/';
const FIELDS = [['form_key', 'k3y&='], ['section', 'Magento_Backend::system']];
const SWITCHED = {
    message: 'The section System is switched off: no client may call its abilities.',
    exposed: 22,
    sections: [
        {code: 'Magento_Backend::system', off: true, exposed: 0},
        {code: 'Magento_Sales::sales', off: false, exposed: 22}
    ]
};

/**
 * @param {number} status
 * @param {function(): Promise<*>} json
 * @returns {Object}
 */
function response(status, json) {
    return {status, ok: status >= 200 && status < 300, json};
}

/**
 * A switch function over a fetch that answers every call with `answer` and records it
 *
 * @param {function(): Promise<Object>} answer
 * @returns {{switchSection: Function, calls: Array<{url: string, init: Object}>}}
 */
function switchOver(answer) {
    const calls = [];
    const switchSection = sectionSwitch.create({
        fetch: (url, init) => {
            calls.push({url, init});

            return answer();
        },
        AbortSignal
    });

    return {switchSection, calls};
}

test('the form fields are posted to the form action as an AJAX request', async () => {
    const {switchSection, calls} = switchOver(() => Promise.resolve(response(200, () => Promise.resolve(SWITCHED))));

    await switchSection(URL, FIELDS, null);

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, URL);
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(calls[0].init.body, 'form_key=k3y%26%3D&section=Magento_Backend%3A%3Asystem&isAjax=true');
});

test('a switched section comes back with its message and the state of every section', async () => {
    const {switchSection} = switchOver(() => Promise.resolve(response(200, () => Promise.resolve(SWITCHED))));

    const result = await switchSection(URL, FIELDS, null);

    assert.deepEqual(plain(result), {ok: true, message: SWITCHED.message, exposed: 22, sections: SWITCHED.sections});
});

test('every count is rounded down, the page\'s total as much as a section\'s', async () => {
    const {switchSection} = switchOver(() => Promise.resolve(response(200, () => Promise.resolve({
        message: 'Done.',
        exposed: 22.9,
        sections: [{code: 'Magento_Sales::sales', off: false, exposed: 21.5}]
    }))));

    const result = plain(await switchSection(URL, FIELDS, null));

    assert.equal(result.exposed, 22);
    assert.equal(result.sections[0].exposed, 21);
});

test('an answer that lists no section is still a switched answer, with its total', async () => {
    const {switchSection} = switchOver(
        () => Promise.resolve(response(200, () => Promise.resolve({message: 'Done.', exposed: 0, sections: []})))
    );

    assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: true, message: 'Done.', exposed: 0, sections: []});
});

test('the endpoint\'s own refusal, a 400 with a JSON error, is refused with its message', async () => {
    const {switchSection} = switchOver(
        () => Promise.resolve(response(400, () => Promise.resolve({error: 'Choose a section.'})))
    );

    assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: false, kind: 'refused', message: 'Choose a section.'});
});

test('a JSON error under any status but 400 fails, since the endpoint refuses only with a 400', async () => {
    for (const status of [403, 404, 409, 422, 500]) {
        const {switchSection} = switchOver(
            () => Promise.resolve(response(status, () => Promise.resolve({error: 'Choose a section.'})))
        );

        assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: false, kind: 'failed'}, String(status));
    }
});

test('anything that is not the endpoint speaking fails', async () => {
    const answers = {
        'HTML access denied page': response(403, () => Promise.reject(new SyntaxError('not JSON'))),
        'a 400 whose error is not a string': response(400, () => Promise.resolve({error: true, message: 'Nope.'})),
        'a 400 that is not JSON': response(400, () => Promise.reject(new SyntaxError('not JSON'))),
        'the HTML page a bad form key is redirected to': response(200, () => Promise.reject(new SyntaxError('not JSON'))),
        'an expired session': response(200, () => Promise.resolve({ajaxExpired: 1, ajaxRedirect: '/admin'})),
        'a server error': response(500, () => Promise.resolve({error: 'The section was not switched.'})),
        'a section without a code': response(200, () => Promise.resolve({
            message: 'Done.',
            exposed: 0,
            sections: [{off: true, exposed: 0}]
        })),
        'a negative count': response(200, () => Promise.resolve({
            message: 'Done.',
            exposed: 0,
            sections: [{code: 'A::b', off: true, exposed: -1}]
        })),
        'no message': response(200, () => Promise.resolve({exposed: 0, sections: []})),
        'no total': response(200, () => Promise.resolve({message: 'Done.', sections: []})),
        'a negative total': response(200, () => Promise.resolve({message: 'Done.', exposed: -1, sections: []})),
        'a total that is not finite': response(200, () => Promise.resolve({message: 'Done.', exposed: Infinity, sections: []})),
        'a total that is a string': response(200, () => Promise.resolve({message: 'Done.', exposed: '22', sections: []})),
        'no sections': response(200, () => Promise.resolve({message: 'Done.', exposed: 0}))
    };

    for (const [name, answer] of Object.entries(answers)) {
        const {switchSection} = switchOver(() => Promise.resolve(answer));

        assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: false, kind: 'failed'}, name);
    }
});

test('a network error fails', async () => {
    const {switchSection} = switchOver(() => Promise.reject(new TypeError('Failed to fetch')));

    assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: false, kind: 'failed'});
});

test('a switch the caller aborted reads as aborted, whatever came back', async () => {
    const controller = new AbortController();
    const {switchSection} = switchOver(() => {
        controller.abort();

        return Promise.resolve(response(200, () => Promise.resolve(SWITCHED)));
    });

    assert.deepEqual(plain(await switchSection(URL, FIELDS, controller.signal)), {ok: false, kind: 'aborted'});
});

test('a browser without AbortSignal.timeout fails the switch instead of throwing', async () => {
    const switchSection = sectionSwitch.create({
        fetch: () => Promise.resolve(response(200, () => Promise.resolve(SWITCHED))),
        AbortSignal: {any: (list) => AbortSignal.any(list)}
    });

    assert.deepEqual(plain(await switchSection(URL, FIELDS, null)), {ok: false, kind: 'failed'});
});
