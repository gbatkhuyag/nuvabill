/**
 * The messages of the terminal console's WebSocket (see ConsoleSession and terminal.js), apart from
 * xterm.js so they can be tested on their own. Binary or text frames from the server are output;
 * what the client types goes out as binary frames; JSON text frames carry {"type":"auth"} once
 * when there are credentials, and {"type":"resize"} with the terminal's size.
 */

export const SUBPROTOCOL = 'nuvabill.terminal.v1';

/** The largest size sent to the server, so a huge window never asks for an absurd terminal. */
export const MAX_COLS = 500;
export const MAX_ROWS = 200;

/**
 * The JSON control frame for a size, kept within 1 and the limits.
 */
export function resizeMessage(cols, rows) {
    const clamp = (value, max) => Math.min(Math.max(Math.floor(Number(value) || 0), 1), max);

    return JSON.stringify({ type: 'resize', cols: clamp(cols, MAX_COLS), rows: clamp(rows, MAX_ROWS) });
}

/**
 * The JSON control frame that signs in, or null when the session has no credentials. Only the
 * username and password are sent.
 */
export function authMessage(credentials) {
    const message = { type: 'auth' };

    for (const key of ['username', 'password']) {
        if (typeof credentials?.[key] === 'string' && credentials[key] !== '') {
            message[key] = credentials[key];
        }
    }

    return Object.keys(message).length > 1 ? JSON.stringify(message) : null;
}

/**
 * What the client typed, as the bytes of a binary frame (UTF-8).
 */
export function inputBytes(text) {
    return new TextEncoder().encode(String(text ?? ''));
}

/**
 * Bytes xterm.js gives as a "binary" string (one character per byte, such as mouse reports).
 */
export function binaryBytes(text) {
    return Uint8Array.from(String(text ?? ''), (character) => character.charCodeAt(0) & 0xff);
}
