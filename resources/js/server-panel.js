import { barChart, formatBytes, formatNumber, formatTime, formatValue, isNumber, lineChart, sparkline } from './panel-chart.js';

/**
 * The client server panel on a service page (theme view client/services/server-panel): the tabs,
 * one section's reads and actions, and the overview's live figures.
 *
 * Remote data reaches the page only through x-text and attributes, never as HTML. A secret from a
 * "reveal" action (a new root password, console details) is kept in memory only, never in the page
 * address or storage, and is dropped after a minute (unless the client keeps it open) or when the
 * client hides it.
 */

/** Samples kept for each live sparkline. */
export const RING_SIZE = 60;

/** Live updates pause after this long without input. */
export const IDLE_MS = 10 * 60 * 1000;

/** Seconds a secret stays on screen, unless the client keeps it open. */
export const SECRET_SECONDS = 60;

/** How often "Tasks and logs" refreshes while a task runs, and for how long at most. */
export const FOLLOW_MS = 10 * 1000;
export const FOLLOW_LIMIT_MS = 10 * 60 * 1000;

/** Letters and digits that are easy to tell apart (no 0/O, 1/l/I). */
const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

/** Answers that carry a message for the client. Other failures get the general message. */
const READABLE = [403, 404, 409, 410, 413, 422, 429, 503];

/**
 * A random password of letters and digits, with at least one of each, from the browser's secure
 * random numbers (never Math.random). Bytes that would favour some characters are skipped.
 *
 * @param {number} length
 * @param {(array: Uint8Array) => Uint8Array} randomValues
 * @returns {string}
 */
export function generatePassword(length = 16, randomValues = (array) => globalThis.crypto.getRandomValues(array)) {
    const alphabet = PASSWORD_ALPHABET;
    const limit = 256 - (256 % alphabet.length);
    const size = Math.max(2, Math.floor(length));

    for (;;) {
        let password = '';

        while (password.length < size) {
            for (const byte of randomValues(new Uint8Array(size * 2))) {
                if (byte < limit && password.length < size) {
                    password += alphabet[byte % alphabet.length];
                }
            }
        }

        if (/[A-Za-z]/.test(password) && /\d/.test(password)) {
            return password;
        }
    }
}

/**
 * The section key in a "#panel-{key}" address, when it is one of the tabs.
 *
 * @param {string|undefined} hash
 * @param {Array<string>} keys
 * @returns {string|null}
 */
export function keyFromHash(hash, keys) {
    const match = /^#panel-([a-z0-9-]{1,32})$/.exec(hash ?? '');

    return match && keys.includes(match[1]) ? match[1] : null;
}

/**
 * Graph series in charts: series with the same group (the "group" field, else the first word of the
 * key, as "net" in "net_in") and the same unit share one chart.
 *
 * @param {Array<{key: string, label: string, unit?: string, group?: string, points: Array}>} series
 * @returns {Array<{key: string, label: string, unit: string, series: Array}>}
 */
export function groupSeries(series) {
    const groups = new Map();

    for (const item of Array.isArray(series) ? series : []) {
        if (!item || typeof item !== 'object' || !Array.isArray(item.points)) {
            continue;
        }

        const unit = typeof item.unit === 'string' ? item.unit : '';
        const group = typeof item.group === 'string' && item.group !== '' ? item.group : String(item.key ?? '').split(/[._-]/)[0];
        const id = `${group}|${unit}`;

        if (!groups.has(id)) {
            groups.set(id, { key: id, unit, series: [] });
        }

        groups.get(id).series.push(item);
    }

    return [...groups.values()].map((group) => ({ ...group, label: group.series.map((item) => String(item.label ?? '')).join(' · ') }));
}

/**
 * The tabs: arrow keys (swapped on right-to-left pages), Home and End, and the #panel-{key} address
 * of the open tab, so a link or a reload opens the same tab.
 *
 * @param {{keys: Array<string>}} config
 */
export function serverPanel(config) {
    const keys = Array.isArray(config?.keys) ? config.keys : [];

    return {
        keys,
        active: keys[0] ?? null,
        onHash: null,

        init() {
            this.active = keyFromHash(globalThis.location?.hash, keys) ?? this.active;
            this.onHash = () => {
                const key = keyFromHash(globalThis.location?.hash, keys);

                if (key) {
                    this.active = key;
                }
            };
            globalThis.addEventListener?.('hashchange', this.onHash);
        },

        destroy() {
            globalThis.removeEventListener?.('hashchange', this.onHash);
        },

        select(key, focus = false) {
            if (!keys.includes(key)) {
                return;
            }

            this.active = key;

            try {
                globalThis.history?.replaceState(null, '', `#panel-${key}`);
            } catch {
                // Some embedded browsers refuse it; the tab still opens.
            }

            if (focus) {
                const tab = globalThis.document?.getElementById(`tab-${key}`);
                tab?.focus();
                tab?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
            }
        },

        onKey(event) {
            const index = keys.indexOf(this.active);
            const rtl = globalThis.document?.documentElement?.dir === 'rtl';
            let next = null;

            if (event.key === 'ArrowRight') {
                next = rtl ? index - 1 : index + 1;
            } else if (event.key === 'ArrowLeft') {
                next = rtl ? index + 1 : index - 1;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = keys.length - 1;
            }

            if (next === null || keys.length === 0) {
                return;
            }

            event.preventDefault();
            this.select(keys[(next + keys.length) % keys.length], true);
        },
    };
}

/**
 * One section: it loads its data the first time its tab opens, runs its actions, shows field errors,
 * waits out limits and shows a secret once.
 *
 * @param {{
 *   key: string,
 *   readUrl: string,
 *   actions: Object<string, {label: string, url: string, confirm: string|null, destructive: boolean, reveals: boolean}>,
 *   initial?: Object|null,
 *   follow?: boolean,
 *   locale?: string,
 *   messages: {failed: string, session: string, done: string, sure: string, unlimited?: string, empty?: string, summary?: string},
 * }} config  follow: reload every 10 seconds while data.running is true (for "Tasks and logs").
 */
export function panelSection(config) {
    const actions = config?.actions && typeof config.actions === 'object' ? config.actions : {};
    const messages = config?.messages ?? {};
    const locale = config?.locale;

    return {
        key: config?.key ?? '',
        data: isObject(config?.initial) ? config.initial : null,
        status: isObject(config?.initial) ? 'ready' : 'idle',
        loading: false,
        loadError: '',
        fetchedAt: null,
        live: true,
        params: {},
        message: '',
        tone: '',
        errors: {},
        errorsFor: null,
        busy: null,
        retryUntil: 0,
        secret: null,
        secretLeft: 0,
        copied: null,
        opened: false,
        runs: 0,
        followSince: 0,
        timers: {},

        destroy() {
            Object.values(this.timers).forEach((timer) => {
                clearTimeout(timer);
                clearInterval(timer);
            });
            this.secret = null;
        },

        /**
         * The tab opened: load the data the first time, or again after a failure.
         */
        open() {
            this.opened = true;

            if (this.status === 'idle' || this.status === 'error') {
                this.load();
            } else if (this.followsTasks()) {
                this.follow();
            }
        },

        close() {
            this.opened = false;
            clearTimeout(this.timers.follow);
        },

        reload() {
            return this.load(this.params);
        },

        /**
         * Read the section (with these query values, such as a graph period). A newer read wins over
         * an older one that answers late.
         *
         * @returns {Promise<boolean>} Whether new data arrived.
         */
        async load(params = this.params) {
            const run = ++this.runs;

            this.params = isObject(params) ? params : {};
            this.loading = true;

            if (!this.data) {
                this.status = 'loading';
            }

            const answer = await send(withQuery(config.readUrl, this.params), { headers: headers() });

            if (run !== this.runs) {
                return false;
            }

            this.loading = false;

            if (answer.ok && answer.body?.ok === true && isObject(answer.body.data)) {
                this.data = answer.body.data;
                this.fetchedAt = typeof answer.body.fetched_at === 'string' ? answer.body.fetched_at : null;
                this.live = answer.body.live !== false;
                this.loadError = '';
                this.status = 'ready';
                this.follow();

                return true;
            }

            this.loadError = failureText(answer, messages);
            this.status = this.data ? 'ready' : 'error';

            return false;
        },

        /**
         * Run an action with the form's fields (plus `extra`). A destructive action needs its ticked
         * confirm box, or else a "Sure?" answer, which is then sent as confirm=1. `ask` replaces the
         * question, such as "Restore this backup?" for a row button.
         *
         * @returns {Promise<Object|null>} The answer, or null when nothing was sent.
         */
        async submit(action, event = null, extra = {}, ask = null) {
            event?.preventDefault?.();

            const declared = actions[action];

            if (!declared || this.busy !== null || this.waiting()) {
                return null;
            }

            const form = typeof event?.target?.querySelector === 'function' && event.target.tagName === 'FORM' ? event.target : null;
            const ticked = form?.querySelector('input[name="confirm"][type="checkbox"]') ?? null;
            const question = ask || declared.confirm || (declared.destructive ? messages.sure : null);

            if (question && !ticked && !globalThis.window?.confirm(question)) {
                return null;
            }

            const body = form ? new FormData(form) : new FormData();

            if (event?.submitter?.name) {
                body.set(event.submitter.name, event.submitter.value ?? '');
            }

            Object.entries(extra ?? {}).forEach(([name, value]) => body.set(name, String(value)));

            if (question && !ticked) {
                body.set('confirm', '1');
            }

            this.busy = action;
            this.errors = {};
            this.errorsFor = action;
            this.message = '';
            this.tone = '';

            const answer = await send(declared.url, { method: 'POST', headers: headers(true), body });
            const result = isObject(answer.body) ? answer.body : {};

            this.busy = null;

            if (answer.ok && result.ok === true) {
                this.tone = 'good';
                this.message = typeof result.message === 'string' && result.message !== '' ? result.message : (messages.done ?? '');

                if (Array.isArray(result.secret) && result.secret.length > 0) {
                    this.showSecret(result.secret);
                }

                clearForm(form);
                announceChange(this.key, action);
                this.reload();

                return result;
            }

            if (answer.status === 422 && isObject(result.errors)) {
                this.errors = result.errors;
            }

            if (answer.status === 429) {
                this.wait(Number(result.retry_after) || 60);
            }

            this.tone = answer.status === 409 || answer.status === 429 ? 'warn' : 'crit';
            this.message = failureText(answer, messages);

            return result;
        },

        has(action) {
            return Object.prototype.hasOwnProperty.call(actions, action);
        },

        /**
         * Whether the action is declared and the module does not say it is off now (data.can).
         */
        can(action) {
            return this.has(action) && !(isObject(this.data?.can) && this.data.can[action] === false);
        },

        label(action) {
            return actions[action]?.label ?? '';
        },

        fieldError(action, name) {
            if (this.errorsFor !== action) {
                return '';
            }

            const list = this.errors[name];

            return Array.isArray(list) && typeof list[0] === 'string' ? list[0] : '';
        },

        /**
         * Whether a "too many tries" wait is running; actions stay locked until it ends.
         */
        waiting() {
            return this.retryUntil > Date.now();
        },

        wait(seconds) {
            const ms = Math.max(1, Math.min(86400, seconds)) * 1000;

            this.retryUntil = Date.now() + ms;
            clearTimeout(this.timers.retry);
            this.timers.retry = setTimeout(() => {
                this.retryUntil = 0;
            }, ms);
        },

        showSecret(list) {
            const items = list
                .filter((item) => isObject(item) && typeof item.value === 'string')
                .map((item) => ({ label: String(item.label ?? ''), value: item.value }));

            if (items.length === 0) {
                return;
            }

            this.secret = items;
            this.secretLeft = SECRET_SECONDS;
            clearInterval(this.timers.secret);
            this.timers.secret = setInterval(() => {
                this.secretLeft -= 1;

                if (this.secretLeft <= 0) {
                    this.hideSecret();
                }
            }, 1000);
        },

        /**
         * Keep the secret open until the client hides it or leaves the page: stops the countdown,
         * for anyone who needs more than a minute to read or copy it. It stays in memory only.
         */
        keepSecret() {
            clearInterval(this.timers.secret);
            this.secretLeft = 0;
        },

        hideSecret() {
            clearInterval(this.timers.secret);
            this.secret = null;
            this.secretLeft = 0;
            this.copied = null;
        },

        async copy(text, id = null) {
            try {
                await globalThis.navigator.clipboard.writeText(String(text));
                this.copied = id ?? text;
                clearTimeout(this.timers.copied);
                this.timers.copied = setTimeout(() => {
                    this.copied = null;
                }, 2000);
            } catch {
                globalThis.window?.prompt('', String(text));
            }
        },

        /**
         * Fill password fields of a form with a new random password, shown as text so the client can
         * see and copy it. The fields are hidden and emptied again after a successful action.
         */
        generate(event, length = 16) {
            const form = event?.target?.closest?.('form');
            const password = generatePassword(length);

            form?.querySelectorAll('input[data-generate]').forEach((input) => {
                input.value = password;
                input.type = 'text';
            });

            return password;
        },

        /**
         * Keep reloading while a task runs (for "Tasks and logs"), at most 10 minutes in a row.
         */
        followsTasks() {
            return config?.follow === true && this.data?.running === true;
        },

        follow() {
            clearTimeout(this.timers.follow);

            if (!this.followsTasks()) {
                this.followSince = 0;

                return;
            }

            this.followSince ||= Date.now();

            if (!this.opened || Date.now() - this.followSince > FOLLOW_LIMIT_MS) {
                return;
            }

            this.timers.follow = setTimeout(() => this.reload(), FOLLOW_MS);
        },

        /**
         * A link from the module (such as a ticket) only when it stays on this site.
         */
        safeUrl(url) {
            try {
                const origin = globalThis.location?.origin;
                const parsed = new URL(String(url ?? ''), origin);

                return origin && parsed.origin === origin && ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : null;
            } catch {
                return null;
            }
        },

        /**
         * A list from the data, grouped by one field in the order the groups first appear.
         *
         * @returns {Array<{name: string, items: Array<Object>}>}
         */
        groupBy(name, field) {
            const groups = new Map();

            this.list(name).forEach((item) => {
                const group = typeof item[field] === 'string' ? item[field] : '';

                if (!groups.has(group)) {
                    groups.set(group, []);
                }

                groups.get(group).push(item);
            });

            return [...groups.entries()].map(([group, items]) => ({ name: group, items }));
        },

        list(name) {
            return Array.isArray(this.data?.[name]) ? this.data[name].filter(isObject) : [];
        },

        notices() {
            return this.list('notices')
                .filter((notice) => typeof notice.text === 'string' && notice.text !== '')
                .map((notice) => ({ tone: ['info', 'warn', 'crit'].includes(notice.tone) ? notice.tone : 'info', text: notice.text }));
        },

        time(unix) {
            return isNumber(unix) ? formatTime(unix, locale, 0, true) : '–';
        },

        number(value, digits = 1) {
            return formatNumber(value, locale, digits);
        },

        bytes(value) {
            return formatBytes(value, locale);
        },

        rate(value) {
            return formatBytes(value, locale, true);
        },

        value(value, unit = '') {
            return formatValue(value, unit, locale);
        },

        /**
         * A usage meter's share (0 to 100), or null without a total.
         */
        meterPercent(meter) {
            if (!isObject(meter) || !isNumber(meter.used) || !isNumber(meter.total) || meter.total <= 0) {
                return null;
            }

            return Math.min(100, Math.max(0, (meter.used / meter.total) * 100));
        },

        meterText(meter) {
            if (!isObject(meter) || !isNumber(meter.used)) {
                return '–';
            }

            const unit = typeof meter.unit === 'string' ? meter.unit : '';
            const used = formatNumber(meter.used, locale, meter.used < 10 ? 1 : 0);

            return isNumber(meter.total) && meter.total > 0
                ? `${used} / ${formatNumber(meter.total, locale, 0)} ${unit}`.trim()
                : `${used} ${unit} / ${messages.unlimited ?? '∞'}`;
        },

        meterTone(percent) {
            return percent === null ? null : percent >= 90 ? 'crit' : percent >= 75 ? 'warn' : null;
        },

        chartGroups() {
            return groupSeries(this.data?.series);
        },

        /**
         * The rows of a chart's table: each time with the value of every series (null when missing).
         */
        chartRows(group) {
            const series = Array.isArray(group?.series) ? group.series : [];
            const maps = series.map((item) => new Map((Array.isArray(item.points) ? item.points : [])
                .filter((row) => Array.isArray(row) && isNumber(row[0]))
                .map((row) => [row[0], isNumber(row[1]) ? row[1] : null])));
            const times = [...new Set(maps.flatMap((map) => [...map.keys()]))].sort((a, b) => a - b);

            return times.map((time) => ({ time, values: maps.map((map) => (map.has(time) ? map.get(time) : null)) }));
        },

        hasChartData() {
            const series = this.chartGroups().some((group) => group.series.some((item) => item.points.some((row) => Array.isArray(row) && isNumber(row[1]))));
            const bars = Array.isArray(this.data?.bars?.points) && this.data.bars.points.some((row) => Array.isArray(row) && (isNumber(row[1]) || isNumber(row[2])));

            return series || bars;
        },

        drawLine(el, group) {
            lineChart(el, { series: group.series, unit: group.unit, label: group.label, locale, messages });
        },

        drawBars(el, bars, names) {
            barChart(el, { label: bars?.label ?? '', unit: bars?.unit ?? '', points: bars?.points ?? [], names, locale, messages });
        },

        drawSpark(el, values, max = undefined, second = undefined) {
            sparkline(el, values, { max, second });
        },

        /**
         * The query name of the graph period: data.period.param, else its kind ("month" or "range").
         */
        periodParam() {
            const period = this.data?.period;

            return typeof period?.param === 'string' && period.param !== '' ? period.param : (period?.kind === 'month' ? 'month' : 'range');
        },

        pickPeriod(value) {
            return this.load({ [this.periodParam()]: String(value) });
        },
    };
}

/**
 * The overview: a section that refreshes itself every `poll` seconds while its tab is open and the
 * page is visible, and keeps the last 60 CPU and network figures for the sparklines. It pauses after
 * 10 minutes without input, until the client resumes it.
 *
 * @param {Object} config  As panelSection, plus poll (seconds between refreshes).
 */
export function liveStats(config) {
    const section = panelSection(config);
    const base = { open: section.open, close: section.close, load: section.load, destroy: section.destroy };
    const poll = Math.max(5, Number(config?.poll) || 10) * 1000;

    return Object.assign(section, {
        samples: { cpu: [], in: [], out: [] },
        paused: false,
        polling: false,
        lastInput: Date.now(),
        listeners: [],

        init() {
            this.record(this.data);

            const touch = () => {
                this.lastInput = Date.now();
            };
            const visibility = () => {
                if (!globalThis.document?.hidden && this.polling && !this.paused) {
                    this.schedule(0);
                }
            };
            const changed = () => this.reload();

            this.listen(globalThis.document, 'pointerdown', touch);
            this.listen(globalThis.document, 'keydown', touch);
            this.listen(globalThis.document, 'scroll', touch);
            this.listen(globalThis.document, 'visibilitychange', visibility);
            this.listen(globalThis, 'server-panel:changed', changed);
        },

        destroy() {
            this.listeners.forEach(([target, name, handler]) => target.removeEventListener(name, handler));
            this.listeners = [];
            base.destroy.call(this);
        },

        listen(target, name, handler) {
            if (target?.addEventListener) {
                target.addEventListener(name, handler, { passive: true });
                this.listeners.push([target, name, handler]);
            }
        },

        open() {
            base.open.call(this);
            this.polling = true;
            this.schedule();
        },

        close() {
            base.close.call(this);
            this.polling = false;
            clearTimeout(this.timers.poll);
        },

        async load(params) {
            const fresh = await base.load.call(this, params);

            if (fresh) {
                this.record(this.data);
                announceState(this.data.state);
            }

            return fresh;
        },

        schedule(delay = poll) {
            clearTimeout(this.timers.poll);

            if (this.polling && !this.paused) {
                this.timers.poll = setTimeout(() => this.tick(), delay);
            }
        },

        async tick() {
            if (globalThis.document?.hidden) {
                // A hidden page reads nothing; it refreshes when it is shown again.
                return;
            }

            if (Date.now() - this.lastInput > IDLE_MS) {
                this.paused = true;

                return;
            }

            await this.load();
            this.schedule();
        },

        resume() {
            this.paused = false;
            this.lastInput = Date.now();
            this.schedule(0);
        },

        record(data) {
            if (!isObject(data)) {
                return;
            }

            push(this.samples.cpu, isNumber(data.cpu) ? data.cpu : null);
            push(this.samples.in, isNumber(data.network?.in) ? data.network.in : null);
            push(this.samples.out, isNumber(data.network?.out) ? data.network.out : null);
        },
    });
}

function push(ring, value) {
    ring.push(value);

    while (ring.length > RING_SIZE) {
        ring.shift();
    }
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function csrfToken() {
    return globalThis.document?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function headers(post = false) {
    const list = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (post) {
        list['X-CSRF-TOKEN'] = csrfToken();
    }

    return list;
}

function withQuery(url, params) {
    const pairs = Object.entries(params ?? {}).filter(([, value]) => value !== null && value !== undefined && value !== '');

    if (pairs.length === 0) {
        return url;
    }

    const query = pairs.map(([name, value]) => `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`).join('&');

    return `${url}${String(url).includes('?') ? '&' : '?'}${query}`;
}

/**
 * A fetch that never throws: {ok, status, body}. Redirects are refused, so a signed-out session is
 * not followed to the sign-in page.
 */
async function send(url, options) {
    try {
        const response = await fetch(url, { credentials: 'same-origin', redirect: 'error', ...options });
        const body = await response.json().catch(() => null);

        return { ok: response.ok, status: response.status, body };
    } catch {
        return { ok: false, status: 0, body: null };
    }
}

function failureText(answer, messages) {
    if (answer.status === 419) {
        return messages.session ?? '';
    }

    if (READABLE.includes(answer.status) && typeof answer.body?.message === 'string' && answer.body.message !== '') {
        return answer.body.message;
    }

    return messages.failed ?? '';
}

/**
 * After a successful action: empty the password fields (also generated ones shown as text) and
 * untick the confirm box, so nothing sensitive stays on the page.
 */
function clearForm(form) {
    if (!form) {
        return;
    }

    form.querySelectorAll('input[type="password"], input[data-generate]').forEach((input) => {
        input.value = '';

        if (input.dataset?.generate !== undefined) {
            input.type = 'password';
        }
    });
    form.querySelectorAll('input[name="confirm"][type="checkbox"]').forEach((input) => {
        input.checked = false;
    });
}

/**
 * Tell the power bar the server's state from a fresh overview, so its pill stays right.
 */
function announceState(state) {
    if (['running', 'stopped', 'suspended', 'unknown'].includes(state) && typeof globalThis.CustomEvent === 'function' && typeof globalThis.dispatchEvent === 'function') {
        globalThis.dispatchEvent(new CustomEvent('server-panel-state', { detail: { state } }));
    }
}

/**
 * Tell the other parts of the panel that the server changed, so the overview reads it again.
 */
function announceChange(section, action) {
    if (typeof globalThis.CustomEvent === 'function' && typeof globalThis.dispatchEvent === 'function') {
        globalThis.dispatchEvent(new CustomEvent('server-panel:changed', { detail: { section, action } }));
    }
}
