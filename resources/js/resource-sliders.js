/**
 * Resource sliders (extra RAM, disk, IP addresses and so on) on the store product page, the
 * client's service page and the admin previews. The server sends every text through the config,
 * so nothing here needs translating, and every price in minor units, so nothing is worked out in
 * floating point money.
 *
 * A slider is described by the array ResourceOptions builds:
 *   {kind, label, description, unit, step, included, min, max, value, toggle, ladder, restarts,
 *    note, stock_left, currency, prices: {cycle: {step, setup}}}
 *
 * The billing cycle is read from the surrounding Alpine scope ("cycle", as on the product page's
 * form), or set with the config's "cycle" for a page with one cycle, such as a service.
 * Each slider tells its parents what was chosen with a "resource-change" event, so a
 * resourceQuote around them can add the prices up or ask the server for a quote.
 */

/** Units shown as a plain number: the kind's name says what is counted ("CPU cores +2"). */
const COUNT_UNITS = ['', 'core', 'IP'];

/**
 * A whole number kept between min and max (max is never below min).
 *
 * @param {*} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
export function clamp(value, min, max) {
    const low = Number.isFinite(Number(min)) ? Math.round(Number(min)) : 0;
    const high = Math.max(low, Number.isFinite(Number(max)) ? Math.round(Number(max)) : low);
    const number = value === null || value === '' ? Number.NaN : Math.round(Number(value));

    if (!Number.isFinite(number)) {
        return low;
    }

    return Math.min(Math.max(number, low), high);
}

/**
 * @param {number} value
 * @param {string} locale
 * @returns {string}
 */
export function formatNumber(value, locale = 'en') {
    try {
        return new Intl.NumberFormat(locale || 'en', { maximumFractionDigits: 1 }).format(value);
    } catch {
        return String(Math.round(value * 10) / 10);
    }
}

/**
 * An amount as people read it, the same as ResourceKinds::format() on the server: RAM in GB from
 * 1024 MB up (1536 MB is "1.5 GB"), GB as TB at whole thousands, cores and IP addresses as a plain
 * number, and a ladder as the name of its step.
 *
 * @param {string} unit
 * @param {number} units
 * @param {string} locale
 * @param {string[]|null} ladder
 * @returns {string}
 */
export function formatUnits(unit, units, locale = 'en', ladder = null) {
    if (Array.isArray(ladder)) {
        return ladder[units] !== undefined ? String(ladder[units]) : formatNumber(units, locale);
    }

    if (unit === 'MB' && units >= 1024) {
        return `${formatNumber(units / 1024, locale)} GB`;
    }

    if (unit === 'GB' && units >= 1000 && units % 1000 === 0) {
        return `${formatNumber(units / 1000, locale)} TB`;
    }

    if (COUNT_UNITS.includes(unit ?? '')) {
        return formatNumber(units, locale);
    }

    return `${formatNumber(units, locale)} ${unit}`;
}

/**
 * Minor units as money, like money() on the server: whole amounts with the currency's usual
 * decimals, others with two.
 *
 * @param {number} minor
 * @param {string} currency
 * @param {string} locale
 * @returns {string}
 */
export function formatMoney(minor, currency, locale = 'en') {
    const whole = minor % 100 === 0;

    try {
        return new Intl.NumberFormat(locale || 'en', {
            style: 'currency',
            currency,
            ...(whole ? {} : { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        }).format(minor / 100);
    } catch {
        return `${(minor / 100).toFixed(2)} ${currency}`;
    }
}

/**
 * Put values into a translated text: fill('Each step adds :amount.', {amount: '1 GB'}).
 *
 * @param {string} template
 * @param {Object<string, *>} values
 * @returns {string}
 */
export function fill(template, values = {}) {
    let text = String(template ?? '');

    Object.keys(values)
        .sort((a, b) => b.length - a.length)
        .forEach((key) => {
            text = text.split(`:${key}`).join(String(values[key]));
        });

    return text;
}

/**
 * The price of one step of an option in a cycle, or null when it is not offered with that cycle.
 *
 * @param {{prices?: Object<string, {step: number, setup?: number}>}} option
 * @param {string|null} cycle
 * @returns {{step: number, setup: number}|null}
 */
export function stepPrice(option, cycle) {
    const price = cycle ? option?.prices?.[cycle] : null;

    if (!price || !Number.isFinite(Number(price.step))) {
        return null;
    }

    return { step: Number(price.step), setup: Number(price.setup ?? 0) || 0 };
}

/**
 * What the chosen steps cost together in a cycle: the recurring price and the setup fees.
 *
 * @param {Object<string, {steps: number, prices: Object}>} entries kind => what a slider reported
 * @param {string|null} cycle
 * @returns {{price: number, setup: number}}
 */
export function extrasTotal(entries, cycle) {
    let price = 0;
    let setup = 0;

    Object.values(entries ?? {}).forEach((entry) => {
        const each = stepPrice(entry, cycle);

        if (each && entry.steps > 0) {
            price += each.step * entry.steps;
            setup += each.setup * entry.steps;
        }
    });

    return { price, setup };
}

/**
 * @param {Object} option
 * @returns {Object}
 */
function normalize(option) {
    const source = option ?? {};
    const min = clamp(source.min, 0, 1000);
    const max = clamp(source.max, min, 1000);

    return {
        kind: String(source.kind ?? ''),
        label: String(source.label ?? ''),
        description: source.description ?? null,
        unit: String(source.unit ?? ''),
        step: Math.max(1, Math.round(Number(source.step) || 1)),
        included: source.included === null || source.included === undefined || source.included === '' ? null : Number(source.included),
        min,
        max,
        value: clamp(source.value ?? min, min, max),
        toggle: Boolean(source.toggle),
        ladder: Array.isArray(source.ladder) ? source.ladder : null,
        restarts: Boolean(source.restarts),
        note: source.note ?? null,
        stock_left: source.stock_left === null || source.stock_left === undefined ? null : Number(source.stock_left),
        currency: source.currency ?? null,
        prices: source.prices && typeof source.prices === 'object' && !Array.isArray(source.prices) ? source.prices : {},
    };
}

/**
 * One slider or switch: its value, the text next to it, and its price for the chosen cycle.
 *
 * @param {Object} option see the top of this file
 * @param {{
 *   currency?: string,
 *   locale?: string,
 *   cycle?: string|null,
 *   live?: boolean,
 *   text?: Object<string, *>,
 * }} config
 * @returns {Object}
 */
export function resourceSlider(option, config = {}) {
    const text = config.text ?? {};
    const locale = config.locale ?? 'en';

    return {
        option: normalize(option),
        value: 0,
        fixedCycle: config.cycle ?? null,
        live: Boolean(config.live),

        init() {
            this.value = this.option.value;
            this.changed();
        },

        /**
         * The cycle prices are shown for: the page's chosen cycle, else the fixed one, else the
         * first one the option is priced for.
         */
        activeCycle() {
            return this.cycle ?? this.fixedCycle ?? Object.keys(this.option.prices)[0] ?? null;
        },

        get offered() {
            return stepPrice(this.option, this.activeCycle()) !== null;
        },

        /** Nothing to choose: not offered with this cycle, or a range of one value. */
        get locked() {
            return !this.offered || this.option.max <= this.option.min;
        },

        money(minor) {
            return formatMoney(minor, this.option.currency ?? config.currency ?? 'USD', locale);
        },

        suffix() {
            return text.suffixes?.[this.activeCycle()] ?? '';
        },

        units() {
            return this.value * this.option.step;
        },

        format(units) {
            return formatUnits(this.option.unit, units, locale);
        },

        valueText() {
            if (this.option.ladder) {
                return formatUnits(this.option.unit, this.value, locale, this.option.ladder);
            }

            if (this.option.toggle) {
                return this.value > 0 ? (text.on ?? 'On') : (text.off ?? 'Off');
            }

            return this.value > 0 ? `+${this.format(this.units())}` : (text.none ?? '');
        },

        /** What the server has with these extras, when staff told what the plan includes. */
        totalText() {
            if (this.option.included === null || this.option.ladder) {
                return '';
            }

            return this.format(this.option.included + this.units());
        },

        scaleMin() {
            if (this.option.ladder) {
                return formatUnits(this.option.unit, this.option.min, locale, this.option.ladder);
            }

            return this.option.min > 0 ? `+${this.format(this.option.min * this.option.step)}` : formatNumber(0, locale);
        },

        scaleMax() {
            if (this.option.ladder) {
                return formatUnits(this.option.unit, this.option.max, locale, this.option.ladder);
            }

            return `+${this.format(this.option.max * this.option.step)}`;
        },

        price() {
            const each = stepPrice(this.option, this.activeCycle());

            return each ? each.step * this.value : 0;
        },

        setupFee() {
            const each = stepPrice(this.option, this.activeCycle());

            return each ? each.setup * this.value : 0;
        },

        /** "+$8.00/mo · + $2.00 setup" for the chosen steps, or nothing when they are free. */
        priceText() {
            const parts = [];

            if (this.price() > 0) {
                parts.push(`+${this.money(this.price())}${this.suffix()}`);
            }

            if (this.setupFee() > 0) {
                parts.push(fill(text.setup, { amount: this.money(this.setupFee()) }));
            }

            return parts.join(' · ');
        },

        /** The price of one step in the chosen cycle, for a switch's label. */
        stepPriceText() {
            const each = stepPrice(this.option, this.activeCycle());

            return each && each.step > 0 ? `+${this.money(each.step)}${this.suffix()}` : '';
        },

        /** The price of one step in every cycle: "$2.00/mo · $20.00/yr". */
        pricesText() {
            return Object.entries(this.option.prices)
                .map(([cycle, price]) => `${this.money(Number(price.step))}${text.suffixes?.[cycle] ?? ''}`)
                .join(' · ');
        },

        /** The help under the slider, the same as the server prints without JavaScript. */
        helpText() {
            const parts = [];
            const { unit, step, included, ladder, toggle } = this.option;

            if (!ladder && !toggle) {
                parts.push(fill(text.eachStep, { amount: formatUnits(unit, step, locale) }));
            }

            if (included !== null && !ladder) {
                parts.push(fill(text.included, { amount: formatUnits(unit, included, locale) }));
            }

            if (Object.keys(this.option.prices).length > 0) {
                parts.push(fill(toggle ? text.switchPrices : text.stepPrices, { prices: this.pricesText() }));
            }

            if (this.option.note) {
                parts.push(this.option.note);
            }

            return parts.filter(Boolean).join(' ');
        },

        /** What a screen reader says: "4 GB extra, 8 GB in total, $8.00 (Monthly)". */
        ariaText() {
            if (this.option.ladder) {
                return this.valueText();
            }

            const parts = [this.value > 0 ? fill(text.ariaExtra, { amount: this.format(this.units()) }) : (text.none ?? '')];

            if (this.totalText() !== '') {
                parts.push(fill(text.ariaTotal, { amount: this.totalText() }));
            }

            if (this.price() > 0) {
                parts.push(fill(text.ariaPrice, { amount: this.money(this.price()), cycle: text.cycles?.[this.activeCycle()] ?? this.activeCycle() }));
            }

            return parts.filter(Boolean).join(', ');
        },

        /** "Only 3 GB left" when stock limits the slider, or "Sold out right now." */
        stockText() {
            const left = this.option.stock_left;

            if (left === null || left > this.option.max) {
                return '';
            }

            if (left <= 0) {
                return text.soldOut ?? '';
            }

            return fill(text.onlyLeft, { count: this.option.ladder ? left : this.format(left * this.option.step) });
        },

        setValue(value) {
            this.value = clamp(value, this.option.min, this.option.max);
            this.changed();
        },

        toggleTo(checked) {
            this.setValue(checked ? 1 : 0);
        },

        /** A new description of the option, from the admin editor's live preview. */
        setOption(next) {
            this.option = normalize(next);
            this.value = this.option.value;
            this.changed();
        },

        changed() {
            this.$dispatch?.('resource-change', {
                kind: this.option.kind,
                steps: this.offered ? this.value : 0,
                prices: this.option.prices,
                currency: this.option.currency ?? config.currency ?? null,
            });
        },
    };
}

/**
 * The price of what the sliders inside say. Without a URL it adds the chosen steps up for the
 * chosen cycle (store pages and admin previews). With a URL (a service's resources) it also asks
 * the server for a quote, 250 ms after the last move, ignores answers to older questions, and keeps
 * "expected" from the last good quote for the form that confirms the change.
 *
 * On a service's card it can also:
 * - skip the question while the sliders are back at what the service has ("initial");
 * - post staff fields with the steps ("fields", such as a renewal price per kind; refresh() asks again);
 * - follow the server while it takes the last change ("status": the JSON of
 *   client.services.resources), every 10 seconds for at most 10 minutes.
 *
 * @param {{
 *   url?: string,
 *   csrf?: string,
 *   cycle?: string,
 *   currency?: string,
 *   locale?: string,
 *   expected?: number|null,
 *   delay?: number,
 *   initial?: Object<string, number>,
 *   fields?: Object<string, *>,
 *   status?: {url: string, state: string, text?: string, every?: number, limit?: number},
 *   text?: Object<string, *>,
 * }} config
 * @returns {Object}
 */
export function resourceQuote(config = {}) {
    const text = config.text ?? {};
    const locale = config.locale ?? 'en';
    const delay = config.delay ?? 250;

    return {
        // Only a quote with its own cycle has one; otherwise the surrounding form's cycle is used.
        ...(config.cycle ? { cycle: config.cycle } : {}),
        entries: {},
        quote: null,
        loading: false,
        error: '',
        expected: config.expected ?? null,
        timer: null,
        asked: 0,
        fields: config.fields ?? {},
        serverState: config.status?.state ?? 'none',
        serverText: config.status?.text ?? '',
        polls: 0,
        pollTimer: null,

        init() {
            if (config.status?.url && this.serverState === 'working') {
                this.schedulePoll();
            }
        },

        destroy() {
            clearTimeout(this.timer);
            clearTimeout(this.pollTimer);
        },

        activeCycle() {
            return this.cycle ?? null;
        },

        /** A slider moved: remember its steps, and ask for a new quote when there is a URL. */
        track(detail) {
            if (!detail || typeof detail.kind !== 'string' || detail.kind === '') {
                return;
            }

            this.entries = {
                ...this.entries,
                [detail.kind]: { steps: Number(detail.steps) || 0, prices: detail.prices ?? {}, currency: detail.currency ?? null },
            };

            if (!config.url) {
                return;
            }

            clearTimeout(this.timer);

            // Back at what the service has: nothing to price, and an answer on its way is ignored.
            if (this.unchanged()) {
                this.asked++;
                this.quote = null;
                this.error = '';
                this.loading = false;

                return;
            }

            this.timer = setTimeout(() => this.request(), delay);
        },

        /** Ask again, for example after a staff field changed. */
        refresh() {
            if (config.url) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.request(), delay);
            }
        },

        /** Whether every slider is at the steps the service has now (only with "initial", and no staff fields). */
        unchanged() {
            if (!config.initial || config.fields) {
                return false;
            }

            return Object.entries(this.steps()).every(([kind, steps]) => Number(config.initial[kind] ?? 0) === steps);
        },

        schedulePoll() {
            if (this.polls >= (config.status?.limit ?? 60)) {
                return;
            }

            clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => this.poll(), config.status?.every ?? 10000);
        },

        /** Read the server state of the last change; keep reading while the server works on it. */
        async poll() {
            this.polls++;

            try {
                const response = await fetch(config.status.url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const body = response.ok ? await response.json().catch(() => null) : null;

                if (body && typeof body.state === 'string') {
                    this.serverState = body.state;
                    this.serverText = typeof body.text === 'string' ? body.text : '';
                }
            } catch {
                // A missed answer is asked again on the next tick.
            }

            if (this.serverState === 'working') {
                this.schedulePoll();
            }
        },

        /** kind => steps, as the quote and change routes take them. */
        steps() {
            return Object.fromEntries(Object.entries(this.entries).map(([kind, entry]) => [kind, entry.steps]));
        },

        currencyCode() {
            return Object.values(this.entries).find((entry) => entry.currency)?.currency ?? config.currency ?? 'USD';
        },

        totals() {
            return extrasTotal(this.entries, this.activeCycle());
        },

        /** "Extras: +$12.00/mo · + $5.00 setup", or the "no extras" text. */
        totalText() {
            const { price, setup } = this.totals();
            const money = (minor) => formatMoney(minor, this.currencyCode(), locale);

            if (price <= 0 && setup <= 0) {
                return text.noExtras ?? '';
            }

            const parts = [fill(text.extras, { amount: `+${money(price)}${text.suffixes?.[this.activeCycle()] ?? ''}` })];

            if (setup > 0) {
                parts.push(fill(text.setup, { amount: money(setup) }));
            }

            return parts.join(' · ');
        },

        async request() {
            const id = ++this.asked;
            this.loading = true;
            this.error = '';

            try {
                const response = await fetch(config.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf ?? '',
                    },
                    body: JSON.stringify({ ...this.fields, steps: this.steps() }),
                });
                const body = await response.json().catch(() => ({}));

                // A newer question was asked while this one was on its way.
                if (id !== this.asked) {
                    return;
                }

                if (!response.ok) {
                    const first = body?.errors ? Object.values(body.errors).flat()[0] : null;
                    this.error = first || body?.message || text.failed || '';
                    this.quote = null;

                    return;
                }

                this.quote = body;
                this.expected = body?.expected ?? null;
            } catch {
                if (id === this.asked) {
                    this.error = text.failed ?? '';
                    this.quote = null;
                }
            } finally {
                if (id === this.asked) {
                    this.loading = false;
                }
            }
        },
    };
}
