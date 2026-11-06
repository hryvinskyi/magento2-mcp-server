/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const {messages} = require('./messages');

const INITIALIZE = 'initialize';
const INITIALIZED = 'notifications/initialized';
const LOST_STATUS = 404;
const FAILURE_CODE = -32000;
const INVALID_REQUEST_CODE = -32600;
const PARSE_ERROR = '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}';
const END_ON_ABORT_MS = 2000;
const NO_SESSION = Object.freeze({sessionId: '', protocolVersion: ''});

/**
 * Relays the JSON-RPC lines of an MCP client to the store's endpoint and writes the answers back as lines
 *
 * Sessions:
 * - A line that is a single `initialize` request always goes without a session, so a client that initializes again
 *   opens a new session. When its answer carries a session id, that session, the protocol version of the answer and
 *   the line itself are kept; an `initialize` answered without a session (an error) changes nothing. A
 *   `notifications/initialized` line accepted in that session is kept too.
 * - Every other line goes with the session kept last.
 * - A lost session (an answer with HTTP 404 to a line sent in a session) is opened again once: the kept `initialize`
 *   goes again without a session, its session and protocol version are adopted, the kept `notifications/initialized`
 *   is sent in it, and the line is sent once more. The answers to those two replayed lines are never written: the
 *   client already has its answer to that `initialize`. The line's own 404 answer is written instead when no
 *   `initialize` was kept, when the replay opens no session, or when the line finds the new session lost as well.
 *   Lines that meet the same loss together share one replay and are each sent once more; lines arriving while it
 *   runs wait for it.
 * - Nothing else is ever sent twice: a `tools/call` may write.
 *
 * Ordering: until a session is open, lines go out one at a time, so the session `initialize` opens is known before
 * the next line is sent. Once a session is open, each line goes as soon as it arrives and its answer is written when
 * it comes back, so a slow call blocks nothing; JSON-RPC ids tell the answers apart. A single `initialize` line waits
 * for every line in flight, and lines arriving after it wait for its answer.
 *
 * Answers:
 * - An answer is written as one line of compact JSON, whatever the store's formatting.
 * - The store refuses a whole body with a single JSON-RPC 2.0 error whose id is null (a lost session, a bad request,
 *   a disabled server): its `code` is an integer, its `message` a string, its `data` absent or a JSON object. The
 *   client would wait for its own ids forever, so such a refusal is re-addressed to the line's requests, keeping the
 *   store's code, message and data: a single request gets one error with its id, a batch gets one line holding the
 *   array of those errors, and a line without requests (only notifications or responses) gets nothing. Any other
 *   error with the id null, and any answer to a line that holds no JSON-RPC message, is written as it came.
 * - A failure without a JSON-RPC answer (refused token, redirect, timeout, no connection, …) is answered the same
 *   way, an error per request of the line (one array for a batch) with the failure's wording and
 *   `data: {reason, status}`, and logged.
 *
 * `close()` waits for every line in flight, then ends the session. `abort()` cancels every line in flight, writes
 * nothing more, and ends the session with a short deadline. The session is ended once, whichever comes first.
 *
 * @param {{
 *     transport: {
 *         post: function(string, {sessionId: string, protocolVersion: string}, AbortSignal): Promise<Object>,
 *         end: function(string, string, number=): Promise<void>
 *     },
 *     write: function(string): void,
 *     log: function(string): void,
 *     host?: string
 * }} options `host` is the store's host, named when it cannot be reached
 * @returns {{
 *     handle: function(string): Promise<void>,
 *     rejectOversize: function(): void,
 *     close: function(): Promise<void>,
 *     abort: function(): Promise<void>
 * }}
 */
function createBridge({transport, write, log, host = ''}) {
    let session = NO_SESSION;
    let initializeLine = '';
    let initializedLine = '';
    let replay = null;
    let replaysFinished = 0;
    let initializing = 0;
    let chain = Promise.resolve();
    let ended = null;
    const inFlight = new Set();
    const cancellation = new AbortController();

    const deliveries = Object.freeze({
        answer: writeAnswer,
        accepted: () => undefined,
        failed: writeFailure
    });

    /**
     * Write the store's JSON-RPC answer, re-addressing a whole-body refusal to the line's requests
     *
     * @param {{text: string}} outcome
     * @param {LineShape} shape
     * @returns {void}
     */
    function writeAnswer(outcome, shape) {
        const body = JSON.parse(outcome.text);
        if (!isWholeBodyRefusal(body) || !shape.holdsMessages) {
            write(JSON.stringify(body));

            return;
        }

        const error = {code: body.error.code, message: body.error.message};
        if ('data' in body.error) {
            error.data = body.error.data;
        }
        writeErrors(error, shape);
    }

    /**
     * Log a failure and answer each request of the line with its wording
     *
     * @param {{reason: string, status?: number, location?: string, detail?: string}} outcome
     * @param {LineShape} shape
     * @returns {void}
     */
    function writeFailure(outcome, shape) {
        if (outcome.reason === 'aborted') {
            return;
        }

        const status = outcome.status === undefined ? '' : ` (HTTP ${outcome.status})`;
        const detail = outcome.detail === undefined ? '' : ` (${outcome.detail})`;
        log(`The store request failed: ${outcome.reason}${status}${detail}.`);

        const message = messages[outcome.reason]({host, status: outcome.status, location: outcome.location});
        const data = outcome.status === undefined
            ? {reason: outcome.reason}
            : {reason: outcome.reason, status: outcome.status};
        writeErrors({code: FAILURE_CODE, message, data}, shape);
    }

    /**
     * Write this error addressed to each request of the line: one object for a single request, one array for a
     * batch, nothing when the line holds no request
     *
     * @param {{code: number, message: string, data?: Object}} error
     * @param {LineShape} shape
     * @returns {void}
     */
    function writeErrors(error, shape) {
        if (shape.ids.length === 0) {
            return;
        }

        const errors = shape.ids.map((id) => ({jsonrpc: '2.0', id, error}));
        write(JSON.stringify(shape.batch ? errors : errors[0]));
    }

    /**
     * Write what answers the line, unless the bridge is going away
     *
     * @param {Object} outcome
     * @param {LineShape} shape
     * @returns {void}
     */
    function deliver(outcome, shape) {
        if (!cancellation.signal.aborted) {
            deliveries[outcome.kind](outcome, shape);
        }
    }

    /**
     * Send an `initialize` line without a session and keep the session its answer opens
     *
     * @param {string} line
     * @param {LineShape} shape
     * @returns {Promise<void>}
     */
    async function initialize(line, shape) {
        const outcome = await transport.post(line, NO_SESSION, cancellation.signal);
        if (outcome.kind === 'answer' && outcome.sessionId !== '') {
            session = {sessionId: outcome.sessionId, protocolVersion: protocolVersionOf(outcome.text)};
            initializeLine = line;
            initializedLine = '';
        }

        deliver(outcome, shape);
    }

    /**
     * Send any other line in the current session, recovering a lost session once
     *
     * @param {string} line
     * @param {LineShape} shape
     * @returns {Promise<void>}
     */
    async function relay(line, shape) {
        while (replay !== null) {
            await replay;
        }

        const sent = session;
        const replaysBefore = replaysFinished;
        let outcome = await transport.post(line, sent, cancellation.signal);
        if (isLost(outcome, sent)) {
            outcome = await recover(line, outcome, sent, replaysBefore);
        }

        if (shape.confirms && session.sessionId !== '' && outcome.kind === 'accepted') {
            initializedLine = line;
        }

        deliver(outcome, shape);
    }

    /**
     * Open the lost session again, or join the reopening already under way, and send the line once more
     *
     * @param {string} line
     * @param {Object} lost The answer that reported the loss
     * @param {{sessionId: string, protocolVersion: string}} sent The session the line was sent in
     * @param {number} replaysBefore How many replays had finished when the line was sent
     * @returns {Promise<Object>} The outcome to deliver
     */
    async function recover(line, lost, sent, replaysBefore) {
        if (initializeLine === '') {
            return lost;
        }

        let reopened;
        if (replay !== null) {
            reopened = await replay;
        } else if (replaysFinished === replaysBefore) {
            reopened = await startReplay();
        } else {
            reopened = session.sessionId !== '' && session.sessionId !== sent.sessionId;
        }

        if (!reopened) {
            log('The lost MCP session could not be opened again; the message is answered as lost.');

            return lost;
        }

        const again = session;
        const retried = await transport.post(line, again, cancellation.signal);
        if (isLost(retried, again)) {
            log('The new MCP session was lost as well; the message is answered as lost.');

            return lost;
        }

        return retried;
    }

    /**
     * Start the one replay that lines meeting this loss share
     *
     * @returns {Promise<boolean>}
     */
    function startReplay() {
        replay = reopen().finally(() => {
            replay = null;
            replaysFinished += 1;
        });

        return replay;
    }

    /**
     * Send the kept session-opening lines again; their answers are dropped
     *
     * @returns {Promise<boolean>} Whether a new session was opened and, when the client had confirmed the old one,
     *     confirmed
     */
    async function reopen() {
        const opened = await transport.post(initializeLine, NO_SESSION, cancellation.signal);
        if (opened.kind !== 'answer' || opened.sessionId === '') {
            return false;
        }

        session = {sessionId: opened.sessionId, protocolVersion: protocolVersionOf(opened.text)};
        if (initializedLine === '') {
            return true;
        }

        const confirmed = await transport.post(initializedLine, session, cancellation.signal);

        return confirmed.kind === 'accepted' || (confirmed.kind === 'answer' && confirmed.status !== LOST_STATUS);
    }

    /**
     * End the session once, when one is held
     *
     * @returns {Promise<void>}
     */
    function end() {
        if (ended === null) {
            ended = session.sessionId === ''
                ? Promise.resolve()
                : transport.end(
                    session.sessionId,
                    session.protocolVersion,
                    cancellation.signal.aborted ? END_ON_ABORT_MS : undefined
                );
        }

        return ended;
    }

    /**
     * Wait until every line in flight has been answered or has failed
     *
     * @returns {Promise<void>}
     */
    async function settle() {
        while (inFlight.size > 0) {
            await Promise.all([...inFlight]);
        }
    }

    return {
        handle(line) {
            if (cancellation.signal.aborted) {
                return Promise.resolve();
            }

            let parsed;
            try {
                parsed = JSON.parse(line);
            } catch {
                write(PARSE_ERROR);

                return Promise.resolve();
            }

            const shape = shapeOf(parsed);
            const serial = shape.opens || session.sessionId === '' || initializing > 0;
            let task;
            if (shape.opens) {
                initializing += 1;
                task = Promise.all([chain, ...inFlight])
                    .then(() => initialize(line, shape))
                    .finally(() => {
                        initializing -= 1;
                    });
            } else if (serial) {
                task = chain.then(() => relay(line, shape));
            } else {
                task = relay(line, shape);
            }

            const tracked = task.then(
                () => undefined,
                (error) => log(`An MCP message could not be relayed: ${error instanceof Error ? error.message : error}`)
            );
            if (serial) {
                chain = tracked;
            }
            inFlight.add(tracked);
            tracked.then(() => inFlight.delete(tracked));

            return tracked;
        },

        rejectOversize() {
            log('A message larger than the store accepts was dropped.');
            write(JSON.stringify({
                jsonrpc: '2.0',
                id: null,
                error: {code: INVALID_REQUEST_CODE, message: messages.oversize({}), data: {reason: 'oversize'}}
            }));
        },

        async close() {
            await settle();

            return end();
        },

        async abort() {
            cancellation.abort();
            await settle();

            return end();
        }
    };
}

/**
 * @typedef {Object} LineShape
 * @property {Array<string|number>} ids The ids of the requests the line holds, in order
 * @property {boolean} batch Whether the line is a batch (a JSON array), whose answer is an array as well
 * @property {boolean} holdsMessages Whether the line holds at least one JSON-RPC message
 * @property {boolean} opens Whether the line is a single `initialize` request
 * @property {boolean} confirms Whether the line is a single `notifications/initialized` notification
 */

/**
 * What the bridge needs to know about a parsed line
 *
 * @param {unknown} parsed
 * @returns {LineShape}
 */
function shapeOf(parsed) {
    const list = Array.isArray(parsed) ? parsed : [parsed];
    const single = Array.isArray(parsed) ? null : parsed;

    return {
        ids: list.filter(isRequest).map((message) => message.id),
        batch: Array.isArray(parsed),
        holdsMessages: list.some((message) => isRequest(message) || isNotification(message) || isResponse(message)),
        opens: isRequest(single) && single.method === INITIALIZE,
        confirms: isNotification(single) && single.method === INITIALIZED
    };
}

/**
 * @param {unknown} value
 * @returns {boolean}
 */
function isObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * @param {unknown} value
 * @returns {boolean}
 */
function isId(value) {
    return typeof value === 'string' || typeof value === 'number';
}

/**
 * @param {unknown} message
 * @returns {boolean}
 */
function isRequest(message) {
    return isObject(message) && typeof message.method === 'string' && isId(message.id);
}

/**
 * @param {unknown} message
 * @returns {boolean}
 */
function isNotification(message) {
    return isObject(message) && typeof message.method === 'string' && !('id' in message);
}

/**
 * A client's response to a request the server sent
 *
 * @param {unknown} message
 * @returns {boolean}
 */
function isResponse(message) {
    return isObject(message) && typeof message.method !== 'string' && 'id' in message
        && ('result' in message || 'error' in message);
}

/**
 * Whether a body is the store's refusal of a whole body: a single JSON-RPC 2.0 error with the id null, an integer
 * code, a string message, and data that is absent or a JSON object
 *
 * @param {unknown} body
 * @returns {boolean}
 */
function isWholeBodyRefusal(body) {
    if (!isObject(body) || body.jsonrpc !== '2.0' || !('id' in body) || body.id !== null || !isObject(body.error)) {
        return false;
    }

    const {error} = body;

    return Number.isInteger(error.code) && typeof error.message === 'string'
        && (!('data' in error) || isObject(error.data));
}

/**
 * Whether the answer reports that the session the line was sent in is gone
 *
 * @param {Object} outcome
 * @param {{sessionId: string}} sent
 * @returns {boolean}
 */
function isLost(outcome, sent) {
    return sent.sessionId !== '' && outcome.kind === 'answer' && outcome.status === LOST_STATUS;
}

/**
 * The protocol version an `initialize` answer settled on, or '' when it names none
 *
 * @param {string} text
 * @returns {string}
 */
function protocolVersionOf(text) {
    const body = JSON.parse(text);
    const version = isObject(body) && isObject(body.result) ? body.result.protocolVersion : undefined;

    return typeof version === 'string' ? version : '';
}

module.exports = {createBridge};
