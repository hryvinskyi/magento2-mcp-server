/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const access = load('Hryvinskyi_McpServer/js/access-token');

test('a token68 token is accepted as it is', () => {
    assert.deepEqual({...access.parse('aB3-._~+/=')}, {ok: true, token: 'aB3-._~+/='});
});

test('whitespace around a pasted token is dropped', () => {
    assert.deepEqual({...access.parse(' \tabc123\n ')}, {ok: true, token: 'abc123'});
});

test('an empty or blank value is empty, not a format error', () => {
    assert.deepEqual({...access.parse('')}, {ok: false, reason: 'empty'});
    assert.deepEqual({...access.parse('   \n')}, {ok: false, reason: 'empty'});
});

test('a value that is not a string reads as empty', () => {
    assert.deepEqual({...access.parse(undefined)}, {ok: false, reason: 'empty'});
});

for (const [label, text] of [
    ['a space', 'abc def'],
    ['a double quote', 'abc"def'],
    ['a single quote', "abc'def"],
    ['a dollar sign', 'abc$def'],
    ['a newline inside', 'abc\ndef'],
    ['a backtick', 'abc`def']
]) {
    test(`a token with ${label} is refused as a format error`, () => {
        assert.deepEqual({...access.parse(text)}, {ok: false, reason: 'format'});
    });
}

test('fill replaces every occurrence of the placeholder', () => {
    assert.equal(
        access.fill('Bearer <integration-token>, again <integration-token>.', '<integration-token>', 'abc'),
        'Bearer abc, again abc.'
    );
});

test('fill takes the placeholder literally, not as a pattern', () => {
    assert.equal(access.fill('a.b a+b', '.', 'X'), 'aXb a+b');
    assert.equal(access.fill('cost: $&', '$&', 'abc'), 'cost: abc');
});

test('fill leaves a template without the placeholder, or with an empty placeholder, unchanged', () => {
    assert.equal(access.fill('no slot here', '<integration-token>', 'abc'), 'no slot here');
    assert.equal(access.fill('no slot here', '', 'abc'), 'no slot here');
});
