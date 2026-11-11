// Harness for PaymentModalParityTest: runs the sell screen's own payment-modal methods (pulled out of
// resources/views/pos/sell.blade.php, not a copy) over scenarios read from stdin and prints what the
// screen would decide, as JSON: whether Complete is enabled, and the change / remaining it shows.
const fs = require('fs');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const src = fs.readFileSync(input.bladePath, 'utf8');

const NAMES = [
    'tenderedUsd', 'cashTenderedUsd', 'nonCashUsd', 'noncashExceeds', 'cashDueUsd',
    'cashNotNeeded', 'remainingUsd', 'changeUsd', 'canComplete', 'fillRemaining', 'setPayMode',
    // the debt book's rules: a customer, room under their limit, a due date that is not past
    'creditUsd', 'creditRoom', 'creditAvailable', 'creditNeedsCustomer', 'creditExceeds', 'creditDueInvalid', 'todayStr', 'dateStr',
];

function method(name) {
    const m = new RegExp('\\n[ \\t]+' + name + '\\([^)]*\\) \\{').exec(src);
    if (!m) throw new Error(name + '() not found in the sell screen');
    const start = m.index + 1;
    let depth = 0;
    for (let i = src.indexOf('{', start); i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
    }
    throw new Error(name + '() braces do not balance');
}

const methods = eval('({' + NAMES.map(method).join(',\n') + '})');

// The half-cent slack is a constant on the screen's state: read from the file, not copied here.
const slack = /paySlack:\s*([0-9.]+)/.exec(src);
if (!slack) throw new Error('paySlack not found in the sell screen');

process.stdout.write(JSON.stringify(input.scenarios.map((s) => {
    const screen = Object.assign({
        exchangeRate: input.exchangeRate,
        paySlack: Number(slack[1]),
        totals: { total: s.total },
        payment: Object.assign({ amount_usd: 0, amount_lbp: 0, amount_card: 0, amount_wish: 0, amount_credit: 0, due_date: '', card_reference: '', wish_reference: '', change_usd_out: null }, s.payment),
        // The till's customer chip shape (credit_limit / balance as the typeahead returns them).
        customer: s.customer ? { credit_limit: s.customer.limit, balance: s.customer.balance } : null,
        payMode: 'split',
        processing: false,
        refreshChangePreview() {},
        $nextTick() {},
        $refs: {},
    }, methods);

    return {
        canComplete: screen.canComplete(),
        change: screen.changeUsd(),
        remaining: screen.remainingUsd(),
        exceeds: screen.noncashExceeds(),
        cashNotNeeded: screen.cashNotNeeded(),
    };
})));
