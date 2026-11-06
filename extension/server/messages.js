/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

/**
 * The text Claude shows for each way the bridge can fail, keyed by the failure's kind
 *
 * The bridge runs on the admin's machine without the store's translations, so the wording is English. Each entry
 * takes the details its kind has: the store's host, the HTTP status, the host a redirect points to. None of them
 * ever takes the access token.
 *
 * - `unauthorized`: Magento answered 401, its answer both to a wrong or expired token and to a token whose
 *   integration lacks the endpoint's resource.
 * - `forbidden`: a 403 that is not a JSON-RPC answer, so it came from a proxy or firewall. A disabled MCP server
 *   answers with a JSON-RPC error of its own, which the bridge re-addresses to the line's requests with the
 *   server's code, message and data, so Claude shows the server's reason instead of this one.
 * - `redirect`: the store answered with a redirect, which the bridge never follows.
 * - `timeout`, `network`, `http`: no answer in time, no connection, an answer that is not JSON-RPC.
 * - `oversize`: a message larger than the store accepts.
 * - `missing`, `endpoint`, `insecure`, `token`: the extension's settings, checked when the bridge starts.
 *
 * @type {Readonly<Record<string, function({host?: string, status?: number, location?: string}): string>>}
 */
const messages = Object.freeze({
    unauthorized: () => 'The store refused the access token: it is wrong or expired, or its integration lacks the '
        + '"Use the MCP endpoint" resource. Check it in the extension\'s settings.',
    forbidden: () => 'The store, or a proxy in front of it, refused the request (HTTP 403).',
    redirect: ({location}) => `The store answered with a redirect to ${location || 'another address'}; set the `
        + 'endpoint to the address it redirects to.',
    timeout: () => 'The store did not answer in time.',
    network: ({host}) => `Could not reach the store at ${host}.`,
    http: ({status}) => `The store answered with HTTP ${status} instead of an MCP answer.`,
    oversize: () => 'The message is larger than the 1 MB the store accepts.',
    missing: () => 'The extension\'s settings hold no store endpoint or no access token. Fill both in.',
    endpoint: () => 'The store endpoint is not an http:// or https:// address without a user name or password. '
        + 'Correct it in the extension\'s settings.',
    insecure: ({host}) => `The store endpoint uses plain http:// to ${host}, which would send the access token `
        + 'unencrypted. Use an https:// address (plain http:// is accepted only for localhost).',
    token: () => 'The access token holds characters a token cannot have. Paste it again in the extension\'s '
        + 'settings.'
});

module.exports = {messages};
