/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {readSettings} = require('../../../extension/server/index');

// The files the extension download ships under server/; the bridge must run from these alone.
const SHIPPED = ['index.js', 'bridge.js', 'http-transport.js', 'line-reader.js', 'messages.js'];
const SOURCE = path.join(__dirname, '..', '..', '..', 'extension', 'server');
const TOKEN = 'fake-token-for-tests';
const SESSION = 'd'.repeat(32);

/**
 * A fresh directory holding a server/ copy of exactly the shipped files
 *
 * @param {test.TestContext} context
 * @returns {string} The copy's index.js
 */
function shippedCopy(context) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-bridge-'));
    context.after(() => fs.rmSync(root, {recursive: true, force: true}));
    const server = path.join(root, 'server');
    fs.mkdirSync(server);
    for (const file of SHIPPED) {
        fs.copyFileSync(path.join(SOURCE, file), path.join(server, file));
    }

    return path.join(server, 'index.js');
}

/**
 * Run the bridge with this environment, feed it these lines and wait until it has exited
 *
 * @param {string} entry
 * @param {Record<string, string>} environment
 * @param {string[]} lines
 * @returns {Promise<{code: number|null, stdout: string, stderr: string}>}
 */
function run(entry, environment, lines) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.execPath, [entry], {env: environment, stdio: ['pipe', 'pipe', 'pipe']});
        let stdout = '';
        let stderr = '';
        child.stdout.setEncoding('utf8');
        child.stderr.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            stdout += chunk;
        });
        child.stderr.on('data', (chunk) => {
            stderr += chunk;
        });
        child.on('error', reject);
        child.on('close', (code) => resolve({code, stdout, stderr}));
        child.stdin.on('error', () => undefined);
        child.stdin.end(lines.map((line) => `${line}\n`).join(''));
    });
}

/**
 * A stand-in for the store's MCP endpoint on a random loopback port
 *
 * @param {test.TestContext} context
 * @returns {Promise<{url: string, requests: Array<{method: string, headers: Object, body: string}>}>}
 */
async function endpoint(context) {
    const requests = [];
    const server = http.createServer((request, response) => {
        let body = '';
        request.setEncoding('utf8');
        request.on('data', (chunk) => {
            body += chunk;
        });
        request.on('end', () => {
            requests.push({method: request.method, headers: request.headers, body});
            if (request.headers.authorization !== `Bearer ${TOKEN}`) {
                response.writeHead(401, {'Content-Type': 'application/json'});
                response.end('{"message":"The consumer isn\'t authorized to access %resources."}');

                return;
            }

            if (request.method === 'DELETE') {
                response.writeHead(204);
                response.end();

                return;
            }

            const message = JSON.parse(body);
            if (message.method === 'initialize') {
                response.writeHead(200, {'Content-Type': 'application/json', 'Mcp-Session-Id': SESSION});
                response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {protocolVersion: '2025-06-18'}}));

                return;
            }

            if (request.headers['mcp-session-id'] !== SESSION) {
                response.writeHead(400, {'Content-Type': 'application/json'});
                response.end('{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"No session"}}');

                return;
            }

            if (message.method === 'tools/call') {
                // Never answered: the bridge has to give up on it.
                return;
            }

            if (!('id' in message)) {
                response.writeHead(202);
                response.end();

                return;
            }

            response.writeHead(200, {'Content-Type': 'application/json'});
            response.end(JSON.stringify({jsonrpc: '2.0', id: message.id, result: {tools: []}}, null, 2));
        });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    context.after(() => new Promise((resolve) => {
        server.closeAllConnections();
        server.close(resolve);
    }));

    return {url: `http://127.0.0.1:${server.address().port}/rest/all/V1/mcp`, requests};
}

const LINES = [
    '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
    '{"jsonrpc":"2.0","method":"notifications/initialized"}',
    '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
];

test('the shipped files relay a session to the store and end it when the input ends', async (context) => {
    const entry = shippedCopy(context);
    const store = await endpoint(context);
    const {code, stdout, stderr} = await run(entry, {MAGENTO_MCP_URL: store.url, MAGENTO_MCP_TOKEN: TOKEN}, LINES);

    assert.equal(code, 0, stderr);
    assert.deepEqual(stdout.split('\n'), [
        '{"jsonrpc":"2.0","id":0,"result":{"protocolVersion":"2025-06-18"}}',
        '{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}',
        ''
    ]);
    assert.deepEqual(store.requests.map((request) => request.method), ['POST', 'POST', 'POST', 'DELETE']);
    assert.equal(store.requests[3].headers['mcp-session-id'], SESSION);
    assert.equal(store.requests[3].headers['mcp-protocol-version'], '2025-06-18');
    assert.equal(`${stdout}${stderr}`.includes(TOKEN), false);
});

test('a termination signal cancels the call in flight, ends the session and exits with 0', async (context) => {
    const entry = shippedCopy(context);
    const store = await endpoint(context);
    const child = spawn(process.execPath, [entry], {
        env: {MAGENTO_MCP_URL: store.url, MAGENTO_MCP_TOKEN: TOKEN},
        stdio: ['pipe', 'pipe', 'pipe']
    });
    let stdout = '';
    child.stdout.setEncoding('utf8');
    child.stdout.on('data', (chunk) => {
        stdout += chunk;
    });
    const exited = new Promise((resolve) => child.on('close', (code) => resolve(code)));
    child.stdin.write(`${LINES[0]}\n${LINES[1]}\n{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{}}\n`);
    while (!store.requests.some((request) => request.body.includes('tools/call'))) {
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
    child.kill('SIGTERM');

    assert.equal(await exited, 0);
    assert.deepEqual(stdout.trim().split('\n').map((line) => JSON.parse(line).id), [0]);
    assert.equal(store.requests.at(-1).method, 'DELETE');
    assert.equal(store.requests.filter((request) => request.method === 'DELETE').length, 1);
});

test('a token with a trailing newline starts and is sent trimmed', async (context) => {
    const entry = shippedCopy(context);
    const store = await endpoint(context);
    const {code, stdout} = await run(entry, {MAGENTO_MCP_URL: ` ${store.url}\n`, MAGENTO_MCP_TOKEN: `${TOKEN}\n`}, [LINES[0]]);

    assert.equal(code, 0);
    assert.equal(JSON.parse(stdout).id, 0);
    assert.equal(store.requests[0].headers.authorization, `Bearer ${TOKEN}`);
});

for (const [name, environment] of [
    ['a plain http endpoint on another host', {MAGENTO_MCP_URL: 'http://example.com/rest/all/V1/mcp', MAGENTO_MCP_TOKEN: TOKEN}],
    ['a token holding a space', {MAGENTO_MCP_URL: 'https://shop.example/rest/all/V1/mcp', MAGENTO_MCP_TOKEN: `${TOKEN} x`}],
    ['an empty token', {MAGENTO_MCP_URL: 'https://shop.example/rest/all/V1/mcp', MAGENTO_MCP_TOKEN: ''}]
]) {
    test(`the bridge refuses to start with ${name}`, async (context) => {
        const entry = shippedCopy(context);
        const {code, stdout, stderr} = await run(entry, environment, [LINES[0]]);

        assert.equal(code, 1);
        assert.equal(stdout, '');
        assert.equal(stderr.trim().split('\n').length, 1);
        assert.notEqual(stderr.trim(), '');
        assert.equal(stderr.includes(TOKEN), false);
    });
}

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
