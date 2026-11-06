/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {createLineReader} = require('../../../extension/server/line-reader');

/**
 * A reader with a small limit that records what it delivers and how often it reported a line as too large
 *
 * @param {number} maxBytes
 * @returns {{reader: {push: function(string): void, end: function(): void}, lines: string[], oversize: number[]}}
 */
function recording(maxBytes = 16) {
    const lines = [];
    const oversize = [0];
    const reader = createLineReader({
        maxBytes,
        onLine: (line) => lines.push(line),
        onOversize: () => {
            oversize[0] += 1;
        }
    });

    return {reader, lines, oversize};
}

test('a line split across chunks is delivered once it ends', () => {
    const {reader, lines} = recording();
    reader.push('{"a"');
    reader.push(':1}\n{"b":');
    assert.deepEqual(lines, ['{"a":1}']);
    reader.push('2}\n');
    assert.deepEqual(lines, ['{"a":1}', '{"b":2}']);
});

test('a carriage return before the newline is dropped', () => {
    const {reader, lines} = recording();
    reader.push('{"a":1}\r\n{"b":2}\r');
    reader.push('\n');
    assert.deepEqual(lines, ['{"a":1}', '{"b":2}']);
});

test('empty and blank lines are skipped', () => {
    const {reader, lines} = recording();
    reader.push('\n\r\n   \n{"a":1}\n\n');
    assert.deepEqual(lines, ['{"a":1}']);
});

test('a line over the limit is dropped and reported once, however many chunks it spans', () => {
    const {reader, lines, oversize} = recording(16);
    reader.push('x'.repeat(10));
    reader.push('x'.repeat(10));
    reader.push('x'.repeat(10));
    reader.push('x'.repeat(10) + '\n');
    assert.deepEqual(lines, []);
    assert.equal(oversize[0], 1);
});

test('a line over the limit in one chunk is reported once', () => {
    const {reader, lines, oversize} = recording(16);
    reader.push('x'.repeat(40) + '\n');
    assert.deepEqual(lines, []);
    assert.equal(oversize[0], 1);
});

test('the limit counts UTF-8 bytes, not characters', () => {
    const {reader, lines, oversize} = recording(16);
    // Nine two-byte characters: nine characters, eighteen bytes.
    reader.push('é'.repeat(9) + '\n');
    assert.deepEqual(lines, []);
    assert.equal(oversize[0], 1);
});

test('a line of exactly the limit is delivered, with or without a carriage return', () => {
    const {reader, lines, oversize} = recording(16);
    reader.push('x'.repeat(16) + '\r\n' + 'y'.repeat(16) + '\n');
    assert.deepEqual(lines, ['x'.repeat(16), 'y'.repeat(16)]);
    assert.equal(oversize[0], 0);
});

test('the line after a dropped one is delivered intact', () => {
    const {reader, lines, oversize} = recording(16);
    reader.push('x'.repeat(30));
    reader.push('x'.repeat(30) + '\n{"next":1}\n');
    assert.deepEqual(lines, ['{"next":1}']);
    assert.equal(oversize[0], 1);
});

test('a last line without a newline is delivered at the end', () => {
    const {reader, lines} = recording();
    reader.push('{"a":1}\n{"b":2}');
    assert.deepEqual(lines, ['{"a":1}']);
    reader.end();
    assert.deepEqual(lines, ['{"a":1}', '{"b":2}']);
});

test('an unfinished line over the limit is not delivered at the end', () => {
    const {reader, lines, oversize} = recording(16);
    reader.push('x'.repeat(30));
    reader.end();
    assert.deepEqual(lines, []);
    assert.equal(oversize[0], 1);
});
