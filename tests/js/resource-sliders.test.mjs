import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import {
    clamp,
    extrasTotal,
    fill,
    formatMoney,
    formatUnits,
    resourceQuote,
    resourceSlider,
    stepPrice,
} from '../../resources/js/resource-sliders.js';

const text = {
    none: 'No extra',
    on: 'On',
    off: 'Off',
    eachStep: 'Each step adds :amount.',
    included: 'The plan includes :amount.',
    stepPrices: 'Price per step: :prices.',
    switchPrices: 'Price: :prices.',
    setup: '+ :amount setup',
    ariaExtra: ':amount extra',
    ariaTotal: ':amount in total',
    ariaPrice: ':amount (:cycle)',
    onlyLeft: 'Only :count left',
    soldOut: 'Sold out right now.',
    suffixes: { monthly: '/mo', annually: '/yr' },
    cycles: { monthly: 'Monthly', annually: 'Yearly' },
};

const ram = (extra = {}) => ({
    kind: 'ram',
    label: 'RAM',
    description: null,
    unit: 'MB',
    step: 1024,
    included: 4096,
    min: 0,
    max: 8,
    value: 0,
    toggle: false,
    ladder: null,
    restarts: true,
    note: 'Takes effect after a restart.',
    stock_left: null,
    currency: 'USD',
    prices: { monthly: { step: 200, setup: 50 }, annually: { step: 2000, setup: 0 } },
    ...extra,
});

/** A slider as Alpine would run it, recording the events it sends. */
const slider = (option, config = {}) => {
    const events = [];
    const component = resourceSlider(option, { text, locale: 'en', currency: 'USD', ...config });
    component.$dispatch = (name, detail) => events.push({ name, detail });
    component.init();

    return { component, events };
};

test('amounts read the way people say them', () => {
    assert.equal(formatUnits('MB', 1024), '1 GB');
    assert.equal(formatUnits('MB', 1536), '1.5 GB');
    assert.equal(formatUnits('MB', 512), '512 MB');
    assert.equal(formatUnits('GB', 2000), '2 TB');
    assert.equal(formatUnits('GB', 1500), '1,500 GB');
    assert.equal(formatUnits('GB', 40), '40 GB');
    assert.equal(formatUnits('core', 2), '2');
    assert.equal(formatUnits('IP', 1), '1');
    assert.equal(formatUnits('', 3), '3');
    assert.equal(formatUnits('KB/s', 512), '512 KB/s');
    assert.equal(formatUnits('', 2, 'en', ['CX23', 'CX33', 'CX43']), 'CX43');
    assert.equal(formatUnits('MB', 1536, 'de'), '1,5 GB');
});

test('money follows the currency and shows cents only when there are some', () => {
    assert.equal(formatMoney(200, 'USD'), '$2.00');
    assert.equal(formatMoney(1250, 'USD'), '$12.50');
    assert.equal(formatMoney(5, 'USD'), '$0.05');
});

test('the price of a step depends on the cycle', () => {
    assert.deepEqual(stepPrice(ram(), 'monthly'), { step: 200, setup: 50 });
    assert.deepEqual(stepPrice(ram(), 'annually'), { step: 2000, setup: 0 });
    assert.equal(stepPrice(ram(), 'quarterly'), null);
    assert.equal(stepPrice(ram(), null), null);
    assert.equal(stepPrice({ prices: [] }, 'monthly'), null);
});

test('values are kept whole and inside the range', () => {
    assert.equal(clamp(3, 0, 8), 3);
    assert.equal(clamp(9, 0, 8), 8);
    assert.equal(clamp(-1, 0, 8), 0);
    assert.equal(clamp('2.6', 0, 8), 3);
    assert.equal(clamp('abc', 1, 8), 1);
    assert.equal(clamp(5, 4, 2), 4);
    assert.equal(fill('Only :count left of :counts', { count: 3, counts: 'x' }), 'Only 3 left of x');
});

test('a slider shows the extra, the total and the price for the cycle of the form around it', () => {
    const { component, events } = slider(ram());
    component.cycle = 'monthly';

    assert.equal(component.valueText(), 'No extra');
    assert.equal(component.priceText(), '');
    assert.deepEqual(events.at(-1), { name: 'resource-change', detail: { kind: 'ram', steps: 0, prices: ram().prices, currency: 'USD' } });

    component.setValue(4);

    assert.equal(component.valueText(), '+4 GB');
    assert.equal(component.totalText(), '8 GB');
    assert.equal(component.priceText(), '+$8.00/mo · + $2.00 setup');
    assert.equal(component.ariaText(), '4 GB extra, 8 GB in total, $8.00 (Monthly)');
    assert.equal(events.at(-1).detail.steps, 4);

    // The surrounding form picks another cycle.
    component.cycle = 'annually';
    assert.equal(component.priceText(), '+$80.00/yr');
    assert.equal(component.scaleMin(), '0');
    assert.equal(component.scaleMax(), '+8 GB');
});

test('without JavaScript the help says the same as with it', () => {
    const { component } = slider(ram());

    assert.equal(
        component.helpText(),
        'Each step adds 1 GB. The plan includes 4 GB. Price per step: $2.00/mo · $20.00/yr. Takes effect after a restart.',
    );
});

test('steps are clamped to the range, also when the option changes', () => {
    const { component } = slider(ram({ value: 12 }));

    assert.equal(component.value, 8, 'A default above the most is lowered.');

    component.setValue(20);
    assert.equal(component.value, 8);

    component.setValue(-3);
    assert.equal(component.value, 0);

    component.setOption(ram({ max: 2, value: 5 }));
    assert.equal(component.value, 2);
    assert.equal(component.option.max, 2);
});

test('an option not priced for the chosen cycle is hidden and reports no steps', () => {
    const { component, events } = slider(ram({ value: 2, prices: { annually: { step: 2000, setup: 0 } } }));
    component.cycle = 'monthly';

    assert.equal(component.offered, false);
    assert.equal(component.locked, true);

    component.setValue(3);
    assert.equal(events.at(-1).detail.steps, 0);

    component.cycle = 'annually';
    assert.equal(component.offered, true);
});

test('a page with one cycle, such as a service, fixes it in the config', () => {
    const { component } = slider(ram({ value: 1 }), { cycle: 'annually' });

    assert.equal(component.activeCycle(), 'annually');
    assert.equal(component.priceText(), '+$20.00/yr');
});

test('a one-step option is a switch', () => {
    const backups = {
        kind: 'backups', label: 'Backups', unit: '', step: 1, included: null, min: 0, max: 1, value: 0, toggle: true,
        ladder: null, currency: 'USD', prices: { monthly: { step: 300, setup: 0 } },
    };
    const { component, events } = slider(backups);
    component.cycle = 'monthly';

    assert.equal(component.valueText(), 'Off');
    assert.equal(component.stepPriceText(), '+$3.00/mo');
    assert.equal(component.helpText(), 'Price: $3.00/mo.');

    component.toggleTo(true);
    assert.equal(component.value, 1);
    assert.equal(component.valueText(), 'On');
    assert.equal(events.at(-1).detail.steps, 1);

    component.toggleTo(false);
    assert.equal(component.value, 0);
});

test('a ladder shows the names of its sizes', () => {
    const size = {
        kind: 'hetzner_cloud_size', label: 'Server size', unit: '', step: 1, included: null, min: 0, max: 2, value: 1,
        ladder: ['CX23', 'CX33', 'CX43'], currency: 'USD', prices: { monthly: { step: 500, setup: 0 } },
    };
    const { component } = slider(size);

    assert.equal(component.valueText(), 'CX33');
    assert.equal(component.ariaText(), 'CX33');
    assert.equal(component.scaleMin(), 'CX23');
    assert.equal(component.scaleMax(), 'CX43');
    assert.equal(component.helpText(), 'Price per step: $5.00/mo.');
});

test('stock that limits the slider is shown', () => {
    assert.equal(slider(ram({ max: 3, stock_left: 3 })).component.stockText(), 'Only 3 GB left');
    assert.equal(slider(ram({ max: 0, stock_left: 0 })).component.stockText(), 'Sold out right now.');
    assert.equal(slider(ram({ max: 8, stock_left: 50 })).component.stockText(), '');
    assert.equal(slider(ram()).component.stockText(), '');
});

test('the extras of several sliders add up for the chosen cycle', () => {
    const entries = {
        ram: { steps: 2, prices: { monthly: { step: 200, setup: 50 } } },
        ipv4: { steps: 1, prices: { monthly: { step: 300, setup: 0 }, annually: { step: 3000, setup: 0 } } },
        disk: { steps: 0, prices: { monthly: { step: 100, setup: 0 } } },
    };

    assert.deepEqual(extrasTotal(entries, 'monthly'), { price: 700, setup: 100 });
    assert.deepEqual(extrasTotal(entries, 'annually'), { price: 3000, setup: 0 });

    const quote = resourceQuote({ locale: 'en', text: { extras: 'Extras: :amount', noExtras: 'No extras chosen.', setup: '+ :amount setup', suffixes: text.suffixes } });
    quote.cycle = 'monthly';
    assert.equal(quote.totalText(), 'No extras chosen.');

    quote.track({ kind: 'ram', steps: 2, prices: entries.ram.prices, currency: 'USD' });
    quote.track({ kind: 'ipv4', steps: 1, prices: entries.ipv4.prices, currency: 'USD' });
    assert.equal(quote.totalText(), 'Extras: +$7.00/mo · + $1.00 setup');

    quote.track({ kind: 'ram', steps: 0, prices: entries.ram.prices, currency: 'USD' });
    assert.equal(quote.totalText(), 'Extras: +$3.00/mo');
    quote.track({ kind: '' });
    assert.deepEqual(Object.keys(quote.entries), ['ram', 'ipv4']);
});

// The quote of a change on a service asks the server.
let requests = [];

beforeEach(() => {
    requests = [];
    mock.timers.enable({ apis: ['setTimeout'] });
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
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

const remote = () => resourceQuote({ url: '/client/services/5/resources/quote', csrf: 'token-1', cycle: 'monthly', text: { failed: 'We could not work out the price. Try again.' } });

test('the quote waits until the sliders stop, then asks once with the steps and the CSRF token', async () => {
    const quote = remote();

    quote.track({ kind: 'ram', steps: 1, prices: {} });
    mock.timers.tick(100);
    quote.track({ kind: 'ram', steps: 2, prices: {} });
    quote.track({ kind: 'ipv4', steps: 1, prices: {} });
    mock.timers.tick(249);
    assert.equal(requests.length, 0);

    mock.timers.tick(1);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/client/services/5/resources/quote');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'token-1');
    assert.deepEqual(JSON.parse(requests[0].options.body), { steps: { ram: 2, ipv4: 1 } });
    assert.equal(quote.loading, true);

    requests[0].answer({ today: '$3.29', expected: 329 });
    await settle();

    assert.equal(quote.loading, false);
    assert.equal(quote.quote.today, '$3.29');
    assert.equal(quote.expected, 329, 'The confirm form posts what the client saw.');
});

test('only the answer to the last question is used', async () => {
    const quote = remote();

    quote.track({ kind: 'ram', steps: 1, prices: {} });
    mock.timers.tick(250);
    quote.track({ kind: 'ram', steps: 3, prices: {} });
    mock.timers.tick(250);
    assert.equal(requests.length, 2);

    requests[1].answer({ today: '$9.00', expected: 900 });
    await settle();
    requests[0].answer({ today: '$3.00', expected: 300 });
    await settle();

    assert.equal(quote.quote.today, '$9.00');
    assert.equal(quote.expected, 900);
    assert.equal(quote.loading, false);
});

test('a refused quote shows the message and keeps the last good expected amount', async () => {
    const quote = remote();

    quote.track({ kind: 'ram', steps: 1, prices: {} });
    mock.timers.tick(250);
    requests[0].answer({ expected: 200 });
    await settle();

    quote.track({ kind: 'disk', steps: 0, prices: {} });
    mock.timers.tick(250);
    requests[1].answer({ message: 'The given data was invalid.', errors: { 'steps.disk': ['Disk space cannot go below what you have now.'] } }, 422);
    await settle();

    assert.equal(quote.error, 'Disk space cannot go below what you have now.');
    assert.equal(quote.quote, null);
    assert.equal(quote.expected, 200);

    quote.track({ kind: 'disk', steps: 1, prices: {} });
    mock.timers.tick(250);
    requests[2].fail();
    await settle();

    assert.equal(quote.error, 'We could not work out the price. Try again.');
    assert.equal(quote.loading, false);
});

test('without a URL nothing is asked', () => {
    const quote = resourceQuote({ cycle: 'monthly' });

    quote.track({ kind: 'ram', steps: 2, prices: {} });
    mock.timers.tick(1000);

    assert.equal(requests.length, 0);
    assert.deepEqual(quote.steps(), { ram: 2 });
});
