import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { resourceQuote } from '../../resources/js/resource-sliders.js';

/*
 * The resources card on a service and the staff change form: no question while the sliders are
 * back at what the service has, staff fields posted with the steps, and the server state read
 * every 10 seconds while the server works on the last change.
 */

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

test('sliders back at what the service has ask nothing, and an answer on its way is dropped', async () => {
    const quote = resourceQuote({ url: '/client/services/5/resources/quote', cycle: 'monthly', initial: { ram: 2, disk: 0 } });

    // Every slider reports its value once when the page starts.
    quote.track({ kind: 'ram', steps: 2, prices: {} });
    quote.track({ kind: 'disk', steps: 0, prices: {} });
    mock.timers.tick(1000);
    assert.equal(requests.length, 0);

    quote.track({ kind: 'ram', steps: 4, prices: {} });
    mock.timers.tick(250);
    assert.equal(requests.length, 1);

    // Moved back before the answer came.
    quote.track({ kind: 'ram', steps: 2, prices: {} });
    requests[0].answer({ button: 'Pay $3.29 and upgrade', expected: 329 });
    await settle();

    assert.equal(quote.quote, null);
    assert.equal(quote.loading, false);
    assert.equal(requests.length, 1);
});

test('staff fields go with the steps, and refresh asks again', async () => {
    const quote = resourceQuote({ url: '/admin/services/5/resources/quote', csrf: 'token-2', initial: { ram: 2 }, fields: { amounts: {}, charge: '1' } });

    quote.track({ kind: 'ram', steps: 2, prices: {} });
    mock.timers.tick(250);
    assert.equal(requests.length, 1, 'Staff may change only the price, so the same steps still ask.');

    quote.fields.amounts = { ram: '7.50' };
    quote.fields.charge = '0';
    quote.refresh();
    mock.timers.tick(250);

    assert.equal(requests.length, 2);
    assert.deepEqual(JSON.parse(requests[1].options.body), { amounts: { ram: '7.50' }, charge: '0', steps: { ram: 2 } });
    assert.equal(requests[1].options.headers['X-CSRF-TOKEN'], 'token-2');
});

test('the server state is read every 10 seconds while it works, and stops when it is done', async () => {
    const quote = resourceQuote({ status: { url: '/client/services/5/resources', state: 'working', text: 'We are adding your resources.' } });

    quote.init();
    mock.timers.tick(9999);
    assert.equal(requests.length, 0);

    mock.timers.tick(1);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/client/services/5/resources');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    requests[0].answer({ state: 'working', text: 'We are adding your resources.' });
    await settle();

    mock.timers.tick(10000);
    assert.equal(requests.length, 2);
    requests[1].answer({ state: 'done', text: 'Your new resources are ready.' });
    await settle();

    assert.equal(quote.serverState, 'done');
    assert.equal(quote.serverText, 'Your new resources are ready.');
    mock.timers.tick(60000);
    assert.equal(requests.length, 2, 'Nothing more is asked once the server is done.');
});

test('a missed answer is asked again, and the reading stops after the limit', async () => {
    const quote = resourceQuote({ status: { url: '/client/services/5/resources', state: 'working', limit: 2 } });

    quote.init();
    mock.timers.tick(10000);
    requests[0].fail();
    await settle();

    mock.timers.tick(10000);
    assert.equal(requests.length, 2);
    requests[1].answer({}, 500);
    await settle();

    mock.timers.tick(60000);
    assert.equal(requests.length, 2);
    assert.equal(quote.serverState, 'working');
});

test('a card with nothing running reads nothing', () => {
    const quote = resourceQuote({ status: { url: '/client/services/5/resources', state: 'done', text: 'Your new resources are ready.' } });

    quote.init();
    mock.timers.tick(60000);

    assert.equal(requests.length, 0);
    assert.equal(quote.serverText, 'Your new resources are ready.');
});
