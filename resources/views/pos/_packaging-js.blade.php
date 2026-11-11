{{--
    Sell-screen logic for pack / package / carton lines (client/tobacco, docs/packaging.md). Included inside the <script> of
    pos/sell.blade.php and spread into posApp(); the few core methods that must know about units call the pkg* helpers below.

    The browser only ever sends a unit NAME per line (pack | package | carton). Size and price here are for display and the stock
    warning; the server prices every line itself (SaleService). With POS_PACKAGING off the server sends no packaging fields,
    so every helper falls through to "a pack" and the screen behaves as it always did.
--}}
function posPackaging() {
    return {
        // The unit-choice modal. `lastKey` / `timer` tell a scanner's burst of keys from a person's keypress (see pkgKey).
        pkg: { open: false, product: null, units: [], openedAt: 0, lastKey: 0, timer: null },
        pkgStrings: @json(__('packaging.ui')),
        pkgUnitNames: @json(__('packaging.unit')),

        pkgT(key, vars = {}) {
            let out = this.pkgStrings[key] ?? key;
            for (const [k, v] of Object.entries(vars)) out = out.split(':' + k).join(v);
            return out;
        },

        // --- what a product offers ---------------------------------------------------
        // Mirrors Product::supportsUnit (the server re-checks): configured, priced and, for a carton, switched on for the till.
        pkgSupports(p, unit) {
            if (unit === 'pack') return true;
            if (!p.has_package || p.type !== 'simple' || !(Number(p.package_size) >= 2) || p.package_price_usd == null) return false;
            if (unit === 'package') return true;
            return unit === 'carton' && !!p.carton_barcode && Number(p.packages_per_carton) >= 1
                && !!p.sell_carton_at_pos && p.carton_price_usd != null;
        },
        pkgUnitsOf(p) { return ['pack', 'package', 'carton'].filter(u => this.pkgSupports(p, u)); },

        // Packs per sale unit (Product::multiplierFor).
        pkgMult(p, unit) {
            if (unit === 'package') return Number(p.package_size);
            if (unit === 'carton') return Number(p.package_size) * Number(p.packages_per_carton);
            return 1;
        },

        // One unit's price on each list; a missing wholesale / VIP price falls back to that unit's retail (Product::priceForUnit).
        pkgTierPrices(p, unit) {
            if (unit === 'pack') return this.tierPrices(p);
            const retail = Number(p[unit + '_price_usd']);
            return {
                retail,
                wholesale: Number(p[unit + '_wholesale_price_usd'] ?? retail),
                vip: Number(p[unit + '_vip_price_usd'] ?? retail),
            };
        },
        pkgPrice(p, unit) { return this.pkgTierPrices(p, unit)[this.priceTier]; },

        pkgLabel(p, unit) {
            const name = this.pkgUnitNames[unit] ?? unit;
            return unit === 'pack' ? name : `${name} ×${this.pkgMult(p, unit)}`;
        },
        pkgBadge(line) {
            const name = this.pkgUnitNames[line.sale_unit] ?? line.sale_unit;
            return line.sale_unit === 'pack' ? name : `${name} ×${line.unit_multiplier}`;
        },

        // --- stock: packs, shared by every line of one product -----------------------
        // Packs the cart would draw from line's product if `line` held `qty` units.
        pkgBase(line, qty) {
            let others = 0;
            for (const l of this.cart) {
                if (l.product_id === line.product_id && l.sale_unit !== line.sale_unit) others += l.qty * (l.unit_multiplier || 1);
            }
            return qty * (line.unit_multiplier || 1) + others;
        },
        // Most units `line` can hold before the product runs out.
        pkgMax(line) {
            const left = line.stock_available - this.pkgBase(line, 0);
            return (line.unit_multiplier || 1) === 1 ? left : Math.floor(left / line.unit_multiplier);
        },

        // --- adding -------------------------------------------------------------------
        // A packaged product asks "Pack or Package?" instead of adding. True when it opened the question.
        pkgAsk(p) {
            const units = this.pkgUnitsOf(p);
            if (units.length < 2) return false;
            const s = this.pkg;
            s.open = true; s.product = p; s.units = units; s.lastKey = 0;
            s.openedAt = performance.now(); // the Enter that opened it (a scan) must not also answer it
            this.$refs.search?.blur();
            this.$nextTick(() => this.$refs.pkgBox?.focus());
            return true;
        },

        pkgChoose(unit) {
            const p = this.pkg.product;
            this.pkgClose();
            if (p) this.addToCart(p, unit);
        },

        pkgClose() {
            clearTimeout(this.pkg.timer);
            this.pkg.timer = null;
            this.pkg.open = false;
            this.pkg.product = null;
            // Hand focus back so the next scan lands in the search box.
            this.$nextTick(() => this.$refs.search?.focus());
        },

        // Keys while the question is open: 1 / 2 / 3 pick a unit, Enter picks Pack, Esc cancels. Nothing typed reaches the
        // search box. A scanner types its digits within a few ms of each other, so a key that follows another within 60 ms is
        // part of a scan and is swallowed, and a digit is acted on only after 90 ms of quiet: the first digit of a scan
        // (a lone "1") is cancelled by the digit behind it and never picks a unit.
        pkgKey(e) {
            const s = this.pkg;
            if (!s.open || e.timeStamp <= s.openedAt) return;
            if (e.ctrlKey || e.metaKey || e.altKey || /^F\d+$/.test(e.key) || e.key === 'Tab') return;

            const gap = e.timeStamp - s.lastKey;
            s.lastKey = e.timeStamp;
            e.preventDefault();

            if (e.key === 'Escape') { this.pkgClose(); return; }
            if (s.timer) { clearTimeout(s.timer); s.timer = null; return; } // a digit was waiting: this key makes it part of a scan
            if (gap < 60) return;                                            // inside a burst

            if (e.key === 'Enter') { this.pkgChoose('pack'); return; }
            const unit = { '1': 'pack', '2': 'package', '3': 'carton' }[e.key];
            if (unit && s.units.includes(unit)) s.timer = setTimeout(() => { s.timer = null; this.pkgChoose(unit); }, 90);
        },

        // A scanned code: a carton barcode adds a carton line straight away (or says cartons are not sold here); anything else
        // goes the normal way, which asks pack / package for a packaged product.
        pkgScanHit(p, raw) {
            if (p.carton_barcode && p.carton_barcode === raw) {
                if (this.pkgSupports(p, 'carton')) this.addToCart(p, 'carton');
                else this.pkgNotice(this.pkgT('carton_not_sold'));
                return;
            }
            this.addToCart(p);
        },

        pkgNotice(msg) {
            this.errorMsg = msg;
            setTimeout(() => { if (this.errorMsg === msg) this.errorMsg = ''; }, 4000);
        },

        // The badge on a packaged line: Pack <-> Package, re-priced at once. A carton line stays a carton.
        pkgToggle(i) {
            const l = this.cart[i];
            const to = l?.sale_unit === 'pack' ? 'package' : (l?.sale_unit === 'package' ? 'pack' : null);
            const p = l && this.allProducts.find(pp => pp.id === l.product_id);
            if (!to || !p || !l.can_toggle) return;

            const j = this.cart.findIndex((x, k) => k !== i && x.product_id === l.product_id && x.sale_unit === to);
            let at = i;
            if (j >= 0) {          // the other unit already has a line: fold this one into it (the number stays, the unit changes)
                this.cart[j].qty += l.qty;
                this.cart.splice(i, 1);
                at = j > i ? j - 1 : j;
            } else {
                this.cart.splice(i, 1, this.makeLine(p, l.qty, { sale_unit: to, discount_pct: l.discount_pct, discount_amount: l.discount_amount, note: l.note }));
            }
            this.lastScannedIndex = at;
            const line = this.cart[at];
            if (line.track_stock && this.pkgBase(line, line.qty) > line.stock_available) {
                this.pkgNotice(this.t('only_in_stock', { qty: line.stock_available, unit: this.unitLabel(line.unit), name: line.name }));
                line.qty = Math.max(1, this.pkgMax(line));
            }
        },

        // A held line whose unit the product no longer sells (packaging switched off, carton switch turned off, package removed).
        pkgDropped(p, line) {
            const unit = line.sale_unit || 'pack';
            if (this.pkgSupports(p, unit)) return false;
            this.errorMsg = this.pkgT('line_dropped', { name: this.pname(p), unit: this.pkgUnitNames[unit] ?? unit });
            return true;
        },
    };
}
