/**
 * Admin → Hardware → a machine (and the "Dedicated server" card on a service page): the machine's
 * power state from its BMC and the power buttons. Admin pages only.
 *
 * The page renders the last saved state, then this component asks for the live one. A power
 * button posts in the background, shows the answer, and checks the state every 10 seconds for at
 * most 2 minutes until the machine gets there. Without JavaScript the same buttons post as a
 * normal form. The config holds no secret: only addresses on this site and texts.
 */
const EXPECTED = { on: 'on', shutdown: 'off', off: 'off', restart: 'on', reset: 'on' };
const TONES = { on: 'good', off: 'crit', changing: 'info' };
const STATES = ['on', 'off', 'changing', 'unknown'];

/**
 * @param {{
 *   state?: string,
 *   label?: string,
 *   checked?: string|null,
 *   actions?: string[],
 *   stateUrl: string,
 *   labels: {pending: string, unknown: string},
 *   messages: {failed: string, slow: string, unread: string},
 *   interval?: number,
 *   tries?: number,
 *   load?: boolean,
 * }} config
 */
export function machinePower(config) {
    const interval = config.interval ?? 10000;
    const maxTries = config.tries ?? 12;

    return {
        state: STATES.includes(config.state) ? config.state : 'unknown',
        stateLabel: config.label ?? config.labels.unknown,
        checked: config.checked ?? '',
        actions: Array.isArray(config.actions) ? config.actions : [],
        pending: null,
        busy: false,
        loading: false,
        message: '',
        timer: null,
        tries: 0,
        // Counts the waits for a new state, so a check from an older wait stops once a new one began.
        run: 0,

        init() {
            if (config.load !== false) {
                this.refresh();
            }
        },

        destroy() {
            clearTimeout(this.timer);
        },

        get label() {
            return this.pending ? config.labels.pending : this.stateLabel;
        },

        get tone() {
            return this.pending ? 'info' : (TONES[this.state] ?? null);
        },

        /**
         * Whether the BMC offers this action now (the live list replaces the one the page showed).
         */
        has(action) {
            return this.actions.includes(action);
        },

        async power(event) {
            const button = event.submitter;
            const action = button?.dataset.action;

            if (!button || !EXPECTED[action]) {
                return;
            }

            event.preventDefault();

            if (this.busy || (button.dataset.confirm && !window.confirm(button.dataset.confirm))) {
                return;
            }

            clearTimeout(this.timer);
            this.run += 1;
            this.pending = null;
            this.busy = true;
            this.message = '';

            let response = null;
            let data = {};

            try {
                response = await fetch(button.formAction, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(event.target),
                    credentials: 'same-origin',
                    redirect: 'error',
                });
                data = await response.json().catch(() => ({}));
            } catch {
                response = null;
            }

            // A refusal (422) and a locked action (403, as on the demo) come with a message. Other
            // answers, such as an expired session (419), get the general message.
            const readable = response && (response.ok || response.status === 403 || response.status === 422);

            if (!readable || typeof data.message !== 'string') {
                this.busy = false;
                this.message = config.messages.failed;

                return;
            }

            this.message = data.message;

            if (!data.ok || !data.pending) {
                this.busy = false;

                // A refused action says nothing new about the power, so the state stays as it was.
                if (data.ok) {
                    this.show(data);
                }

                return;
            }

            this.pending = action;
            this.wait();
        },

        wait() {
            const run = ++this.run;

            this.busy = true;
            this.tries = 0;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.check(run), interval);
        },

        async check(run) {
            const data = await this.fetchState();

            // Another action began while this check waited for its answer.
            if (run !== this.run) {
                return;
            }

            if (data !== null) {
                this.show(data);
            }

            if (data !== null && data.state === EXPECTED[this.pending]) {
                this.pending = null;
                this.busy = false;

                return;
            }

            this.tries += 1;

            if (this.tries < maxTries) {
                this.timer = setTimeout(() => this.check(run), interval);

                return;
            }

            this.pending = null;
            this.busy = false;
            this.message = config.messages.slow;
        },

        /**
         * Ask for the live state now ("Check now", and once when the page opens).
         */
        async refresh() {
            if (this.busy) {
                return;
            }

            this.loading = true;
            const data = await this.fetchState();
            this.loading = false;

            if (data === null) {
                this.message = config.messages.unread;

                return;
            }

            this.message = '';
            this.show(data);
        },

        show(data) {
            if (STATES.includes(data.state)) {
                this.state = data.state;
                this.stateLabel = typeof data.label === 'string' ? data.label : this.stateLabel;
            }

            if (typeof data.checked === 'string') {
                this.checked = data.checked;
            }

            if (Array.isArray(data.actions)) {
                this.actions = data.actions.filter((action) => typeof action === 'string');
            }
        },

        /**
         * The machine's state, or null when it cannot be read. Sent as a background request, so
         * the session does not keep the state address as the page to go back to.
         */
        async fetchState() {
            try {
                const response = await fetch(config.stateUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    redirect: 'error',
                });

                if (!response.ok) {
                    return null;
                }

                const data = await response.json();

                return data && typeof data === 'object' ? data : null;
            } catch {
                return null;
            }
        },
    };
}
