/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

/**
 * A client's built-in Node runtime: it runs an extension's entry point inside a process of its own
 *
 * As such a runtime does, it imports the entry point instead of starting it, so the entry point is never the main
 * module; it hands standard input over through a stream of its own, which never ends; and it stays alive until it
 * is stopped. Started as `node import-host.cjs <entry point>`, with the lines to hand over on standard input.
 */
const {Readable} = require('node:stream');
const {pathToFileURL} = require('node:url');

const received = new Readable({read() {}});
process.stdin.on('data', (chunk) => received.push(chunk));
Object.defineProperty(process, 'stdin', {value: received});

setInterval(() => undefined, 1000);
process.on('SIGTERM', () => process.exit(0));
import(pathToFileURL(process.argv[2]).href);
