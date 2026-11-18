<script>
(function () {
	const GSM_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
	const GSM_EXTENDED = '^{}\\[~]|€\f';
	const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
	const PAGE_SIZE = 50;

	function smsInfo(text) {
		text = text || '';
		if (text === '') return { unicode: false, characters: 0, parts: 0, perPart: 160 };
		const chars = Array.from(text);
		const unicode = chars.some(c => !GSM_BASIC.includes(c) && !GSM_EXTENDED.includes(c));
		if (unicode) {
			const n = chars.length;
			return { unicode: true, characters: n, parts: n <= 70 ? 1 : Math.ceil(n / 67), perPart: n <= 70 ? 70 : 67 };
		}
		const n = chars.reduce((sum, c) => sum + (GSM_EXTENDED.includes(c) ? 2 : 1), 0);
		return { unicode: false, characters: n, parts: n <= 160 ? 1 : Math.ceil(n / 153), perPart: n <= 160 ? 160 : 153 };
	}

	function normalizePhone(raw) {
		let d = String(raw || '').replace(/[০-৯]/g, c => String(BN_DIGITS.indexOf(c))).replace(/\D+/g, '');
		if (d.startsWith('00880')) d = d.slice(2);
		if (d.length === 11 && d.startsWith('01')) d = '88' + d;
		else if (d.length === 10 && d.startsWith('1')) d = '880' + d;
		return /^8801[3-9]\d{8}$/.test(d) ? d : null;
	}

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
			const r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}

	function toBn(value) {
		return String(value).replace(/[0-9]/g, d => BN_DIGITS[d]);
	}

	function ymd(offsetDays) {
		const d = new Date();
		d.setDate(d.getDate() + offsetDays);
		const p = n => String(n).padStart(2, '0');
		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
	}

	window.smsBalance = function (initial) {
		return {
			balance: initial,
			loading: false,
			async refresh() {
				this.loading = true;
				try {
					const res = await fetch(@json(route('sms.balance')), { headers: { Accept: 'application/json' } });
					this.balance = await res.json();
				} catch (e) {
					this.balance = { ok: false, balance: null, message: 'Could not refresh balance' };
				} finally {
					this.loading = false;
				}
			},
		};
	};

	window.smsComposer = function (cfg) {
		const firstAudience = Object.keys(cfg.audiences)[0];

		return {
			...cfg,
			mode: 'bulk',
			token: uuid(),

			// bulk
			audience: firstAudience,
			filters: { from: '', to: '', branch_id: '', sale_type: '', min_due: '', min_overdue_days: '', q: '' },
			loading: false,
			loaded: false,
			loadedAudience: null,
			loadedFilters: {},
			rows: [],
			skipped: 0,
			selected: {},
			tableSearch: '',
			page: 1,
			previewRow: null,

			// single
			customerQuery: '',
			customerResults: [],
			singleCustomer: null,
			singlePhones: '',

			// message
			templateId: '',
			message: '',
			senderType: cfg.senderIds[cfg.defaultSenderType] ? cfg.defaultSenderType : (cfg.senderIds.non_masking ? 'non_masking' : (cfg.senderIds.masking ? 'masking' : cfg.defaultSenderType)),
			banglaDigits: false,

			init() {
				this.chooseAudience(firstAudience);
				this.$watch('tableSearch', () => { this.page = 1; });
			},

			smsInfo,

			chooseAudience(key) {
				this.audience = key;
				const def = this.audiences[key];
				this.filters.from = def.from || '';
				this.filters.to = def.to || '';
				this.filters.sale_type = '';
				this.filters.min_due = '';
				this.filters.min_overdue_days = '';
				const match = this.templates.find(t => t.category === key);
				if (match && (this.message === '' || this.templates.some(t => t.body === this.message))) {
					this.templateId = String(match.id);
					this.message = match.body;
				}
				this.loaded = false;
				this.rows = [];
				this.selected = {};
			},

			hasExtra(name) {
				return (this.audiences[this.audience]?.extra || []).includes(name);
			},

			quickRange(fromOffset, toOffset) {
				this.filters.from = ymd(fromOffset);
				this.filters.to = ymd(toOffset);
			},

			async load() {
				this.loading = true;
				const params = new URLSearchParams({ audience: this.audience });
				Object.entries(this.filters).forEach(([k, v]) => { if (v !== '' && v !== null) params.append(k, v); });
				try {
					const res = await fetch(this.urls.audience + '?' + params.toString(), { headers: { Accept: 'application/json' } });
					const data = await res.json();
					if (!res.ok) {
						const msg = data.errors ? Object.values(data.errors).flat().join('\n') : (data.message || 'Could not load customers');
						window.Swal ? Swal.fire({ icon: 'error', title: 'Filter problem', text: msg }) : alert(msg);
						return;
					}
					this.rows = data.rows;
					this.skipped = data.skipped;
					const sel = {};
					data.rows.forEach(r => { sel[r.customer_id] = true; });
					this.selected = sel;
					this.loadedAudience = this.audience;
					this.loadedFilters = { ...this.filters };
					this.loaded = true;
					this.page = 1;
					this.tableSearch = '';
				} catch (e) {
					window.Swal ? Swal.fire({ icon: 'error', title: 'Error', text: 'Could not load customers.' }) : alert('Could not load customers.');
				} finally {
					this.loading = false;
				}
			},

			get filteredRows() {
				const term = this.tableSearch.trim().toLowerCase();
				if (!term) return this.rows;
				return this.rows.filter(r => (r.name + ' ' + r.code + ' ' + r.phone + ' ' + r.branch + ' ' + r.detail).toLowerCase().includes(term));
			},
			get pages() { return Math.max(1, Math.ceil(this.filteredRows.length / PAGE_SIZE)); },
			get pageRows() { return this.filteredRows.slice((this.page - 1) * PAGE_SIZE, this.page * PAGE_SIZE); },
			get pageAllSelected() { return this.pageRows.length > 0 && this.pageRows.every(r => this.selected[r.customer_id]); },
			togglePage(on) { this.pageRows.forEach(r => { this.selected[r.customer_id] = on; }); },
			selectAll(on) { this.filteredRows.forEach(r => { this.selected[r.customer_id] = on; }); },
			get selectedRows() { return this.rows.filter(r => this.selected[r.customer_id]); },
			get selectedIds() { return this.selectedRows.map(r => r.customer_id); },
			get selectedCount() { return this.selectedRows.length; },

			// single
			async searchCustomers() {
				const q = this.customerQuery.trim();
				if (q.length < 2) { this.customerResults = []; return; }
				try {
					const res = await fetch(this.urls.customers + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
					this.customerResults = await res.json();
				} catch (e) { this.customerResults = []; }
			},
			pickCustomer(c) {
				this.singleCustomer = c;
				this.customerResults = [];
				this.customerQuery = '';
				if (c.phones.length) {
					const existing = this.parsedPhones.valid;
					if (!existing.includes(c.phones[0])) {
						this.singlePhones = (this.singlePhones.trim() ? this.singlePhones.trim() + ', ' : '') + c.phones[0].replace(/^88/, '');
					}
				}
			},
			get parsedPhones() {
				const valid = [], invalid = [];
				this.singlePhones.split(/[\s,;]+/).filter(Boolean).forEach(t => {
					const n = normalizePhone(t);
					if (n) { if (!valid.includes(n)) valid.push(n); } else invalid.push(t);
				});
				return { valid, invalid };
			},

			// message
			get sortedTemplates() {
				return [...this.templates].sort((a, b) => (b.category === this.audience) - (a.category === this.audience));
			},
			applyTemplate() {
				const t = this.templates.find(t => String(t.id) === String(this.templateId));
				if (t) this.message = t.body;
			},
			insert(key) {
				const el = this.$refs.message;
				const token = '{' + key + '}';
				const start = el.selectionStart ?? this.message.length;
				const end = el.selectionEnd ?? this.message.length;
				this.message = this.message.slice(0, start) + token + this.message.slice(end);
				this.$nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = start + token.length; });
			},

			varsFor(row) {
				if (this.mode === 'single') {
					const v = { company: this.company };
					if (this.singleCustomer) {
						v.name = this.singleCustomer.name;
						v.customer_code = this.singleCustomer.code;
						v.total_due = this.singleCustomer.total_due;
					}
					return v;
				}
				return row ? row.vars : { company: this.company };
			},
			renderFor(row) {
				const vars = this.varsFor(row);
				return this.message.replace(/\{([a-z_]+)\}/g, (m, key) => {
					if (!(key in vars)) return m;
					const val = String(vars[key] ?? '');
					return this.banglaDigits && key !== 'phone' ? toBn(val) : val;
				}).trim();
			},
			get currentPreviewRow() {
				return this.mode === 'bulk' ? (this.selectedRows[0] || null) : null;
			},
			get previewText() {
				return this.renderFor(this.currentPreviewRow);
			},
			get info() {
				return smsInfo(this.previewText || this.message);
			},
			get unknownPlaceholders() {
				const found = this.previewText.match(/\{[a-z_]+\}/g) || [];
				return [...new Set(found)];
			},
			get estimatedParts() {
				return this.selectedRows.reduce((sum, r) => sum + Math.max(1, smsInfo(this.renderFor(r)).parts), 0);
			},

			get canSend() {
				if (this.message.trim() === '' || !this.senderIds[this.senderType]) return false;
				if (this.mode === 'bulk') return this.loaded && this.selectedCount > 0 && this.selectedCount <= this.maxRecipients;
				return this.parsedPhones.valid.length > 0;
			},

			async confirmSend() {
				if (!this.canSend) return;
				const bulk = this.mode === 'bulk';
				const count = bulk ? this.selectedCount : this.parsedPhones.valid.length;
				const parts = bulk ? this.estimatedParts : this.info.parts * count;
				const sender = (this.senderType === 'masking' ? 'Masking' : 'Non-masking') + ' (' + this.senderIds[this.senderType] + ')';
				const warn = this.unknownPlaceholders.length ? '\n\nWarning: unfilled placeholders ' + this.unknownPlaceholders.join(', ') : '';
				const text = 'Send ' + count + ' SMS (about ' + parts + ' SMS parts) from ' + sender + '?' + warn;

				let ok;
				if (window.Swal) {
					const r = await Swal.fire({
						icon: 'question',
						title: 'Send SMS now?',
						text,
						showCancelButton: true,
						confirmButtonText: 'Yes, send',
						cancelButtonText: 'Cancel',
						confirmButtonColor: '#059669',
					});
					ok = r.isConfirmed;
				} else {
					ok = confirm(text);
				}
				if (!ok) return;

				this.$nextTick(() => (bulk ? this.$refs.bulkForm : this.$refs.singleForm).submit());
			},
		};
	};
})();
</script>
