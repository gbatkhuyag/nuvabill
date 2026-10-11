import RFB from '@novnc/novnc';

/**
 * The console page (theme view client/services/console): the server's screen in the browser with
 * noVNC. This file is its own Vite entry, so the rest of the site does not load noVNC.
 *
 * The page holds only a single-use ticket. This script trades it once for the connection details
 * (a POST with the CSRF token), connects, and then forgets them. The ticket is also removed from the
 * page and the address bar, so it never ends up in history or a shared link.
 */

/** Characters a paste may type at most. */
const PASTE_LIMIT = 500;

/** X11 key symbols for keys that are not plain characters. */
const KEYSYMS = { '\n': 0xff0d, '\r': 0xff0d, '\t': 0xff09, '\b': 0xff08 };

/**
 * The X11 key symbol of one character: Latin-1 characters are their own code; others are
 * 0x01000000 plus the Unicode code point.
 */
export function keysymFor(character) {
    if (KEYSYMS[character] !== undefined) {
        return KEYSYMS[character];
    }

    const code = character.codePointAt(0);

    if ((code >= 0x20 && code <= 0x7e) || (code >= 0xa0 && code <= 0xff)) {
        return code;
    }

    return 0x01000000 | code;
}

/**
 * The key symbols to type for a text, at most PASTE_LIMIT characters.
 */
export function pasteKeys(text) {
    return Array.from(String(text ?? ''))
        .slice(0, PASTE_LIMIT)
        .filter((character) => character !== '\r')
        .map(keysymFor);
}

const screen = document.querySelector('[data-console]');

if (screen) {
    start(screen);
}

function start(screen) {
    const messages = parse(screen.dataset.messages);
    const status = document.querySelector('[data-console-status]');
    const dot = document.querySelector('[data-console-dot]');
    const again = document.querySelector('[data-console-again]');
    const dialog = document.querySelector('[data-console-paste]');
    const buttons = Object.fromEntries([...document.querySelectorAll('[data-console-action]')].map((button) => [button.dataset.consoleAction, button]));
    const ticket = screen.dataset.ticket ?? '';
    let rfb = null;
    let state = 'connecting';

    const show = (next, text = null) => {
        state = next;
        dot?.setAttribute('data-state', next === 'password' || next === 'expired' ? 'failed' : next);

        if (status) {
            status.textContent = text ?? messages[next] ?? '';
        }

        const connected = next === 'connected';
        ['keys', 'paste', 'fit', 'disconnect'].forEach((name) => {
            if (buttons[name]) {
                buttons[name].disabled = !connected;
            }
        });

        if (again) {
            again.hidden = next === 'connecting' || connected;
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

        let credentials = answer.credentials && typeof answer.credentials === 'object' ? { ...answer.credentials } : {};

        try {
            rfb = new RFB(screen, answer.url, { credentials, shared: true, wsProtocols: ['binary'] });
        } catch {
            show('failed');

            return;
        } finally {
            // The details are not needed again; noVNC has them for this connection only.
            credentials = null;
            answer.credentials = null;
            answer.url = null;
        }

        rfb.scaleViewport = true;
        rfb.resizeSession = false;
        rfb.focusOnClick = true;

        rfb.addEventListener('connect', () => {
            show('connected');
            rfb.focus();
        });

        // A connection that never opened failed; one that was open ended.
        rfb.addEventListener('disconnect', () => {
            if (state !== 'password') {
                show(state === 'connecting' ? 'failed' : 'disconnected');
            }

            rfb = null;
        });

        rfb.addEventListener('securityfailure', () => show('password'));

        // The details already held the password; asking again means it was not accepted.
        rfb.addEventListener('credentialsrequired', () => {
            show('password');
            rfb?.disconnect();
        });
    });

    buttons.keys?.addEventListener('click', () => {
        rfb?.sendCtrlAltDel();
        rfb?.focus();
    });

    buttons.fit?.addEventListener('click', () => {
        if (!rfb) {
            return;
        }

        rfb.scaleViewport = !rfb.scaleViewport;
        rfb.clipViewport = !rfb.scaleViewport;
        buttons.fit.setAttribute('aria-pressed', rfb.scaleViewport ? 'true' : 'false');
        rfb.focus();
    });

    buttons.fullscreen?.addEventListener('click', () => {
        const page = document.documentElement;

        if (document.fullscreenElement) {
            document.exitFullscreen?.();
        } else {
            page.requestFullscreen?.().catch(() => {});
        }
    });

    buttons.disconnect?.addEventListener('click', () => rfb?.disconnect());

    buttons.paste?.addEventListener('click', () => {
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        dialog.querySelector('textarea').value = '';
        dialog.showModal();
    });

    dialog?.addEventListener('close', () => {
        const field = dialog.querySelector('textarea');

        if (dialog.returnValue === 'type' && rfb) {
            pasteKeys(field.value).forEach((keysym) => rfb.sendKey(keysym));

            if (status) {
                status.textContent = messages.pasted ?? status.textContent;
            }
        }

        field.value = '';
        rfb?.focus();
    });

    window.addEventListener('beforeunload', () => rfb?.disconnect());
}

/**
 * Trade the ticket for the connection details: {ok, url, credentials, expires_at}, or null.
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
