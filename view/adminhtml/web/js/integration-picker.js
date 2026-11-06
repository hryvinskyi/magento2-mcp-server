/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The MCP Server page's integration picker: choose an integration, and its access token fills the token field
 *
 * The select `[data-role="mcp-integration"]` lists the integrations; its first option, with an empty value, leaves
 * the token to be pasted by hand. Picking a ready integration (`data-ready="1"`) asks the store for its token and
 * writes it into the token field `[data-role="mcp-token"]`, then announces the change the way typing does — an
 * `input` event that bubbles to the connection card, which builds its links and snippets from the field. Picking one
 * that is not ready shows its note `[data-integration-note="{id}"]` (why, and a link to edit it) and asks nothing.
 * `[data-role="mcp-integration-status"]` reads the loading wording while the store answers, and the refused or failed
 * wording when no token came back.
 *
 * The field never shows one token while the select names an integration it did not come from. Picking an
 * integration empties the field first, whatever it held, typed or picked, and only then asks for that integration's
 * token, so a pick that is refused, fails or is not ready leaves the field empty, and nothing is shown under the new
 * name while the token loads. Picking the empty option ("paste a token by hand") empties the field only when it holds
 * a token the picker wrote; a value the admin typed stays. Typing into the field hands it back to the admin: the
 * select returns to the empty option. The picker remembers only whether the field's value is one it wrote, never the
 * token itself, and never a value it emptied, so no earlier value comes back.
 *
 * The select stays enabled while a token loads: the admin may change their mind, and the newer choice aborts the
 * older request, whose answer is dropped even if it still arrives.
 *
 * Mounted through x-magento-init on the connection card with `{tokenUrl, formKey, wording: {loading, refused,
 * failed}}`; `mount(root, environment)` is the same with the request function and the announcer passed in, and
 * returns a teardown that also aborts a request in flight.
 */
define([
    'Hryvinskyi_McpServer/js/token-request'
], function (tokenRequest) {
    'use strict';

    var SELECT = '[data-role="mcp-integration"]',
        TOKEN_FIELD = '[data-role="mcp-token"]',
        STATUS = '[data-role="mcp-integration-status"]',
        NOTE = '[data-integration-note]',
        READY = '1',
        mounted = new WeakSet();

    /**
     * @typedef {{ok: true, token: string}|{ok: false, kind: ('refused'|'failed'|'aborted')}} TokenResult
     * @typedef {{loading: string, refused: string, failed: string}} Wording
     * @typedef {{request: function(string, AbortSignal): Promise<TokenResult>,
     *     announce: function(HTMLElement): void, wording: Wording}} Environment
     */

    /**
     * @param {HTMLElement} root
     * @param {Environment} environment
     * @returns {function(): void} Teardown
     */
    function mount(root, environment) {
        var select = root.querySelector(SELECT),
            field = root.querySelector(TOKEN_FIELD),
            status = root.querySelector(STATUS),
            notes = Array.prototype.slice.call(root.querySelectorAll(NOTE)),
            controller,
            pending = null,
            picked = false,
            announcing = false,

            /**
             * What the picker shows for each kind of answer that carries no token
             */
            withoutToken = Object.freeze({
                refused: function () {
                    setStatus(environment.wording.refused);
                },

                failed: function () {
                    setStatus(environment.wording.failed);
                },

                aborted: function () {}
            });

        if (mounted.has(root) || !select || !field) {
            return function () {};
        }
        mounted.add(root);
        controller = new AbortController();

        /**
         * @param {string} text
         */
        function setStatus(text) {
            if (status) {
                status.textContent = text;
            }
        }

        /**
         * Put a value into the field and tell the page it changed
         *
         * The announcement comes back to this picker's own input listener before `announce` returns, so it is
         * marked as the picker's and not mistaken for typing.
         *
         * @param {string} value
         */
        function write(value) {
            field.value = value;
            announcing = true;
            try {
                environment.announce(field);
            } finally {
                announcing = false;
            }
        }

        /**
         * Empty the field, whoever filled it; an empty field is left alone, so nothing is announced for nothing
         */
        function empty() {
            picked = false;
            if (field.value !== '') {
                write('');
            }
        }

        /**
         * Empty the field when it holds a token the picker wrote, and leave a typed value alone
         */
        function dropPicked() {
            if (picked) {
                empty();
            }
        }

        /**
         * Abort the request in flight, if any
         */
        function cancelPending() {
            if (pending) {
                pending.abort();
                pending = null;
            }
        }

        /**
         * Hide every note, then show the one for the given integration, if there is one
         *
         * @param {?string} id
         */
        function showNote(id) {
            notes.forEach(function (note) {
                if (id !== null && note.getAttribute('data-integration-note') === id) {
                    note.removeAttribute('hidden');
                } else {
                    note.setAttribute('hidden', '');
                }
            });
        }

        /**
         * @param {string} id
         */
        function fetchToken(id) {
            var request = new AbortController();

            pending = request;
            setStatus(environment.wording.loading);

            /**
             * @param {TokenResult} result
             */
            function apply(result) {
                var kind;

                if (pending !== request || request.signal.aborted || select.value !== id) {
                    return;
                }
                pending = null;
                if (result && result.ok === true) {
                    setStatus('');
                    write(result.token);
                    picked = true;

                    return;
                }
                kind = result && Object.prototype.hasOwnProperty.call(withoutToken, result.kind)
                    ? result.kind
                    : 'failed';
                withoutToken[kind]();
            }

            environment.request(id, request.signal).then(apply, function () {
                apply({ok: false, kind: 'failed'});
            });
        }

        root.addEventListener('change', function (event) {
            var id,
                option;

            if (event.target !== select) {
                return;
            }
            id = select.value;
            option = select.options[select.selectedIndex] || null;

            cancelPending();
            showNote(null);
            setStatus('');

            if (id === '') {
                dropPicked();

                return;
            }
            empty();
            if (!option || option.getAttribute('data-ready') !== READY) {
                showNote(id);

                return;
            }
            fetchToken(id);
        }, {signal: controller.signal});

        root.addEventListener('input', function (event) {
            if (announcing || event.target !== field) {
                return;
            }
            picked = false;
            cancelPending();
            showNote(null);
            setStatus('');
            if (select.value !== '') {
                select.value = '';
            }
        }, {signal: controller.signal});

        return function () {
            controller.abort();
            cancelPending();
            mounted.delete(root);
        };
    }

    /**
     * @param {*} value
     * @returns {boolean}
     */
    function isFilled(value) {
        return typeof value === 'string' && value !== '';
    }

    /**
     * The wording the page printed, or null when any of it is missing
     *
     * @param {*} value
     * @returns {?Wording}
     */
    function parseWording(value) {
        if (!value || typeof value !== 'object'
            || !isFilled(value.loading) || !isFilled(value.refused) || !isFilled(value.failed)) {
            return null;
        }

        return Object.freeze({loading: value.loading, refused: value.refused, failed: value.failed});
    }

    /**
     * The configuration the page printed, or null when it is missing or not the expected shape
     *
     * @param {*} config
     * @returns {?{tokenUrl: string, formKey: string, wording: Wording}}
     */
    function parseConfig(config) {
        var wording;

        if (!config || typeof config !== 'object' || !isFilled(config.tokenUrl) || !isFilled(config.formKey)) {
            return null;
        }
        wording = parseWording(config.wording);

        return wording ? Object.freeze({tokenUrl: config.tokenUrl, formKey: config.formKey, wording: wording}) : null;
    }

    /**
     * Tell the page the field changed: an `input` event that bubbles, made with the page's own constructor
     *
     * @param {HTMLElement} field
     */
    function announceInput(field) {
        var view = field.ownerDocument.defaultView;

        field.dispatchEvent(new view.Event('input', {bubbles: true}));
    }

    /**
     * The x-magento-init entry; a configuration that is not the expected shape mounts nothing
     *
     * @param {*} config
     * @param {HTMLElement} element
     */
    function entry(config, element) {
        var parsed = parseConfig(config),
            view;

        if (!parsed) {
            return;
        }
        view = element.ownerDocument.defaultView;

        mount(element, {
            request: tokenRequest.create({
                fetch: view.fetch.bind(view),
                AbortSignal: view.AbortSignal,
                url: parsed.tokenUrl,
                formKey: parsed.formKey
            }),
            announce: announceInput,
            wording: parsed.wording
        });
    }

    entry.mount = mount;

    return entry;
});
