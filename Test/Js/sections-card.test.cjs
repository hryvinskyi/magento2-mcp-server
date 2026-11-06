/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const card = load('Hryvinskyi_McpServer/js/sections-card');

/**
 * A copy made in this realm: the module's objects come from the AMD loader's context, whose prototypes differ
 *
 * @param {*} value
 * @returns {*}
 */
const plain = (value) => JSON.parse(JSON.stringify(value));

const ON_URL = 'https://shop.example/admin/mcpserver/section/on/';
const OFF_URL = 'https://shop.example/admin/mcpserver/section/off/';
const WORDING = {on: 'On', off: 'Off', switchOn: 'Switch on', switchOff: 'Switch off', failed: 'Reload and try again.'};
const SYSTEM = 'Magento_Backend::system';
const SALES = 'Magento_Sales::sales';
const GONE = 'Example_Removed::removed';
const CATALOG_SOURCE = 'hryvinskyi_mcp_ability_listing.hryvinskyi_mcp_ability_listing_data_source';
const TOTAL = '[data-role="mcp-exposed-total"]';

/**
 * Let every pending promise callback run
 *
 * @returns {Promise<void>}
 */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

/**
 * An element of the fake page: it answers the attribute selectors the script uses and nothing else
 *
 * @param {Object<string, string>} attributes
 * @param {Array<Object>} [children]
 * @param {string} [text]
 * @returns {Object}
 */
function el(attributes, children = [], text = '') {
    const classes = new Set((attributes.class || '').split(' ').filter(Boolean));
    const listeners = {};
    const element = {
        attributes,
        children,
        parent: null,
        textContent: text,
        value: attributes.value || '',
        classList: {
            toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
            contains: (name) => classes.has(name)
        },
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        setAttribute: (name, value) => {
            attributes[name] = String(value);
        },
        removeAttribute: (name) => {
            delete attributes[name];
        },
        hasAttribute: (name) => name in attributes,
        focused: 0,
        focus: () => {
            element.focused += 1;
        },
        remove: () => {
            if (element.parent) {
                element.parent.children.splice(element.parent.children.indexOf(element), 1);
                element.parent = null;
            }
        },
        matches: (selector) => {
            const parsed = /^\[([\w-]+)(?:="([^"]*)")?\]$/.exec(selector);

            if (!parsed) {
                throw new Error(`The fake page does not answer ${selector}`);
            }

            return parsed[2] === undefined ? parsed[1] in attributes : attributes[parsed[1]] === parsed[2];
        },
        querySelectorAll: (selector) => descendants(element).filter((node) => node.matches(selector)),
        querySelector: (selector) => element.querySelectorAll(selector)[0] || null,
        closest: (selector) => {
            for (let node = element; node; node = node.parent) {
                if (node.matches(selector)) {
                    return node;
                }
            }

            return null;
        },
        contains: (other) => {
            for (let node = other; node; node = node.parent) {
                if (node === element) {
                    return true;
                }
            }

            return false;
        },
        addEventListener: (type, listener) => {
            (listeners[type] = listeners[type] || []).push(listener);
        },
        removeEventListener: (type, listener) => {
            listeners[type] = (listeners[type] || []).filter((known) => known !== listener);
        },
        listenerCount: (type) => (listeners[type] || []).length,
        dispatch: (type, event) => (listeners[type] || []).forEach((listener) => listener(event))
    };

    children.forEach((child) => {
        child.parent = element;
    });

    return element;
}

/**
 * @param {Object} element
 * @returns {Array<Object>}
 */
function descendants(element) {
    return element.children.flatMap((child) => [child, ...descendants(child)]);
}

/**
 * A row of the card as the template prints it
 *
 * @param {string} code
 * @param {boolean} off
 * @param {number} exposed
 * @returns {Object}
 */
function row(code, off, exposed) {
    return el({'data-role': 'mcp-section', 'data-section': code, class: off ? 'hui-list__row hui-list__row--muted' : 'hui-list__row'}, [
        el({'data-role': 'mcp-section-exposed'}, [], String(exposed)),
        el({'data-role': 'mcp-section-state', class: `hui-badge hui-badge--${off ? 'off' : 'on'}`}, [
            el({'data-role': 'mcp-section-state-text'}, [], off ? 'Off' : 'On')
        ]),
        el({'data-role': 'mcp-section-switch', action: off ? ON_URL : OFF_URL}, [
            el({name: 'form_key', value: 'k3y'}),
            el({name: 'section', value: code}),
            el({'data-role': 'mcp-section-toggle', 'aria-checked': off ? 'false' : 'true'}, [
                el({'data-role': 'mcp-section-toggle-text'}, [], off ? 'Switch on' : 'Switch off')
            ])
        ])
    ]);
}

/**
 * The card with two sections on, its status notice hidden; with `withGone`, a third row for a section switched off
 * whose module is no longer installed
 *
 * @param {boolean} [withGone]
 * @returns {{root: Object, status: Object, rows: Object<string, Object>}}
 */
function page(withGone = false) {
    const rows = {[SYSTEM]: row(SYSTEM, false, 7), [SALES]: row(SALES, false, 22)};
    const status = el({'data-role': 'mcp-sections-status', hidden: ''}, [el({'data-role': 'mcp-sections-status-text'})]);

    if (withGone) {
        rows[GONE] = row(GONE, true, 0);
    }

    const root = el({'data-role': 'mcp-sections'}, [status, ...Object.values(rows)]);

    return {root, status, rows};
}

/**
 * The codes of the rows the card still holds
 *
 * @param {Object} root
 * @returns {Array<string>}
 */
function listedCodes(root) {
    return root.querySelectorAll('[data-role="mcp-section"]').map((node) => node.getAttribute('data-section'));
}

/**
 * Mount the card over a switch function that answers with what `answer` resolves to, recording every call
 *
 * @param {Object} root
 * @param {function(): Promise<Object>} answer
 * @returns {{teardown: function(): void, calls: Array<Object>, reloads: function(): number, totals: Array<number>}}
 */
function mount(root, answer) {
    const calls = [];
    const totals = [];
    let reloads = 0;
    const teardown = card.mount(root, {
        switchSection: (url, fields, signal) => {
            calls.push({url, fields, signal});

            return answer();
        },
        drawTotal: (count) => {
            totals.push(count);
        },
        reloadCatalog: () => {
            reloads += 1;
        },
        onUrl: ON_URL,
        offUrl: OFF_URL,
        wording: WORDING
    });

    return {teardown, calls, reloads: () => reloads, totals};
}

/**
 * Submit a row's switch form, as the browser does, and return the event
 *
 * @param {Object} root
 * @param {Object} form
 * @returns {{defaultPrevented: boolean}}
 */
function submit(root, form) {
    const event = {
        target: form,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        }
    };

    root.dispatch('submit', event);

    return event;
}

/**
 * @param {Object} rowElement
 * @returns {Object}
 */
function formOf(rowElement) {
    return rowElement.querySelector('[data-role="mcp-section-switch"]');
}

/**
 * What a row shows
 *
 * @param {Object} rowElement
 * @returns {Object}
 */
function drawn(rowElement) {
    const badge = rowElement.querySelector('[data-role="mcp-section-state"]');
    const toggle = rowElement.querySelector('[data-role="mcp-section-toggle"]');

    return {
        muted: rowElement.classList.contains('hui-list__row--muted'),
        exposed: rowElement.querySelector('[data-role="mcp-section-exposed"]').textContent,
        badgeOn: badge.classList.contains('hui-badge--on'),
        badgeOff: badge.classList.contains('hui-badge--off'),
        badgeText: badge.querySelector('[data-role="mcp-section-state-text"]').textContent,
        action: formOf(rowElement).getAttribute('action'),
        checked: toggle.getAttribute('aria-checked'),
        toggleText: toggle.querySelector('[data-role="mcp-section-toggle-text"]').textContent
    };
}

test('a switch is sent in the background with the form\'s fields, and the form does not post', () => {
    const {root, rows} = page();
    const {calls} = mount(root, () => new Promise(() => {}));

    const event = submit(root, formOf(rows[SYSTEM]));

    assert.equal(event.defaultPrevented, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, OFF_URL);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0].fields)), [['form_key', 'k3y'], ['section', SYSTEM]]);
});

test('every section in the answer is redrawn, the total drawn, the message shown and the catalog reloaded', async () => {
    const {root, status, rows} = page();
    const {reloads, totals} = mount(root, () => Promise.resolve({
        ok: true,
        message: 'The section System is switched off.',
        exposed: 20,
        sections: [{code: SYSTEM, off: true, exposed: 0}, {code: SALES, off: false, exposed: 20}]
    }));

    submit(root, formOf(rows[SYSTEM]));
    await settle();

    assert.deepEqual(drawn(rows[SYSTEM]), {
        muted: true,
        exposed: '0',
        badgeOn: false,
        badgeOff: true,
        badgeText: 'Off',
        action: ON_URL,
        checked: 'false',
        toggleText: 'Switch on'
    });
    assert.deepEqual(drawn(rows[SALES]), {
        muted: false,
        exposed: '20',
        badgeOn: true,
        badgeOff: false,
        badgeText: 'On',
        action: OFF_URL,
        checked: 'true',
        toggleText: 'Switch off'
    });
    assert.equal(status.hasAttribute('hidden'), false);
    assert.equal(status.getAttribute('data-tone'), 'success');
    assert.equal(status.querySelector('[data-role="mcp-sections-status-text"]').textContent,
        'The section System is switched off.');
    assert.deepEqual(totals, [20]);
    assert.equal(reloads(), 1);
});

test('a row the answer does not list is removed from the card, the listed ones are redrawn', async () => {
    const {root, rows} = page(true);
    const {totals} = mount(root, () => Promise.resolve({
        ok: true,
        message: 'The section is switched on.',
        exposed: 29,
        sections: [{code: SYSTEM, off: false, exposed: 7}, {code: SALES, off: false, exposed: 22}]
    }));

    submit(root, formOf(rows[GONE]));
    await settle();

    assert.deepEqual(listedCodes(root), [SYSTEM, SALES]);
    assert.equal(root.contains(rows[GONE]), false);
    assert.equal(drawn(rows[SYSTEM]).exposed, '7');
    assert.equal(drawn(rows[SALES]).checked, 'true');
    assert.deepEqual(totals, [29]);
});

test('when the pressed switch leaves with its row, the notice takes the focus; otherwise the focus stays', async () => {
    const gone = page(true);
    mount(gone.root, () => Promise.resolve({
        ok: true,
        message: 'The section is switched on.',
        exposed: 29,
        sections: [{code: SYSTEM, off: false, exposed: 7}, {code: SALES, off: false, exposed: 22}]
    }));
    submit(gone.root, formOf(gone.rows[GONE]));
    await settle();
    assert.equal(gone.status.focused, 1);

    const kept = page(true);
    mount(kept.root, () => Promise.resolve({
        ok: true,
        message: 'The section System is switched off.',
        exposed: 22,
        sections: [{code: SYSTEM, off: true, exposed: 0}, {code: SALES, off: false, exposed: 22}]
    }));
    submit(kept.root, formOf(kept.rows[SYSTEM]));
    await settle();
    assert.equal(kept.status.focused, 0);
});

test('an answer that lists no section empties the card of rows', async () => {
    const {root, status, rows} = page();
    mount(root, () => Promise.resolve({ok: true, message: 'Done.', exposed: 0, sections: []}));

    submit(root, formOf(rows[SYSTEM]));
    await settle();

    assert.deepEqual(listedCodes(root), []);
    assert.equal(root.contains(status), true);
});

test('a refused switch shows the refusal and changes no row', async () => {
    const {root, status, rows} = page();
    const before = drawn(rows[SYSTEM]);
    const {reloads, totals} = mount(root, () => Promise.resolve({ok: false, kind: 'refused', message: 'Choose a section.'}));

    submit(root, formOf(rows[SYSTEM]));
    await settle();

    assert.deepEqual(drawn(rows[SYSTEM]), before);
    assert.deepEqual(listedCodes(root), [SYSTEM, SALES]);
    assert.deepEqual(totals, []);
    assert.equal(status.getAttribute('data-tone'), 'error');
    assert.equal(status.querySelector('[data-role="mcp-sections-status-text"]').textContent, 'Choose a section.');
    assert.equal(reloads(), 0);
});

test('a failed switch shows the failed wording and changes no row', async () => {
    const {root, status, rows} = page();
    const before = drawn(rows[SYSTEM]);
    const {totals} = mount(root, () => Promise.resolve({ok: false, kind: 'failed'}));

    submit(root, formOf(rows[SYSTEM]));
    await settle();

    assert.deepEqual(drawn(rows[SYSTEM]), before);
    assert.deepEqual(listedCodes(root), [SYSTEM, SALES]);
    assert.deepEqual(totals, []);
    assert.equal(status.getAttribute('data-tone'), 'error');
    assert.equal(status.querySelector('[data-role="mcp-sections-status-text"]').textContent, WORDING.failed);
});

test('one switch runs at a time: a submit while one is under way is dropped, and the busy switch says so', async () => {
    const {root, rows} = page();
    let answer;
    const {calls} = mount(root, () => new Promise((resolve) => {
        answer = resolve;
    }));
    const toggle = rows[SYSTEM].querySelector('[data-role="mcp-section-toggle"]');

    submit(root, formOf(rows[SYSTEM]));
    const second = submit(root, formOf(rows[SALES]));

    assert.equal(calls.length, 1);
    assert.equal(second.defaultPrevented, true);
    assert.equal(toggle.getAttribute('aria-busy'), 'true');

    answer({ok: false, kind: 'failed'});
    await settle();

    assert.equal(toggle.hasAttribute('aria-busy'), false);
    submit(root, formOf(rows[SALES]));
    assert.equal(calls.length, 2);
});

test('a submit of another form is left alone', () => {
    const {root} = page();
    const other = el({'data-role': 'something-else'});
    root.children.push(other);
    other.parent = root;
    const {calls} = mount(root, () => new Promise(() => {}));

    const event = submit(root, other);

    assert.equal(event.defaultPrevented, false);
    assert.equal(calls.length, 0);
});

test('the teardown aborts a switch in flight and stops listening', async () => {
    const {root, rows} = page();
    const before = drawn(rows[SYSTEM]);
    let answer;
    const {teardown, calls} = mount(root, () => new Promise((resolve) => {
        answer = resolve;
    }));

    submit(root, formOf(rows[SYSTEM]));
    teardown();
    answer({ok: true, message: 'Done.', exposed: 0, sections: [{code: SYSTEM, off: true, exposed: 0}]});
    await settle();

    assert.equal(calls[0].signal.aborted, true);
    assert.deepEqual(drawn(rows[SYSTEM]), before);
    assert.equal(root.listenerCount('submit'), 0);
});

test('the card is mounted once, however often the entry runs', () => {
    const {root, rows} = page();
    const first = mount(root, () => new Promise(() => {}));
    const second = mount(root, () => new Promise(() => {}));

    submit(root, formOf(rows[SYSTEM]));

    assert.equal(root.listenerCount('submit'), 1);
    assert.equal(first.calls.length + second.calls.length, 1);
});

test('after the teardown the card can be mounted again', () => {
    const {root, rows} = page();
    mount(root, () => new Promise(() => {})).teardown();
    const {calls} = mount(root, () => new Promise(() => {}));

    submit(root, formOf(rows[SYSTEM]));

    assert.equal(root.listenerCount('submit'), 1);
    assert.equal(calls.length, 1);
});

/**
 * The page the x-magento-init entry runs in: the overview with the facts total, the card, and a window whose
 * `fetch` answers every switch with `body` and whose RequireJS hands out a fake component registry
 *
 * @param {Object} options
 * @param {Object} [options.body] What the switch endpoint answers
 * @param {Object} [options.signals] The window's AbortSignal
 * @param {boolean} [options.withRequire] Whether the window has RequireJS
 * @param {boolean} [options.withTotal] Whether the overview prints the total
 * @returns {Object}
 */
function browserPage({body, signals = AbortSignal, withRequire = true, withTotal = true} = {}) {
    const card = page();
    const total = el({'data-role': 'mcp-exposed-total'}, [], '29');
    const overview = el({'data-role': 'overview'}, withTotal ? [total, card.root] : [card.root]);
    const fetches = [];
    const deadlines = [];
    const registryNames = [];
    const reloads = [];
    const provider = {
        reload: (options) => {
            reloads.push(options);
        }
    };
    const view = {
        fetch(url, init) {
            fetches.push({url, init});

            return Promise.resolve({status: 200, json: () => Promise.resolve(body)});
        },
        AbortSignal: {
            timeout: signals.timeout ? (ms) => {
                deadlines.push(ms);

                return signals.timeout(ms);
            } : undefined,
            any: signals.any ? (list) => signals.any(list) : undefined
        }
    };

    if (withRequire) {
        view.require = (deps, callback) => {
            assert.deepEqual(plain(deps), ['uiRegistry']);
            callback({
                get: (name, found) => {
                    registryNames.push(name);
                    found(provider);
                }
            });
        };
    }
    overview.defaultView = view;
    [overview, ...descendants(overview)].forEach((node) => {
        node.ownerDocument = overview;
    });

    return {...card, total, fetches, deadlines, registryNames, reloads};
}

const CONFIG = Object.freeze({
    onUrl: ON_URL,
    offUrl: OFF_URL,
    catalogSource: CATALOG_SOURCE,
    wording: WORDING
});

const SWITCHED = Object.freeze({
    message: 'The section System is switched off.',
    exposed: 22,
    sections: [{code: SYSTEM, off: true, exposed: 0}, {code: SALES, off: false, exposed: 22}]
});

test('the entry mounts the card over the window: a switch posts with a thirty-second deadline, draws the total and '
    + 'reloads the catalog once', async () => {
    const browser = browserPage({body: SWITCHED});

    card(CONFIG, browser.root);
    const event = submit(browser.root, formOf(browser.rows[SYSTEM]));
    await settle();

    assert.equal(event.defaultPrevented, true);
    assert.equal(browser.fetches.length, 1);
    assert.equal(browser.fetches[0].url, OFF_URL);
    assert.deepEqual(browser.deadlines, [30000]);
    assert.equal(browser.total.textContent, '22');
    assert.equal(drawn(browser.rows[SYSTEM]).badgeText, 'Off');
    assert.deepEqual(browser.registryNames, [CATALOG_SOURCE]);
    assert.deepEqual(plain(browser.reloads), [{refresh: true}]);
});

for (const key of Object.keys(WORDING)) {
    test(`a configuration without the "${key}" wording mounts nothing, and the form posts as usual`, () => {
        const browser = browserPage({body: SWITCHED});
        const wording = {...WORDING};
        delete wording[key];

        card({...CONFIG, wording}, browser.root);
        const event = submit(browser.root, formOf(browser.rows[SYSTEM]));

        assert.equal(event.defaultPrevented, false);
        assert.equal(browser.root.listenerCount('submit'), 0);
        assert.equal(browser.fetches.length, 0);
    });
}

test('a window without RequireJS still switches and draws the total, and nothing throws', async () => {
    const browser = browserPage({body: SWITCHED, withRequire: false});

    assert.doesNotThrow(() => card(CONFIG, browser.root));
    submit(browser.root, formOf(browser.rows[SYSTEM]));
    await settle();

    assert.equal(browser.total.textContent, '22');
    assert.equal(drawn(browser.rows[SYSTEM]).badgeText, 'Off');
});

test('a page without the total still switches', async () => {
    const browser = browserPage({body: SWITCHED, withTotal: false});

    card(CONFIG, browser.root);
    submit(browser.root, formOf(browser.rows[SYSTEM]));
    await settle();

    assert.equal(drawn(browser.rows[SYSTEM]).badgeText, 'Off');
    assert.deepEqual(plain(browser.reloads), [{refresh: true}]);
});

test('a window without AbortSignal.timeout mounts nothing, so the forms post as they would without a script',
    async () => {
        const browser = browserPage({body: SWITCHED, signals: {any: (list) => AbortSignal.any(list)}});

        card(CONFIG, browser.root);
        const event = submit(browser.root, formOf(browser.rows[SYSTEM]));
        await settle();

        assert.equal(event.defaultPrevented, false);
        assert.equal(browser.fetches.length, 0);
        assert.equal(browser.root.listenerCount('submit'), 0);
    });
