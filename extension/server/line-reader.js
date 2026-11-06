/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

/**
 * Splits text that arrives in chunks into newline-delimited lines, bounded in UTF-8 bytes
 *
 * - A line ends at `\n`; a `\r` before it is dropped, so CRLF input reads the same as LF input.
 * - A line that is empty, or holds only whitespace, is skipped.
 * - A line over `maxBytes` bytes (counted in UTF-8, the way the store counts a body) is never delivered and never
 *   kept: `onOversize()` is called once, and everything up to the next `\n` is discarded as it arrives, so an
 *   endless line cannot grow the buffer. The line after it is delivered intact.
 * - `end()` delivers a last line that had no `\n`.
 *
 * @param {{maxBytes: number, onLine: function(string): void, onOversize: function(): void}} options
 * @returns {{push: function(string): void, end: function(): void}}
 */
function createLineReader({maxBytes, onLine, onOversize}) {
    let pending = '';
    let pendingBytes = 0;
    let discarding = false;

    /**
     * Hand a complete line on, or report it as too large
     *
     * @param {string} raw The line without its `\n`
     * @returns {void}
     */
    function finish(raw) {
        const line = raw.endsWith('\r') ? raw.slice(0, -1) : raw;
        if (line.trim() === '') {
            return;
        }

        if (Buffer.byteLength(line, 'utf8') > maxBytes) {
            onOversize();

            return;
        }

        onLine(line);
    }

    /**
     * Keep the start of a line that has not ended yet, unless it is already too large
     *
     * @param {string} part
     * @returns {void}
     */
    function hold(part) {
        if (discarding || part === '') {
            return;
        }

        pending += part;
        pendingBytes += Buffer.byteLength(part, 'utf8');
        // One byte of room for the `\r` a CRLF line still drops once its `\n` arrives.
        if (pendingBytes > maxBytes + 1) {
            pending = '';
            pendingBytes = 0;
            discarding = true;
            onOversize();
        }
    }

    return {
        push(chunk) {
            let start = 0;
            let newline = chunk.indexOf('\n', start);
            while (newline !== -1) {
                const part = chunk.slice(start, newline);
                if (discarding) {
                    discarding = false;
                } else {
                    finish(pending + part);
                }
                pending = '';
                pendingBytes = 0;
                start = newline + 1;
                newline = chunk.indexOf('\n', start);
            }

            hold(chunk.slice(start));
        },

        end() {
            if (!discarding && pending !== '') {
                finish(pending);
            }
            pending = '';
            pendingBytes = 0;
            discarding = false;
        }
    };
}

module.exports = {createLineReader};
