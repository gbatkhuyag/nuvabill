import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { machinePower } from '../../resources/js/machine-power.js';

// Each fetch waits until the test answers it by hand.
let requests = [];
let confirms = [];

beforeEach(() => {
    requests = [];
    confirms = [];
    mock.timers.enable({ apis: ['setTimeout'] });
    globalThis.FormData = class {};
    globalThis.window = { confirm: (message) => confirms.push(message) > 0 };
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
    delete globalThis.fetch;
    delete globalThis.window;
    delete globalThis.FormData;
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

const config = (extra = {}) => ({
    state: 'on',
    label: 'On',
    checked: 'Checked 3 minutes ago',
    actions: ['on', 'shutdown', 'restart', 'reset', 'off'],
    stateUrl: '/admin/hardware/7/power',
    labels: { pending: 'Waiting for the server…', unknown: 'Unknown' },
    messages: { failed: 'That did not work.', slow: 'Not yet.', unread: 'No state now.' },
    ...extra,
});

const click = (action, confirm) => ({
    defaultPrevented: false,
    target: {},
    submitter: { dataset: { action, ...(confirm ? { confirm } : {}) }, formAction: `/admin/hardware/7/power/${action}` },
    preventDefault() {
        this.defaultPrevented = true;
    },
});

test('the live state replaces the saved one when the page opens', async () => {
    const power = machinePower(config({ state: 'unknown', label: 'Unknown' }));

    power.init();
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/admin/hardware/7/power');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    assert.equal(requests[0].options.redirect, 'error');
    requests[0].answer({ state: 'off', label: 'Off', checked: 'Checked just now', actions: ['on'] });
    await settle();

    assert.equal(power.state, 'off');
    assert.equal(power.label, 'Off');
    assert.equal(power.tone, 'crit');
    assert.equal(power.checked, 'Checked just now');
    assert.equal(power.has('on'), true);
    assert.equal(power.has('off'), false);
});

test('a machine without power control asks nothing', async () => {
    const power = machinePower(config({ load: false }));

    power.init();
    await settle();

    assert.equal(requests.length, 0);
});

test('a force off waits until the machine is off', async () => {
    const power = machinePower(config());

    power.power(click('off', 'Force the power off?'));
    await settle();

    assert.deepEqual(confirms, ['Force the power off?']);
    assert.equal(requests[0].url, '/admin/hardware/7/power/off');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.credentials, 'same-origin');
    requests[0].answer({ ok: true, message: 'The server is powered off.', pending: 'off', state: 'changing', label: 'Turning on or off' });
    await settle();

    assert.equal(power.busy, true);
    assert.equal(power.label, 'Waiting for the server…');
    assert.equal(power.tone, 'info');
    assert.equal(power.message, 'The server is powered off.');

    mock.timers.tick(10000);
    await settle();
    requests[1].answer({ state: 'on', label: 'On' });
    await settle();
    assert.equal(power.pending, 'off');

    mock.timers.tick(10000);
    await settle();
    requests[2].answer({ state: 'off', label: 'Off', checked: 'Checked just now' });
    await settle();

    assert.equal(power.state, 'off');
    assert.equal(power.pending, null);
    assert.equal(power.busy, false);
    assert.equal(power.label, 'Off');
});

test('a cancelled confirmation sends nothing', async () => {
    globalThis.window.confirm = () => false;
    const power = machinePower(config());
    const event = click('reset', 'Force a restart?');

    await power.power(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(requests.length, 0);
});

test('a refused action shows its message and keeps the state', async () => {
    const power = machinePower(config());

    power.power(click('on'));
    await settle();
    requests[0].answer({ ok: false, message: 'Another power action is running. Try again in a minute.', state: 'unknown', busy: true }, 422);
    await settle();

    assert.equal(power.busy, false);
    assert.equal(power.state, 'on');
    assert.equal(power.message, 'Another power action is running. Try again in a minute.');
});

test('an action that needs no wait shows the new state at once', async () => {
    const power = machinePower(config({ state: 'off', label: 'Off' }));

    power.power(click('on'));
    await settle();
    requests[0].answer({ ok: true, message: 'The server is already on.', pending: null, state: 'on', label: 'On' });
    await settle();

    assert.equal(power.busy, false);
    assert.equal(power.state, 'on');
    mock.timers.tick(30000);
    assert.equal(requests.length, 1);
});

test('an expired session or a network error gives the general message', async () => {
    const power = machinePower(config());

    power.power(click('restart'));
    await settle();
    requests[0].answer({ message: 'CSRF token mismatch.' }, 419);
    await settle();

    assert.equal(power.message, 'That did not work.');
    assert.equal(power.busy, false);

    power.power(click('restart'));
    await settle();
    requests[1].fail();
    await settle();

    assert.equal(power.message, 'That did not work.');
});

test('a machine that never gets there stops waiting after two minutes', async () => {
    const power = machinePower(config());

    power.power(click('shutdown'));
    await settle();
    requests[0].answer({ ok: true, message: 'A shutdown signal was sent.', pending: 'shutdown', state: 'changing' });
    await settle();

    for (let i = 1; i <= 12; i++) {
        mock.timers.tick(10000);
        await settle();
        requests[i].answer({ state: 'on', label: 'On' });
        await settle();
    }

    assert.equal(power.busy, false);
    assert.equal(power.pending, null);
    assert.equal(power.message, 'Not yet.');
    mock.timers.tick(60000);
    assert.equal(requests.length, 13);
});

test('a second click while an action runs is ignored', async () => {
    const power = machinePower(config());

    power.power(click('restart'));
    await settle();
    const second = click('off');
    await power.power(second);

    assert.equal(second.defaultPrevented, true);
    assert.equal(requests.length, 1);
});

test('a state that cannot be read says so and keeps the buttons free', async () => {
    const power = machinePower(config());

    power.refresh();
    assert.equal(power.loading, true);
    await settle();
    requests[0].answer({ message: 'Too many requests.' }, 429);
    await settle();

    assert.equal(power.loading, false);
    assert.equal(power.busy, false);
    assert.equal(power.state, 'on');
    assert.equal(power.message, 'No state now.');
});
