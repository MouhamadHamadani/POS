// Harness for PackagingKeyboardTest: runs the sell screen's own packaging mixin (the Blade partial pos/_packaging-js,
// rendered by PHP and passed in on stdin, not a copy) against scripted key events on a virtual clock, and prints what it did.
const fs = require('fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));

const product = (extra = {}) => ({
    id: 1, name: 'Marlboro Red', type: 'simple', price_usd: '8', has_package: true, package_size: 10, package_price_usd: '75',
    carton_barcode: 'CTN', packages_per_carton: 20, sell_carton_at_pos: false, carton_price_usd: '1400', ...extra,
});

function host(clockRef) {
    const perf = { now: () => clockRef.t };
    const st = (fn, ms) => { const id = ++clockRef.seq; clockRef.timers.push({ id, at: clockRef.t + ms, fn }); return id; };
    const ct = (id) => { clockRef.timers = clockRef.timers.filter((x) => x.id !== id); };
    const make = new Function('performance', 'setTimeout', 'clearTimeout', input.js + '\nreturn posPackaging;')(perf, st, ct);
    const added = [];
    const h = Object.assign(make(), {
        cart: [], priceTier: 'retail', errorMsg: '',
        $refs: { search: { blur() {}, focus() {} }, pkgBox: { focus() {} } },
        $nextTick: (f) => f(),
        addToCart: (p, unit) => { added.push(unit); },
        tierPrices: (p) => ({ retail: Number(p.price_usd), wholesale: Number(p.price_usd), vip: Number(p.price_usd) }),
        pname: (p) => p.name, t: (k) => k,
    });
    return { h, added };
}

function advance(c, ms) {
    const target = c.t + ms;
    for (;;) {
        const due = c.timers.filter((x) => x.at <= target).sort((a, b) => a.at - b.at)[0];
        if (!due) break;
        c.timers = c.timers.filter((x) => x.id !== due.id);
        c.t = due.at;
        due.fn();
    }
    c.t = target;
}

const out = input.scenarios.map((s) => {
    const c = { t: 1000, seq: 0, timers: [] };
    const { h, added } = host(c);
    const res = { name: s.name };

    if (s.math) {
        // pkgBase / pkgMax over a cart: packs shared by every line of one product
        h.cart = s.math.cart;
        res.max = h.pkgMax(s.math.line);
        res.base = h.pkgBase(s.math.line, s.math.qty);
        return res;
    }

    h.pkgAsk(product(s.product || {}));
    res.units = [...h.pkg.units];
    for (const step of s.steps) {
        if (step.wait) { advance(c, step.wait); continue; }
        advance(c, step.at ?? 0);
        // `before`: an event stamped earlier than the modal opened (the Enter of the scan that opened it)
        h.pkgKey({ key: step.key, timeStamp: step.before ? 999 : c.t, ctrlKey: false, metaKey: false, altKey: false, preventDefault() {} });
    }
    advance(c, 500);
    res.added = added;
    res.open = h.pkg.open;
    return res;
});

process.stdout.write(JSON.stringify(out));
