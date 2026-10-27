import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';
import { authMessage, binaryBytes, inputBytes, resizeMessage, SUBPROTOCOL } from './terminal-protocol.js';

/**
 * The console page for a text terminal (theme view client/services/console with protocol
 * "terminal"): xterm.js over a WebSocket. This file is its own Vite entry, so the rest of the site
 * does not load xterm.js.
 *
 * As with the VNC console, the page holds only a single-use ticket. This script trades it once for
 * the connection details (a POST with the CSRF token), connects, and then forgets them. The ticket
 * is also removed from the page and the address bar.
 *
 * The WebSocket (subprotocol "nuvabill.terminal.v1", see ConsoleSession): binary or text frames
 * from the server are output; what the client types goes out as binary frames; JSON text frames
 * carry {"type":"auth"} once when there are credentials, and {"type":"resize"} with the size.
 */

const screen = document.querySelector('[data-terminal]');

if (screen) {
    start(screen);
}

function start(screen) {
    const messages = parse(screen.dataset.messages);
    const status = document.querySelector('[data-console-status]');
    const dot = document.querySelector('[data-console-dot]');
    const again = document.querySelector('[data-console-again]');
    const buttons = Object.fromEntries([...document.querySelectorAll('[data-console-action]')].map((button) => [button.dataset.consoleAction, button]));
    const ticket = screen.dataset.ticket ?? '';
    let socket = null;
    let state = 'connecting';

    const term = new Terminal({ cursorBlink: true, scrollback: 5000, convertEol: false, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' });
    const fit = new FitAddon();
    term.loadAddon(fit);

    const show = (next, text = null) => {
        state = next;
        dot?.setAttribute('data-state', next === 'expired' ? 'failed' : next);

        if (status) {
            status.textContent = text ?? messages[next] ?? '';
        }

        if (buttons.disconnect) {
            buttons.disconnect.disabled = next !== 'connected';
        }

        if (again) {
            again.hidden = next === 'connecting' || next === 'connected';
        }
    };

    const send = (data) => {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(data);
        }
    };

    // The ticket works once: take it out of the page and the address bar before using it.
    delete screen.dataset.ticket;

    try {
        const address = new URL(window.location.href);
        address.searchParams.delete('ticket');
        window.history.replaceState(null, '', address.pathname + address.search + address.hash);
    } catch {
        // Not important: the ticket cannot be used again anyway.
    }

    show('connecting');

    redeem(screen.dataset.redeem, ticket).then((answer) => {
        if (!answer || answer.ok !== true || typeof answer.url !== 'string') {
            show('expired', typeof answer?.message === 'string' ? answer.message : messages.expired);

            return;
        }

        let auth = authMessage(answer.credentials);

        try {
            socket = new WebSocket(answer.url, [SUBPROTOCOL]);
        } catch {
            show('failed');

            return;
        } finally {
            // The details are not needed again.
            answer.credentials = null;
            answer.url = null;
        }

        socket.binaryType = 'arraybuffer';
        term.open(screen);
        fit.fit();

        socket.addEventListener('open', () => {
            if (auth !== null) {
                send(auth);
                auth = null;
            }

            send(resizeMessage(term.cols, term.rows));
            show('connected');
            term.focus();
        });

        socket.addEventListener('message', (event) => {
            term.write(event.data instanceof ArrayBuffer ? new Uint8Array(event.data) : String(event.data));
        });

        // A connection that never opened failed; one that was open ended.
        socket.addEventListener('close', () => {
            auth = null;
            show(state === 'connecting' ? 'failed' : 'disconnected');
            socket = null;
        });
    });

    term.onData((data) => send(inputBytes(data)));
    term.onBinary((data) => send(binaryBytes(data)));
    term.onResize(({ cols, rows }) => send(resizeMessage(cols, rows)));

    window.addEventListener('resize', () => {
        try {
            fit.fit();
        } catch {
            // Not open yet.
        }
    });

    buttons.fullscreen?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen?.();
        } else {
            document.documentElement.requestFullscreen?.().catch(() => {});
        }
    });

    buttons.disconnect?.addEventListener('click', () => socket?.close());

    window.addEventListener('beforeunload', () => socket?.close());
}

/**
 * Trade the ticket for the connection details: {ok, url, credentials, expires_at, protocol}, or null.
 */
async function redeem(url, ticket) {
    if (!url || !ticket) {
        return null;
    }

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
            body: JSON.stringify({ ticket }),
            credentials: 'same-origin',
            redirect: 'error',
            cache: 'no-store',
        });

        return await response.json().catch(() => null);
    } catch {
        return null;
    }
}

function parse(text) {
    try {
        const value = JSON.parse(text ?? '{}');

        return value && typeof value === 'object' ? value : {};
    } catch {
        return {};
    }
}
