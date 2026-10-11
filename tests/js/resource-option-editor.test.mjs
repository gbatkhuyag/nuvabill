import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import {
    convertPrices,
    parseAmount,
    priceGrid,
    resourceOptionEditor,
    scaleCycles,
    toMinor,
} from '../../resources/js/resource-option-editor.js';

const cycles = ['monthly', 'quarterly', 'annually', 'onetime'];

test('typed amounts are read without floating point surprises', () => {
    assert.equal(parseAmount('12.50'), 12.5);
    assert.equal(parseAmount('1,200'), 1200);
    assert.equal(parseAmount(''), null);
    assert.equal(parseAmount('-1'), null);
    assert.equal(parseAmount('1e3'), null);
    assert.equal(toMinor('0.1'), 10);
    assert.equal(toMinor('2.675'), 268);
});

test('other cycles are filled from one by months, and typed setup fees stay', () => {
    const row = {
        monthly: { price: '2.00', setup_fee: '1.00' },
        quarterly: { price: '', setup_fee: '' },
        annually: { price: '30.00', setup_fee: '0.50' },
        onetime: { price: '', setup_fee: '' },
    };

    const next = scaleCycles(row, 'monthly', cycles);

    assert.deepEqual(next.quarterly, { price: '6.00', setup_fee: '1.00' });
    assert.deepEqual(next.annually, { price: '24.00', setup_fee: '0.50' });
    assert.deepEqual(next.onetime, { price: '', setup_fee: '' }, 'A one-time price has no months to scale by.');
    assert.deepEqual(next.monthly, row.monthly);
    assert.deepEqual(scaleCycles({ monthly: { price: '' } }, 'monthly', cycles), { monthly: { price: '' } });
});

test('another currency is filled from the billing currency at the rate, rounded to the cent', () => {
    const base = { monthly: { price: '2.00', setup_fee: '0.35' }, quarterly: { price: '', setup_fee: '' }, annually: { price: '19.99', setup_fee: '' } };
    const target = { monthly: { price: '', setup_fee: '' }, quarterly: { price: '9.00', setup_fee: '' }, annually: { price: '', setup_fee: '' } };

    const next = convertPrices(target, base, 1310, ['monthly', 'quarterly', 'annually']);

    assert.deepEqual(next.monthly, { price: '2620.00', setup_fee: '458.50' });
    assert.deepEqual(next.quarterly, { price: '9.00', setup_fee: '' }, 'A cycle without a base price keeps what was typed.');
    assert.deepEqual(next.annually, { price: '26186.90', setup_fee: '0.00' });
    assert.deepEqual(convertPrices(target, base, 0, ['monthly']), target);
});

const gridConfig = () => ({
    id: 'pg',
    base: 'USD',
    currencies: ['USD', 'IQD', 'EUR'],
    cycles: ['monthly', 'annually'],
    fillFrom: 'monthly',
    rates: { IQD: 1310 },
    values: { USD: { monthly: { price: '2.00', setup_fee: '0.00' } } },
});

test('the price grid has a tab per currency and fills from the billing currency', () => {
    const events = [];
    const grid = priceGrid(gridConfig());
    grid.$dispatch = (name, detail) => events.push({ name, detail });
    grid.init();

    assert.equal(grid.tab, 'USD');
    assert.equal(grid.priced('USD'), 1);
    assert.equal(grid.priced('IQD'), 0);
    assert.deepEqual(grid.values.EUR.annually, { price: '', setup_fee: '' }, 'Every currency and cycle has a field.');
    assert.equal(events.at(-1).name, 'price-change');

    assert.equal(grid.canConvert('IQD'), true);
    assert.equal(grid.canConvert('EUR'), false, 'No rate for EUR.');
    assert.equal(grid.canConvert('USD'), false);

    grid.select('IQD');
    grid.fillFromBase('IQD');
    assert.equal(grid.values.IQD.monthly.price, '2620.00');
    assert.equal(events.at(-1).detail.tab, 'IQD');
    assert.equal(events.at(-1).detail.values.IQD.monthly.price, '2620.00');

    grid.fillCycles('USD');
    assert.equal(grid.values.USD.annually.price, '24.00');

    grid.select('GBP');
    assert.equal(grid.tab, 'IQD', 'A currency that is not on the grid is ignored.');
});

test('arrow keys move between the currency tabs', () => {
    const grid = priceGrid(gridConfig());
    const key = (name, rtl = false) => {
        const event = {
            key: name,
            prevented: false,
            target: { closest: () => (rtl ? { getAttribute: () => 'rtl' } : null) },
            preventDefault() {
                this.prevented = true;
            },
        };
        grid.moveTab(event);

        return event;
    };
    globalThis.document = { getElementById: () => null };

    key('ArrowRight');
    assert.equal(grid.tab, 'IQD');
    key('End');
    assert.equal(grid.tab, 'EUR');
    key('ArrowRight');
    assert.equal(grid.tab, 'USD', 'It wraps around.');
    key('ArrowRight', true);
    assert.equal(grid.tab, 'EUR', 'Right goes back on a right-to-left page.');
    assert.equal(key('a').prevented, false);

    delete globalThis.document;
});

const kind = (extra = {}) => ({
    key: 'ram', label: 'RAM', unit: 'MB', minStep: 128, stepMultiple: 1, maxExtra: 16384, ladder: null, restarts: true, note: null, ...extra,
});

const text = {
    restarts: 'Takes effect after a restart.',
    noPrice: 'No price for :cycle, so clients who pay :cycle do not see this option.',
    minStep: 'Each step must add at least :amount.',
    multiple: 'Each step must be a multiple of :amount.',
    ladder: 'This kind has :count sizes above the plan, so choose at most :count.',
    maxExtra: 'Most extra steps × step size is more than this server type allows (:max).',
    minMax: 'The fewest steps cannot be more than the most steps.',
    defaultRange: 'Steps chosen at first must be between the fewest and the most.',
    unlimited: 'The plan has unlimited traffic, so extra traffic does nothing.',
    baseFailed: 'The server did not answer.',
    baseUnknown: 'The server does not say how much the plan includes.',
    baseDone: 'The plan includes :amount.',
};

const editor = (extra = {}) => resourceOptionEditor({
    kind: kind(extra.kind),
    form: { label: '', description: '', step_size: '1', step_unit: 'GB', included: '4', included_unit: 'GB', min_steps: '0', max_steps: '8', default_steps: '1', stock: '', ...extra.form },
    cycles: [{ value: 'monthly', label: 'Monthly' }, { value: 'annually', label: 'Yearly' }],
    base: 'USD',
    usedSteps: extra.usedSteps ?? 0,
    baseUrl: '/admin/products/3/resources/base',
    csrf: 'token-1',
    locale: 'en',
    unlimitedZero: extra.unlimitedZero ?? false,
    text,
});

test('the preview is the option as the store will send it', () => {
    const form = editor();
    form.onPrices({ tab: 'USD', values: { USD: { monthly: { price: '2.00', setup_fee: '0.50' }, annually: { price: '', setup_fee: '' } } } });

    assert.deepEqual(form.preview(), {
        kind: 'ram',
        label: 'RAM',
        description: null,
        unit: 'MB',
        step: 1024,
        included: 4096,
        min: 0,
        max: 8,
        value: 1,
        toggle: false,
        ladder: null,
        restarts: true,
        note: 'Takes effect after a restart.',
        stock_left: null,
        currency: 'USD',
        prices: { monthly: { step: 200, setup: 50 } },
    });

    form.form.label = '  Extra memory ';
    form.form.max_steps = '1';
    form.form.stock = '5';
    assert.equal(form.preview().label, 'Extra memory');
    assert.equal(form.preview().toggle, true, 'One step shows as a switch.');
    assert.equal(form.preview().stock_left, 5);
});

test('stock in the preview is what is left after the steps clients have', () => {
    const form = editor({ usedSteps: 3, form: { stock: '5' } });

    assert.equal(form.preview().stock_left, 2);
    assert.equal(form.preview().max, 2);
});

test('flipping MB and GB keeps the same amount', () => {
    const form = editor({ form: { step_size: '2', step_unit: 'GB' } });

    form.switchUnit('step_size', 'step_unit', 'MB');
    assert.equal(form.form.step_size, '2048');
    assert.equal(form.form.step_unit, 'MB');
    assert.equal(form.stepUnits(), 2048);

    form.form.step_size = '1536';
    form.switchUnit('step_size', 'step_unit', 'GB');
    assert.equal(form.form.step_size, '1.5');
    assert.equal(form.stepUnits(), 1536);
});

test('warnings follow what staff type', () => {
    const form = editor();
    form.onPrices({ tab: 'USD', values: { USD: { monthly: { price: '2.00', setup_fee: '' } } } });

    assert.deepEqual(form.warnings(), ['No price for Yearly, so clients who pay Yearly do not see this option.']);

    form.form.step_unit = 'MB';
    form.form.step_size = '64';
    form.form.max_steps = '300';
    form.form.default_steps = '400';
    assert.deepEqual(form.warnings().slice(1), [
        'Each step must add at least 128 MB.',
        'Most extra steps × step size is more than this server type allows (16 GB).',
        'Steps chosen at first must be between the fewest and the most.',
    ]);

    form.form.min_steps = '500';
    assert.equal(form.warnings().at(-1), 'The fewest steps cannot be more than the most steps.');
});

test('a ladder allows only its sizes', () => {
    const form = editor({ kind: { key: 'panelfake_size', unit: '', minStep: 1, maxExtra: 2, ladder: ['Plan size', 'Bigger', 'Biggest'] }, form: { max_steps: '3', step_size: '7' } });
    form.onPrices({ tab: 'USD', values: { USD: { monthly: { price: '5' }, annually: { price: '50' } } } });

    assert.equal(form.stepUnits(), 1);
    assert.deepEqual(form.warnings(), ['This kind has 2 sizes above the plan, so choose at most 2.']);
});

let requests = [];

beforeEach(() => {
    requests = [];
    globalThis.fetch = (url, options = {}) =>
        new Promise((resolve) => {
            requests.push({ url, options, answer: (body, status = 200) => resolve({ ok: status >= 200 && status < 300, status, json: async () => body }) });
        });
});

afterEach(() => {
    delete globalThis.fetch;
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('read from server fills what the plan includes', async () => {
    const form = editor({ form: { included: '', included_unit: 'MB' } });

    const reading = form.readBase();
    assert.equal(form.baseLoading, true);
    assert.equal(requests[0].url, '/admin/products/3/resources/base');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'token-1');

    form.readBase();
    assert.equal(requests.length, 1, 'A second click while reading does nothing.');

    requests[0].answer({ base: { ram: 2048, cpu: 2 } });
    await reading;

    assert.equal(form.form.included, '2');
    assert.equal(form.form.included_unit, 'GB');
    assert.equal(form.baseMessage, 'The plan includes 2 GB.');
    assert.equal(form.baseLoading, false);
});

test('read from server shows the staff message, or says the plan does not tell', async () => {
    const form = editor({ kind: { key: 'bandwidth', label: 'Monthly traffic', unit: 'GB', minStep: 1, maxExtra: null }, unlimitedZero: true });
    form.onPrices({ tab: 'USD', values: { USD: { monthly: { price: '1' }, annually: { price: '10' } } } });

    let reading = form.readBase();
    requests[0].answer({ message: 'The server did not answer. Check the server and try again.' }, 422);
    await reading;
    assert.equal(form.baseMessage, 'The server did not answer. Check the server and try again.');

    reading = form.readBase();
    requests[1].answer({ base: { bandwidth: null } });
    await reading;
    assert.equal(form.baseMessage, 'The server does not say how much the plan includes.');

    reading = form.readBase();
    requests[2].answer({ base: { bandwidth: 0 } });
    await reading;
    assert.equal(form.form.included, '0');
    assert.deepEqual(form.warnings(), ['The plan has unlimited traffic, so extra traffic does nothing.']);
    await settle();
});
