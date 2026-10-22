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
