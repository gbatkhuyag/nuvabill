import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    MAX_POINTS,
    barChart,
    downsample,
    formatBytes,
    formatValue,
    lineChart,
    linePath,
    niceMax,
    sparkline,
    stats,
    timeTicks,
} from '../../resources/js/panel-chart.js';

/**
 * A tiny stand-in for the DOM: elements with attributes, children, text and listeners. It has no
 * innerHTML at all, so a chart that tried to write HTML would fail here.
 */
class FakeElement {
    constructor(tag, namespace, doc) {
        this.tagName = tag;
        this.namespaceURI = namespace;
        this.ownerDocument = doc;
        this.attributes = new Map();
        this.children = [];
        this.textContent = '';
        this.listeners = {};
        this.style = { props: {}, setProperty(name, value) { this.props[name] = value; } };
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    append(...nodes) {
        this.children.push(...nodes);
    }

    insertBefore(node, reference) {
        const index = this.children.indexOf(reference);
        index < 0 ? this.children.push(node) : this.children.splice(index, 0, node);
    }

    get firstChild() {
        return this.children[0] ?? null;
    }

    replaceChildren(...nodes) {
        this.children = [...nodes];
    }

    addEventListener(name, handler) {
        (this.listeners[name] ??= []).push(handler);
    }

    fire(name, event) {
        (this.listeners[name] ?? []).forEach((handler) => handler(event));
    }

    getBoundingClientRect() {
        return { left: 0, top: 0, width: 640, height: 220 };
    }

    all(predicate) {
        return this.children.flatMap((child) => [...(predicate(child) ? [child] : []), ...child.all(predicate)]);
    }

    byClass(name) {
        return this.all((element) => (element.getAttribute('class') ?? '').split(' ').includes(name));
    }
}

function container() {
    const doc = {
        createElement: (tag) => new FakeElement(tag, null, doc),
        createElementNS: (namespace, tag) => new FakeElement(tag, namespace, doc),
    };

    return new FakeElement('div', null, doc);
}

const HOUR = 3600;

test('long series are averaged in buckets and keep gaps that have no numbers', () => {
    const points = Array.from({ length: 800 }, (_, index) => [index * 60, index % 2 === 0 ? 10 : 20]);
    const short = downsample(points);

    assert.ok(short.length <= MAX_POINTS);
    assert.equal(short[0][0], 0);
    assert.equal(short[0][1], 15);

    assert.deepEqual(downsample([[0, null], [60, null], [120, 4], [180, 6]], 2), [[0, null], [120, 5]]);
    assert.deepEqual(downsample('nonsense'), []);
    assert.deepEqual(downsample([[0, 1], ['x', 2], null]), [[0, 1]]);
});

test('the value axis gets a round top', () => {
    assert.equal(niceMax(0), 1);
    assert.equal(niceMax(37), 50);
    assert.equal(niceMax(99), 100);
    assert.equal(niceMax(100), 100);
    assert.equal(niceMax(101), 200);
    assert.equal(niceMax(0.3), 0.5);
    assert.equal(niceMax(2400), 2500);
});

test('numbers get their units', () => {
    assert.equal(formatValue(12.345, '%', 'en'), '12.3%');
    assert.equal(formatValue(1536, 'B/s', 'en'), '1.5 KB/s');
    assert.equal(formatValue(3 * 1024 ** 3, 'B', 'en'), '3 GB');
    assert.equal(formatValue(42, 'iops', 'en'), '42 iops');
    assert.equal(formatValue(null, '%', 'en'), '–');
    assert.equal(formatBytes(512, 'en'), '512 B');
    assert.deepEqual(stats([2, null, 4, 6]), { min: 2, max: 6, avg: 4 });
    assert.equal(stats([null]), null);
    assert.deepEqual(timeTicks(0, 400, 5), [0, 100, 200, 300, 400]);
});

test('a missing value breaks the line and a lone point is drawn as a dot', () => {
    const path = linePath([[0, 1], [1, 2], [2, null], [3, 4], [4, null]], (time) => time * 10, (value) => value);

    assert.equal(path, 'M0 1L10 2M30 4h0.1');
});

test('a line chart draws each series, a summary for screen readers and text-only labels', () => {
    const el = container();
    const series = [
        { key: 'net_in', label: 'In <b>', unit: 'B/s', points: [[0, 100], [HOUR, 300], [2 * HOUR, null], [3 * HOUR, 200]] },
        { key: 'net_out', label: 'Out', unit: 'B/s', points: [[0, 50], [HOUR, 60], [2 * HOUR, 70], [3 * HOUR, 80]] },
    ];

    lineChart(el, { series, unit: 'B/s', label: 'Network', locale: 'en', messages: { summary: ':label: :min to :max, average :avg' } });

    const lines = el.byClass('chart-line');
    assert.equal(lines.length, 2);
    assert.equal(lines[0].getAttribute('vector-effect'), 'non-scaling-stroke');
    assert.equal(lines[1].getAttribute('data-color'), '1');

    const svg = el.byClass('chart-svg')[0];
    assert.equal(svg.getAttribute('role'), 'img');
    assert.equal(svg.getAttribute('preserveAspectRatio'), 'none');
    assert.equal(svg.getAttribute('aria-label'), 'In <b>: 100 B/s to 300 B/s, average 200 B/s; Out: 50 B/s to 80 B/s, average 65 B/s');
    assert.equal(el.byClass('chart-grid').length, 5);

    const legend = el.byClass('chart-legend')[0];
    assert.deepEqual(legend.children.map((item) => item.children[1].textContent), ['In <b>', 'Out']);

    // The pointer shows the values at the nearest time, as text.
    const plot = el.byClass('chart-plot')[0];
    const tooltip = el.byClass('chart-tooltip')[0];
    assert.equal(tooltip.getAttribute('hidden'), '');
    plot.fire('pointermove', { clientX: 640 });
    assert.equal(tooltip.getAttribute('hidden'), null);
    assert.deepEqual(tooltip.children.slice(1).map((line) => line.textContent), ['In <b>: 200 B/s', 'Out: 80 B/s']);
    plot.fire('pointerleave', {});
    assert.equal(tooltip.getAttribute('hidden'), '');
});

test('a chart without numbers says so', () => {
    const el = container();

    lineChart(el, { series: [{ key: 'cpu', label: 'CPU', points: [[0, null]] }], messages: { empty: 'No data for this period yet.' } });

    assert.equal(el.children.length, 1);
    assert.equal(el.children[0].textContent, 'No data for this period yet.');
});

test('a series longer than 400 points is drawn with at most 400', () => {
    const el = container();
    const points = Array.from({ length: 2000 }, (_, index) => [index * 60, index]);

    lineChart(el, { series: [{ key: 'cpu', label: 'CPU', points }], unit: '%' });

    const path = el.byClass('chart-line')[0].getAttribute('d');
    assert.ok(path.split(/[ML]/).filter(Boolean).length <= MAX_POINTS);
});

test('a bar chart draws in and out for each day', () => {
    const el = container();

    barChart(el, { label: 'Traffic', unit: 'B', names: ['In', 'Out'], points: [[0, 1024, 2048], [86400, null, 512]], locale: 'en' });

    const bars = el.byClass('chart-bar');
    assert.equal(bars.length, 3);
    assert.deepEqual(bars.map((bar) => bar.getAttribute('data-color')), ['0', '1', '1']);
    assert.ok(bars.every((bar) => Number(bar.getAttribute('height')) >= 0));
    assert.deepEqual(el.byClass('chart-legend')[0].children.map((item) => item.children[1].textContent), ['In', 'Out']);
});

test('a sparkline needs two numbers and can draw a second line', () => {
    const el = container();

    sparkline(el, [5]);
    assert.equal(el.children.length, 0);

    sparkline(el, [1, 2, 3], { second: [3, 2, 1] });
    const svg = el.children[0];
    assert.equal(svg.getAttribute('aria-hidden'), 'true');
    assert.deepEqual(svg.children.map((path) => path.getAttribute('data-color')), ['0', '1']);
});
