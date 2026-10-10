/**
 * Resource sliders (extra RAM, disk, IP addresses and so on) on the store product page, the
 * client's service page and the admin preview. The server sends every text through the config,
 * so nothing here needs translating.
 *
 * For now these are empty Alpine components; the resource option screens fill them.
 */

/**
 * One slider or switch: its value, the text next to it, and its price for the chosen cycle.
 *
 * @returns {Object}
 */
export function resourceSlider() {
    return {};
}

/**
 * The live price of a change on a service: asks the quote route as the sliders move.
 *
 * @returns {Object}
 */
export function resourceQuote() {
    return {};
}
