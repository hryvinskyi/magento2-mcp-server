/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {readSettings} = require('../../../extension/server/settings');

const TOKEN = 'fake-token-for-tests';

test('the settings accept https anywhere and plain http only to this machine', () => {
    for (const url of ['https://shop.example/rest/all/V1/mcp', 'http://localhost:8080/mcp', 'http://127.0.0.1/mcp', 'http://[::1]:9000/mcp']) {
        assert.equal(readSettings({MAGENTO_MCP_URL: url, MAGENTO_MCP_TOKEN: TOKEN}).ok, true, url);
    }

    for (const [url, message] of [
        ['http://shop.example/mcp', /plain http:\/\/ to shop\.example/],
        ['http://127.0.0.1.example.com/mcp', /plain http:\/\//],
        ['ftp://shop.example/mcp', /not an http/],
        ['not a url', /not an http/],
        ['https://user:secret@shop.example/mcp', /not an http/]
    ]) {
        const settings = readSettings({MAGENTO_MCP_URL: url, MAGENTO_MCP_TOKEN: TOKEN});
        assert.equal(settings.ok, false, url);
        assert.match(settings.message, message);
    }
});

test('the settings name the store\'s host and accept every token68 character', () => {
    const settings = readSettings({
        MAGENTO_MCP_URL: 'https://shop.example:8443/rest/all/V1/mcp',
        MAGENTO_MCP_TOKEN: 'Az09._~+/=-'
    });

    assert.deepEqual({...settings}, {
        ok: true,
        url: 'https://shop.example:8443/rest/all/V1/mcp',
        token: 'Az09._~+/=-',
        host: 'shop.example:8443'
    });
});

test('the settings refuse a missing endpoint', () => {
    assert.equal(readSettings({MAGENTO_MCP_TOKEN: TOKEN}).ok, false);
});
