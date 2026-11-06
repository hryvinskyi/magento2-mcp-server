/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const picker = load('Hryvinskyi_McpServer/js/integration-picker');
const card = load('Hryvinskyi_McpServer/js/connection-card');

const WORDING = {loading: 'Fetching the token…', refused: 'The store refused this integration.', failed: 'Try again.'};
const FIRST = 'abcdefghijklmnopqrstuvwxyz012345';
const SECOND = 'ZYXWVUTSRQPONMLKJIHGFEDCBA987654';

/**
 * Let every pending promise callback run
 *
 * @returns {Promise<void>}
 */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

/**
 * An element of the fake page; it answers the attribute selectors the scripts use and nothing else
 *
 * @param {Object<string, string>} attributes
 * @param {string} [text]
 * @param {?Object} [parent]
 * @returns {Object}
 */
function node(attributes, text = '', parent = null) {
    const matches = (selector) => {
        const parsed = /^\[([\w-]+)(?:="([^"]*)")?\]$/.exec(selector);

        if (!parsed) {
            throw new Error(`The fake page does not answer ${selector}`);
        }

        return parsed[2] === undefined ? parsed[1] in attributes : attributes[parsed[1]] === parsed[2];
    };
    const element = {
        attributes,
        textContent: text,
        value: '',
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        hasAttribute: (name) => name in attributes,
        setAttribute: (name, value) => {
            attributes[name] = String(value);
        },
        removeAttribute: (name) => {
            delete attributes[name];
        },
        matches,
        closest: (selector) => {
            if (matches(selector)) {
                return element;
            }

            return parent ? parent.closest(selector) : null;
        }
    };

    return element;
}

/**
 * A select whose value follows its selected option, as a browser's does
 *
 * @param {Array<{value: string, ready: (string|undefined), label: string}>} options
 * @returns {Object}
 */
function selectNode(options) {
    const select = node({'data-role': 'mcp-integration', id: 'mcp-integration'});

    select.options = options.map(({value, ready, label}) => node(
        ready === undefined ? {value} : {value, 'data-ready': ready},
        label
    ));
    select.selectedIndex = 0;
    Object.defineProperty(select, 'value', {
        get: () => (select.selectedIndex >= 0 ? select.options[select.selectedIndex].getAttribute('value') : ''),
        set: (value) => {
            select.selectedIndex = select.options.findIndex((option) => option.getAttribute('value') === value);
        }
    });

    return select;
}

/**
 * The connection card's picker: the select, the token field, the status line and a note per integration that is
 * not ready, with `extra` elements of the card beside them
 *
 * @param {Array<Object>} [extra]
 * @returns {Object}
 */
function pickerPage(extra = []) {
    const listeners = [];
    const select = selectNode([
        {value: '', label: 'Paste a token by hand'},
        {value: '1', ready: '1', label: 'Assistant'},
        {value: '2', ready: '1', label: 'Editor'},
        {value: '3', ready: '0', label: 'Inactive'},
        {value: '4', ready: '0', label: 'No access'}
    ]);
    const field = node({'data-role': 'mcp-token', id: 'mcp-access-token', type: 'password'});
    const status = node({'data-role': 'mcp-integration-status', 'aria-live': 'polite'});
    const noteInactive = node({'data-integration-note': '3', hidden: ''}, 'Not active. Edit integration');
    const noteNoAccess = node({'data-integration-note': '4', hidden: ''}, 'No MCP access. Edit integration');
    const elements = [select, field, status, noteInactive, noteNoAccess, ...extra];
    const ownerDocument = {getElementById: (id) => elements.find((element) => element.attributes.id === id) || null};
    const root = {
        ownerDocument,
        querySelector: (selector) => elements.find((element) => element.matches(selector)) || null,
        querySelectorAll: (selector) => elements.filter((element) => element.matches(selector)),
        addEventListener: (type, listener, options) => listeners.push({type, listener, signal: options.signal})
    };
    const dispatch = (type, target) => listeners
        .filter((entry) => entry.type === type && !entry.signal.aborted)
        .forEach((entry) => entry.listener({target}));

    field.ownerDocument = ownerDocument;

    return {
        root,
        listeners,
        select,
        field,
        status,
        noteInactive,
        noteNoAccess,
        dispatch,
        pick: (value) => {
            select.value = value;
            dispatch('change', select);
        },
        type: (value) => {
            field.value = value;
            dispatch('input', field);
        },
        live: (type) => listeners.filter((entry) => entry.type === type && !entry.signal.aborted).length
    };
}

/**
 * A request function whose answers the test gives by hand, one per call
 *
 * @returns {{request: function(string, AbortSignal): Promise<Object>,
 *     calls: Array<{id: string, signal: AbortSignal, answer: function(Object): void, fail: function(): void}>}}
 */
function manualRequest() {
    const calls = [];

    return {
        calls,
        request: (id, signal) => new Promise((resolve, reject) => {
            calls.push({id, signal, answer: resolve, fail: () => reject(new Error('Broken'))});
        })
    };
}

/**
 * Mount the picker with a manual request and an announcer that records each announced value and delivers the
 * `input` event to the root's listeners, as the browser's bubbling does
 *
 * @param {Object} page
 * @returns {{calls: Array<Object>, announced: Array<string>, teardown: function(): void}}
 */
function mountPicker(page) {
    const {request, calls} = manualRequest();
    const announced = [];
    const teardown = picker.mount(page.root, {
        request,
        announce: (field) => {
            announced.push(field.value);
            page.dispatch('input', field);
        },
        wording: WORDING
    });

    return {calls, announced, teardown};
}

/**
 * @param {Object} page
 * @returns {Array<string>} The ids of the notes that are shown
 */
function shownNotes(page) {
    return [page.noteInactive, page.noteNoAccess]
        .filter((note) => !note.hasAttribute('hidden'))
        .map((note) => note.getAttribute('data-integration-note'));
}

test('picking a ready integration asks for its token, writes it into the field and announces it', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);

    page.pick('1');

    assert.equal(calls.length, 1);
    assert.equal(calls[0].id, '1');
    assert.equal(calls[0].signal.aborted, false);
    assert.equal(page.status.textContent, WORDING.loading);

    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(page.field.value, FIRST);
    assert.deepEqual(announced, [FIRST]);
    assert.equal(page.status.textContent, '');
    assert.equal(page.select.value, '1', 'The picker\'s own announcement is not taken for typing');
});

test('picking an integration that is not ready shows its note alone and asks for nothing', () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);

    page.pick('3');
    assert.deepEqual(shownNotes(page), ['3']);

    page.pick('4');
    assert.deepEqual(shownNotes(page), ['4']);

    assert.equal(calls.length, 0);
    assert.deepEqual(announced, []);
    assert.equal(page.status.textContent, '');
});

test('picking a ready integration after a not-ready one hides the note', () => {
    const page = pickerPage();
    mountPicker(page);

    page.pick('3');
    page.pick('1');

    assert.deepEqual(shownNotes(page), []);
});

test('picking the empty option empties the field and announces it once', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.pick('1');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    page.pick('');

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, [FIRST, '']);
    assert.equal(page.status.textContent, '');
});

test('picking the empty option after a not-ready one hides the note and announces nothing', () => {
    const page = pickerPage();
    const {announced} = mountPicker(page);
    page.pick('3');

    page.pick('');

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, [], 'The field was already empty');
    assert.deepEqual(shownNotes(page), []);
});

test('switching from a picked integration to a not-ready one empties the field', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.pick('1');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    page.pick('3');

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, [FIRST, '']);
    assert.deepEqual(shownNotes(page), ['3']);
});

test('switching from a picked integration to a ready one empties the field while it loads and after it fails',
    async () => {
        const page = pickerPage();
        const {calls, announced} = mountPicker(page);
        page.pick('1');
        calls[0].answer({ok: true, token: FIRST});
        await settle();

        page.pick('2');

        assert.equal(page.field.value, '', 'No token of the first integration under the second one\'s name');
        assert.deepEqual(announced, [FIRST, '']);
        assert.equal(page.status.textContent, WORDING.loading);

        calls[1].answer({ok: false, kind: 'failed'});
        await settle();

        assert.equal(page.field.value, '');
        assert.deepEqual(announced, [FIRST, '']);
        assert.equal(page.status.textContent, WORDING.failed);
    });

test('typing after a pick puts the select back on the empty option and keeps what was typed', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.pick('1');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    page.type('typed-by-hand');

    assert.equal(page.select.value, '');
    assert.equal(page.field.value, 'typed-by-hand');
    assert.deepEqual(announced, [FIRST]);
});

test('picking an integration empties a typed value first, and a refused pick leaves the field empty', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.type('typed-by-hand');

    page.pick('2');

    assert.equal(page.field.value, '', 'Nothing typed is shown under the integration\'s name while it loads');
    assert.deepEqual(announced, ['']);
    assert.equal(page.status.textContent, WORDING.loading);

    calls[0].answer({ok: false, kind: 'refused'});
    await settle();

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, ['']);
    assert.equal(page.status.textContent, WORDING.refused);
    assert.equal(page.select.value, '2', 'The select still names the integration whose token was refused');
});

test('picking an integration that is not ready empties a typed value and shows the note', () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.type('typed-by-hand');

    page.pick('3');

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, ['']);
    assert.deepEqual(shownNotes(page), ['3']);
    assert.equal(calls.length, 0);
});

test('picking the empty option keeps a typed value', () => {
    const page = pickerPage();
    const {announced} = mountPicker(page);
    page.type('typed-by-hand');

    page.pick('');

    assert.equal(page.field.value, 'typed-by-hand');
    assert.deepEqual(announced, []);
});

test('a typed value emptied by a pick never comes back, not even through the empty option', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.type('typed-by-hand');

    page.pick('3');
    page.pick('2');
    calls[0].answer({ok: false, kind: 'failed'});
    await settle();
    page.pick('');

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, [''], 'The field was emptied once, by the first pick, and never written again');
    assert.deepEqual(shownNotes(page), []);
    assert.equal(page.status.textContent, '');
});

test('typing while a token loads drops that request and its loading wording', async () => {
    const page = pickerPage();
    const {calls} = mountPicker(page);
    page.pick('1');

    page.type('typed-by-hand');

    assert.equal(calls[0].signal.aborted, true);
    assert.equal(page.status.textContent, '');
    assert.equal(page.select.value, '');

    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(page.field.value, 'typed-by-hand');
});

test('a slow first answer after a second pick is dropped, and its request was aborted', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);

    page.pick('1');
    page.pick('2');

    assert.equal(calls[0].signal.aborted, true);
    assert.equal(calls[1].signal.aborted, false);

    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(page.field.value, '');
    assert.equal(page.status.textContent, WORDING.loading);

    calls[1].answer({ok: true, token: SECOND});
    await settle();

    assert.equal(page.field.value, SECOND);
    assert.deepEqual(announced, [SECOND]);
});

test('an answer that arrives after the select moved to a not-ready integration is dropped', async () => {
    const page = pickerPage();
    const {calls, announced} = mountPicker(page);
    page.pick('1');

    page.pick('3');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(page.field.value, '');
    assert.deepEqual(announced, []);
    assert.deepEqual(shownNotes(page), ['3']);
});

for (const [kind, wording] of [['refused', WORDING.refused], ['failed', WORDING.failed]]) {
    test(`a ${kind} answer shows its wording and leaves the field empty`, async () => {
        const page = pickerPage();
        const {calls, announced} = mountPicker(page);
        page.pick('1');

        calls[0].answer({ok: false, kind});
        await settle();

        assert.equal(page.status.textContent, wording);
        assert.equal(page.field.value, '');
        assert.deepEqual(announced, []);
    });
}

test('an answer of a kind the picker does not know, or a broken request, reads as failed', async () => {
    const page = pickerPage();
    const {calls} = mountPicker(page);

    page.pick('1');
    calls[0].answer({ok: false, kind: 'toString'});
    await settle();
    assert.equal(page.status.textContent, WORDING.failed);

    page.pick('2');
    calls[1].fail();
    await settle();
    assert.equal(page.status.textContent, WORDING.failed);
});

test('the status line is cleared by the next pick', async () => {
    const page = pickerPage();
    const {calls} = mountPicker(page);
    page.pick('1');
    calls[0].answer({ok: false, kind: 'refused'});
    await settle();

    page.pick('3');

    assert.equal(page.status.textContent, '');
});

test('the teardown stops listening and aborts the request in flight', async () => {
    const page = pickerPage();
    const {calls, announced, teardown} = mountPicker(page);
    page.pick('1');

    teardown();

    assert.equal(calls[0].signal.aborted, true);
    assert.equal(page.live('change'), 0);
    assert.equal(page.live('input'), 0);

    page.pick('2');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(calls.length, 1, 'A change after the teardown asks for nothing');
    assert.equal(page.field.value, '');
    assert.deepEqual(announced, []);
});

test('mounting twice binds once', () => {
    const page = pickerPage();
    mountPicker(page);
    mountPicker(page);

    assert.equal(page.live('change'), 1);
    assert.equal(page.live('input'), 1);
});

test('a change of another element in the card is ignored', () => {
    const page = pickerPage();
    const {calls} = mountPicker(page);
    const other = node({'data-role': 'mcp-transport'});

    page.select.value = '1';
    page.dispatch('change', other);

    assert.equal(calls.length, 0);
});

for (const missing of ['select', 'field']) {
    test(`without the ${missing} the picker binds nothing`, () => {
        const page = pickerPage();
        const role = missing === 'select' ? 'mcp-integration' : 'mcp-token';
        const element = missing === 'select' ? page.select : page.field;

        element.attributes['data-role'] = `not-${role}`;
        mountPicker(page);

        assert.equal(page.listeners.length, 0);
    });
}

/**
 * The window `entry()` takes its services from: a recording fetch, Node's AbortSignal and an Event constructor
 *
 * @param {function(string, Object): Promise<Object>} fetch
 * @returns {Object}
 */
function browser(fetch) {
    return {
        fetch,
        AbortSignal,
        Event: class {
            constructor(type, init) {
                this.type = type;
                this.bubbles = Boolean(init && init.bubbles);
            }
        }
    };
}

const CONFIG = {
    tokenUrl: 'https://shop.example/admin/hryvinskyi_mcp/server/integrationToken/',
    formKey: 'formkey123',
    wording: WORDING
};

test('the x-magento-init entry asks the token URL with the form key and announces with the page\'s own Event',
    async () => {
        const page = pickerPage();
        const fetched = [];
        const dispatched = [];
        const view = browser((url, init) => {
            fetched.push({url, init});

            return Promise.resolve({status: 200, ok: true, json: () => Promise.resolve({token: FIRST})});
        });

        page.root.ownerDocument.defaultView = view;
        page.field.dispatchEvent = (event) => {
            dispatched.push(event);
            page.dispatch(event.type, page.field);

            return true;
        };
        picker(CONFIG, page.root);

        page.pick('1');
        await settle();

        assert.equal(fetched.length, 1);
        assert.equal(fetched[0].url, CONFIG.tokenUrl);
        assert.equal(fetched[0].init.body, 'form_key=formkey123&integration_id=1&isAjax=true');
        assert.equal(page.field.value, FIRST);
        assert.equal(dispatched.length, 1);
        assert.ok(dispatched[0] instanceof view.Event);
        assert.equal(dispatched[0].type, 'input');
        assert.equal(dispatched[0].bubbles, true);
        assert.equal(page.select.value, '1');
    });

for (const [label, config] of [
    ['missing', undefined],
    ['null', null],
    ['a string', 'config'],
    ['without a token URL', {...CONFIG, tokenUrl: undefined}],
    ['with an empty token URL', {...CONFIG, tokenUrl: ''}],
    ['with a form key that is not a string', {...CONFIG, formKey: 123}],
    ['without a form key', {tokenUrl: CONFIG.tokenUrl, wording: WORDING}],
    ['without wording', {tokenUrl: CONFIG.tokenUrl, formKey: CONFIG.formKey}],
    ['with wording missing the failed text', {...CONFIG, wording: {loading: 'a', refused: 'b'}}],
    ['with wording that is a string', {...CONFIG, wording: 'Loading'}]
]) {
    test(`a configuration ${label} mounts nothing`, () => {
        const page = pickerPage();
        const view = browser(() => {
            throw new Error('Nothing may be fetched');
        });

        page.root.ownerDocument.defaultView = view;
        picker(config, page.root);

        assert.equal(page.listeners.length, 0);
    });
}

test('a picked token reaches the connection card through the field\'s input event', async () => {
    const PLACEHOLDER = '<integration-token>';
    const CURSOR = 'cursor://anysphere.cursor-deeplink/mcp/install?name=magento&config=';
    const connection = {name: 'magento', url: 'https://shop.example/rest/all/V1/mcp', placeholder: PLACEHOLDER};
    const cursor = node({'data-install': 'cursor', hidden: ''}, 'Add to Cursor');
    const page = pickerPage([cursor]);
    const base64 = (text) => Buffer.from(text, 'utf8').toString('base64');

    card.mount(page.root, {
        copied: 'Copied',
        connection,
        base64,
        clipboard: null,
        schedule: () => 1,
        cancel: () => undefined,
        select: () => undefined
    });
    const {calls} = mountPicker(page);

    assert.equal(cursor.hasAttribute('hidden'), true);

    page.pick('1');
    calls[0].answer({ok: true, token: FIRST});
    await settle();

    assert.equal(cursor.hasAttribute('hidden'), false);
    const href = cursor.getAttribute('href');
    assert.ok(href.startsWith(CURSOR));
    assert.deepEqual(JSON.parse(Buffer.from(decodeURIComponent(href.slice(CURSOR.length)), 'base64').toString('utf8')), {
        url: connection.url,
        headers: {Authorization: `Bearer ${FIRST}`}
    });

    page.pick('3');

    assert.equal(cursor.hasAttribute('hidden'), true, 'Switching away takes the token out of the card too');
    assert.equal(cursor.hasAttribute('href'), false);
});
