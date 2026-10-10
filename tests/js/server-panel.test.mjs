import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import {
    FOLLOW_LIMIT_MS,
    FOLLOW_MS,
    IDLE_MS,
    RING_SIZE,
    SECRET_SECONDS,
    generatePassword,
    groupSeries,
    keyFromHash,
    liveStats,
    panelSection,
    serverPanel,
} from '../../resources/js/server-panel.js';

// Each fetch waits until the test answers it by hand.
let requests = [];
let confirms = [];
let confirmAnswer = true;
let replaced = [];
let events = [];

/** FormData as the browser builds it, with only what these tests read. */
class FakeFormData {
    constructor(form) {
        this.values = new Map(Object.entries(form?.fields ?? {}));
    }

    set(name, value) {
        this.values.set(name, String(value));
    }

    get(name) {
        return this.values.has(name) ? this.values.get(name) : null;
    }
}

beforeEach(() => {
    requests = [];
    confirms = [];
    confirmAnswer = true;
    replaced = [];
    events = [];
    mock.timers.enable({ apis: ['setTimeout', 'setInterval'] });
    globalThis.FormData = FakeFormData;
    globalThis.window = { confirm: (message) => { confirms.push(message); return confirmAnswer; } };
    globalThis.document = {
        hidden: false,
        documentElement: { dir: 'ltr' },
        listeners: {},
        querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? { getAttribute: () => 'csrf-123' } : null),
        getElementById: () => null,
        addEventListener(name, handler) {
            (this.listeners[name] ??= []).push(handler);
        },
        removeEventListener() {},
    };
    globalThis.location = { hash: '', origin: 'https://billing.example.test' };
    globalThis.history = { replaceState: (state, title, url) => replaced.push(url) };
    globalThis.CustomEvent = class {
        constructor(type, options) {
            this.type = type;
            this.detail = options?.detail;
        }
    };
    globalThis.dispatchEvent = (event) => events.push(event);
    globalThis.fetch = (url, options = {}) =>
        new Promise((resolve) => {
            requests.push({
                url,
                options,
                answer: (body, status = 200) => resolve({ ok: status >= 200 && status < 300, status, json: async () => body }),
                fail: () => resolve(Promise.reject(new TypeError('Network error'))),
            });
        });
});

afterEach(() => {
    mock.timers.reset();
    for (const name of ['fetch', 'window', 'document', 'location', 'history', 'CustomEvent', 'dispatchEvent', 'FormData']) {
        delete globalThis[name];
    }
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

const messages = {
    failed: 'That did not work.',
    session: 'Your session ended. Reload the page.',
    done: 'Done.',
    sure: 'Are you sure?',
    unlimited: 'Unlimited',
};

const config = (extra = {}) => ({
    key: 'network',
    readUrl: '/client/services/5/sections/network',
    actions: {
        'rdns-set': { label: 'Save', url: '/client/services/5/sections/network/rdns-set', confirm: null, destructive: false, reveals: false },
        wipe: { label: 'Wipe', url: '/client/services/5/sections/network/wipe', confirm: null, destructive: true, reveals: false },
        details: { label: 'Show', url: '/client/services/5/sections/network/details', confirm: null, destructive: false, reveals: true },
    },
    locale: 'en',
    messages,
    ...extra,
});

/** A submit event of a form, with its fields and an optional ticked confirm box. */
const formEvent = (fields = {}, { ticked = false } = {}) => {
    const inputs = [];
    const form = {
        tagName: 'FORM',
        fields,
        querySelector: (selector) => (ticked && selector.includes('confirm') ? { checked: true } : null),
        querySelectorAll: () => inputs,
    };

    return { target: form, preventDefault() { this.defaultPrevented = true; }, defaultPrevented: false };
};

test('a password has letters and digits only, both of them, from the secure random source', () => {
    for (let run = 0; run < 50; run++) {
        const password = generatePassword(16);

        assert.equal(password.length, 16);
        assert.match(password, /^[A-Za-z0-9]+$/);
        assert.match(password, /[A-Za-z]/);
        assert.match(password, /\d/);
        assert.doesNotMatch(password, /[0O1lI]/);
    }

    // Bytes that would favour some characters are skipped: 255 is never used.
    let calls = 0;
    const sequence = (array) => {
        calls++;
        array.fill(255);
        array[0] = 0;
        array[1] = 48;

        return array;
    };
    assert.equal(generatePassword(2, sequence), 'A2');
    assert.ok(calls >= 1);
    assert.equal(generatePassword(8).length, 8);
});

test('only the address of a known tab opens it', () => {
    assert.equal(keyFromHash('#panel-graphs', ['overview', 'graphs']), 'graphs');
    assert.equal(keyFromHash('#panel-files', ['overview', 'graphs']), null);
    assert.equal(keyFromHash('#panel-<img>', ['overview']), null);
    assert.equal(keyFromHash(undefined, ['overview']), null);
});

test('graph series with the same group and unit share a chart', () => {
    const groups = groupSeries([
        { key: 'cpu', label: 'CPU', unit: '%', points: [] },
        { key: 'net_in', label: 'In', unit: 'B/s', points: [] },
        { key: 'net_out', label: 'Out', unit: 'B/s', points: [] },
        { key: 'disk_read', label: 'Read', unit: 'B/s', points: [] },
        { key: 'disk_iops', label: 'IOPS', unit: 'iops', points: [] },
        { key: 'broken', label: 'Broken' },
    ]);

    assert.deepEqual(groups.map((group) => group.key), ['cpu|%', 'net|B/s', 'disk|B/s', 'disk|iops']);
    assert.equal(groups[1].label, 'In · Out');
});

test('the tabs follow the address, the arrow keys (swapped right to left), Home and End', () => {
    globalThis.location.hash = '#panel-settings';
    const panel = serverPanel({ keys: ['overview', 'graphs', 'settings'] });

    panel.init();
    assert.equal(panel.active, 'settings');

    const key = (name) => {
        const event = { key: name, prevented: false, preventDefault() { this.prevented = true; } };
        panel.onKey(event);

        return event;
    };

    assert.equal(key('ArrowRight').prevented, true);
    assert.equal(panel.active, 'overview', 'It wraps around.');
    key('End');
    assert.equal(panel.active, 'settings');
    key('Home');
    assert.equal(panel.active, 'overview');
    assert.equal(key('a').prevented, false);

    globalThis.document.documentElement.dir = 'rtl';
    key('ArrowLeft');
    assert.equal(panel.active, 'graphs');
    assert.deepEqual(replaced.slice(-1), ['#panel-graphs']);

    panel.select('files');
    assert.equal(panel.active, 'graphs', 'An unknown tab is ignored.');
});

test('a section loads once when its tab opens, and a failed read offers a retry', async () => {
    const section = panelSection(config());

    section.open();
    await settle();
    assert.equal(section.status, 'loading');
    assert.equal(requests[0].url, '/client/services/5/sections/network');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    assert.equal(requests[0].options.credentials, 'same-origin');
    requests[0].answer({ ok: false, message: 'This feature is turned off for your plan.' }, 403);
    await settle();

    assert.equal(section.status, 'error');
    assert.equal(section.loadError, 'This feature is turned off for your plan.');

    section.open();
    await settle();
    requests[1].answer({ ok: true, section: 'network', data: { ips: [{ ip: '192.0.2.10' }], rdns: true }, fetched_at: '2026-10-10T12:00:00Z', live: true });
    await settle();

    assert.equal(section.status, 'ready');
    assert.equal(section.loadError, '');
    assert.deepEqual(section.list('ips'), [{ ip: '192.0.2.10' }]);

    section.open();
    await settle();
    assert.equal(requests.length, 2, 'An open tab with data does not read again.');
});

test('an older read that answers late is ignored, and an ended session gets its own message', async () => {
    const section = panelSection(config());

    section.load({ period: 'day' });
    section.load({ period: 'week' });
    await settle();
    assert.equal(requests[1].url, '/client/services/5/sections/network?period=week');

    requests[1].answer({ ok: true, data: { which: 'week' } });
    await settle();
    requests[0].answer({ ok: true, data: { which: 'day' } });
    await settle();
    assert.equal(section.data.which, 'week');

    section.reload();
    await settle();
    requests[2].answer({ message: 'CSRF token mismatch.' }, 419);
    await settle();
    assert.equal(section.loadError, 'Your session ended. Reload the page.');
    assert.equal(section.status, 'ready', 'The last good data stays.');

    section.reload();
    await settle();
    requests[3].fail();
    await settle();
    assert.equal(section.loadError, 'That did not work.');
});

test('an action posts the form with the CSRF token, shows field errors and the message', async () => {
    const section = panelSection(config({ initial: { ips: [] } }));
    const event = formEvent({ ip: '192.0.2.10', ptr: 'bad name' });

    section.submit('rdns-set', event);
    await settle();

    assert.equal(event.defaultPrevented, true);
    assert.equal(section.busy, 'rdns-set');
    assert.equal(requests[0].url, '/client/services/5/sections/network/rdns-set');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.equal(requests[0].options.body.get('ptr'), 'bad name');
    assert.equal(requests[0].options.body.get('confirm'), null);

    // A second click waits for the answer.
    assert.equal(await section.submit('rdns-set', formEvent()), null);

    requests[0].answer({ ok: false, message: 'Check the form and try again.', errors: { ptr: ['Enter a valid name.'] } }, 422);
    await settle();

    assert.equal(section.busy, null);
    assert.equal(section.tone, 'crit');
    assert.equal(section.message, 'Check the form and try again.');
    assert.equal(section.fieldError('rdns-set', 'ptr'), 'Enter a valid name.');
    assert.equal(section.fieldError('wipe', 'ptr'), '', 'Errors belong to the form that was sent.');

    section.submit('rdns-set', formEvent({ ip: '192.0.2.10', ptr: 'vps.example.test' }));
    await settle();
    requests[1].answer({ ok: true, message: 'Reverse DNS saved.', data: {}, pending: null, state: null, secret: null });
    await settle();

    assert.equal(section.tone, 'good');
    assert.equal(section.message, 'Reverse DNS saved.');
    assert.equal(section.fieldError('rdns-set', 'ptr'), '');
    assert.equal(requests[2].url, '/client/services/5/sections/network', 'The section is read again.');
    assert.equal(events[0].type, 'server-panel:changed');
    assert.equal(await section.submit('unknown', formEvent()), null, 'Only declared actions are sent.');
});

test('a destructive action asks first and sends confirm=1, unless the form has a ticked box', async () => {
    const section = panelSection(config());

    confirmAnswer = false;
    assert.equal(await section.submit('wipe', null, { id: 7 }), null);
    assert.deepEqual(confirms, ['Are you sure?']);
    assert.equal(requests.length, 0);

    confirmAnswer = true;
    section.submit('wipe', null, { id: 7 }, 'Delete this backup?');
    await settle();
    assert.deepEqual(confirms, ['Are you sure?', 'Delete this backup?']);
    assert.equal(requests[0].options.body.get('confirm'), '1');
    assert.equal(requests[0].options.body.get('id'), '7');
    requests[0].answer({ ok: true, message: 'Gone.' });
    await settle();

    section.submit('wipe', formEvent({ confirm: '1' }, { ticked: true }));
    await settle();
    assert.equal(confirms.length, 2, 'A ticked box is the answer.');
    assert.equal(requests[2].options.body.get('confirm'), '1');
});

test('too many tries locks the actions until the wait is over', async () => {
    const section = panelSection(config());

    section.submit('rdns-set', formEvent());
    await settle();
    requests[0].answer({ ok: false, message: 'Too many tries. Wait 30 seconds, then try again.', retry_after: 30 }, 429);
    await settle();

    assert.equal(section.tone, 'warn');
    assert.equal(section.waiting(), true);
    assert.equal(await section.submit('rdns-set', formEvent()), null);

    mock.timers.tick(30000);
    assert.equal(section.waiting(), false);

    section.submit('rdns-set', formEvent());
    await settle();
    requests[1].answer({ ok: false, message: 'Another change is running on this server. Try again in a moment.' }, 409);
    await settle();
    assert.equal(section.message, 'Another change is running on this server. Try again in a moment.');

    section.submit('rdns-set', formEvent());
    await settle();
    requests[2].answer({ message: 'Server Error' }, 500);
    await settle();
    assert.equal(section.message, 'That did not work.', 'A raw server error is not shown.');
});

test('a secret is shown once and dropped after a minute or on Hide', async () => {
    const section = panelSection(config());

    section.submit('details', formEvent());
    await settle();
    requests[0].answer({ ok: true, message: 'Save this now.', secret: [{ label: 'Password', value: 'Vnc-8812' }, { label: 'Bad', value: 5 }] });
    await settle();

    assert.deepEqual(section.secret, [{ label: 'Password', value: 'Vnc-8812' }]);
    assert.equal(section.secretLeft, SECRET_SECONDS);

    mock.timers.tick(30000);
    assert.equal(section.secretLeft, SECRET_SECONDS - 30);
    mock.timers.tick(30000);
    assert.equal(section.secret, null);

    section.showSecret([{ label: 'Root password', value: 'abc' }]);
    section.hideSecret();
    assert.equal(section.secret, null);
    section.destroy();
});

test('actions the module turns off are hidden, and links from the module stay on this site', () => {
    const section = panelSection(config({ initial: { can: { wipe: false } } }));

    assert.equal(section.can('rdns-set'), true);
    assert.equal(section.can('wipe'), false);
    assert.equal(section.can('nothing'), false);
    assert.equal(section.safeUrl('/client/tickets/12'), 'https://billing.example.test/client/tickets/12');
    assert.equal(section.safeUrl('javascript:alert(1)'), null);
    assert.equal(section.safeUrl('https://evil.example/x'), null);
    assert.equal(section.meterPercent({ used: 512, total: 2048, unit: 'MB' }), 25);
    assert.equal(section.meterText({ used: 5, total: null, unit: 'GB' }), '5 GB / Unlimited');
    assert.deepEqual(section.groupBy('missing', 'group'), []);
});

test('the overview refreshes while its tab is open, records the last minute and pauses when idle', async () => {
    const stats = liveStats({ ...config({ key: 'overview', readUrl: '/s/overview', actions: {} }), initial: { state: 'running', cpu: 10, network: { in: 1, out: 2 } }, poll: 10 });

    stats.init();
    assert.deepEqual(stats.samples.cpu, [10]);

    stats.open();
    await settle();
    assert.equal(requests.length, 0, 'The first paint is fresh enough.');

    mock.timers.tick(10000);
    await settle();
    assert.equal(requests[0].url, '/s/overview');
    requests[0].answer({ ok: true, data: { state: 'stopped', cpu: 20, network: { in: 5, out: 6 } } });
    await settle();
    assert.deepEqual(stats.samples.cpu, [10, 20]);
    assert.deepEqual(stats.samples.out, [2, 6]);
    assert.equal(events.find((event) => event.type === 'server-panel-state').detail.state, 'stopped');

    // A hidden page reads nothing.
    globalThis.document.hidden = true;
    mock.timers.tick(10000);
    await settle();
    assert.equal(requests.length, 1);

    // Back on the page, it reads again; ten minutes without input pause it.
    globalThis.document.hidden = false;
    globalThis.document.listeners.visibilitychange.forEach((handler) => handler());
    mock.timers.tick(0);
    await settle();
    requests[1].answer({ ok: true, data: { cpu: 30 } });
    await settle();

    stats.lastInput = Date.now() - IDLE_MS - 1;
    mock.timers.tick(10000);
    await settle();
    assert.equal(stats.paused, true);
    assert.equal(requests.length, 2);

    stats.resume();
    mock.timers.tick(0);
    await settle();
    assert.equal(requests.length, 3);
    requests[2].answer({ ok: true, data: { cpu: 40 } });
    await settle();

    stats.close();
    mock.timers.tick(60000);
    await settle();
    assert.equal(requests.length, 3, 'A closed tab reads nothing.');

    for (let index = 0; index < RING_SIZE + 5; index++) {
        stats.record({ cpu: index });
    }
    assert.equal(stats.samples.cpu.length, RING_SIZE);
    stats.destroy();
});

test('tasks and logs reload every 10 seconds while a task runs, for 10 minutes at most', async () => {
    const section = panelSection(config({ key: 'activity', readUrl: '/s/activity', actions: {}, follow: true }));

    section.open();
    await settle();
    requests[0].answer({ ok: true, data: { running: true, tasks: [] } });
    await settle();

    mock.timers.tick(FOLLOW_MS);
    await settle();
    assert.equal(requests.length, 2);
    requests[1].answer({ ok: true, data: { running: false, tasks: [] } });
    await settle();

    mock.timers.tick(FOLLOW_MS * 3);
    await settle();
    assert.equal(requests.length, 2, 'A finished task stops the reloads.');

    section.reload();
    await settle();
    requests[2].answer({ ok: true, data: { running: true } });
    await settle();
    section.followSince = Date.now() - FOLLOW_LIMIT_MS - 1;
    section.follow();
    mock.timers.tick(FOLLOW_MS * 3);
    await settle();
    assert.equal(requests.length, 3, 'It stops after 10 minutes.');
});
