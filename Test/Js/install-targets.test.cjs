/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const targets = load('Hryvinskyi_McpServer/js/install-targets');

const SERVER = {name: 'magento', url: 'https://shop.example/rest/all/V1/mcp', token: 'abc.DEF-123_~+/='};
const ENCODE = {base64: (text) => Buffer.from(text, 'utf8').toString('base64')};

/**
 * @param {string} value
 * @param {string} prefix
 * @returns {string} What follows the prefix
 */
function after(value, prefix) {
    assert.ok(value.startsWith(prefix), `${value} starts with ${prefix}`);

    return value.slice(prefix.length);
}

test('the Cursor link carries the name and the server object as base64 JSON', () => {
    const built = targets.build('cursor', SERVER, ENCODE);
    const [name, config] = after(built.value, 'cursor://anysphere.cursor-deeplink/mcp/install?name=').split('&config=');

    assert.equal(built.kind, 'href');
    assert.equal(decodeURIComponent(name), 'magento');
    assert.deepEqual(JSON.parse(Buffer.from(decodeURIComponent(config), 'base64').toString('utf8')), {
        url: 'https://shop.example/rest/all/V1/mcp',
        headers: {Authorization: 'Bearer abc.DEF-123_~+/='}
    });
});

test('the Cursor link encodes the name and the base64 characters a query would misread', () => {
    const built = targets.build('cursor', {...SERVER, name: 'my shop&x'}, {base64: () => 'a+b/c='});

    assert.equal(
        built.value,
        'cursor://anysphere.cursor-deeplink/mcp/install?name=my%20shop%26x&config=a%2Bb%2Fc%3D'
    );
});

test('the VS Code link carries the add-mcp object as URI-encoded JSON', () => {
    const built = targets.build('vscode', SERVER, ENCODE);

    assert.equal(built.kind, 'href');
    assert.deepEqual(JSON.parse(decodeURIComponent(after(built.value, 'vscode:mcp/install?'))), {
        name: 'magento',
        type: 'http',
        url: 'https://shop.example/rest/all/V1/mcp',
        headers: {Authorization: 'Bearer abc.DEF-123_~+/='}
    });
});

test('the Claude Code command adds the server over HTTP with the token as a header', () => {
    const built = targets.build('claude-code', SERVER, ENCODE);

    assert.equal(built.kind, 'text');
    assert.equal(
        built.value,
        'claude mcp add --transport http magento "https://shop.example/rest/all/V1/mcp"'
            + ' --header "Authorization: Bearer abc.DEF-123_~+/="'
    );
});

test('an unknown target, an inherited property name included, builds nothing', () => {
    assert.equal(targets.build('zed', SERVER, ENCODE), null);
    assert.equal(targets.build('toString', SERVER, ENCODE), null);
    assert.equal(targets.build('__proto__', SERVER, ENCODE), null);
});

test('the three targets are listed', () => {
    assert.deepEqual([...targets.targets()], ['cursor', 'vscode', 'claude-code']);
});
