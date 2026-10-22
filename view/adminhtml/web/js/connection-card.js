/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The copy buttons of the MCP Server page's connection card
 *
 * A button `[data-role="mcp-copy"]` copies the text of the element its `data-copy-target` names, then reads the
 * "copied" wording the page sends for a moment and goes back to its own. When the browser refuses the clipboard, the
 * text is selected instead so the admin can copy it by hand. Choosing a client and a transport needs no script: the
 * card's radio buttons show the matching configuration.
 *
 * Mounted through x-magento-init with `{copied}`; `mount(root, environment)` is the same with the browser's
 * clipboard, timers and selection passed in, and returns a teardown.
 */
define([], function () {
    'use strict';

    var BUTTON = '[data-role="mcp-copy"]',
        RESET_MS = 1500,
        mounted = new WeakSet();

    /**
     * @param {HTMLElement} root
     * @param {{copied: string, clipboard: ?{writeText: function(string): Promise<void>},
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

        return function () {
            controller.abort();
            timers.forEach(function (timer) {
                environment.cancel(timer);
            });
            mounted.delete(root);
        };
    }

    /**
     * The x-magento-init entry
     *
     * @param {{copied: string}} config
     * @param {HTMLElement} element
     */
    function entry(config, element) {
        var view = element.ownerDocument.defaultView;

        mount(element, {
            copied: config && typeof config.copied === 'string' ? config.copied : '',
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
