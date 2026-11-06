/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The access token an admin pastes into the MCP Server page
 *
 * A token is accepted only in the token68 form an `Authorization: Bearer` header allows (letters, digits and
 * `-._~+/=`), so whatever is accepted can be written into a link, a header value or a double-quoted shell argument
 * without escaping. Surrounding whitespace, which a paste often carries, is dropped first.
 *
 * Pure: no DOM, no storage, no globals.
 */
define([], function () {
    'use strict';

    var TOKEN68 = /^[A-Za-z0-9._~+\/=-]+$/;

    /**
     * Read what the admin typed
     *
     * @param {*} text The field's value
     * @returns {{ok: true, token: string}|{ok: false, reason: ('empty'|'format')}}
     */
    function parse(text) {
        var token = typeof text === 'string' ? text.trim() : '';

        if (token === '') {
            return {ok: false, reason: 'empty'};
        }

        return TOKEN68.test(token) ? {ok: true, token: token} : {ok: false, reason: 'format'};
    }

    /**
     * Replace every occurrence of the placeholder with the token, taking both literally
     *
     * An empty placeholder marks nothing, so the template comes back as it is.
     *
     * @param {string} template
     * @param {string} placeholder
     * @param {string} token
     * @returns {string}
     */
    function fill(template, placeholder, token) {
        return placeholder === '' ? template : template.split(placeholder).join(token);
    }

    return Object.freeze({parse: parse, fill: fill});
});
