/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const {createBridge} = require('./bridge');
const {createHttpTransport} = require('./http-transport');
const {createLineReader} = require('./line-reader');
const {readSettings} = require('./settings');

const MAX_LINE_BYTES = 1048576;
const MESSAGE_TIMEOUT_MS = 300000;

/**
 * Wire the bridge to this process: standard input in, standard output out, diagnostics to standard error
 *
 * This file is the extension's entry point, so running it starts the bridge, whether Node starts it as the main
 * module or a client's built-in runtime imports it. Everything that can be used on its own lives in the other files.
 *
 * Refused settings end the process with the code 1 once the reason is written: a runtime that imports this file
 * keeps its process alive, and the client would wait for an answer that never comes. In every other case the exit
 * code is set and the process ends once nothing is left to do, because a pipe write is asynchronous on some systems
 * and exiting right after the last write could cut it off.
 *
 * @returns {void}
 */
function main() {
    const settings = readSettings(process.env);
    if (!settings.ok) {
        process.stderr.write(`${settings.message}\n`, () => process.exit(1));

        return;
    }

    const log = (text) => {
        process.stderr.write(`[magento-mcp] ${text}\n`);
    };
    const bridge = createBridge({
        transport: createHttpTransport({
            url: settings.url,
            token: settings.token,
            fetch: globalThis.fetch,
            timeout: (milliseconds) => AbortSignal.timeout(milliseconds),
            timeoutMs: MESSAGE_TIMEOUT_MS,
            log
        }),
        write: (text) => {
            process.stdout.write(`${text}\n`);
        },
        log,
        host: settings.host
    });
    // handle() never rejects; the bridge keeps track of each line until it is answered.
    const reader = createLineReader({
        maxBytes: MAX_LINE_BYTES,
        onLine: (line) => bridge.handle(line),
        onOversize: () => bridge.rejectOversize()
    });

    const stop = () => {
        process.stdin.destroy();
        bridge.abort().then(() => {
            process.exitCode = 0;
        });
    };
    process.once('SIGTERM', stop);
    process.once('SIGINT', stop);

    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => reader.push(chunk));
    process.stdin.on('end', () => {
        reader.end();
        bridge.close().then(() => {
            process.exitCode = 0;
        });
    });
}

main();
