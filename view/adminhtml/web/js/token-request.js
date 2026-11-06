/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Asks the MCP Server page's token endpoint for the access token of one integration
 *
 * One POST per call, with the admin's form key, answered with JSON `{"token": "…"}`. The answer is reduced to a
 * small result: the token, or why there is none — `refused` when the endpoint itself says no, `failed` for everything
 * else, and `aborted` when the caller gave up, which is never shown.
 *
 * Only the endpoint's own refusals read as `refused`: a 400, 403, 404 or 409 whose body is a JSON object with a
 * string `error` (a bad or unknown integration, or one that is not ready). The same statuses with any other body are
 * not the endpoint speaking, and read as `failed`: a store that does not route the endpoint yet answers its HTML
 * "page not found", and an admin without the right gets the HTML "access denied" page, and neither says anything
 * about the integration. `failed` also covers no answer before the deadline, a network error, a server error, a
 * redirect, and a 2xx body that is not JSON or holds no valid token.
 *
 * The token is a secret: it is returned to the caller and nowhere else — never logged, stored or put into an error.
 * Nothing is retried: a token is not worth fetching twice on its own, and the admin can pick the integration again.
 *
 * The POST itself, its deadline and the caller's abort are `admin-post`'s; `fetch` and `AbortSignal` are passed in
 * and handed on to it. This module reads no global; `admin-post` reads `AbortController`, and only on a browser
 * without `AbortSignal.any`.
 */
define([
    'Hryvinskyi_McpServer/js/access-token',
    'Hryvinskyi_McpServer/js/admin-post'
], function (accessToken, adminPost) {
    'use strict';

    var REFUSED_STATUSES = Object.freeze([400, 403, 404, 409]),
        REFUSED = Object.freeze({ok: false, kind: 'refused'}),
        FAILED = Object.freeze({ok: false, kind: 'failed'}),
        ABORTED = Object.freeze({ok: false, kind: 'aborted'});

    /**
     * @typedef {{ok: true, token: string}|{ok: false, kind: ('refused'|'failed'|'aborted')}} TokenResult
     * @typedef {{kind: 'answered', status: number, json: *}|{kind: 'failed'}|{kind: 'aborted'}} Reply
     */

    /**
     * @param {*} body Parsed JSON, not trusted
     * @returns {boolean}
     */
    function isObject(body) {
        return body !== null && typeof body === 'object' && !Array.isArray(body);
    }

    /**
     * The token in a 2xx body, or failed when the body holds none in the form a Bearer header allows
     *
     * @param {*} body Parsed JSON, not trusted
     * @returns {TokenResult}
     */
    function fromBody(body) {
        var parsed = isObject(body) ? accessToken.parse(body.token) : null;

        return parsed && parsed.ok ? Object.freeze({ok: true, token: parsed.token}) : FAILED;
    }

    /**
     * Refused when the body is the endpoint's own refusal, a JSON object with a string `error`; failed otherwise
     *
     * @param {*} body Parsed JSON, not trusted
     * @returns {TokenResult}
     */
    function fromRefusal(body) {
        return isObject(body) && typeof body.error === 'string' ? REFUSED : FAILED;
    }

    /**
     * @param {Reply} reply
     * @returns {TokenResult}
     */
    function fromReply(reply) {
        if (reply.kind === 'aborted') {
            return ABORTED;
        }
        if (reply.kind !== 'answered') {
            return FAILED;
        }
        if (REFUSED_STATUSES.indexOf(reply.status) !== -1) {
            return fromRefusal(reply.json);
        }

        return reply.status >= 200 && reply.status < 300 ? fromBody(reply.json) : FAILED;
    }

    /**
     * Build the request function for one page
     *
     * @param {{fetch: function(string, Object): Promise<Response>, AbortSignal: Object, url: string,
     *     formKey: string, timeoutMs: (number|undefined)}} options
     * @returns {function((string|number), ?AbortSignal): Promise<TokenResult>}
     */
    function create(options) {
        var post = adminPost.create(options),
            url = options.url,
            formKey = options.formKey;

        /**
         * Fetch the token of one integration; the promise always resolves
         *
         * @param {(string|number)} integrationId
         * @param {?AbortSignal} signal Aborted by the caller when the answer is no longer wanted
         * @returns {Promise<TokenResult>}
         */
        return function request(integrationId, signal) {
            return post(url, [['form_key', formKey], ['integration_id', String(integrationId)]], signal)
                .then(fromReply);
        };
    }

    return Object.freeze({create: create});
});
