import Alpine from 'alpinejs';
import Swal from 'sweetalert2';

window.Alpine = Alpine;
window.Swal = Swal;

window.appAlert = (message, options = {}) => {
    const text = typeof message === 'string' ? message : String(message ?? '');

    return Swal.fire({
        icon: options.icon || 'info',
        title: options.title || 'Notice',
        text,
        confirmButtonText: options.confirmButtonText || 'OK',
    });
};

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const message = form.dataset.confirm;
    if (!message || form.dataset.confirmed === '1') {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    Swal.fire({
        icon: 'warning',
        title: 'Please confirm',
        text: message,
        showCancelButton: true,
        confirmButtonText: 'Yes, continue',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }

        form.dataset.confirmed = '1';
        form.requestSubmit();
    });
}, true);

document.addEventListener('alpine:init', () => {
    /**
     * Kebab menu for index table rows: teleported panel avoids overflow clipping;
     * outside-close uses capture so it does not fight with the opening click.
     */
    Alpine.data('indexRowActionsMenu', () => ({
        open: false,
        top: 0,
        left: 0,
        panelWidth: 192,
        boundClose: null,

        init() {
            this.boundClose = (e) => {
                if (!this.open) {
                    return;
                }
                const panel = this.$refs.panel;
                const trigger = this.$refs.trigger;
                if (panel && panel.contains(e.target)) {
                    return;
                }
                if (trigger && trigger.contains(e.target)) {
                    return;
                }
                this.close();
            };
        },

        toggle() {
            if (this.open) {
                this.close();
                return;
            }
            const btn = this.$refs.trigger;
            if (!btn) {
                return;
            }
            const r = btn.getBoundingClientRect();
            const w = this.panelWidth;
            this.top = r.bottom + 6;
            this.left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
            this.open = true;
            this.$nextTick(() => {
                window.addEventListener('click', this.boundClose, true);
            });
        },

        close() {
            if (!this.open) {
                return;
            }
            this.open = false;
            window.removeEventListener('click', this.boundClose, true);
        },

        destroy() {
            if (this.boundClose) {
                window.removeEventListener('click', this.boundClose, true);
            }
        },
    }));

    Alpine.data('returnOrderSheet', () => ({
        sheet: null,
        editingId: null,
        updateToken: 0,
        updateTpl: '',
        lookupCustomersUrl: '',
        lookupInvoicesUrl: '',
        barcodeScanHandler: null,
        debCustomer: null,
        debInvoice: null,
        pick: {
            sales_invoice_id: '',
            customer_id: '',
            branch_id: '',
            return_date: '',
            reason: '',
            customerSearch: '',
            invoiceSearch: '',
            customerResults: [],
            invoiceResults: [],
            customerOpen: false,
            invoiceOpen: false,
            invoiceDetail: null,
        },

        init() {
            const root = this.$el;
            this.lookupCustomersUrl = root.dataset.lookupCustomersUrl || '';
            this.lookupInvoicesUrl = root.dataset.lookupInvoicesUrl || '';
            this.updateToken = Number(root.dataset.updateToken || 0);
            this.updateTpl = root.dataset.updateUrlTemplate || '';
            this.pick.return_date = root.dataset.defaultReturnDate || '';

            if (root.dataset.bootCreateOld) {
                try {
                    const o = JSON.parse(root.dataset.bootCreateOld);
                    Object.assign(this.pick, {
                        sales_invoice_id: String(o.sales_invoice_id || ''),
                        customer_id: String(o.customer_id || ''),
                        branch_id: String(o.branch_id || ''),
                        return_date: o.return_date || this.pick.return_date,
                        reason: o.reason || '',
                        customerSearch: o.customer_search || '',
                        invoiceSearch: o.invoice_search || '',
                    });
                } catch {
                    //
                }
            }

            if (root.dataset.bootEdit) {
                try {
                    const ef = JSON.parse(root.dataset.bootEdit);
                    this.sheet = 'edit';
                    this.editingId = ef.id;
                    Object.assign(this.pick, {
                        sales_invoice_id: String(ef.sales_invoice_id),
                        customer_id: String(ef.customer_id),
                        branch_id: String(ef.branch_id),
                        return_date: ef.return_date,
                        reason: ef.reason ?? '',
                    });
                    this.$nextTick(() => {
                        this.afterPickBoot();
                    });
                } catch {
                    //
                }
            } else if (root.dataset.bootOpenCreate === 'true') {
                this.sheet = 'create';
                if (root.dataset.bootCreateOld) {
                    this.$nextTick(() => {
                        this.afterPickBoot();
                    });
                }
            }

            this.barcodeScanHandler = (event) => {
                const code = String(event?.detail?.code || '').trim();
                if (!code) {
                    return;
                }
                if (this.sheet !== 'create' && this.sheet !== 'edit') {
                    return;
                }
                this.pick.invoiceSearch = code;
                this.scheduleInvoiceSearch();
                this.pick.invoiceOpen = true;
            };
            window.addEventListener('barcode-scanned', this.barcodeScanHandler);
        },

        async afterPickBoot() {
            await this.loadInvoiceDetailById();
            const inv = this.pick.invoiceDetail;
            if (inv && inv.id != null) {
                this.selectInvoice(inv);
                return;
            }
            await this.refreshInvoiceResults();
            if (this.pick.invoiceDetail) {
                this.pick.customerSearch = this.pick.invoiceDetail.customer_name || '';
                this.pick.invoiceSearch = this.pick.invoiceDetail.invoice_number || '';
            }
        },

        async loadInvoiceDetailById() {
            const id = this.pick.sales_invoice_id;
            if (!id || !this.lookupInvoicesUrl) {
                return;
            }
            try {
                const url = new URL(this.lookupInvoicesUrl, window.location.origin);
                url.searchParams.set('invoice_id', String(id));
                const res = await fetch(url.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                const inv = (data.invoices || [])[0];
                this.pick.invoiceDetail = inv || null;
                if (inv) {
                    this.pick.invoiceSearch = inv.invoice_number || '';
                }
            } catch {
                this.pick.invoiceDetail = null;
            }
        },

        async refreshInvoiceResults() {
            if (!this.lookupInvoicesUrl) {
                return;
            }
            const url = new URL(this.lookupInvoicesUrl, window.location.origin);
            if (this.pick.customer_id) {
                url.searchParams.set('customer_id', String(this.pick.customer_id));
            }
            const q = (this.pick.invoiceSearch || '').trim();
            if (q !== '') {
                url.searchParams.set('q', q);
            }
            try {
                const res = await fetch(url.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.pick.invoiceResults = data.invoices || [];
            } catch {
                this.pick.invoiceResults = [];
            }
        },

        scheduleCustomerSearch() {
            clearTimeout(this.debCustomer);
            this.debCustomer = setTimeout(() => {
                this.runCustomerSearch();
            }, 280);
        },

        async runCustomerSearch() {
            if (!this.lookupCustomersUrl) {
                return;
            }
            const q = (this.pick.customerSearch || '').trim();
            const url = new URL(this.lookupCustomersUrl, window.location.origin);
            if (q !== '') {
                url.searchParams.set('q', q);
            }
            try {
                const res = await fetch(url.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.pick.customerResults = data.customers || [];
                this.pick.customerOpen = this.pick.customerResults.length > 0;
            } catch {
                this.pick.customerResults = [];
            }
        },

        selectCustomer(c) {
            this.pick.customer_id = String(c.id);
            this.pick.customerSearch = c.name;
            this.pick.customerOpen = false;
            this.pick.sales_invoice_id = '';
            this.pick.invoiceDetail = null;
            this.pick.invoiceSearch = '';
            this.pick.invoiceResults = [];
            this.refreshInvoiceResults();
            this.pick.invoiceOpen = true;
        },

        scheduleInvoiceSearch() {
            clearTimeout(this.debInvoice);
            this.debInvoice = setTimeout(() => {
                this.refreshInvoiceResults();
            }, 280);
        },

        onCustomerPickFocus() {
            this.runCustomerSearch();
        },

        onInvoicePickFocus() {
            this.refreshInvoiceResults().then(() => {
                this.pick.invoiceOpen = this.pick.invoiceResults.length > 0;
            });
        },

        selectInvoice(inv) {
            this.pick.sales_invoice_id = String(inv.id);
            this.pick.customer_id = String(inv.customer_id);
            this.pick.branch_id = String(inv.branch_id);
            this.pick.invoiceSearch = inv.invoice_number || '';
            this.pick.invoiceDetail = inv;
            this.pick.customerSearch = inv.customer_name || '';
            this.pick.invoiceOpen = false;
            this.pick.invoiceResults = [];
        },

        resetPickFromDefaults() {
            const root = this.$el;
            this.pick = {
                sales_invoice_id: '',
                customer_id: '',
                branch_id: '',
                return_date: root.dataset.defaultReturnDate || '',
                reason: '',
                customerSearch: '',
                invoiceSearch: '',
                customerResults: [],
                invoiceResults: [],
                customerOpen: false,
                invoiceOpen: false,
                invoiceDetail: null,
            };
        },

        openCreate() {
            this.resetPickFromDefaults();
            this.sheet = 'create';
            this.editingId = null;
        },

        openEdit(payload) {
            if (!payload?.id) {
                return;
            }
            this.sheet = 'edit';
            this.editingId = payload.id;
            Object.assign(this.pick, {
                sales_invoice_id: String(payload.sales_invoice_id),
                customer_id: String(payload.customer_id),
                branch_id: String(payload.branch_id),
                return_date: payload.return_date,
                reason: payload.reason ?? '',
                customerSearch: '',
                invoiceSearch: '',
                customerResults: [],
                invoiceResults: [],
                customerOpen: false,
                invoiceOpen: false,
                invoiceDetail: null,
            });
            this.$nextTick(() => {
                this.afterPickBoot();
            });
        },

        closeSheet() {
            this.sheet = null;
            this.editingId = null;
            clearTimeout(this.debCustomer);
            clearTimeout(this.debInvoice);
        },

        destroy() {
            if (this.barcodeScanHandler) {
                window.removeEventListener('barcode-scanned', this.barcodeScanHandler);
                this.barcodeScanHandler = null;
            }
        },

        updateAction() {
            return this.updateTpl.replace(String(this.updateToken), String(this.editingId));
        },

        parseRowPayload(el) {
            const raw = el?.dataset?.returnPayload;
            if (!raw) {
                return null;
            }
            try {
                return JSON.parse(raw);
            } catch {
                return null;
            }
        },
    }));

    /**
     * Sidebar menu: type-to-filter over every link the user can see, plus group
     * collapse state that survives navigation. Groups and subsections carry the
     * search text of their children so they drop out once nothing under them hits.
     *
     * @param {{ groups?: string[], items?: string[], activeGroup?: string|null }} config
     */
    Alpine.data('sidebarNav', (config = {}) => ({
        q: '',
        tokens: [],
        open: {},
        keys: Array.isArray(config.groups) ? config.groups : [],
        items: Array.isArray(config.items) ? config.items : [],
        activeGroup: config.activeGroup || null,
        storageKey: 'sidebar:open-groups',

        init() {
            this.open = this.restore();
            this.$watch('q', (value) => {
                this.tokens = value.toLowerCase().split(/\s+/).filter(Boolean);
            });
            this.$nextTick(() => this.revealActive());
        },

        restore() {
            const state = {};
            this.keys.forEach((key) => {
                state[key] = false;
            });

            let saved = {};
            try {
                saved = JSON.parse(window.localStorage.getItem(this.storageKey)) || {};
            } catch {
                saved = {};
            }

            Object.keys(state).forEach((key) => {
                if (typeof saved[key] === 'boolean') {
                    state[key] = saved[key];
                }
            });

            // The group holding the current page always opens, so the sidebar
            // shows where you are even after collapsing it on a previous visit.
            if (this.activeGroup) {
                state[this.activeGroup] = true;
            }

            return state;
        },

        persist() {
            try {
                window.localStorage.setItem(this.storageKey, JSON.stringify(this.open));
            } catch {
                /* storage unavailable (private mode / quota) */
            }
        },

        toggle(key) {
            this.open[key] = !this.open[key];
            this.persist();
        },

        get allOpen() {
            return this.keys.length > 0 && this.keys.every((key) => this.open[key]);
        },

        toggleAll() {
            const next = !this.allOpen;
            this.keys.forEach((key) => {
                this.open[key] = next;
            });
            this.persist();
        },

        get filtering() {
            return this.tokens.length > 0;
        },

        isOpen(key) {
            return this.filtering || !!this.open[key];
        },

        matches(text) {
            return !this.filtering || this.tokens.every((token) => text.includes(token));
        },

        matchesAny(list) {
            return !this.filtering || list.some((text) => this.matches(text));
        },

        get resultCount() {
            return this.filtering ? this.items.filter((text) => this.matches(text)).length : this.items.length;
        },

        visibleLinks() {
            return Array.from(this.$refs.list.querySelectorAll('a[data-nav-item]'))
                .filter((el) => el.getClientRects().length > 0);
        },

        moveFocus(direction) {
            const links = this.visibleLinks();
            if (!links.length) {
                return;
            }
            const current = links.indexOf(document.activeElement);
            const next = current === -1
                ? (direction > 0 ? 0 : links.length - 1)
                : (current + direction + links.length) % links.length;
            links[next].focus();
            links[next].scrollIntoView({ block: 'nearest' });
        },

        openFirst() {
            const [first] = this.visibleLinks();
            if (first) {
                window.location.href = first.href;
            }
        },

        focusSearch() {
            this.$refs.search.focus({ preventScroll: true });
            this.$refs.search.select();
        },

        clear() {
            this.q = '';
            this.$refs.search.focus();
        },

        clearOrBlur() {
            if (this.filtering) {
                this.clear();

                return;
            }
            this.$refs.search.blur();
        },

        revealActive() {
            this.$refs.list.querySelector('[data-sidebar-active]')?.scrollIntoView({ block: 'center' });
        },

        /**
         * Alt+M focuses the filter. Matched on event.code so it survives the
         * accented character macOS emits for Alt+letter.
         */
        onShortcut(event) {
            if (!event.altKey || event.ctrlKey || event.metaKey || event.code !== 'KeyM') {
                return false;
            }
            event.preventDefault();
            this.focusSearch();

            return true;
        },
    }));

    Alpine.data('navSearch', (liveUrl, fullSearchUrl) => ({
        q: '',
        open: false,
        loading: false,
        groups: [],
        empty: false,
        liveUrl: typeof liveUrl === 'string' ? liveUrl : '',
        fullSearchUrl: typeof fullSearchUrl === 'string' ? fullSearchUrl : '',

        async liveQuery() {
            const v = this.q.trim();
            if (v.length < 2) {
                this.groups = [];
                this.empty = false;
                this.open = false;
                this.loading = false;

                return;
            }
            this.loading = true;
            this.open = true;
            await this.fetchResults(v);
        },

        onFocus() {
            if (this.q.trim().length >= 2 && (this.groups.length > 0 || this.empty)) {
                this.open = true;
            }
        },

        async fetchResults(v) {
            if (!this.liveUrl) {
                this.loading = false;

                return;
            }
            try {
                const url = new URL(this.liveUrl, window.location.origin);
                url.searchParams.set('q', v);
                const res = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await res.json();
                this.groups = data.groups || [];
                this.empty = !!data.empty;
                this.open = true;
            } catch {
                this.groups = [];
                this.empty = false;
            } finally {
                this.loading = false;
            }
        },

        close() {
            this.open = false;
        },

        dropdownStyle() {
            const input = document.getElementById('nav-global-search');
            if (!input) return '';
            const r = input.getBoundingClientRect();
            const hasProducts = this.groups.some((g) => g.label === 'Products');
            if (hasProducts) {
                const dropW = Math.min(window.innerWidth - 32, 820);
                const left = Math.max(8, Math.min(r.left + r.width / 2 - dropW / 2, window.innerWidth - dropW - 8));
                return `top:${r.bottom + 6}px;left:${left}px;width:${dropW}px;`;
            }
            const dropW = Math.max(r.width, 360);
            const left = Math.max(8, Math.min(r.left, window.innerWidth - dropW - 8));
            return `top:${r.bottom + 6}px;left:${left}px;width:${dropW}px;`;
        },

        fullSearchHref() {
            const v = this.q.trim();
            if (v.length < 2 || !this.fullSearchUrl) {
                return '#';
            }
            const sep = this.fullSearchUrl.includes('?') ? '&' : '?';

            return `${this.fullSearchUrl}${sep}q=${encodeURIComponent(v)}`;
        },

        goFullSearch() {
            const v = this.q.trim();
            if (v.length < 2 || !this.fullSearchUrl) {
                return;
            }
            window.location.href = this.fullSearchHref();
        },
    }));

    /**
     * Searchable single-select for product brand / category (hidden id + combobox text).
     * @param {{ options: Array<{id: number, name: string}>, initialId?: number|string|null }} config
     */
    Alpine.data('productOptionPicker', (config) => ({
        options: Array.isArray(config.options) ? config.options : [],
        selectedId: null,
        query: '',
        open: false,

        init() {
            const raw = config.initialId;
            if (raw !== null && raw !== undefined && raw !== '' && Number(raw) > 0) {
                this.selectedId = Number(raw);
            } else {
                this.selectedId = null;
            }
            this.syncQueryFromId();
        },

        syncQueryFromId() {
            const id = this.selectedId;
            if (id == null || Number.isNaN(Number(id))) {
                this.query = '';

                return;
            }
            const o = this.options.find((x) => Number(x.id) === Number(id));
            this.query = o ? String(o.name) : '';
        },

        get filtered() {
            const q = (this.query || '').trim().toLowerCase();
            if (q === '') {
                return this.options;
            }

            return this.options.filter((o) => String(o.name).toLowerCase().includes(q));
        },

        onSearchInput() {
            this.open = true;
            const q = (this.query || '').trim().toLowerCase();
            const exact = this.options.find((o) => String(o.name).toLowerCase() === q);
            if (exact) {
                this.selectedId = Number(exact.id);

                return;
            }
            const stillSelected =
                this.selectedId != null &&
                this.options.some(
                    (o) => Number(o.id) === Number(this.selectedId) && String(o.name).toLowerCase().includes(q),
                );
            if (!stillSelected) {
                this.selectedId = null;
            }
        },

        pick(option) {
            this.selectedId = Number(option.id);
            this.query = String(option.name);
            this.open = false;
        },

        onFocus() {
            this.open = true;
        },

        onBlur() {
            this.open = false;
            const q = (this.query || '').trim().toLowerCase();
            if (q === '') {
                this.selectedId = null;

                return;
            }
            const matches = this.options.filter((o) => String(o.name).toLowerCase() === q);
            if (matches.length === 1) {
                this.pick(matches[0]);
            }
        },
    }));
});

Alpine.start();

(() => {
    const root = document.documentElement;
    const idleMinutes = Number(root.dataset.sessionIdleMinutes || 0);
    const logoutUrl = root.dataset.logoutUrl || '';
    const csrfToken = root.dataset.csrfToken || '';

    if (!idleMinutes || idleMinutes <= 0 || !logoutUrl || !csrfToken) {
        return;
    }

    const idleMs = idleMinutes * 60 * 1000;
    let idleTimer = null;

    const performLogout = () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = logoutUrl;

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrfToken;
        form.appendChild(token);

        document.body.appendChild(form);
        form.submit();
    };

    const resetIdleTimer = () => {
        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(performLogout, idleMs);
    };

    ['click', 'keydown', 'mousedown', 'mousemove', 'scroll', 'touchstart'].forEach((eventName) => {
        window.addEventListener(eventName, resetIdleTimer, { passive: true });
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            resetIdleTimer();
        }
    });

    resetIdleTimer();
})();

/**
 * Detect keyboard-wedge barcode scans and broadcast them globally.
 * Emits: window "barcode-scanned" with { code } detail.
 */
(() => {
    let buffer = '';
    let scanStartedAt = 0;
    let lastKeyAt = 0;
    let flushTimer = null;

    const reset = () => {
        buffer = '';
        scanStartedAt = 0;
        lastKeyAt = 0;
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
    };

    const emit = () => {
        const code = buffer.trim();
        const duration = Date.now() - scanStartedAt;
        const isLikelyScan = code.length >= 4 && duration <= 1200;
        if (isLikelyScan) {
            window.dispatchEvent(
                new CustomEvent('barcode-scanned', {
                    detail: { code },
                }),
            );
        }
        reset();
    };

    document.addEventListener(
        'keydown',
        (event) => {
            if (event.defaultPrevented) {
                return;
            }
            if (event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }

            const active = document.activeElement;
            const isTypingTarget =
                active &&
                (active.tagName === 'TEXTAREA' ||
                    (active.tagName === 'INPUT' &&
                        !['checkbox', 'radio', 'button', 'submit'].includes(String(active.type || '').toLowerCase())));

            const now = Date.now();

            if (event.key === 'Enter' && buffer.length > 0) {
                event.preventDefault();
                emit();
                return;
            }

            if (event.key.length !== 1) {
                return;
            }

            if (isTypingTarget) {
                // Allow normal typing in form fields.
                return;
            }

            if (!scanStartedAt || now - lastKeyAt > 100) {
                buffer = '';
                scanStartedAt = now;
            }
            lastKeyAt = now;
            buffer += event.key;

            if (flushTimer) {
                clearTimeout(flushTimer);
            }
            flushTimer = setTimeout(() => {
                emit();
            }, 80);
        },
        true,
    );
})();
