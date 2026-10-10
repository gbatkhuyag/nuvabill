/**
 * The client server panel on a service page: tabs, one section's reads and actions, and the live
 * figures of the overview. All remote data is shown as text, never as HTML.
 *
 * For now these are empty Alpine components; the server panel screens fill them.
 */

/**
 * The tabs: keyboard moves, and the #panel-{key} address of the open tab.
 *
 * @returns {Object}
 */
export function serverPanel() {
    return {};
}

/**
 * One section: load its data, run its actions, show errors and secrets.
 *
 * @returns {Object}
 */
export function panelSection() {
    return {};
}

/**
 * The overview's live figures, refreshed while the page is visible.
 *
 * @returns {Object}
 */
export function liveStats() {
    return {};
}
