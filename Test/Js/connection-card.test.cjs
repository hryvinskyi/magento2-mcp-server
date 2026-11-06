/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const card = load('Hryvinskyi_McpServer/js/connection-card');

/**
 * A card with one copy button for an element holding the text, and an element outside any button
 *
 * @returns {{root: Object, button: Object, plain: Object, target: Object, click: function(Object): void}}
 */
function page() {
    const listeners = [];
    const target = {textContent: 'https://shop.example/rest/all/V1/mcp'};
    const root = {
        ownerDocument: {getElementById: (id) => (id === 'mcp-endpoint' ? target : null)},
        addEventListener: (type, listener, options) => listeners.push({type, listener, signal: options.signal})
    };
    const element = (attributes, text) => {
        const node = {
            textContent: text,
            getAttribute: (name) => (name in attributes ? attributes[name] : null),
            setAttribute: (name, value) => {
                attributes[name] = value;
            },
            closest: (selector) => (selector === '[data-role="mcp-copy"]' && attributes['data-role'] === 'mcp-copy'
                ? node : null)
        };

        return node;
    };
    const button = element({'data-role': 'mcp-copy', 'data-copy-target': 'mcp-endpoint'}, 'Copy');
    const plain = element({}, 'Endpoint URL');

    return {
        root,
        button,
        plain,
        target,
        click: (node) => listeners
            .filter((entry) => entry.type === 'click' && !entry.signal.aborted)
            .forEach((entry) => entry.listener({target: node}))
    };
}

/**
 * Browser services that record what was asked of them
 *
 * @param {boolean} clipboardWorks
 * @returns {Object}
 */
function environment(clipboardWorks) {
    const recorded = {copied: [], selected: [], scheduled: [], cancelled: []};

    return {
        recorded,
        copied: 'Copied',
        clipboard: {
            writeText: (text) => {
                recorded.copied.push(text);

                return clipboardWorks ? Promise.resolve() : Promise.reject(new Error('Denied'));
            }
        },
        schedule: (callback, ms) => {
            recorded.scheduled.push({callback, ms});

            return recorded.scheduled.length;
        },
        cancel: (timer) => recorded.cancelled.push(timer),
        select: (node) => recorded.selected.push(node)
    };
}

test('a copy button copies its target and reads "Copied" for a moment', async () => {
    const {root, button, target, click} = page();
    const env = environment(true);
    card.mount(root, env);

    click(button);
    await Promise.resolve();

    assert.deepEqual(env.recorded.copied, [target.textContent]);
    assert.equal(button.textContent, 'Copied');
    assert.equal(env.recorded.scheduled[0].ms, 1500);
    env.recorded.scheduled[0].callback();
    assert.equal(button.textContent, 'Copy');
});

test('when the browser refuses the clipboard, the text is selected to copy by hand', async () => {
    const {root, button, target, click} = page();
    const env = environment(false);
    card.mount(root, env);

    click(button);
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(env.recorded.selected, [target]);
    assert.equal(button.textContent, 'Copy');
});

test('without a clipboard the text is selected at once', () => {
    const {root, button, target, click} = page();
    const env = {...environment(true), clipboard: null};
    card.mount(root, env);

    click(button);

    assert.deepEqual(env.recorded.selected, [target]);
});

test('a click outside a copy button does nothing, and mounting twice listens once', async () => {
    const {root, button, plain, click} = page();
    const env = environment(true);
    card.mount(root, env);
    card.mount(root, env);

    click(plain);
    click(button);
    await Promise.resolve();

    assert.equal(env.recorded.copied.length, 1);
});

test('the teardown stops listening and cancels the pending reset', async () => {
    const {root, button, click} = page();
    const env = environment(true);
    const teardown = card.mount(root, env);
    click(button);
    await Promise.resolve();

    teardown();
    click(button);
    await Promise.resolve();

    assert.equal(env.recorded.copied.length, 1);
    assert.deepEqual(env.recorded.cancelled.slice(-1), [1]);
});

const PLACEHOLDER = '<integration-token>';
const CONNECTION = {name: 'magento', url: 'https://shop.example/rest/all/V1/mcp', placeholder: PLACEHOLDER};
const base64 = (text) => Buffer.from(text, 'utf8').toString('base64');
const CURSOR = 'cursor://anysphere.cursor-deeplink/mcp/install?name=magento&config=';
const VSCODE = 'vscode:mcp/install?';

/**
 * @param {string} token
 * @param {string} [url]
 * @returns {string} The Claude Code command the card shows for that token
 */
function command(token, url = CONNECTION.url) {
    return `claude mcp add --transport http magento "${url}" --header "Authorization: Bearer ${token}"`;
}

/**
 * An element of the fake card; it answers the attribute selectors the card uses and nothing else
 *
 * @param {Object<string, string>} attributes
 * @param {string} [text]
 * @param {?Object} [parent] The element that wraps this one
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
 * A connection card with the token field, its hint, the install targets, the hidden Claude Code group, an endpoint
 * with its copy button and two token slots
 *
 * @returns {Object}
 */
function installCard() {
    const listeners = [];
    const field = node({'data-role': 'mcp-token', id: 'mcp-access-token', type: 'password'});
    const other = node({'data-role': 'mcp-transport', id: 'mcp-transport-http'});
    const hint = node({'data-role': 'mcp-token-hint', hidden: ''}, 'Not a valid token.');
    const cursor = node({'data-install': 'cursor', hidden: ''}, 'Add to Cursor');
    const vscode = node({'data-install': 'vscode', hidden: ''}, 'Add to VS Code');
    const unknown = node({'data-install': 'zed', hidden: ''}, 'Add to Zed');
    const group = node({'data-role': 'mcp-claude-code', hidden: ''});
    const claude = node({id: 'mcp-claude-code-command', 'data-install': 'claude-code'}, '', group);
    const copy = node({'data-role': 'mcp-copy', 'data-copy-target': 'mcp-claude-code-command'}, 'Copy', group);
    const endpoint = node({id: 'mcp-endpoint'}, CONNECTION.url);
    const endpointCopy = node({'data-role': 'mcp-copy', 'data-copy-target': 'mcp-endpoint'}, 'Copy');
    const json = node({'data-token-slot': '', id: 'mcp-snippet-json'},
        `{"headers": {"Authorization": "Bearer ${PLACEHOLDER}"}}`);
    const twice = node({'data-token-slot': '', id: 'mcp-snippet-twice'}, `a ${PLACEHOLDER} b ${PLACEHOLDER}`);
    const elements = [field, other, hint, cursor, vscode, unknown, group, claude, copy, endpoint, endpointCopy, json,
        twice];
    const root = {
        ownerDocument: {getElementById: (id) => elements.find((element) => element.attributes.id === id) || null},
        querySelectorAll: (selector) => elements.filter((element) => element.matches(selector)),
        addEventListener: (type, listener, options) => listeners.push({type, listener, signal: options.signal})
    };
    const dispatch = (type, target) => listeners
        .filter((entry) => entry.type === type && !entry.signal.aborted)
        .forEach((entry) => entry.listener({target}));

    return {
        root,
        listeners,
        elements,
        field,
        other,
        hint,
        cursor,
        vscode,
        unknown,
        group,
        claude,
        copy,
        endpointCopy,
        json,
        twice,
        type: (value) => {
            field.value = value;
            dispatch('input', field);
        },
        click: (target) => dispatch('click', target)
    };
}

/**
 * @param {string} href
 * @returns {Object} The server object a Cursor link installs
 */
function cursorConfig(href) {
    assert.ok(href.startsWith(CURSOR), href);

    return JSON.parse(Buffer.from(decodeURIComponent(href.slice(CURSOR.length)), 'base64').toString('utf8'));
}

/**
 * @param {Object} page
 */
function assertNoTokenState(page) {
    assert.equal(page.claude.textContent, command(PLACEHOLDER));
    assert.equal(page.group.hasAttribute('hidden'), false);
    for (const link of [page.cursor, page.vscode]) {
        assert.equal(link.hasAttribute('hidden'), true);
        assert.equal(link.hasAttribute('href'), false);
    }
    assert.equal(page.json.textContent, `{"headers": {"Authorization": "Bearer ${PLACEHOLDER}"}}`);
    assert.equal(page.twice.textContent, `a ${PLACEHOLDER} b ${PLACEHOLDER}`);
}

test('on mount the command shows the placeholder and the links stay hidden without an href', () => {
    const page = installCard();
    assert.equal(page.group.hasAttribute('hidden'), true, 'The page renders the Claude Code group hidden');
    entryMount(page);

    assertNoTokenState(page);
    assert.equal(page.hint.hasAttribute('hidden'), true);
});

test('a valid token gives the links their hrefs, shows them, and fills the command and every slot', () => {
    const page = installCard();
    entryMount(page);

    page.type('abc.DEF-123_~+/=');

    assert.deepEqual(cursorConfig(page.cursor.getAttribute('href')), {
        url: CONNECTION.url,
        headers: {Authorization: 'Bearer abc.DEF-123_~+/='}
    });
    assert.ok(page.vscode.getAttribute('href').startsWith(VSCODE));
    assert.deepEqual(JSON.parse(decodeURIComponent(page.vscode.getAttribute('href').slice(VSCODE.length))), {
        name: 'magento',
        type: 'http',
        url: CONNECTION.url,
        headers: {Authorization: 'Bearer abc.DEF-123_~+/='}
    });
    assert.equal(page.cursor.hasAttribute('hidden'), false);
    assert.equal(page.vscode.hasAttribute('hidden'), false);
    assert.equal(page.claude.textContent, command('abc.DEF-123_~+/='));
    assert.equal(page.json.textContent, '{"headers": {"Authorization": "Bearer abc.DEF-123_~+/="}}');
    assert.equal(page.twice.textContent, 'a abc.DEF-123_~+/= b abc.DEF-123_~+/=');
    assert.equal(page.hint.hasAttribute('hidden'), true);
});

test('the Claude Code group is shown with a token and stays shown when the token is cleared', () => {
    const page = installCard();
    entryMount(page);

    page.type('abc123');
    assert.equal(page.group.hasAttribute('hidden'), false);

    page.type('');
    assert.equal(page.group.hasAttribute('hidden'), false);
});

test('a pasted token is used without the whitespace around it', () => {
    const page = installCard();
    entryMount(page);

    page.type('  abc123\n');

    assert.equal(page.claude.textContent, command('abc123'));
});

test('an invalid token hides the links again, restores the placeholder and shows the hint', () => {
    const page = installCard();
    entryMount(page);
    page.type('abc123');

    page.type('abc 123');

    assertNoTokenState(page);
    assert.equal(page.hint.hasAttribute('hidden'), false);
});

test('clearing the field gives the no-token state back and hides the hint', () => {
    const page = installCard();
    entryMount(page);
    page.type('abc123');
    page.type('abc"123');

    page.type('');

    assertNoTokenState(page);
    assert.equal(page.hint.hasAttribute('hidden'), true);
});

test('a valid token after an invalid one hides the hint', () => {
    const page = installCard();
    entryMount(page);
    page.type('abc$123');

    page.type('abc123');

    assert.equal(page.hint.hasAttribute('hidden'), true);
    assert.equal(page.claude.textContent, command('abc123'));
});

test('an install target the card does not know is left as the page rendered it', () => {
    const page = installCard();
    entryMount(page);

    page.type('abc123');

    assert.deepEqual({...page.unknown.attributes}, {'data-install': 'zed', hidden: ''});
    assert.equal(page.unknown.textContent, 'Add to Zed');
});

test('input in another field changes nothing', () => {
    const page = installCard();
    entryMount(page);
    page.other.value = 'abc123';

    page.listeners.filter((entry) => entry.type === 'input').forEach((entry) => entry.listener({target: page.other}));

    assertNoTokenState(page);
});

test('the token is written only into the links, the command and the slots', () => {
    const page = installCard();
    entryMount(page);

    page.type('SeCrEt123');

    const holders = page.elements
        .filter((element) => element !== page.field)
        .flatMap((element) => [
            ...Object.entries(element.attributes)
                .filter(([, value]) => value.includes('SeCrEt123') || decodeURIComponent(value).includes('SeCrEt123')
                    || (value.startsWith(CURSOR) && JSON.stringify(cursorConfig(value)).includes('SeCrEt123')))
                .map(([name]) => `${element.attributes.id || element.attributes['data-install']}@${name}`),
            ...(element.textContent.includes('SeCrEt123')
                ? [`${element.attributes.id || element.attributes['data-install']}#text`] : [])
        ]);

    assert.deepEqual(holders, [
        'cursor@href',
        'vscode@href',
        'mcp-claude-code-command#text',
        'mcp-snippet-json#text',
        'mcp-snippet-twice#text'
    ]);
});

test('the copy button copies the command with the token once one is typed', async () => {
    const page = installCard();
    const env = {...environment(true), connection: CONNECTION, base64};
    card.mount(page.root, env);
    page.type('abc123');

    page.click(page.copy);
    await Promise.resolve();

    assert.deepEqual(env.recorded.copied, [command('abc123')]);
});

test('an endpoint outside Latin-1 is base64-encoded as UTF-8 and decodes back', () => {
    const url = 'https://shop.example/магазин/rest/all/V1/mcp';
    const page = installCard();
    card.mount(page.root, {...environment(true), connection: {...CONNECTION, url}, base64});

    page.type('abc123');

    assert.equal(cursorConfig(page.cursor.getAttribute('href')).url, url);
    assert.equal(page.claude.textContent, command('abc123', url));
});

test('the x-magento-init entry encodes the UTF-8 bytes through the window, so btoa never sees a wide character', () => {
    const url = 'https://shop.example/магазин/rest/all/V1/mcp';
    const page = installCard();
    const view = browser();

    page.root.ownerDocument.defaultView = view;
    card({copied: 'Copied', connection: {...CONNECTION, url}}, page.root);
    page.type('abc123');

    assert.equal(cursorConfig(page.cursor.getAttribute('href')).url, url);
});

for (const [label, connection] of [
    ['missing', undefined],
    ['null', null],
    ['a string', 'magento'],
    ['an array', []],
    ['without a name', {url: CONNECTION.url, placeholder: PLACEHOLDER}],
    ['with an empty url', {...CONNECTION, url: ''}],
    ['with a placeholder that is not a string', {...CONNECTION, placeholder: 1}],
    ['without a placeholder', {name: 'magento', url: CONNECTION.url}]
]) {
    test(`a connection ${label} leaves the install targets hidden and the copy buttons working`, async () => {
        const page = installCard();
        const view = browser();
        const config = {copied: 'Copied'};

        if (connection !== undefined) {
            config.connection = connection;
        }
        page.root.ownerDocument.defaultView = view;
        card(config, page.root);
        page.type('abc123');
        page.click(page.endpointCopy);
        await Promise.resolve();

        for (const link of [page.cursor, page.vscode]) {
            assert.equal(link.hasAttribute('hidden'), true);
            assert.equal(link.hasAttribute('href'), false);
        }
        assert.equal(page.claude.textContent, '');
        assert.equal(page.group.hasAttribute('hidden'), true, 'An empty command is never offered to copy');
        assert.equal(page.json.textContent, `{"headers": {"Authorization": "Bearer ${PLACEHOLDER}"}}`);
        assert.deepEqual(view.copied, [CONNECTION.url]);
        assert.equal(page.endpointCopy.textContent, 'Copied');
    });
}

test('mounting the card twice binds the token field once', () => {
    const page = installCard();
    entryMount(page);
    entryMount(page);

    assert.equal(page.listeners.filter((entry) => entry.type === 'input').length, 1);
});

test('after the teardown, typing changes nothing and nothing is restored', () => {
    const page = installCard();
    const teardown = card.mount(page.root, {...environment(true), connection: CONNECTION, base64});
    page.type('abc123');
    const href = page.cursor.getAttribute('href');

    teardown();
    page.type('');
    page.type('xyz789');

    assert.equal(page.cursor.getAttribute('href'), href);
    assert.equal(page.cursor.hasAttribute('hidden'), false);
    assert.equal(page.claude.textContent, command('abc123'));
    assert.equal(page.twice.textContent, 'a abc123 b abc123');
});


/**
 * Mount the card with the connection and the test's base64
 *
 * @param {Object} page
 */
function entryMount(page) {
    card.mount(page.root, {...environment(true), connection: CONNECTION, base64});
}

/**
 * The window `entry()` takes its services from: the language's btoa and TextEncoder, a recording clipboard
 *
 * @returns {Object}
 */
function browser() {
    const copied = [];

    return {
        copied,
        btoa,
        TextEncoder,
        navigator: {clipboard: {writeText: (text) => copied.push(text) && Promise.resolve()}},
        setTimeout: () => 1,
        clearTimeout: () => undefined,
        getSelection: () => ({selectAllChildren: () => undefined})
    };
}
