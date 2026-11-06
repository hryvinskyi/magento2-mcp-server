/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The MCP Server page's connection card: copy buttons, install links and the access token
 *
 * A button `[data-role="mcp-copy"]` copies the text of the element its `data-copy-target` names, then reads the
 * "copied" wording the page sends for a moment and goes back to its own. When the browser refuses the clipboard, the
 * text is selected instead so the admin can copy it by hand. Choosing a client and a transport needs no script: the
 * card's radio buttons show the matching configuration.
 *
 * With the page's connection (server name, endpoint, token placeholder), the card also binds the install targets
 * `[data-install]` to the token field `[data-role="mcp-token"]`. A link (`href` kind) is shown, with its `href`, only
 * while the field holds a valid token: a link carrying the placeholder would install a server that every call
 * refuses. A command (`text` kind) is filled with the placeholder until a valid token replaces it, and the group
 * around it `[data-role="mcp-claude-code"]`, which the page renders hidden, is shown as soon as it holds a command:
 * without a connection the group stays hidden, so the admin never sees an empty box to copy. Every
 * `[data-token-slot]` element gets the token in place of the placeholder, and its own text back when the field is
 * emptied or invalid; an invalid token also shows `[data-role="mcp-token-hint"]`.
 *
 * The token stays in the field: it is written only into those links and texts, never stored, logged or sent.
 *
 * Mounted through x-magento-init with `{copied, connection}`; `mount(root, environment)` is the same with the
 * parsed connection and the browser's clipboard, timers, selection and base64 passed in, and returns a teardown.
 */
define([
    'Hryvinskyi_McpServer/js/install-targets',
    'Hryvinskyi_McpServer/js/access-token'
], function (installTargets, accessToken) {
    'use strict';

    var BUTTON = '[data-role="mcp-copy"]',
        TOKEN_FIELD = '[data-role="mcp-token"]',
        TOKEN_HINT = '[data-role="mcp-token-hint"]',
        INSTALL = '[data-install]',
        COMMAND_GROUP = '[data-role="mcp-claude-code"]',
        TOKEN_SLOT = '[data-token-slot]',
        RESET_MS = 1500,
        mounted = new WeakSet(),

        /**
         * How each kind of install target is shown, with a token and without one
         */
        kinds = Object.freeze({
            href: Object.freeze({
                /**
                 * @param {HTMLElement} element
                 * @param {string} value
                 */
                withToken: function (element, value) {
                    element.setAttribute('href', value);
                    element.removeAttribute('hidden');
                },

                /**
                 * @param {HTMLElement} element
                 */
                withoutToken: function (element) {
                    element.removeAttribute('href');
                    element.setAttribute('hidden', '');
                }
            }),
            text: Object.freeze({
                /**
                 * @param {HTMLElement} element
                 * @param {string} value
                 */
                withToken: function (element, value) {
                    showCommand(element, value);
                },

                /**
                 * @param {HTMLElement} element
                 * @param {string} value Built with the placeholder
                 */
                withoutToken: function (element, value) {
                    showCommand(element, value);
                }
            })
        });

    /**
     * Put the command into its element and show the group the page renders hidden around it
     *
     * @param {HTMLElement} element
     * @param {string} value
     */
    function showCommand(element, value) {
        var group = element.closest(COMMAND_GROUP);

        element.textContent = value;
        if (group) {
            group.removeAttribute('hidden');
        }
    }

    /**
     * @typedef {{name: string, url: string, placeholder: string}} Connection
     */

    /**
     * Bind the token field to the install targets and the token slots
     *
     * @param {HTMLElement} root
     * @param {Connection} connection
     * @param {function(string): string} base64
     * @param {AbortSignal} signal
     */
    function bindToken(root, connection, base64, signal) {
        var installs = Array.prototype.slice.call(root.querySelectorAll(INSTALL)),
            hints = Array.prototype.slice.call(root.querySelectorAll(TOKEN_HINT)),
            slots = new Map();

        Array.prototype.forEach.call(root.querySelectorAll(TOKEN_SLOT), function (slot) {
            slots.set(slot, slot.textContent);
        });

        /**
         * Show every install target with the token, or in its no-token state for null
         *
         * @param {?string} token
         */
        function applyInstalls(token) {
            var server = {name: connection.name, url: connection.url, token: token === null
                ? connection.placeholder
                : token};

            installs.forEach(function (element) {
                var built = installTargets.build(element.getAttribute('data-install'), server, {base64: base64}),
                    kind = built ? kinds[built.kind] : null;

                if (!kind) {
                    return;
                }
                if (token === null) {
                    kind.withoutToken(element, built.value);
                } else {
                    kind.withToken(element, built.value);
                }
            });
        }

        /**
         * Put the token into every slot, or give each its own text back for null
         *
         * @param {?string} token
         */
        function applySlots(token) {
            slots.forEach(function (original, slot) {
                slot.textContent = token === null
                    ? original
                    : accessToken.fill(original, connection.placeholder, token);
            });
        }

        /**
         * @param {boolean} shown
         */
        function showHint(shown) {
            hints.forEach(function (hint) {
                if (shown) {
                    hint.removeAttribute('hidden');
                } else {
                    hint.setAttribute('hidden', '');
                }
            });
        }

        applyInstalls(null);

        root.addEventListener('input', function (event) {
            var field = event.target && event.target.closest ? event.target.closest(TOKEN_FIELD) : null,
                parsed = field ? accessToken.parse(field.value) : null,
                token = parsed && parsed.ok ? parsed.token : null;

            if (!parsed) {
                return;
            }
            applyInstalls(token);
            applySlots(token);
            showHint(!parsed.ok && parsed.reason === 'format');
        }, {signal: signal});
    }

    /**
     * @param {HTMLElement} root
     * @param {{copied: string, connection: ?Connection, base64: function(string): string,
     *     clipboard: ?{writeText: function(string): Promise<void>},
     *     schedule: function(Function, number): *, cancel: function(*): void,
     *     select: function(HTMLElement): void}} environment
     * @returns {function(): void} Teardown
     */
    function mount(root, environment) {
        var controller,
            timers = new Map();

        if (mounted.has(root)) {
            return function () {};
        }
        mounted.add(root);
        controller = new AbortController();

        /**
         * Show the copied wording on the button for a moment
         *
         * @param {HTMLElement} button
         */
        function showCopied(button) {
            var label = button.getAttribute('data-label') || button.textContent;

            button.setAttribute('data-label', label);
            button.textContent = environment.copied;
            environment.cancel(timers.get(button));
            timers.set(button, environment.schedule(function () {
                button.textContent = label;
                timers.delete(button);
            }, RESET_MS));
        }

        root.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest(BUTTON) : null,
                target = button ? root.ownerDocument.getElementById(button.getAttribute('data-copy-target')) : null;

            if (!button || !target) {
                return;
            }
            if (!environment.clipboard) {
                environment.select(target);

                return;
            }
            environment.clipboard.writeText(target.textContent).then(function () {
                showCopied(button);
            }, function () {
                environment.select(target);
            });
        }, {signal: controller.signal});

        if (environment.connection) {
            bindToken(root, environment.connection, environment.base64, controller.signal);
        }

        return function () {
            controller.abort();
            timers.forEach(function (timer) {
                environment.cancel(timer);
            });
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
     * The connection the page printed, or null when it is missing or not the expected shape
     *
     * @param {*} value
     * @returns {?Connection}
     */
    function parseConnection(value) {
        if (!value || typeof value !== 'object'
            || !isFilled(value.name) || !isFilled(value.url) || !isFilled(value.placeholder)) {
            return null;
        }

        return Object.freeze({name: value.name, url: value.url, placeholder: value.placeholder});
    }

    /**
     * Base64 of the text's UTF-8 bytes
     *
     * `btoa` alone accepts only characters up to U+00FF, and an endpoint path may hold others.
     *
     * @param {Window} view
     * @returns {function(string): string}
     */
    function utf8Base64(view) {
        return function (text) {
            var bytes = new view.TextEncoder().encode(text),
                binary = '',
                index;

            for (index = 0; index < bytes.length; index++) {
                binary += String.fromCharCode(bytes[index]);
            }

            return view.btoa(binary);
        };
    }

    /**
     * The x-magento-init entry
     *
     * @param {{copied: string, connection: *}} config
     * @param {HTMLElement} element
     */
    function entry(config, element) {
        var view = element.ownerDocument.defaultView;

        mount(element, {
            copied: config && typeof config.copied === 'string' ? config.copied : '',
            connection: parseConnection(config ? config.connection : null),
            base64: utf8Base64(view),
            clipboard: view.navigator.clipboard || null,
            schedule: view.setTimeout.bind(view),
            cancel: view.clearTimeout.bind(view),
            select: function (target) {
                view.getSelection().selectAllChildren(target);
            }
        });
    }

    entry.mount = mount;

    return entry;
});
