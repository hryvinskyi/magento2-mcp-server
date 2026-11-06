/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const {createBridge} = require('./bridge');
const {createHttpTransport} = require('./http-transport');
const {createLineReader} = require('./line-reader');
const {messages} = require('./messages');

const MAX_LINE_BYTES = 1048576;
const MESSAGE_TIMEOUT_MS = 300000;
const TOKEN_PATTERN = /^[A-Za-z0-9._~+/=-]+$/;
const LOOPBACK_HOSTS = Object.freeze(['localhost', '127.0.0.1', '[::1]']);

/**
 * The bridge's settings, read from the environment the extension starts it with
 *
 * Both values are trimmed: a pasted token often ends with a newline, which fetch would refuse as a header value on
 * every call. The bridge refuses to start without an endpoint or a token, with a token that is not a token, with an
 * endpoint that is not an http(s) address or carries a user name or password, and with plain http to any host but
 * this machine, so the token never travels unencrypted.
 *
 * @param {Record<string, string|undefined>} environment
 * @returns {{ok: true, url: string, token: string, host: string}|{ok: false, message: string}}
 */
function readSettings(environment) {
    const url = (environment.MAGENTO_MCP_URL ?? '').trim();
    const token = (environment.MAGENTO_MCP_TOKEN ?? '').trim();
    if (url === '' || token === '') {
        return {ok: false, message: messages.missing({})};
    }

    if (!TOKEN_PATTERN.test(token)) {
        return {ok: false, message: messages.token({})};
    }

    let endpoint;
    try {
        endpoint = new URL(url);
    } catch {
        return {ok: false, message: messages.endpoint({})};
    }

    if (!['https:', 'http:'].includes(endpoint.protocol) || endpoint.username !== '' || endpoint.password !== '') {
        return {ok: false, message: messages.endpoint({})};
    }

    if (endpoint.protocol === 'http:' && !LOOPBACK_HOSTS.includes(endpoint.hostname)) {
        return {ok: false, message: messages.insecure({host: endpoint.host})};
    }

    return {ok: true, url: endpoint.href, token, host: endpoint.host};
}

/**
 * Wire the bridge to this process: standard input in, standard output out, diagnostics to standard error
 *
 * The process is never ended with `process.exit()`: a pipe write is asynchronous on some systems, so exiting right
 * after the last write could cut it off. The exit code is set and the process ends once nothing is left to do.
 *
 * @returns {void}
 */
function main() {
    const settings = readSettings(process.env);
    if (!settings.ok) {
        process.stderr.write(`${settings.message}\n`);
        process.exitCode = 1;

        return;
    }

    const log = (text) => {
        process.stderr.write(`[magento-mcp] ${text}\n`);
    };
    const bridge = createBridge({
        transport: createHttpTransport({
            url: settings.url,
            token: settings.token,
            fetch: globalThis.fetch,
            timeout: (milliseconds) => AbortSignal.timeout(milliseconds),
            timeoutMs: MESSAGE_TIMEOUT_MS,
            log
        }),
        write: (text) => {
            process.stdout.write(`${text}\n`);
        },
        log,
        host: settings.host
    });
    // handle() never rejects; the bridge keeps track of each line until it is answered.
    const reader = createLineReader({
        maxBytes: MAX_LINE_BYTES,
        onLine: (line) => bridge.handle(line),
        onOversize: () => bridge.rejectOversize()
    });

    const stop = () => {
        process.stdin.destroy();
        bridge.abort().then(() => {
            process.exitCode = 0;
        });
    };
    process.once('SIGTERM', stop);
    process.once('SIGINT', stop);

    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => reader.push(chunk));
    process.stdin.on('end', () => {
        reader.end();
        bridge.close().then(() => {
            process.exitCode = 0;
        });
    });
}

if (require.main === module) {
    main();
}

module.exports = {readSettings};
