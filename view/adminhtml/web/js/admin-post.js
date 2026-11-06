/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Posts form fields to one of the MCP Server page's admin endpoints and reads the JSON it answers
 *
 * One POST per call, form-encoded, marked as an AJAX request with an `X-Requested-With` header and an `isAjax` field
 * in the body. Magento's backend reads that field from the body when the admin session has expired, and answers JSON
 * (`ajaxExpired`) instead of the login page. A bad form key is answered with JSON only when `isAjax` is in the query
 * string; with it in the body, as here, a bad form key ends in a redirect that fetch follows to an HTML page with
 * status 200, whose body is not JSON. The reply is one of three:
 * - `answered`: the status, and the body parsed as JSON — `json` is `undefined` when the body is not JSON;
 * - `failed`: no answer before the deadline, a network error, a response that could not be read, or a deadline that
 *   could not be set up (a browser without `AbortSignal.timeout`);
 * - `aborted`: the caller gave up; its abort wins over any other outcome, so nothing reaches a caller that left.
 *
 * The post function never throws: every failure resolves. Nothing is retried, and what the body means is the
 * caller's to judge. `fetch` and `AbortSignal` are passed in; the one global the module reads is `AbortController`,
 * and only on a browser without `AbortSignal.any`, to combine the caller's signal with the deadline.
 */
define([], function () {
    'use strict';

    var DEFAULT_TIMEOUT_MS = 10000,
        FAILED = Object.freeze({kind: 'failed'}),
        ABORTED = Object.freeze({kind: 'aborted'});

    /**
     * @typedef {{kind: 'answered', status: number, json: *}|{kind: 'failed'}|{kind: 'aborted'}} Reply
     * @typedef {{timeout: function(number): AbortSignal, any: (function(AbortSignal[]): AbortSignal|undefined)}}
     *     SignalFactory
     */

    /**
     * An `application/x-www-form-urlencoded` body
     *
     * @param {Array<Array<string>>} fields Name and value pairs
     * @returns {string}
     */
    function formBody(fields) {
        return fields.map(function (field) {
            return encodeURIComponent(field[0]) + '=' + encodeURIComponent(field[1]);
        }).join('&');
    }

    /**
     * One signal that aborts when the caller's does or when the deadline passes
     *
     * Without `AbortSignal.any` a local controller is aborted from both; `release` removes those listeners once the
     * request has settled, so a long-lived caller's signal does not collect one listener per request.
     *
     * @param {SignalFactory} signals
     * @param {?AbortSignal} caller
     * @param {AbortSignal} deadline
     * @returns {{signal: AbortSignal, release: function(): void}}
     */
    function combine(signals, caller, deadline) {
        var local,
            sources;

        if (!caller) {
            return {signal: deadline, release: function () {}};
        }
        if (typeof signals.any === 'function') {
            return {signal: signals.any([caller, deadline]), release: function () {}};
        }

        local = new AbortController();
        sources = [caller, deadline];

        /**
         * @param {Event} event
         */
        function relay(event) {
            local.abort(event && event.target ? event.target.reason : undefined);
        }

        /**
         * Stop listening to both sources
         */
        function release() {
            sources.forEach(function (source) {
                source.removeEventListener('abort', relay);
            });
        }

        sources.forEach(function (source) {
            if (source.aborted) {
                local.abort(source.reason);
            } else {
                source.addEventListener('abort', relay, {once: true});
            }
        });

        return {signal: local.signal, release: release};
    }

    /**
     * The status and the body read as JSON; a body that is not JSON leaves `json` undefined
     *
     * @param {Response} response
     * @returns {Promise<Reply>}
     */
    function fromResponse(response) {
        var status = response.status;

        if (typeof status !== 'number') {
            return Promise.resolve(FAILED);
        }

        return Promise.resolve().then(function () {
            return response.json();
        }).then(function (json) {
            return Object.freeze({kind: 'answered', status: status, json: json});
        }, function () {
            return Object.freeze({kind: 'answered', status: status, json: undefined});
        });
    }

    /**
     * @param {*} value
     * @returns {boolean}
     */
    function isPositiveNumber(value) {
        return typeof value === 'number' && isFinite(value) && value > 0;
    }

    /**
     * Build the post function for one page
     *
     * @param {{fetch: function(string, Object): Promise<Response>, AbortSignal: SignalFactory,
     *     timeoutMs: (number|undefined)}} options
     * @returns {function(string, Array<Array<string>>, ?AbortSignal): Promise<Reply>}
     */
    function create(options) {
        var fetchFn = options.fetch,
            signals = options.AbortSignal,
            timeoutMs = isPositiveNumber(options.timeoutMs) ? options.timeoutMs : DEFAULT_TIMEOUT_MS;

        /**
         * Post the fields; the promise always resolves, and nothing here throws: setting up the deadline happens
         * inside the promise, so a browser that cannot set one gets `failed`
         *
         * @param {string} url
         * @param {Array<Array<string>>} fields Name and value pairs, the form key among them
         * @param {?AbortSignal} signal Aborted by the caller when the answer is no longer wanted
         * @returns {Promise<Reply>}
         */
        return function post(url, fields, signal) {
            var caller = signal || null,
                combined = null;

            /**
             * @param {Reply} reply
             * @returns {Reply}
             */
            function settle(reply) {
                if (combined) {
                    combined.release();
                }

                return caller && caller.aborted ? ABORTED : reply;
            }

            return new Promise(function (resolve) {
                combined = combine(signals, caller, signals.timeout(timeoutMs));
                resolve(fetchFn(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formBody(fields.concat([['isAjax', 'true']])),
                    signal: combined.signal
                }));
            }).then(fromResponse).then(settle, function () {
                return settle(FAILED);
            });
        };
    }

    return Object.freeze({create: create});
});
