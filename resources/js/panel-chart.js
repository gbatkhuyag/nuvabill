/**
 * Charts for the server panel's Graphs tab and the overview's live figures, drawn as SVG without a
 * library. Lines and bars stretch with their box (vector-effect keeps the strokes thin), and the
 * labels sit in an HTML layer on top, so text never stretches. Values reach the page only through
 * textContent and attributes, never as HTML. Charts read left to right, also on right-to-left pages.
 *
 * Points are [unix seconds, value or null]; a null leaves a gap in the line. Bars are
 * [unix seconds, in, out]. Colors come from the --nb-chart-1..4 tokens (data-color 0..3).
 */

const SVG = 'http://www.w3.org/2000/svg';

/** The drawing box in SVG units; the chart stretches it to its container. */
export const WIDTH = 640;
export const HEIGHT = 220;

/** Points per series at most; longer series are averaged in buckets. */
export const MAX_POINTS = 400;

const PAD = 6;
const GRID_LINES = 4;
const COLORS = 4;
const X_TICKS = 5;

export function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

/**
 * At most `max` rows: longer lists are cut into equal buckets, each the average of its numbers per
 * column (null when a bucket column has none), timed at the bucket's first row.
 *
 * @param {Array<Array<number|null>>} points
 * @param {number} max
 * @returns {Array<Array<number|null>>}
 */
export function downsample(points, max = MAX_POINTS) {
    if (!Array.isArray(points)) {
        return [];
    }

    const rows = points.filter((row) => Array.isArray(row) && isNumber(row[0]));

    if (rows.length <= max) {
        return rows;
    }

    const size = Math.ceil(rows.length / max);
    const columns = Math.max(...rows.map((row) => row.length));
    const result = [];

    for (let start = 0; start < rows.length; start += size) {
        const bucket = rows.slice(start, start + size);
        const row = [bucket[0][0]];

        for (let column = 1; column < columns; column++) {
            const values = bucket.map((item) => item[column]).filter(isNumber);
            row.push(values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null);
        }

        result.push(row);
    }

    return result;
}

/**
 * A round top for the value axis: 1, 2, 2.5 or 5 times a power of ten, at least the value.
 */
export function niceMax(value) {
    if (!isNumber(value) || value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const step of [1, 2, 2.5, 5, 10]) {
        if (value <= step * magnitude * (1 + 1e-9)) {
            return step * magnitude;
        }
    }

    return 10 * magnitude;
}

/**
 * The lowest, highest and average number, or null when there is none.
 *
 * @param {Array<number|null>} values
 * @returns {{min: number, max: number, avg: number}|null}
 */
export function stats(values) {
    const numbers = (Array.isArray(values) ? values : []).filter(isNumber);

    if (numbers.length === 0) {
        return null;
    }

    return {
        min: Math.min(...numbers),
        max: Math.max(...numbers),
        avg: numbers.reduce((sum, value) => sum + value, 0) / numbers.length,
    };
}

export function formatNumber(value, locale, digits = 1) {
    if (!isNumber(value)) {
        return '–';
    }

    try {
        return new Intl.NumberFormat(locale || undefined, { maximumFractionDigits: digits }).format(value);
    } catch {
        return String(Math.round(value * 10 ** digits) / 10 ** digits);
    }
}

/**
 * Bytes (or bytes a second) in binary steps: 1536 → "1.5 KB".
 */
export function formatBytes(bytes, locale, perSecond = false) {
    if (!isNumber(bytes)) {
        return '–';
    }

    const units = perSecond ? ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'] : ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.max(0, bytes);
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${formatNumber(value, locale, unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/**
 * A value with its unit: "%" as a percentage, "B" and "B/s" in binary steps, others as "12 iops".
 */
export function formatValue(value, unit = '', locale = undefined) {
    if (!isNumber(value)) {
        return '–';
    }

    if (unit === '%') {
        return `${formatNumber(value, locale, 1)}%`;
    }

    if (unit === 'B' || unit === 'B/s') {
        return formatBytes(value, locale, unit === 'B/s');
    }

    return unit ? `${formatNumber(value, locale, 2)} ${unit}` : formatNumber(value, locale, 2);
}

/**
 * A time label: the time of day for spans up to two days, else the date.
 */
export function formatTime(unix, locale, span = 0, full = false) {
    if (!isNumber(unix)) {
        return '–';
    }

    const options = full
        ? { dateStyle: 'medium', timeStyle: 'short' }
        : span > 2 * 86400 ? { day: 'numeric', month: 'short' } : { hour: '2-digit', minute: '2-digit' };

    try {
        return new Intl.DateTimeFormat(locale || undefined, options).format(new Date(unix * 1000));
    } catch {
        return new Date(unix * 1000).toISOString().slice(0, 16).replace('T', ' ');
    }
}

/**
 * `count` evenly spaced times from start to end (one time when they are equal).
 */
export function timeTicks(start, end, count = X_TICKS) {
    if (!isNumber(start) || !isNumber(end)) {
        return [];
    }

    if (end <= start || count < 2) {
        return [start];
    }

    return Array.from({ length: count }, (_, index) => Math.round(start + ((end - start) * index) / (count - 1)));
}

/**
 * The SVG path of one column of the points. A null starts a new stroke; a lone point becomes a dot.
 */
export function linePath(points, x, y, column = 1) {
    const parts = [];
    let run = 0;

    for (const row of points) {
        const value = row[column];

        if (!isNumber(value)) {
            if (run === 1) {
                parts.push('h0.1');
            }

            run = 0;
            continue;
        }

        parts.push(`${run === 0 ? 'M' : 'L'}${round(x(row[0]))} ${round(y(value))}`);
        run++;
    }

    if (run === 1) {
        parts.push('h0.1');
    }

    return parts.join('');
}

/**
 * A line chart of one or more series with the same unit.
 *
 * @param {Element} el  Emptied and filled with the chart.
 * @param {{
 *   series: Array<{key: string, label: string, unit?: string, points: Array<Array<number|null>>}>,
 *   unit?: string,
 *   label?: string,
 *   locale?: string,
 *   messages?: {empty?: string, summary?: string},
 * }} options  summary is a text with :label, :min, :max and :avg, such as ":label: lowest :min, highest :max, average :avg".
 */
export function lineChart(el, options = {}) {
    const doc = el.ownerDocument;
    const locale = options.locale;
    const messages = options.messages ?? {};
    const series = (Array.isArray(options.series) ? options.series : [])
        .filter((item) => item && Array.isArray(item.points))
        .map((item, index) => ({ label: String(item.label ?? ''), points: downsample(item.points), color: index % COLORS }));
    const unit = options.unit ?? '';
    const values = series.flatMap((item) => item.points.map((row) => row[1])).filter(isNumber);

    el.replaceChildren();

    if (values.length === 0) {
        el.append(empty(doc, messages.empty));

        return;
    }

    const times = series.flatMap((item) => item.points.map((row) => row[0]));
    const start = Math.min(...times);
    const end = Math.max(...times);
    const top = niceMax(Math.max(...values));
    const x = (time) => (end === start ? WIDTH / 2 : ((time - start) / (end - start)) * WIDTH);
    const y = (value) => PAD + (1 - Math.max(0, Math.min(value, top)) / top) * (HEIGHT - 2 * PAD);
    const summary = series
        .map((item) => describe(item.label, stats(item.points.map((row) => row[1])), unit, locale, messages.summary))
        .filter(Boolean)
        .join('; ');

    const { plot, svg } = frame(doc, summary || options.label || '');

    series.forEach((item) => {
        svg.append(make(doc, 'path', { class: 'chart-line', 'data-color': item.color, d: linePath(item.points, x, y), 'vector-effect': 'non-scaling-stroke' }, SVG));
    });

    axes(doc, plot, top, y, unit, locale, timeTicks(start, end), x, end - start);

    // The pointer shows the values at the nearest time of the longest series.
    const longest = series.reduce((best, item) => (item.points.length > best.points.length ? item : best), series[0]);
    pointer(doc, plot, svg, longest.points.map((row) => row[0]), x, (time) => {
        const lines = [formatTime(time, locale, 0, true)];

        series.forEach((item) => {
            const row = item.points.find((point) => point[0] === time) ?? nearest(item.points, time);
            lines.push(`${item.label}: ${formatValue(row ? row[1] : null, unit, locale)}`);
        });

        return lines;
    }, start, end);

    el.append(plot, legend(doc, series.map((item) => ({ label: item.label, color: item.color }))));
}

/**
 * Two bars per row, such as traffic in and out each day.
 *
 * @param {Element} el
 * @param {{
 *   label?: string,
 *   unit?: string,
 *   points: Array<Array<number|null>>,
 *   names?: Array<string>,
 *   locale?: string,
 *   messages?: {empty?: string, summary?: string},
 * }} options  names are the labels of the two values, such as ["In", "Out"].
 */
export function barChart(el, options = {}) {
    const doc = el.ownerDocument;
    const locale = options.locale;
    const messages = options.messages ?? {};
    const unit = options.unit ?? '';
    const names = Array.isArray(options.names) ? options.names.map(String) : ['', ''];
    const rows = downsample(options.points, MAX_POINTS / 4);
    const values = rows.flatMap((row) => [row[1], row[2]]).filter(isNumber);

    el.replaceChildren();

    if (values.length === 0) {
        el.append(empty(doc, messages.empty));

        return;
    }

    const top = niceMax(Math.max(...values));
    const y = (value) => PAD + (1 - Math.max(0, Math.min(value, top)) / top) * (HEIGHT - 2 * PAD);
    const slot = WIDTH / rows.length;
    const bar = Math.max(1, slot * 0.36);
    const summary = [1, 2]
        .map((column) => describe(names[column - 1] ?? '', stats(rows.map((row) => row[column])), unit, locale, messages.summary))
        .filter(Boolean)
        .join('; ');
    const { plot, svg } = frame(doc, summary || options.label || '');

    rows.forEach((row, index) => {
        [1, 2].forEach((column) => {
            if (!isNumber(row[column])) {
                return;
            }

            const left = index * slot + slot * 0.12 + (column - 1) * bar;
            const height = Math.max(0, HEIGHT - PAD - y(row[column]));

            svg.append(make(doc, 'rect', {
                class: 'chart-bar',
                'data-color': column - 1,
                x: round(left),
                y: round(HEIGHT - PAD - height),
                width: round(bar * 0.92),
                height: round(height),
            }, SVG));
        });
    });

    const times = rows.map((row) => row[0]);
    const first = times[0];
    const last = times[times.length - 1];
    const centre = (time) => {
        const index = times.indexOf(time);

        return (index < 0 ? 0 : index) * slot + slot / 2;
    };
    const ticks = rows.length <= X_TICKS ? times : timeTicks(0, rows.length - 1).map((index) => times[index]);

    axes(doc, plot, top, y, unit, locale, ticks, centre, Math.max(last - first, 3 * 86400));
    pointer(doc, plot, svg, times, centre, (time) => {
        const row = rows[times.indexOf(time)] ?? [];

        return [
            formatTime(time, locale, 3 * 86400),
            `${names[0] ?? ''}: ${formatValue(row[1] ?? null, unit, locale)}`,
            `${names[1] ?? ''}: ${formatValue(row[2] ?? null, unit, locale)}`,
        ];
    }, 0, WIDTH, true);

    el.append(plot, legend(doc, names.map((label, index) => ({ label, color: index }))));
}

/**
 * A small line without axes next to a live figure. It is only decoration: the figure itself is text.
 *
 * @param {Element} el
 * @param {Array<number|null>} values  Oldest first.
 * @param {{max?: number, second?: Array<number|null>}} options  max: a fixed top, such as 100 for a
 *   percentage. second: another line on the same scale, such as traffic out next to traffic in.
 */
export function sparkline(el, values, options = {}) {
    const doc = el.ownerDocument;
    const clean = (list) => (Array.isArray(list) ? list : []).map((value) => (isNumber(value) ? value : null));
    const lines = [clean(values), clean(options.second)].filter((list) => list.filter(isNumber).length >= 2);

    el.replaceChildren();

    if (lines.length === 0) {
        return;
    }

    const width = 120;
    const height = 32;
    const top = isNumber(options.max) && options.max > 0 ? options.max : niceMax(Math.max(...lines.flat().filter(isNumber)));
    const y = (value) => 2 + (1 - Math.max(0, Math.min(value, top)) / top) * (height - 4);
    const svg = make(doc, 'svg', { class: 'sparkline', viewBox: `0 0 ${width} ${height}`, preserveAspectRatio: 'none', 'aria-hidden': 'true', focusable: 'false' }, SVG);

    lines.forEach((list, line) => {
        const x = (index) => (index / (list.length - 1)) * width;

        svg.append(make(doc, 'path', { class: 'sparkline-line', 'data-color': line, d: linePath(list.map((value, index) => [index, value]), x, y), 'vector-effect': 'non-scaling-stroke' }, SVG));
    });

    el.append(svg);
}

function round(value) {
    return Math.round(value * 10) / 10;
}

/**
 * @param {Document} doc
 * @param {string} tag
 * @param {Object<string, string|number>} attributes
 * @param {string|null} namespace
 */
function make(doc, tag, attributes = {}, namespace = null) {
    const element = namespace ? doc.createElementNS(namespace, tag) : doc.createElement(tag);

    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, String(value)));

    return element;
}

function empty(doc, text) {
    const note = make(doc, 'p', { class: 'chart-empty muted' });
    note.textContent = text ?? '';

    return note;
}

function describe(label, numbers, unit, locale, template) {
    if (!numbers) {
        return '';
    }

    const text = template ?? ':label: :min to :max, average :avg';

    return text
        .replace(':label', label)
        .replace(':min', formatValue(numbers.min, unit, locale))
        .replace(':max', formatValue(numbers.max, unit, locale))
        .replace(':avg', formatValue(numbers.avg, unit, locale));
}

/**
 * The plot box: the stretched SVG with its grid, and a box for the HTML labels.
 */
function frame(doc, label) {
    const plot = make(doc, 'div', { class: 'chart-plot', dir: 'ltr' });
    const svg = make(doc, 'svg', {
        class: 'chart-svg',
        viewBox: `0 0 ${WIDTH} ${HEIGHT}`,
        preserveAspectRatio: 'none',
        role: 'img',
        'aria-label': label,
        focusable: 'false',
    }, SVG);

    plot.append(svg);

    return { plot, svg };
}

function axes(doc, plot, top, y, unit, locale, ticks, x, span) {
    const svg = plot.firstChild;
    const values = make(doc, 'div', { class: 'chart-axis chart-axis-y', 'aria-hidden': 'true' });
    const times = make(doc, 'div', { class: 'chart-axis chart-axis-x', 'aria-hidden': 'true' });

    for (let line = 0; line <= GRID_LINES; line++) {
        const value = (top * line) / GRID_LINES;
        const position = y(value);

        svg.insertBefore(make(doc, 'line', { class: 'chart-grid', x1: 0, x2: WIDTH, y1: round(position), y2: round(position), 'vector-effect': 'non-scaling-stroke' }, SVG), svg.firstChild);

        const tick = make(doc, 'span', {});
        tick.style.setProperty('top', `${round((position / HEIGHT) * 100)}%`);
        tick.textContent = formatValue(value, unit, locale);
        values.append(tick);
    }

    ticks.forEach((time) => {
        const tick = make(doc, 'span', {});
        tick.style.setProperty('left', `${round((x(time) / WIDTH) * 100)}%`);
        tick.textContent = formatTime(time, locale, span);
        times.append(tick);
    });

    plot.append(values, times);
}

function nearest(points, time) {
    let best = null;

    for (const row of points) {
        if (best === null || Math.abs(row[0] - time) < Math.abs(best[0] - time)) {
            best = row;
        }
    }

    return best;
}

/**
 * A crosshair and a tooltip at the time nearest the pointer. The tooltip is for sighted pointer
 * users only; screen readers get the summary label and the "Show as table" list.
 */
function pointer(doc, plot, svg, times, x, lines, start, end, bySlot = false) {
    if (times.length === 0) {
        return;
    }

    const cross = make(doc, 'line', { class: 'chart-cross', x1: 0, x2: 0, y1: 0, y2: HEIGHT, 'vector-effect': 'non-scaling-stroke', visibility: 'hidden' }, SVG);
    const tooltip = make(doc, 'div', { class: 'chart-tooltip', 'aria-hidden': 'true', hidden: '' });

    svg.append(cross);
    plot.append(tooltip);

    const show = (event) => {
        const box = svg.getBoundingClientRect();

        if (!box || box.width <= 0) {
            return;
        }

        const ratio = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width));
        let time;

        if (bySlot) {
            time = times[Math.min(times.length - 1, Math.floor(ratio * times.length))];
        } else {
            const wanted = start + ratio * (end - start);
            time = times.reduce((best, item) => (Math.abs(item - wanted) < Math.abs(best - wanted) ? item : best), times[0]);
        }

        const position = x(time);
        cross.setAttribute('x1', String(round(position)));
        cross.setAttribute('x2', String(round(position)));
        cross.setAttribute('visibility', 'visible');

        tooltip.replaceChildren(...lines(time).map((text, index) => {
            const line = make(doc, index === 0 ? 'strong' : 'span', {});
            line.textContent = text;

            return line;
        }));
        tooltip.style.setProperty('left', `${round((position / WIDTH) * 100)}%`);
        tooltip.setAttribute('data-side', position > WIDTH * 0.66 ? 'end' : position < WIDTH * 0.33 ? 'start' : 'middle');
        tooltip.removeAttribute('hidden');
    };

    const hide = () => {
        cross.setAttribute('visibility', 'hidden');
        tooltip.setAttribute('hidden', '');
    };

    plot.addEventListener('pointermove', show);
    plot.addEventListener('pointerdown', show);
    plot.addEventListener('pointerleave', hide);
}

function legend(doc, items) {
    const list = make(doc, 'ul', { class: 'chart-legend' });

    items.forEach((item) => {
        const entry = make(doc, 'li', {});
        const swatch = make(doc, 'span', { class: 'chart-swatch', 'data-color': item.color, 'aria-hidden': 'true' });
        const text = make(doc, 'span', {});
        text.textContent = item.label;
        entry.append(swatch, text);
        list.append(entry);
    });

    return list;
}
