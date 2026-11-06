/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const SESSION_HEADER = 'Mcp-Session-Id';
const VERSION_HEADER = 'MCP-Protocol-Version';
const END_TIMEOUT_MS = 10000;
const REFUSALS = Object.freeze({401: 'unauthorized', 403: 'forbidden'});

/**
 * The store's Streamable HTTP endpoint, as the bridge sees it: a message goes in, one outcome comes back
 *
 * `post(body, session, signal)` sends one JSON-RPC line with the access token and the session's headers, and
 * resolves (it never rejects) to one of:
 * - `{kind: 'answer', status, text, sessionId}` when the body is JSON-RPC, whatever the status: the endpoint answers
 *   a lost session (404) and a refused request (400, 403) with JSON-RPC bodies of its own;
 * - `{kind: 'accepted'}` for 202, or a 204 without a body;
 * - `{kind: 'failed', reason, status?, location?, detail?}`, `reason` being `unauthorized` (401), `forbidden` (403),
 *   `redirect` (3xx, with `location` the host of its `Location` header and nothing else of it), `http` (any other
 *   answer), `timeout` (the per-message deadline passed), `aborted` (the caller's signal was aborted) or `network`
 *   (no answer at all, `detail` being the system error code when there is one).
 *
 * A redirect is never followed: fetch would turn the POST into a GET, and the request, token included, would go to
 * an address the endpoint check at start-up never saw. The deadline bounds the request and reading its body.
 *
 * `end(sessionId, protocolVersion, deadlineMs)` ends the session with DELETE and only logs how that went.
 *
 * @param {{
 *     url: string,
 *     token: string,
 *     fetch: function(string, Object): Promise<Response>,
 *     timeout: function(number): AbortSignal,
 *     timeoutMs: number,
 *     log: function(string): void
 * }} options
 * @returns {{
 *     post: function(string, {sessionId: string, protocolVersion: string}, AbortSignal=): Promise<Object>,
 *     end: function(string, string, number=): Promise<void>
 * }}
 */
function createHttpTransport({url, token, fetch, timeout, timeoutMs, log}) {
    /**
     * The headers every request carries, with the session's when there is one
     *
     * @param {string} sessionId
     * @param {string} protocolVersion
     * @returns {Record<string, string>}
     */
    function headersFor(sessionId, protocolVersion) {
        const headers = {Authorization: `Bearer ${token}`};
        if (sessionId !== '') {
            headers[SESSION_HEADER] = sessionId;
        }
        if (protocolVersion !== '') {
            headers[VERSION_HEADER] = protocolVersion;
        }

        return headers;
    }

    /**
     * Send one request, reading its whole body within the deadline
     *
     * @param {string} method
     * @param {Record<string, string>} headers
     * @param {string|undefined} body
     * @param {AbortSignal|undefined} signal The caller's cancellation, when it has one
     * @param {number} deadlineMs
     * @returns {Promise<Object>} `{response, text}`, or a `failed` outcome
     */
    async function send(method, headers, body, signal, deadlineMs) {
        const deadline = timeout(deadlineMs);
        const combined = combine(signal === undefined ? [deadline] : [signal, deadline]);
        try {
            const response = await fetch(url, {method, headers, body, redirect: 'manual', signal: combined.signal});
            const text = await response.text();

            return {response, text};
        } catch (error) {
            if (signal !== undefined && signal.aborted) {
                return {kind: 'failed', reason: 'aborted'};
            }
            if (deadline.aborted) {
                return {kind: 'failed', reason: 'timeout'};
            }

            return withDetail({kind: 'failed', reason: 'network'}, error);
        } finally {
            combined.release();
        }
    }

    return {
        async post(body, session, signal) {
            const headers = headersFor(session.sessionId, session.protocolVersion);
            headers['Content-Type'] = 'application/json';
            headers.Accept = 'application/json, text/event-stream';

            const sent = await send('POST', headers, body, signal, timeoutMs);
            if (sent.kind === 'failed') {
                return sent;
            }

            return outcomeOf(sent.response, sent.text, url);
        },

        async end(sessionId, protocolVersion, deadlineMs = END_TIMEOUT_MS) {
            const sent = await send('DELETE', headersFor(sessionId, protocolVersion), undefined, undefined, deadlineMs);
            if (sent.kind === 'failed') {
                log(`Ending the MCP session failed: ${sent.reason}${sent.detail ? ` (${sent.detail})` : ''}.`);

                return;
            }

            log(`The MCP session ended (HTTP ${sent.response.status}).`);
        }
    };
}

/**
 * What an answer means to the bridge
 *
 * @param {Response} response
 * @param {string} text Its whole body
 * @param {string} url The endpoint, against which a relative `Location` resolves
 * @returns {Object}
 */
function outcomeOf(response, text, url) {
    const status = response.status;
    if (status >= 300 && status < 400) {
        return {kind: 'failed', reason: 'redirect', status, location: hostOf(response.headers.get('Location'), url)};
    }

    if (isJsonRpc(text)) {
        return {kind: 'answer', status, text, sessionId: response.headers.get(SESSION_HEADER) ?? ''};
    }

    if (status === 202 || (status === 204 && text === '')) {
        return {kind: 'accepted'};
    }

    return {kind: 'failed', reason: REFUSALS[status] ?? 'http', status};
}

/**
 * Whether a body is a JSON-RPC message or a batch of them
 *
 * @param {string} text
 * @returns {boolean}
 */
function isJsonRpc(text) {
    let parsed;
    try {
        parsed = JSON.parse(text);
    } catch {
        return false;
    }

    const isMessage = (value) => typeof value === 'object' && value !== null && !Array.isArray(value)
        && value.jsonrpc === '2.0';

    return Array.isArray(parsed) ? parsed.length > 0 && parsed.every(isMessage) : isMessage(parsed);
}

/**
 * The host a `Location` header points to, never the rest of its value
 *
 * @param {string|null} location
 * @param {string} base
 * @returns {string} '' when there is no header or it is not an address
 */
function hostOf(location, base) {
    if (location === null) {
        return '';
    }

    try {
        return new URL(location, base).host;
    } catch {
        return '';
    }
}

/**
 * A network failure with the system error code behind it, when there is one, and nothing else of the error
 *
 * The error's message is not kept: a rejected header value, for one, is quoted in it.
 *
 * @param {{kind: string, reason: string}} failure
 * @param {unknown} error
 * @returns {Object}
 */
function withDetail(failure, error) {
    const cause = error instanceof Error && error.cause instanceof Error ? error.cause : error;
    const code = typeof cause === 'object' && cause !== null && 'code' in cause ? cause.code : undefined;

    return typeof code === 'string' && /^[A-Z][A-Z0-9_]*$/.test(code) ? {...failure, detail: code} : failure;
}

/**
 * One signal that aborts as soon as any of the given ones does, with that one's reason
 *
 * `release()` removes its listeners, so a long-lived signal handed to every request does not collect one per
 * request.
 *
 * @param {AbortSignal[]} signals
 * @returns {{signal: AbortSignal, release: function(): void}}
 */
function combine(signals) {
    const controller = new AbortController();
    const listeners = new AbortController();
    for (const signal of signals) {
        if (signal.aborted) {
            controller.abort(signal.reason);

            return {signal: controller.signal, release: () => undefined};
        }
    }

    for (const signal of signals) {
        signal.addEventListener('abort', () => {
            controller.abort(signal.reason);
            listeners.abort();
        }, {once: true, signal: listeners.signal});
    }

    return {signal: controller.signal, release: () => listeners.abort()};
}

module.exports = {createHttpTransport};
