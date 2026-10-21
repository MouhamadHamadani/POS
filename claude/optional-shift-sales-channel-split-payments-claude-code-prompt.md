# Claude Code prompt — optional shifts fix, on-site/online sales channel, split payments (Cash / Card / Wish)

Branch off `main` as `feat/shift-optional-channel-split-payments`. Build it in the **main product**.
Do **not** merge into `vortex-client` — Mouhamad does that afterwards.

Read `CLAUDE.md` first and follow every convention in it. The ones this task leans on hardest:
`SaleService` is server-authoritative, `AuditLog::record()` on every mutation, enum labels via
`EnumLabel`, both languages for every string (`LangParityTest`, `NoHardcodedStringsTest`),
`CartMathParityTest` (do not touch `totalsFromCart()` / `computeCart()` arithmetic), features are
declared only in `config/features.php`, never edit a shipped migration, never touch `storage/`.

Origin: a prospective client asked for (1) selling to work when "open/close shift" is switched off,
and (2) a way to separate on-site sales from online sales. Mouhamad added (3): a customer in the store
paying part Wish, part cash is a real scenario the system can't record today, so payments must be
splittable for **both** on-site and online sales.

---

## 0. What the code review found (verified against the repo, don't re-discover it)

| Finding | Where |
|---|---|
| **Part 1 is a known bug, already written up as a test.** `tests/Feature/BugHunt/Bug032SellWithoutShiftWhenShiftNotRequiredTest.php` exists and describes it. `ShiftRequired` middleware lets the till open when `require_shift` is off, but `SaleController::store()` still looks up an open shift and answers `409 messages.no_open_shift`, `SaleService::process()` does `Shift::findOrFail($shiftId)` + `isOpen()`, and `sales.shift_id` is `NOT NULL` (`2025_01_07_000001_create_sales_table.php`). Three layers all require a shift. | `app/Http/Controllers/SaleController.php`, `app/Services/SaleService.php`, `app/Http/Middleware/ShiftRequired.php` |
| The setting is `require_shift` (`Setting::get('require_shift', true)`, Settings → POS, default `'1'` in `DefaultSettingsSeeder`, env `POS_REQUIRE_SHIFT` in `config/pos.php`). `shifts` is a **core** feature in `config/features.php`; it is not a plan feature and stays that way. | |
| Payments are flat columns on `sales`: `payment_method` (`cash_usd\|cash_lbp\|card\|mixed\|credit\|split`), `amount_tendered_usd`, `amount_tendered_lbp`, `amount_card_usd`, `amount_credit_usd`, `change_usd`, `change_lbp`, `card_type`, `card_reference`. `mixed` today means cash USD + cash LBP. There is **no Wish** anywhere. | `Sale::METHODS`, `SaleService::process()` |
| **`ShiftService::totals()` computes expected drawer cash from `amount_tendered_usd/lbp` minus `change_usd/lbp`**, plus cash movements, minus cash refunds. Many existing tests build `Sale` rows directly with those legacy columns (`tests/Feature/Sales/MakesSales.php`, `tests/Feature/Shifts/*`, `BugHunt/Bug021*`, `Bug033*`). So the legacy columns **must stay authoritative for the drawer** — see §3 design. | `app/Services/ShiftService.php` |
| The sell screen's payment modal has one `<select x-model="payment.method">` (cash_usd / cash_lbp / mixed / card). Alpine `payment = { method, amount_usd, amount_lbp, amount_card, card_type, card_reference, change_usd_out }`. `tenderedUsd()` sums cash + LBP/rate + card. The modal has **no on-account/credit UI** even though the server accepts `amount_credit`. | `resources/views/pos/sell.blade.php` (~L395-495 markup; JS at ~L591, ~L1235-1320) |
| **Existing latent bug to fix in passing:** `SaleService` computes change as `calculateChange($grandTotal - $card - $credit, $tendUsd, $tendLbp, …)`. If `card + credit > total`, that first argument goes negative and the cashier is told to hand back *cash from the drawer* for a card overpayment. Non-cash methods must never be able to produce change. | `SaleService::process()` |
| Returns: `refund_method` is validated `in:cash_usd,cash_lbp,account,exchange` and returns are **by item, one refund method per return**. Only `cash_usd`/`cash_lbp` touch the drawer (`ShiftService::totals()`). | `ReturnController::store()` ~L85, `returns/create.blade.php` |
| Sales History filters `sales.payment_method` against `Sale::METHODS`. Receipts print `payment_method` + the legacy tendered/card/credit rows. | `SaleHistoryService::filters()/query()`, `receipts/thermal.blade.php` ~L160-177, `receipts/a4.blade.php` ~L193-210, `sales/show.blade.php` ~L127-140, `ReceiptRenderer` ~L66, `SettingController` test-print `Sale` ~L298 |
| `ReportService::dailySales()` aggregates `total_usd` by day only; no payment or channel breakdown exists. Reports have PDF variants that need `@pdf()` for Arabic. | `app/Services/ReportService.php`, `resources/views/reports/daily-sales*.blade.php` |
| `held_sales` has `price_tier` and `cart_discount` columns added in `2026_10_06_000001`; a held cart restores them on recall. Channel must ride along the same way. | `HeldSaleController` |
| `ResetData` command lists the tables it wipes. | `app/Console/Commands/ResetData.php` ~L44 |
| `app/Http/Controllers/HeldSaleController-1.php` is a stray copy. Leave it alone. | |

---

## 1. PART 1 — Selling with shifts switched off (fix BUG-032)

Goal: with **Settings → POS → "Require open shift to sell" off**, a cashier can sell with no shift.
With it **on**, behaviour is exactly what it is today.

1. **Migration** (new file, e.g. `2026_10_22_000001_make_sales_shift_id_nullable.php`): make
   `sales.shift_id` nullable, keep the FK (`restrictOnDelete`) and the `shift_id` index.
   SQLite: Laravel 11's `->nullable()->change()` rebuilds the table — re-state the full column
   definition, then **verify on a copy of a populated DB** (`PRAGMA foreign_key_check`, `PRAGMA index_list(sales)`,
   row count before/after, all other columns intact). **Never run migrations against `database/nativephp*.sqlite`
   directly — copy first.** `down()` may refuse (or null-fill) if shift-less sales exist; document which.
2. `SaleController::store()`: look up the user's open shift as now. If none:
   - `require_shift` on → keep returning `409 messages.no_open_shift` (unchanged);
   - `require_shift` off → continue with `shiftId = null`.
3. `SaleService::process()`: `?int $shiftId`. If an id is given, keep the existing "closed shift" guard.
   If `null` and `Setting::get('require_shift', true)` is truthy, throw the same domain error the controller
   would have returned (defence in depth — the service must not rely on the controller). A cashier who **has**
   voluntarily opened a shift while the setting is off still gets the sale attached to it, so their drawer count stays right.
4. UI: `layouts/pos.blade.php` shows the "Close shift" button unconditionally. Hide it when the user has no open shift
   **and** `require_shift` is off. Do not add new shift UI.
5. Audit every place that dereferences `$sale->shift` / `shift_id`: `sales/show.blade.php` (already guarded),
   `SaleHistoryService` eager load, receipts, returns, `ShiftService`, `ReportService`, dashboard. A shift-less sale
   must render everywhere with no error and must be counted in revenue/reports exactly like any other sale.
   Returns/voids of a shift-less sale must work and simply move no drawer cash.
6. Expenses: "paid from drawer" already requires an open shift (`ExpenseService::shiftFor`, `canDrawer`). Confirm that
   with shifts off the form falls back to "paid outside the drawer" with no error, and add a test.
7. Expected product behaviour to document in the final summary: with shifts off there is no drawer reconciliation
   at all (that is the point of the setting); sales are still fully reported.

Tests: the existing `Bug032…Test` must go green. Add: sale succeeds shift-less when off; still 409 when on; service
rejects `null` shift when on; an open voluntary shift gets the sale attached when off; closed shift id still rejected;
void and return of a shift-less sale; shift-less sale appears in daily-sales/P&L/Sales History; drawer expense form with no shift.

---

## 2. PART 2 — Sales channel: on-site vs online

"Online" here means orders the store takes by **WhatsApp / Instagram / Facebook / phone** and rings up
manually in the POS. It is **not** a website integration and nothing is imported automatically. Say so in code comments and in the manual notes.

1. **Migration**: `sales.channel` string, not null, default `'onsite'`, indexed with `created_at`
   (`index(['channel','created_at'])`); `sales.channel_source` string nullable
   (`whatsapp|facebook|instagram|phone|other`). Existing rows become `onsite`.
   Also `held_sales.channel` (default `'onsite'`) + `held_sales.channel_source` nullable.
2. `Sale`: `CHANNEL_ONSITE`, `CHANNEL_ONLINE`, `CHANNELS`, `CHANNEL_SOURCES`, `$fillable`, `channelLabel()` via
   `EnumLabel::get('sale_channel', …)` / `sale_channel_source`.
3. `SaleController::store()` validates `channel` (`nullable|in:onsite,online`) and `channel_source`
   (`nullable|in:…`, **only kept when channel = online**, otherwise nulled). `SaleService::process()` takes `channel`/`channelSource`,
   writes them, and includes them in the `AuditLog::record` payload. `channel` has no effect on pricing, stock, tax, or loyalty.
4. **Feature gate.** Add feature key `sales_channels` (group `sales`, `requires: []`) in `config/features.php` and put it in the
   **`$standard`** preset (so Standard and Premium; **not** Starter). Follow the whole checklist in `CLAUDE.md`: config entry, preset,
   `features.labels.sales_channels` + group strings in both languages, a row in `claude/feature-matrix.md`
   (`FeatureUpgradeAndCatalogueTest` fails if matrix and config drift). There is no route to gate: when the feature is off,
   `SaleController` forces `channel = onsite` (ignore what the request sent, don't error), the sell-screen toggle and the History/report
   channel filters are hidden (`@feature('sales_channels')` / `feature()`), and existing stored values stay readable.
   "Switching a feature off never stops core flows from writing data" — selling must work either way.
   *(Placement is a pricing decision Mouhamad can change in one line of `config/features.php`.)*
5. **Sell screen**: a two-option segmented control "On-site | Online" next to the customer / price-tier controls; choosing Online reveals
   an optional "Source" select. Default On-site; after each completed sale it resets to On-site. State lives in the Alpine component; send
   `channel` + `channel_source` in the `submit()` body. Hold/recall carries channel and source (`HeldSaleController` store/recall payload,
   same pattern as `price_tier`). No new i18n mechanism: strings go in `pos.ui` and are read with `t()`.
6. Surfacing: Sales History gets a channel filter and a channel badge column; `sales/show` shows channel + source; receipts print a short
   "Online order · WhatsApp" line **only** when channel is online (thermal + A4 + `ReceiptRenderer` + the test-print sample in `SettingController`).
   Daily-sales report gets a channel breakdown (see §5).

Tests: default onsite; online + source saved; source dropped for onsite; invalid values rejected; feature off forces onsite and hides UI;
hold→recall round trip keeps channel; Sales History channel filter; legacy rows read as onsite.

---

## 3. PART 3 — Split payments (Cash / Card / Wish), for on-site and online

### Design (and why)

Keep the legacy columns on `sales` as the **authoritative cash-drawer ledger** (`amount_tendered_usd/lbp`, `change_usd/lbp`,
`amount_card_usd`, `amount_credit_usd`, `card_*`), because `ShiftService`, receipts and many existing tests read them.
Add a new `sale_payments` table as the **itemised record** used for display and reporting. `SaleService` writes both, consistently, in the
same transaction.

**Payment request shape: keep it flat**, extending the existing object rather than inventing an N-row array. Each method can appear
at most once (cash already splits USD/LBP via two fields), so five slots express every real combination and the Alpine/`previewChange`
code stays simple:

```
payment: {
  amount_usd, amount_lbp,          // cash tendered, by currency (unchanged)
  amount_card, card_type, card_reference,   // unchanged
  amount_wish, wish_reference,     // NEW (USD)
  amount_credit,                   // unchanged (on-account, loyalty feature)
  change_usd_out, loyalty_points_redeemed,  // unchanged
  method                           // legacy: still accepted, now NULLABLE and IGNORED
}
```

**The server derives `sales.payment_method`; it never trusts the client's `method`.** Rule: only cash USD → `cash_usd`; only cash LBP → `cash_lbp`;
cash USD + LBP only → `mixed` (existing meaning); only card → `card`; only wish → `wish`; only credit → `credit`; **anything else → `split`**.
Add `Sale::METHOD_WISH = 'wish'` to `Sale::METHODS`. (`payment.method` is currently `required` in `SaleController` — make it `nullable`.)

### Schema (new migrations, never edit shipped ones)

`create_sale_payments_table`: `id`, `sale_id` (FK, cascade on delete), `method` (`cash|card|wish|credit`), `currency` (`USD|LBP`),
`amount` decimal(18,4) **in `currency`** (cash: the amount *tendered* in that currency), `amount_usd` decimal(12,4) (same value normalised
to USD), `reference` nullable (card approval / Wish transaction ref), `card_type` nullable, timestamps; index `(sale_id)`, `(method)`.
Wish is entered in **USD in v1**; `currency` exists so LBP can be added later without a migration.

`Sale::payments(): HasMany`, `SalePayment` model, and `Sale::paymentLines()` which returns the rows, or — for a sale with none
(older data, or a test that built a `Sale` from legacy columns) — synthesises equivalent lines from the legacy columns. Use that accessor
for **display** (receipts, sale detail). Reports use SQL on `sale_payments`.

`backfill_sale_payments`: one idempotent, chunked migration creating lines for every existing sale from its legacy columns
(cash USD / cash LBP / card with its type+reference / credit). Every sale gets its lines, whatever its status (voided included), so history stays consistent.

Add `sale_payments` to `ResetData::TRANSACTIONAL` in an FK-safe order (before `sales`), and add `channel` / `channel_source` to `HeldSale::$fillable`. If `DemoSeeder` seeds sales (it doesn't today, check), give a few demo sales a cash+Wish split,
a full Wish and an online channel so the demo shows the feature (`DemoSeederTest`/`DemoResetTest` must stay green).

### Rules in `SaleService::process()` (replace the payment block; keep everything else)

1. `cashAsUsd = amount_usd + lbpToUsd(amount_lbp)`; `nonCash = card + wish + credit`; `totalPaid = cashAsUsd + nonCash`.
2. `totalPaid + 0.001 < grandTotal` → `ValidationException` key `payment`, `messages.payment_insufficient` (unchanged).
3. **`nonCash > grandTotal + EPS`** → `ValidationException` key `payment`, new `messages.payment_noncash_exceeds`. Card/Wish/on-account must be exact or less; they can never create change.
4. `cashDue = grandTotal - nonCash`. If `cashDue <= EPS` and any cash was tendered → `payment`, new `messages.payment_cash_not_needed`.
5. `change = calculateChange($cashDue, $tendUsd, $tendLbp, $changeUsdOut)` — i.e. change is computed **only** against the cash due. (Fixes the latent card-overpay bug above.)
6. Credit checks stay as they are (customer required, limit), and remain behind `feature('loyalty')` in the controller.
7. Write the sale (legacy columns exactly as today, derived `payment_method`) and the `sale_payments` rows. The sum of the non-cash `amount_usd` plus
   (cash tendered − change) must equal `total_usd` within the LBP rounding step; put any rounding remainder on cash and **cover it with a test**
   (LBP rounding is the usual source of a 1-cent mismatch).
8. `AuditLog::record` for the sale gains `payment_method`, `channel`, and per-method amounts. Audit descriptions stay English.
9. `voidSale()`: nothing new to reverse for card/wish (no integration); keep the existing credit reversal.

`ShiftService::totals()` needs **no change to the drawer maths** — Wish and card never enter the drawer. Add a test proving a Wish line and a card line
leave `expected_usd` untouched and that a cash+Wish split counts only its cash part (tendered − change). Small addition: on `shifts/close`
show a read-only "Non-cash takings this shift" panel (card total, Wish total, from `sale_payments` for that shift's non-voided sales) so the
cashier can reconcile against the terminal/Wish app. It must not affect variance.

`PosController::previewChange` is called with the **cash due** (the JS passes `total - nonCash` as `total_usd`); no server change needed beyond
confirming it never receives a negative value.

### Sell screen (`resources/views/pos/sell.blade.php`) — payment modal

- Replace the method `<select>` with quick buttons: **Cash**, **Card**, **Wish**, **Split**.
  - Cash: clear card/wish, focus the USD field (the existing USD/LBP cash inputs, +denomination chips and split-change panel stay exactly as they are).
  - Card: `amount_card = total`, everything else 0, show type + approval ref (as today).
  - Wish: `amount_wish = total`, everything else 0, show the optional Wish reference field.
  - Split: show every field (cash USD, cash LBP, card, Wish) at once, each non-cash row with a **"fill remaining"** button.
- Always show **Total / Paid so far / Remaining** (or Change owed). Compute with one `paidUsd()` helper = cash USD + cash LBP/rate + card + wish
  (+ credit if present). Extend `tenderedUsd()` rather than adding a parallel function.
- Disable "Complete sale (F12)" when remaining > 0.005 **or** non-cash exceeds the total (show the `payment_noncash_exceeds` message inline).
  The change panel only appears when the overpayment is covered by cash.
- On submit send the flat `payment` object above; nothing else about `submit()` changes. Reset the object in `openPayment()` and after a sale.
- RTL/i18n rules from `CLAUDE.md` apply (logical utilities, `<x-ltr>` around amounts, `dir="ltr"` on numeric inputs). Keep F-key shortcuts working.
  Do not touch `computeCart()`.

### Receipts, sale detail, History

- Thermal (`receipts/thermal.blade.php`), A4 (`receipts/a4.blade.php`), `ReceiptRenderer`, `sales/show.blade.php`, and the `SettingController` test-print sample
  (make the sample a cash + Wish split) list **each payment line** (method label, amount, reference) from `Sale::paymentLines()`, then change as today.
  Receipts follow the store `receipt_language` setting, not the UI language.
- Sales History payment filter: options Cash / Card / Wish / On account / Split. A split sale must show up under each method it contains:
  cash → `payment_method IN (cash_usd, cash_lbp, mixed)` or has a cash line; card → `payment_method = card` or has a card line; wish → has a wish line;
  on account → `payment_method = credit` or `amount_credit_usd > 0`; split → `payment_method = split`. Put this in `SaleHistoryService` (keep it one rule) and test every branch,
  including legacy sales that have no `sale_payments` rows.

### Returns (scoped deliberately small)

Returns are item-based with one refund method. Do **not** build proportional multi-line refunds now. Add refund methods `card` and `wish`
(validation `in:`, the select in `returns/create.blade.php`, `enums.refund_method` in both languages) and **preselect the method of the sale's largest payment line**.
`card`/`wish` refunds move no drawer cash (assert it in a test next to `ShiftRefundCashTest`). Note the limitation in the summary: a sale paid part Wish /
part cash is refunded through one method per return; a proportional multi-method refund is a possible follow-up.

---

## 4. i18n (both languages, no exceptions)

New keys in `lang/en/*` and `lang/ar/*`: `enums.payment_method.wish`, `enums.sale_channel.{onsite,online}`, `enums.sale_channel_source.*`,
`enums.refund_method.{card,wish}`, `pos.ui.*` for the modal and channel toggle (read through `t()`), `messages.payment_noncash_exceeds`,
`messages.payment_cash_not_needed`, `sales.*` / `receipt.*` / `shifts.*` strings for the new rows and panel, `features.labels.sales_channels`.
Reuse the wording already in `lang/ar/*`'s glossary (for Wish the brand is written ويش; "online" as أونلاين, "on-site" as في المتجر unless the glossary already
has a different established term — check first). `LangParityTest` and `NoHardcodedStringsTest` must pass. Western digits in both languages.

## 5. Reports

Daily-sales report (`ReportService::dailySales` + `reports/daily-sales.blade.php` + `daily-sales-pdf.blade.php`): **leave the existing columns and totals
untouched** (`RevenueReportTest`), and add two read-only sections below them: **by payment method** (cash net of change, card, Wish, on-account — summing to
total revenue) and **by channel** (on-site vs online: count + revenue; section hidden when `sales_channels` is off). PDF uses `@pdf()` and reversed RTL columns. CSV/export
filenames stay ASCII. Add a Pest test for each aggregate, including a cash+Wish split and an online sale.

## 6. Docs to update in the same PR

- `CLAUDE.md`: add short convention bullets — (a) shift-less sales are legal only while `require_shift` is off, `sales.shift_id` is nullable; (b) payments: legacy `sales`
  columns are the drawer ledger, `sale_payments` the itemised record, `SaleService` derives `payment_method` and writes both, non-cash can never cause change;
  (c) `channel` / `sales_channels` feature.
- `claude/feature-matrix.md`: the new `sales_channels` row; note that split payments/Wish are core on every plan.
- Do **not** rebuild the user manual. In the final summary, list which manual sections need updating (Selling & payments, Shifts, Sales History, Reports, Returns, Plans & Features).

## 7. Order of work (commit after each, run `php artisan test` before each commit)

1. Part 1 (bug + migration + tests). 2. Part 2 (channel + feature key). 3. Part 3 backend (migrations, `SaleService`, `ShiftService` panel, tests).
4. Part 3 UI (sell modal, receipts, sale detail, History filter). 5. Returns methods + reports sections. 6. Docs. Keep commits small and conventional (`fix(shifts): …`, `feat(sales): …`).

## 8. Out of scope (do not build)

Any Wish/card terminal or API integration — these methods are **bookkeeping only**; the cashier still takes the payment in the Wish app or card machine.
A website/online-store import; delivery fees, addresses or couriers; LBP-denominated Wish; proportional multi-method refunds; per-channel pricing or stock rules;
changing the shift open/close screens; touching `vortex-client`.

## 9. Acceptance checklist

- [ ] `Bug032…Test` passes; with `require_shift` on, selling without a shift still returns 409.
- [ ] Migrations run clean on a **copy** of a populated `nativephp.sqlite`; row counts, FKs and indexes intact; old sales show as `onsite` with correct payment lines.
- [ ] A cash + Wish split completes on-site and online; receipt, sale detail, History and reports all show both lines.
- [ ] Card/Wish over the total is refused; card/Wish never produce change; the old card-overpay-gives-cash-change path is gone.
- [ ] Wish and card never change `expected_usd` at shift close; cash part of a split does.
- [ ] Rounding test: LBP-rounded split sales sum to `total_usd` within the rounding step.
- [ ] `sales_channels` off → forced on-site, UI hidden, selling unaffected; on → toggle, filter, badge, report section work.
- [ ] Arabic and English both complete; RTL layout checked on the modal; `LangParityTest` + `NoHardcodedStringsTest` green.
- [ ] Full suite green: `php artisan test`.
- [ ] **Needs physical hardware, say so in the summary rather than claiming it works:** thermal receipt print of a split + online receipt, and an A4 print to a real printer.
