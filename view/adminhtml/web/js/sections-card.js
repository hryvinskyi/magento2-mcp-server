/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The MCP Server page's sections card: a section's switch changes it without reloading the page
 *
 * Each row `[data-role="mcp-section"][data-section="{code}"]` holds a form `[data-role="mcp-section-switch"]` that
 * posts the section's code and the form key to the switch endpoint; without this script the form posts as usual and
 * the page reloads. With it, the form's submit is sent in the background, and the answer — the state of every
 * section after the switch — is drawn onto the rows on the spot: the muted row, the On/Off badge
 * `[data-role="mcp-section-state"]`, the exposed count `[data-role="mcp-section-exposed"]`, the switch
 * `[data-role="mcp-section-toggle"]` (its `aria-checked` and its text) and the form's action, which points at the
 * opposite switch. Every row in the answer is redrawn, because an ability can sit in more than one section; a row
 * the answer does not list is removed from the card, as the reload after a plain form post would drop it (a section
 * switched off whose module is gone is listed only while it stays off). The page's total of abilities a client may
 * call, `[data-role="mcp-exposed-total"]` in the overview outside the card, is redrawn from the answer's total, and
 * the ability catalog below is reloaded, since its exposure column follows the sections.
 *
 * `[data-role="mcp-sections-status"]` (a notice with `role="status"`) shows the endpoint's message, or its refusal,
 * or the failed wording; a refused or failed switch changes nothing on the rows. One switch runs at a time: a submit
 * while another is under way is dropped, and the busy switch carries `aria-busy`.
 *
 * Mounted through x-magento-init on the card with `{onUrl, offUrl, catalogSource, wording: {on, off, switchOn,
 * switchOff, failed}}`; `mount(root, environment)` is the same with the switch function, the total's drawer and the
 * catalog reload passed in, and returns a teardown that also aborts a switch in flight.
 */
define([
    'Hryvinskyi_McpServer/js/section-switch'
], function (sectionSwitch) {
    'use strict';

    var ROW = '[data-role="mcp-section"]',
        FORM = '[data-role="mcp-section-switch"]',
        FIELD = '[name]',
        TOGGLE = '[data-role="mcp-section-toggle"]',
        TOGGLE_TEXT = '[data-role="mcp-section-toggle-text"]',
        STATE = '[data-role="mcp-section-state"]',
        STATE_TEXT = '[data-role="mcp-section-state-text"]',
        EXPOSED = '[data-role="mcp-section-exposed"]',
        STATUS = '[data-role="mcp-sections-status"]',
        STATUS_TEXT = '[data-role="mcp-sections-status-text"]',
        TOTAL = '[data-role="mcp-exposed-total"]',
        ROW_MUTED = 'hui-list__row--muted',
        BADGE_ON = 'hui-badge--on',
        BADGE_OFF = 'hui-badge--off',
        WORDING_KEYS = Object.freeze(['on', 'off', 'switchOn', 'switchOff', 'failed']),
        SWITCH_TIMEOUT_MS = 30000,
        mounted = new WeakSet();

    /**
     * @typedef {{code: string, off: boolean, exposed: number}} SectionState
     * @typedef {{ok: true, message: string, exposed: number, sections: SectionState[]}
     *     |{ok: false, kind: 'refused', message: string}|{ok: false, kind: ('failed'|'aborted')}} SwitchResult
     * @typedef {{on: string, off: string, switchOn: string, switchOff: string, failed: string}} Wording
     * @typedef {{switchSection: function(string, Array<Array<string>>, AbortSignal): Promise<SwitchResult>,
     *     drawTotal: function(number): void, reloadCatalog: function(): void, onUrl: string, offUrl: string,
     *     wording: Wording}} Environment
     */

    /**
     * @param {HTMLElement} root
     * @param {Environment} environment
     * @returns {function(): void} Teardown
     */
    function mount(root, environment) {
        var status = root.querySelector(STATUS),
            statusText = status ? status.querySelector(STATUS_TEXT) : null,
            wording = environment.wording,
            controller = null,

            /**
             * What the card shows for each kind of answer that switched nothing
             */
            unswitched = Object.freeze({
                refused: function (result) {
                    showStatus('error', result.message);
                },

                failed: function () {
                    showStatus('error', wording.failed);
                },

                aborted: function () {}
            });

        /**
         * @param {string} tone A tone of the kit's notice
         * @param {string} text
         */
        function showStatus(tone, text) {
            if (!status || !statusText) {
                return;
            }
            statusText.textContent = text;
            status.setAttribute('data-tone', tone);
            status.removeAttribute('hidden');
        }

        /**
         * Hide the status notice
         */
        function hideStatus() {
            if (status) {
                status.setAttribute('hidden', '');
            }
        }

        /**
         * The row of a section, or null when the card has none
         *
         * @param {string} code
         * @returns {?HTMLElement}
         */
        function rowOf(code) {
            var rows = Array.prototype.slice.call(root.querySelectorAll(ROW));

            return rows.filter(function (row) {
                return row.getAttribute('data-section') === code;
            })[0] || null;
        }

        /**
         * @param {?HTMLElement} element
         * @param {string} text
         */
        function setText(element, text) {
            if (element) {
                element.textContent = text;
            }
        }

        /**
         * Draw a section's state onto its row
         *
         * @param {SectionState} state
         */
        function draw(state) {
            var row = rowOf(state.code),
                badge,
                form,
                toggle;

            if (!row) {
                return;
            }
            badge = row.querySelector(STATE);
            form = row.querySelector(FORM);
            toggle = row.querySelector(TOGGLE);

            row.classList.toggle(ROW_MUTED, state.off);
            setText(row.querySelector(EXPOSED), String(state.exposed));
            if (badge) {
                badge.classList.toggle(BADGE_ON, !state.off);
                badge.classList.toggle(BADGE_OFF, state.off);
                setText(badge.querySelector(STATE_TEXT), state.off ? wording.off : wording.on);
            }
            if (form) {
                form.setAttribute('action', state.off ? environment.onUrl : environment.offUrl);
            }
            if (toggle) {
                toggle.setAttribute('aria-checked', state.off ? 'false' : 'true');
                setText(toggle.querySelector(TOGGLE_TEXT), state.off ? wording.switchOn : wording.switchOff);
            }
        }

        /**
         * Remove every row whose section the answer does not list
         *
         * @param {SectionState[]} sections
         * @param {?HTMLElement} submitting The row whose switch was pressed
         * @returns {boolean} Whether that row was removed
         */
        function dropUnlisted(sections, submitting) {
            var listed = sections.map(function (state) {
                    return state.code;
                }),
                droppedSubmitting = false;

            Array.prototype.slice.call(root.querySelectorAll(ROW)).forEach(function (row) {
                if (listed.indexOf(row.getAttribute('data-section')) === -1) {
                    droppedSubmitting = droppedSubmitting || row === submitting;
                    row.remove();
                }
            });

            return droppedSubmitting;
        }

        /**
         * The form's fields as name and value pairs
         *
         * @param {HTMLFormElement} form
         * @returns {Array<Array<string>>}
         */
        function fieldsOf(form) {
            return Array.prototype.slice.call(form.querySelectorAll(FIELD)).map(function (field) {
                return [field.getAttribute('name'), String(field.value)];
            });
        }

        /**
         * @param {Event} event
         */
        function onSubmit(event) {
            var form = event.target && typeof event.target.closest === 'function'
                    ? event.target.closest(FORM)
                    : null,
                toggle,
                current,
                lostFocus;

            if (!form || !root.contains(form)) {
                return;
            }
            event.preventDefault();
            if (controller) {
                return;
            }

            toggle = form.querySelector(TOGGLE);
            current = new AbortController();
            controller = current;
            hideStatus();
            if (toggle) {
                toggle.setAttribute('aria-busy', 'true');
            }

            environment.switchSection(form.getAttribute('action'), fieldsOf(form), current.signal)
                .then(function (result) {
                    if (controller !== current) {
                        return;
                    }
                    controller = null;
                    if (toggle) {
                        toggle.removeAttribute('aria-busy');
                    }
                    if (!result.ok) {
                        unswitched[result.kind](result);

                        return;
                    }
                    lostFocus = dropUnlisted(result.sections, form.closest(ROW));
                    result.sections.forEach(draw);
                    environment.drawTotal(result.exposed);
                    showStatus('success', result.message);
                    // The pressed switch left with its row: the notice takes the focus, so a keyboard user keeps
                    // their place on the card.
                    if (lostFocus && status && typeof status.focus === 'function') {
                        status.focus();
                    }
                    environment.reloadCatalog();
                });
        }

        if (mounted.has(root)) {
            return function () {};
        }
        mounted.add(root);
        root.addEventListener('submit', onSubmit);

        return function teardown() {
            root.removeEventListener('submit', onSubmit);
            mounted.delete(root);
            if (controller) {
                controller.abort();
                controller = null;
            }
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
     * The wording, or null when a phrase is missing
     *
     * @param {*} value
     * @returns {?Wording}
     */
    function parseWording(value) {
        var wording = {};

        if (!value || typeof value !== 'object') {
            return null;
        }

        return WORDING_KEYS.every(function (key) {
            wording[key] = value[key];

            return isFilled(value[key]);
        }) ? Object.freeze(wording) : null;
    }

    /**
     * The configuration, or null when it is not the expected shape
     *
     * @param {*} config
     * @returns {?{onUrl: string, offUrl: string, catalogSource: string, wording: Wording}}
     */
    function parseConfig(config) {
        var wording;

        if (!config || typeof config !== 'object' || !isFilled(config.onUrl) || !isFilled(config.offUrl)) {
            return null;
        }
        wording = parseWording(config.wording);

        return wording ? Object.freeze({
            onUrl: config.onUrl,
            offUrl: config.offUrl,
            catalogSource: isFilled(config.catalogSource) ? config.catalogSource : '',
            wording: wording
        }) : null;
    }

    /**
     * Reload the ability catalog's data through the page's component registry; without one, nothing happens
     *
     * @param {Window} view
     * @param {string} source The listing's data source name
     * @returns {function(): void}
     */
    function catalogReloader(view, source) {
        return function reloadCatalog() {
            if (source === '' || typeof view.require !== 'function') {
                return;
            }
            view.require(['uiRegistry'], function (registry) {
                registry.get(source, function (provider) {
                    if (provider && typeof provider.reload === 'function') {
                        provider.reload({refresh: true});
                    }
                });
            });
        };
    }

    /**
     * Draw the page's total of abilities a client may call into its element; without one, nothing happens
     *
     * @param {Document} document
     * @returns {function(number): void}
     */
    function totalDrawer(document) {
        var total = document.querySelector(TOTAL);

        if (!total) {
            return function () {};
        }

        return function drawTotal(count) {
            total.textContent = String(count);
        };
    }

    /**
     * The x-magento-init entry; a configuration that is not the expected shape mounts nothing, and the forms post
     * as they would without a script
     *
     * @param {*} config
     * @param {HTMLElement} element
     */
    function entry(config, element) {
        var parsed = parseConfig(config),
            view = element.ownerDocument.defaultView;

        // Without a deadline the background switch could never finish; the forms then post as they would without a
        // script, which works in any browser.
        if (!parsed || !view.AbortSignal || typeof view.AbortSignal.timeout !== 'function') {
            return;
        }

        mount(element, {
            // A switch re-evaluates the exposure of every ability after it writes, which takes far longer than the
            // other posts of the page, so it is given thirty seconds instead of the default ten.
            switchSection: sectionSwitch.create({
                fetch: view.fetch.bind(view),
                AbortSignal: view.AbortSignal,
                timeoutMs: SWITCH_TIMEOUT_MS
            }),
            drawTotal: totalDrawer(element.ownerDocument),
            reloadCatalog: catalogReloader(view, parsed.catalogSource),
            onUrl: parsed.onUrl,
            offUrl: parsed.offUrl,
            wording: parsed.wording
        });
    }

    entry.mount = mount;

    return entry;
});
