/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The clients the MCP Server page can install the server into in one step
 *
 * Each target builds what the admin uses to install: an `href` (a deeplink the client opens) or a `text` (a command
 * to run). The server is the same for all of them: its name, the endpoint URL and the access token, sent as an
 * `Authorization: Bearer` header. Base64 is passed in, so the module reads no global.
 *
 * Pure: no DOM, no storage, no network.
 */
define([], function () {
    'use strict';

    /**
     * @typedef {{name: string, url: string, token: string}} Server
     * @typedef {{base64: function(string): string}} Encoders
     */

    /**
     * @param {string} token
     * @returns {{Authorization: string}}
     */
    function headers(token) {
        return {Authorization: 'Bearer ' + token};
    }

    var registry = Object.freeze({
        cursor: Object.freeze({
            kind: 'href',

            /**
             * Cursor's install deeplink: the server object alone, as base64 JSON
             *
             * @param {Server} server
             * @param {Encoders} encode
             * @returns {string}
             */
            build: function (server, encode) {
                var config = JSON.stringify({url: server.url, headers: headers(server.token)});

                return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' + encodeURIComponent(server.name)
                    + '&config=' + encodeURIComponent(encode.base64(config));
            }
        }),
        vscode: Object.freeze({
            kind: 'href',

            /**
             * VS Code's install deeplink: the object `code --add-mcp` takes, URI-encoded JSON
             *
             * @param {Server} server
             * @returns {string}
             */
            build: function (server) {
                return 'vscode:mcp/install?' + encodeURIComponent(JSON.stringify({
                    name: server.name,
                    type: 'http',
                    url: server.url,
                    headers: headers(server.token)
                }));
            }
        }),
        'claude-code': Object.freeze({
            kind: 'text',

            /**
             * The Claude Code command that adds the server
             *
             * @param {Server} server
             * @returns {string}
             */
            build: function (server) {
                return 'claude mcp add --transport http ' + server.name + ' "' + server.url + '" --header "'
                    + 'Authorization: Bearer ' + server.token + '"';
            }
        })
    });

    /**
     * Build what one target installs with
     *
     * @param {string} target e.g. "cursor"
     * @param {Server} server
     * @param {Encoders} encode
     * @returns {?{kind: ('href'|'text'), value: string}} Null for a target the page does not know
     */
    function build(target, server, encode) {
        var entry = Object.prototype.hasOwnProperty.call(registry, target) ? registry[target] : null;

        return entry ? {kind: entry.kind, value: entry.build(server, encode)} : null;
    }

    /**
     * @returns {string[]} Every target the page can install into
     */
    function targets() {
        return Object.keys(registry);
    }

    return Object.freeze({build: build, targets: targets});
});
