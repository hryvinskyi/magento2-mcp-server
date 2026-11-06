/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Switches one section of the MCP Server page through its switch endpoint, and reads what changed
 *
 * The endpoint answers a switched section with `{"message": "…", "exposed": n, "sections": [{"code", "off",
 * "exposed"}, …]}`: the top-level `exposed` is the page's total of abilities a client may call, and `sections` the
 * state of every listed section after the switch, because an ability can sit in more than one section and switching
 * one changes what the others expose. That answer becomes `{ok: true, message, exposed, sections}`; every count must
 * be a finite number of at least zero, and is rounded down.
 *
 * Only the endpoint's own refusal reads as `refused`, with its message: a 400 whose body is a JSON object with a
 * string `error` (a section it does not know). Everything else is `failed`: no answer in time, any other status (a
 * server error among them, even with a JSON `error`), Magento's HTML "access denied" page, its JSON for an expired
 * session, the HTML page a bad form key is redirected to (fetch follows the redirect and gets a 200 whose body is
 * not JSON), or a 2xx body that is not the shape above. `aborted` is never shown.
 */
define([
    'Hryvinskyi_McpServer/js/admin-post'
], function (adminPost) {
    'use strict';

    var REFUSED_STATUS = 400,
        FAILED = Object.freeze({ok: false, kind: 'failed'}),
        ABORTED = Object.freeze({ok: false, kind: 'aborted'});

    /**
     * @typedef {{code: string, off: boolean, exposed: number}} SectionState
     * @typedef {{ok: true, message: string, exposed: number, sections: SectionState[]}
     *     |{ok: false, kind: 'refused', message: string}
     *     |{ok: false, kind: ('failed'|'aborted')}} SwitchResult
     * @typedef {{kind: 'answered', status: number, json: *}|{kind: 'failed'}|{kind: 'aborted'}} Reply
     */

    /**
     * @param {*} value
     * @returns {boolean}
     */
    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    /**
     * @param {*} value Parsed JSON, not trusted
     * @returns {boolean}
     */
    function isCount(value) {
        return typeof value === 'number' && isFinite(value) && value >= 0;
    }

    /**
     * One section's state, or null when the value is not one
     *
     * @param {*} value Parsed JSON, not trusted
     * @returns {?SectionState}
     */
    function parseSection(value) {
        if (!isObject(value) || typeof value.code !== 'string' || value.code === ''
            || typeof value.off !== 'boolean' || !isCount(value.exposed)
        ) {
            return null;
        }

        return Object.freeze({code: value.code, off: value.off, exposed: Math.floor(value.exposed)});
    }

    /**
     * The switched answer, or failed when any part of it is not the expected shape
     *
     * @param {*} body Parsed JSON, not trusted
     * @returns {SwitchResult}
     */
    function fromBody(body) {
        var sections;

        if (!isObject(body) || typeof body.message !== 'string' || !isCount(body.exposed)
            || !Array.isArray(body.sections)
        ) {
            return FAILED;
        }
        sections = body.sections.map(parseSection);
        if (sections.indexOf(null) !== -1) {
            return FAILED;
        }

        return Object.freeze({
            ok: true,
            message: body.message,
            exposed: Math.floor(body.exposed),
            sections: Object.freeze(sections)
        });
    }

    /**
     * @param {*} body Parsed JSON, not trusted
     * @returns {SwitchResult}
     */
    function fromRefusal(body) {
        return isObject(body) && typeof body.error === 'string'
            ? Object.freeze({ok: false, kind: 'refused', message: body.error})
            : FAILED;
    }

    /**
     * @param {Reply} reply
     * @returns {SwitchResult}
     */
    function fromReply(reply) {
        if (reply.kind === 'aborted') {
            return ABORTED;
        }
        if (reply.kind !== 'answered') {
            return FAILED;
        }
        if (reply.status === REFUSED_STATUS) {
            return fromRefusal(reply.json);
        }

        return reply.status >= 200 && reply.status < 300 ? fromBody(reply.json) : FAILED;
    }

    /**
     * Build the switch function for one page
     *
     * @param {{fetch: function(string, Object): Promise<Response>, AbortSignal: Object,
     *     timeoutMs: (number|undefined)}} options
     * @returns {function(string, Array<Array<string>>, ?AbortSignal): Promise<SwitchResult>}
     */
    function create(options) {
        var post = adminPost.create(options);

        /**
         * Post a switch form's fields to its action; the promise always resolves
         *
         * @param {string} url The form's action
         * @param {Array<Array<string>>} fields The form's fields: its form key and the section's code
         * @param {?AbortSignal} signal
         * @returns {Promise<SwitchResult>}
         */
        return function switchSection(url, fields, signal) {
            return post(url, fields, signal).then(fromReply);
        };
    }

    return Object.freeze({create: create});
});
