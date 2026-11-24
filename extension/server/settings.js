/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const {messages} = require('./messages');

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

module.exports = {readSettings};
