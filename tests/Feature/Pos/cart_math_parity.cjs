// Harness for CartMathParityTest: runs the sell screen's own computeCart() (pulled
// out of resources/views/pos/sell.blade.php, not a copy) over scenarios read from
// stdin and prints the results as JSON. computeCart must stay free of `this`.
const fs = require('fs');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const src = fs.readFileSync(input.bladePath, 'utf8');

const start = src.indexOf('computeCart(cart, cartDiscount, customer) {');
if (start < 0) throw new Error('computeCart() not found in the sell screen');

let depth = 0;
let end = -1;
for (let i = src.indexOf('{', start); i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}' && --depth === 0) { end = i; break; }
}
if (end < 0) throw new Error('computeCart() braces do not balance');

const host = eval('({' + src.slice(start, end + 1) + '})');

process.stdout.write(JSON.stringify(
    input.scenarios.map((s) => host.computeCart(s.cart, s.cartDiscount, s.customer))
));
