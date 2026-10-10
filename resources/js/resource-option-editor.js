/**
 * Admin → a product → Resource options: the option editor with its live preview, and the price
 * grid (one tab per currency, one row per billing cycle) used by options and add-ons.
 *
 * Every input keeps its name, so both forms post as normal forms without JavaScript; these
 * components only add the tabs, the fill helpers, the live preview and the warnings.
 */
import { clamp, fill, formatUnits } from './resource-sliders.js';

/** Months in one period of each cycle that renews. */
const MONTHS = { monthly: 1, quarterly: 3, semiannually: 6, annually: 12, biennially: 24, triennially: 36 };

/**
 * A typed amount as a number, or null when it is empty or not an amount ("12.50", "1,200").
 *
 * @param {*} value
 * @returns {number|null}
 */
export function parseAmount(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).replace(/[,\s]/g, '');

    return /^\d+(\.\d+)?$/.test(text) ? Number(text) : null;
}

/**
 * @param {*} value
 * @returns {number|null}
 */
export function toMinor(value) {
    const amount = parseAmount(value);

    return amount === null ? null : Math.round(amount * 100);
}

/**
 * @param {number} minor
 * @returns {string}
 */
export function toDecimal(minor) {
    return (minor / 100).toFixed(2);
}

/**
 * Prices for the other renewing cycles from one cycle's price, by months: from 2.00 a month,
 * 6.00 every 3 months and 24.00 a year. A setup fee is copied only where none was typed.
 *
 * @param {Object<string, {price: string, setup_fee: string}>} row cycle => typed amounts
 * @param {string} from
 * @param {string[]} cycles
 * @returns {Object<string, {price: string, setup_fee: string}>}
 */
export function scaleCycles(row, from, cycles) {
    const source = toMinor(row?.[from]?.price);

    if (source === null || !MONTHS[from]) {
        return { ...row };
    }

    const next = { ...row };

    cycles.forEach((cycle) => {
        if (cycle === from || !MONTHS[cycle]) {
            return;
        }

        next[cycle] = {
            price: toDecimal(Math.round((source * MONTHS[cycle]) / MONTHS[from])),
            setup_fee: parseAmount(row?.[cycle]?.setup_fee) !== null ? row[cycle].setup_fee : (row?.[from]?.setup_fee ?? ''),
        };
    });

    return next;
}

/**
 * Another currency's prices from the billing currency's at an exchange rate, rounded to the cent.
 * Cycles without a price in the billing currency are left as they are.
 *
 * @param {Object<string, {price: string, setup_fee: string}>} target
 * @param {Object<string, {price: string, setup_fee: string}>} base
 * @param {number} rate units of the target currency for 1 of the billing currency
 * @param {string[]} cycles
 * @returns {Object<string, {price: string, setup_fee: string}>}
 */
export function convertPrices(target, base, rate, cycles) {
    const next = { ...target };

    if (!(Number(rate) > 0)) {
        return next;
    }

    cycles.forEach((cycle) => {
        const price = toMinor(base?.[cycle]?.price);

        if (price === null) {
            return;
        }

        next[cycle] = {
            price: toDecimal(Math.round(price * rate)),
            setup_fee: toDecimal(Math.round((toMinor(base?.[cycle]?.setup_fee) ?? 0) * rate)),
        };
    });

    return next;
}

/**
 * The price grid: currency tabs (arrow keys move between them) and the fill helpers. It tells
 * its parents about every change with a "price-change" event.
 *
 * @param {{
 *   id: string,
 *   base: string,
 *   tab?: string,
 *   currencies: string[],
 *   cycles: string[],
 *   fillFrom?: string|null,
 *   rates?: Object<string, number>,
 *   values: Object<string, Object<string, {price: string, setup_fee: string}>>,
 * }} config
 * @returns {Object}
 */
export function priceGrid(config) {
    const values = {};

    config.currencies.forEach((currency) => {
        values[currency] = {};
        config.cycles.forEach((cycle) => {
            const typed = config.values?.[currency]?.[cycle] ?? {};
            values[currency][cycle] = { price: typed.price ?? '', setup_fee: typed.setup_fee ?? '' };
        });
    });

    return {
        tab: config.currencies.includes(config.tab) ? config.tab : config.base,
        values,

        init() {
            this.emit();
        },

        select(currency) {
            if (config.currencies.includes(currency)) {
                this.tab = currency;
                this.emit();
            }
        },

        /** Arrow keys, Home and End move between the currency tabs, as in any tab list. */
        moveTab(event) {
            const list = config.currencies;
            const index = list.indexOf(this.tab);
            const rtl = event.target?.closest?.('[dir]')?.getAttribute('dir') === 'rtl';
            const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
            const back = rtl ? 'ArrowRight' : 'ArrowLeft';
            const next = {
                [forward]: list[(index + 1) % list.length],
                [back]: list[(index - 1 + list.length) % list.length],
                Home: list[0],
                End: list[list.length - 1],
            }[event.key];

            if (!next) {
                return;
            }

            event.preventDefault();
            this.select(next);
            document.getElementById(`${config.id}-tab-${next}`)?.focus();
        },

        /** How many cycles have a price in a currency. */
        priced(currency) {
            return config.cycles.filter((cycle) => parseAmount(this.values[currency]?.[cycle]?.price) !== null).length;
        },

        canScale(currency) {
            return Boolean(config.fillFrom) && parseAmount(this.values[currency]?.[config.fillFrom]?.price) !== null;
        },

        canConvert(currency) {
            return currency !== config.base && Number(config.rates?.[currency]) > 0 && this.priced(config.base) > 0;
        },

        fillCycles(currency) {
            this.values[currency] = scaleCycles(this.values[currency], config.fillFrom, config.cycles);
            this.emit();
        },

        fillFromBase(currency) {
            this.values[currency] = convertPrices(this.values[currency], this.values[config.base], Number(config.rates?.[currency]), config.cycles);
            this.emit();
        },

        emit() {
            this.$dispatch?.('price-change', { values: JSON.parse(JSON.stringify(this.values)), tab: this.tab });
        },
    };
}

/**
 * The option form: the unit switch (RAM in MB or GB), "Read from server", the live preview (sent
 * to the preview slider with a "resource-preview" event) and plain warnings while staff type. The
 * server checks the same rules again when the form is saved.
 *
 * @param {{
 *   kind: {key: string, label: string, unit: string, minStep: number, stepMultiple: number, maxExtra: number|null, ladder: string[]|null, restarts: boolean, note: string|null},
 *   form: Object<string, *>,
 *   cycles: {value: string, label: string}[],
 *   base: string,
 *   prices?: Object<string, Object<string, {price: string, setup_fee: string}>>,
 *   usedSteps?: number,
 *   baseUrl?: string|null,
 *   csrf?: string,
 *   locale?: string,
 *   unlimitedZero?: boolean,
 *   text?: Object<string, string>,
 * }} config
 * @returns {Object}
 */
export function resourceOptionEditor(config) {
    const kind = config.kind;
    const text = config.text ?? {};
    const locale = config.locale ?? 'en';
    const factor = (unit) => (kind.unit === 'MB' && unit === 'GB' ? 1024 : 1);

    return {
        form: { ...config.form },
        cycle: config.cycles[0]?.value ?? null,
        prices: config.prices ?? {},
        priceTab: config.base,
        baseLoading: false,
        baseMessage: '',
        baseRead: null,

        number(field) {
            const value = Number(this.form[field]);

            return this.form[field] === '' || this.form[field] === null || !Number.isFinite(value) ? null : value;
        },

        int(field) {
            return Math.round(this.number(field) ?? 0);
        },

        /** One step in the stored unit (MB for RAM). A ladder always counts sizes one by one. */
        stepUnits() {
            return kind.ladder ? 1 : Math.round((this.number('step_size') ?? 0) * factor(this.form.step_unit));
        },

        includedUnits() {
            const value = this.number('included');

            return value === null ? null : Math.round(value * factor(this.form.included_unit));
        },

        /** Keep the same amount when staff flip MB and GB: 2048 MB becomes 2 GB. */
        switchUnit(field, unitField, unit) {
            const value = this.number(field);
            const before = factor(this.form[unitField]);

            this.form[unitField] = unit;

            if (value !== null) {
                const after = Math.round(((value * before) / factor(unit)) * 100) / 100;
                this.form[field] = String(after);
            }
        },

        minorPrices(currency) {
            const row = this.prices?.[currency] ?? {};
            const prices = {};

            config.cycles.forEach(({ value }) => {
                const price = parseAmount(row[value]?.price);

                if (price !== null) {
                    prices[value] = { step: Math.round(price * 100), setup: Math.round((parseAmount(row[value]?.setup_fee) ?? 0) * 100) };
                }
            });

            return prices;
        },

        /** The option as clients would get it, in the shape the store sends to the sliders. */
        preview() {
            const max = clamp(this.int('max_steps'), 1, 1000);
            const min = clamp(this.int('min_steps'), 0, max);
            const stock = this.number('stock') === null ? null : Math.max(0, this.int('stock') - (config.usedSteps ?? 0));
            const limited = stock === null ? max : Math.max(min, Math.min(max, stock));
            const label = String(this.form.label ?? '').trim();
            const description = String(this.form.description ?? '').trim();

            return {
                kind: kind.key,
                label: label || kind.label,
                description: description || null,
                unit: kind.unit,
                step: Math.max(1, this.stepUnits()),
                included: this.includedUnits(),
                min,
                max: limited,
                value: clamp(this.int('default_steps'), min, limited),
                toggle: !kind.ladder && max === 1,
                ladder: kind.ladder ?? null,
                restarts: Boolean(kind.restarts),
                note: kind.note ?? (kind.restarts ? (text.restarts ?? null) : null),
                stock_left: stock,
                currency: this.priceTab,
                prices: this.minorPrices(this.priceTab),
            };
        },

        /** Plain warnings while staff type, the same rules the server checks on save. */
        warnings() {
            const list = [];
            const prices = this.minorPrices(this.priceTab);
            const step = this.stepUnits();
            const max = this.int('max_steps');
            const min = this.int('min_steps');
            const first = this.int('default_steps');

            config.cycles.filter(({ value }) => !(value in prices)).forEach(({ label }) => {
                list.push(fill(text.noPrice, { cycle: label }));
            });

            if (!kind.ladder && step < kind.minStep) {
                list.push(fill(text.minStep, { amount: formatUnits(kind.unit, kind.minStep, locale) }));
            } else if (!kind.ladder && kind.stepMultiple > 1 && step % kind.stepMultiple !== 0) {
                list.push(fill(text.multiple, { amount: formatUnits(kind.unit, kind.stepMultiple, locale) }));
            }

            if (kind.ladder && max > kind.ladder.length - 1) {
                list.push(fill(text.ladder, { count: kind.ladder.length - 1 }));
            } else if (kind.maxExtra && max * step > kind.maxExtra) {
                list.push(fill(text.maxExtra, { max: formatUnits(kind.unit, kind.maxExtra, locale) }));
            }

            if (min > max) {
                list.push(text.minMax);
            } else if (first < min || first > max) {
                list.push(text.defaultRange);
            }

            if (config.unlimitedZero && kind.key === 'bandwidth' && this.baseRead === 0) {
                list.push(text.unlimited);
            }

            return list.filter(Boolean);
        },

        /** Send the preview to the preview slider; runs again whenever a field it reads changes. */
        emitPreview() {
            this.$dispatch?.('resource-preview', this.preview());
        },

        onPrices(detail) {
            this.prices = detail?.values ?? {};
            this.priceTab = detail?.tab ?? config.base;
        },

        /** "Read from server": fill "Included in the plan" with what the plan gives. */
        async readBase() {
            if (this.baseLoading || !config.baseUrl) {
                return;
            }

            this.baseLoading = true;
            this.baseMessage = '';

            try {
                const response = await fetch(config.baseUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf ?? '' },
                });
                const body = await response.json().catch(() => ({}));

                if (!response.ok) {
                    this.baseMessage = typeof body?.message === 'string' && body.message !== '' ? body.message : (text.baseFailed ?? '');

                    return;
                }

                const units = body?.base?.[kind.key];

                if (typeof units !== 'number') {
                    this.baseMessage = text.baseUnknown ?? '';

                    return;
                }

                this.baseRead = units;

                if (kind.unit === 'MB' && units >= 1024 && units % 1024 === 0) {
                    this.form.included_unit = 'GB';
                    this.form.included = String(units / 1024);
                } else {
                    this.form.included_unit = kind.unit === 'MB' ? 'MB' : this.form.included_unit;
                    this.form.included = String(units);
                }

                this.baseMessage = fill(text.baseDone, { amount: formatUnits(kind.unit, units, locale, kind.ladder) });
            } catch {
                this.baseMessage = text.baseFailed ?? '';
            } finally {
                this.baseLoading = false;
            }
        },
    };
}
