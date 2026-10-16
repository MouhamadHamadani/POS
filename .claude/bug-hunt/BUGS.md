# Bug ledger

IDs are stable; never renumber. Repro tests live in `tests/Feature/BugHunt/`. Form repro tests use the
helper trait `tests/Feature/BugHunt/Concerns/SubmitsRenderedForms.php`, which parses the rendered
`<form>` and submits exactly what a browser would (an un-ticked checkbox sends nothing, `:disabled="true"`
controls are not sent), so a test passes for any correct fix (controller default OR hidden fallback input).

---

## BUG-001 — Un-ticking "Active" on a category row never deactivates the category
- Status: verified
- Verified: 2026-10-16. Hidden is_active=0 before the box is the cause fix (CategoryController default for API callers kept). Repro passes, assertion intact.
- Severity: medium
- Area: forms / categories
- Where: app/Http/Controllers/CategoryController.php:42 (update), resources/views/categories/index.blade.php:60
- Repro test: tests/Feature/BugHunt/Bug001CategoryCannotBeDeactivatedTest.php
- What happens: The row's "Active" checkbox can be ticked but not un-ticked. An un-ticked box is absent from the request and the controller reads `$request->boolean('is_active', true)`, so "absent" means "active". The sell screen lists only active categories, so a category the owner meant to hide stays on the till.
- Expected: Saving the row with the box un-ticked sets `is_active = false`.
- Cause (if known): `boolean('is_active', true)` default. `store()` (line 28) legitimately defaults to true (the add form has no checkbox); `update()` must not.
- Fix notes: FIXED in d90f567. Cause: un-ticked checkbox sends nothing; update() reads a missing is_active as true. Fix: hidden `is_active=0` before the checkbox in the category row (resources/views/categories/index.blade.php), same convention as BUG-004. Controller untouched (store() keeps its default-true for the add form, which has no checkbox). The category row is still a `<form>` directly under `<tr>` (see Needs manual check); the hidden input sits inside the same form as the other controls, so it is submitted with them.

## BUG-002 — Un-ticking "Active" on the customer edit form never deactivates the customer
- Status: verified
- Verified: 2026-10-16. Hidden is_active=0 on the customer form fixes the cause; repro passes, no assertion weakened. Round-trip of an inactive customer is clean.
- Severity: medium
- Area: forms / customers
- Where: app/Http/Controllers/CustomerController.php:171 (validateData), resources/views/customers/form.blade.php:68
- Repro test: tests/Feature/BugHunt/Bug002CustomerCannotBeDeactivatedTest.php
- What happens: Same pattern as BUG-001. `tax_exempt` on the same form works (read with no default); `is_active` is read with `boolean('is_active', true)`. Inactive customers are what the till's customer search hides, so a customer cannot be retired from the UI.
- Expected: Saving with "Active" un-ticked sets `is_active = false`.
- Cause (if known): `boolean('is_active', true)` default in `validateData()`, shared by store and update. `quickAdd()` sets is_active itself, so it is unaffected.
- Fix notes: FIXED in 3b0a812. Cause: un-ticked checkbox sends nothing; validateData() (shared by store/update) reads a missing is_active as true. Fix: hidden `is_active=0` before the checkbox in resources/views/customers/form.blade.php (same convention as BUG-004). Controller untouched: the create form renders the box ticked, so new customers stay active; quickAdd() sets is_active itself. New test: tests/Feature/Customers/CustomerActiveCheckboxTest.php (create default active / un-tick on create / re-activate on edit). `tax_exempt` on the same form is deliberately NOT given a hidden input here (BUG-026, batch 3); the hidden-`is_active` input also makes the redisplayed form after a validation error show Active as the user left it, but the BUG-026 test still needs the tax_exempt part.

## BUG-003 — Un-ticking "Active" on the supplier edit form never deactivates the supplier
- Status: verified
- Verified: 2026-10-16. Passing through the BUG-026 hidden input on the supplier form (cause fixed there). Repro unchanged and green.
- Severity: low
- Area: forms / suppliers
- Where: app/Http/Controllers/SupplierController.php:119 (validateData), resources/views/suppliers/form.blade.php:63
- Repro test: tests/Feature/BugHunt/Bug003SupplierCannotBeDeactivatedTest.php
- What happens: Same pattern as BUG-001/002. Inactive suppliers are what the purchase-order supplier picker hides.
- Expected: Saving with "Active" un-ticked sets `is_active = false`.
- Cause (if known): `boolean('is_active', true)` default.
- Fix notes: already passing, fixed by an earlier change: commit 1e1dd2f (BUG-026) put the hidden `is_active=0` before the supplier form's checkbox (resources/views/suppliers/form.blade.php), the same convention as BUG-001/002/004, which BUG-026's supplier test needed. SupplierController is unchanged (`boolean('is_active', true)` still serves non-browser callers). The BUG-003 repro test was committed in 1e1dd2f too, since that is the commit that makes it pass.

## BUG-004 — Un-ticking "Active" on a tax-rate row (Settings > Tax) never deactivates the rate
- Status: verified
- Verified: 2026-10-16. Hidden is_active=0 in the tax row fixes the cause; the product edit form keeping an inactive tax is a sound companion. Repro green. See BUG-040 (what Active should mean for sales).
- Severity: medium
- Area: forms / settings / tax
- Where: app/Http/Controllers/SettingController.php:389 (updateTax), resources/views/settings/index.blade.php:138
- Repro test: tests/Feature/BugHunt/Bug004TaxRateCannotBeDeactivatedTest.php
- What happens: The row's `is_active` is read with `boolean('is_active', true)`, so an old VAT rate cannot be retired from the UI. (The row's `is_inclusive` and `is_default` boxes work because they have no default.)
- Expected: Saving with "Active" un-ticked sets `is_active = false`.
- Cause (if known): `boolean('is_active', true)` default.
- Fix notes: FIXED in d0f018a. Cause: an un-ticked checkbox sends nothing and updateTax() reads a missing is_active as true. Fix: a hidden `<input type="hidden" name="is_active" value="0">` immediately before the checkbox in the tax row (resources/views/settings/index.blade.php); a ticked box is sent after it and wins. The controller is unchanged (its `boolean('is_active', true)` still serves non-browser callers). CONVENTION FOR THE "ACTIVE" CLASS (001/002/003/004/026): hidden `is_active=0` before the checkbox, controller default untouched. It also makes `old('is_active', ...)` repopulate as un-ticked after a validation error (old value is "0", falsy for @checked), which is what BUG-026 needs; batch 3 copies the same two-line pattern into the supplier/user/product forms. Interaction fixed in the same commit: ProductController::edit() now lists `Tax::where(is_active OR id = product.tax_id)` and the option is suffixed "(Inactive)" (common.inactive), so a product on a retired rate keeps it on save; create() is unchanged (inactive rates are not offered to new products). New test: tests/Feature/Products/ProductInactiveTaxKeptTest.php. Same pattern elsewhere: category row (BUG-001) and customer form (BUG-002), fixed in their own commits.

## BUG-005 — The "Restock" checkbox on the return form cannot be turned off; damaged goods are always restocked
- Status: verified
- Verified: 2026-10-16. Hidden items[N][restock]=0 ahead of the checkbox; server reads "0" as false. Repro asserts stock unchanged and the return created, intact.
- Severity: critical
- Area: forms / returns / stock
- Where: resources/views/returns/create.blade.php:66, app/Http/Controllers/ReturnController.php:109
- Repro test: tests/Feature/BugHunt/Bug005ReturnRestockCheckboxCannotBeUntickedTest.php
- What happens: The Restock box starts ticked. Un-ticking it makes the browser send nothing for that line, and `store()` treats a missing `restock` key as `true` ("Default restock to true ... Caller must explicitly pass restock=0"). The form never passes 0. A returned item the cashier marked as not-for-restock is added back to sellable stock and logged as a return movement.
- Expected: A line returned with Restock un-ticked leaves `stock_qty` unchanged.
- Cause (if known): No hidden `restock=0` fallback input in the form; server default of true for a missing key. (Existing API-style callers that omit `restock` may rely on the default, so the fix is likely the hidden input in the view.)
- Fix notes: FIXED in 193b266. Cause: an un-ticked checkbox sends nothing and store() reads a missing `restock` key as true; the form never sent 0. Fix: a hidden `items[N][restock]=0` ahead of the checkbox in resources/views/returns/create.blade.php (only on lines that can still be returned). The controller default for an omitted key is unchanged, so API-style callers keep restocking. No other form has a restock box.

## BUG-006 — Clearing a wholesale / VIP / LBP price or max stock on the product edit form is silently ignored
- Status: verified
- Verified: 2026-10-16. Only the NOT NULL columns are still dropped when blank; nullable tier prices and max_stock arrive as NULL. Repro asserts all four are NULL and the wholesale price falls back to retail, intact.
- Severity: high
- Area: forms / products / money
- Where: app/Http/Requests/StoreProductRequest.php:73-79 (prepareForValidation), resources/views/products/form.blade.php:146-167,198
- Repro test: tests/Feature/BugHunt/Bug006ProductEditCannotClearOptionalPricesTest.php
- What happens: `wholesale_price_usd`, `vip_price_usd`, `price_lbp` and `max_stock` are nullable columns (a null tier price falls back to retail in `Product::priceForGroup`). `prepareForValidation()` drops every numeric field that arrives blank "so the model default kicks in". That is right on create, but on update it means the old value is kept: the admin empties the field, saves, sees "Product updated", and wholesale/VIP customers are still charged the old tier price (and the old forced LBP price stays).
- Expected: A blanked nullable field is saved as NULL on update. (Non-nullable ones, `cost_usd`, `stock_qty`, `min_stock`, `unit`, still need the keep-existing / default treatment.)
- Cause (if known): One blank-handling rule applied to nullable and NOT NULL columns alike, on create and update alike.
- Fix notes: FIXED in 7c80142. Cause: StoreProductRequest::prepareForValidation() dropped every blank numeric field. Fix: only the NOT NULL columns (cost_usd, stock_qty, min_stock) are dropped now; wholesale_price_usd, vip_price_usd, price_lbp and max_stock arrive as NULL and are saved as NULL on update (and on create, where NULL is the column default anyway). Files: app/Http/Requests/StoreProductRequest.php.

## BUG-007 — Settings > Currency saves an exchange rate of 0, a negative, blank or text (no server validation)
- Status: verified
- Verified: 2026-10-16. All four data sets (0, negative, blank, text) are still in the repro; rules are sometimes-prefixed so a tab only validates the keys it posts. Every tab (general, currency, pos, receipt, numbering, loyalty, backup) was re-posted exactly as rendered with the seeded defaults: saves, and no setting changes on a no-edit save.
- Severity: high
- Area: forms / settings / money
- Where: app/Http/Controllers/SettingController.php:97-175 (update), resources/views/settings/index.blade.php:108
- Repro test: tests/Feature/BugHunt/Bug007ExchangeRateAcceptsZeroAndGarbageTest.php
- What happens: `update()` validates only `group` and that `settings` is an array, then stores every known key as `(string) $value`. The input itself allows 0 (`min="0"`). With a rate of 0, `CurrencyService::lbpToUsd()` returns 0 (every LBP note tendered counts as $0) and `usdToLbp()` prices everything at 0 LBP. Blank/text are stored as '' / 'abc' and read back as 0.0. Nothing else on the page is validated either: `lbp_rounding_step`, `receipt_width`, `max_cashier_discount_pct` (>100), `idle_timeout_min`, `points_per_dollar`, `redemption_rate`, the numbering prefixes.
- Expected: A rate must be a number > 0; the save is refused with a validation error and the old rate is kept. The other numeric settings should be validated to the ranges the form advertises.
- Cause (if known): No per-key rules. The form's `min="0"` should be `min="0.0001"` (or similar) as well.
- Fix notes: FIXED in ee20250. Cause: SettingController::update() had no per-key validation. Fix: a NUMERIC_SETTINGS table in app/Http/Controllers/SettingController.php (`sometimes|...` rules, labels from settings.*): exchange_rate required|numeric|gt:0 with the message messages.exchange_rate_invalid (lang/en + lang/ar), old rate kept; lbp_rounding_step numeric>=0; receipt_width in 58,80; max_cashier_discount_pct 0..100; idle_timeout_min 1..600; paper_width_char 20..80; points_per_dollar >=0; redemption_rate required int>=1 (0 would divide by zero in loyalty maths); tier thresholds / expiry_days / retention_days int>=0; numbering prefixes max 20 chars. Other keys (general text, language) are unchanged. The rate input is now min="0.01" (resources/views/settings/index.blade.php). New test: tests/Feature/Settings/SettingsValidationTest.php. Not touched: BUG-023 (demo lock by key) and BUG-027 (settings never read).

## BUG-008 — Emptying "Credit limit" on the customer form crashes with a NOT NULL violation
- Status: verified
- Verified: 2026-10-16. Blank credit_limit becomes 0 on create and edit; omitted field keeps the stored limit. Repro intact.
- Severity: medium
- Area: forms / customers
- Where: app/Http/Controllers/CustomerController.php:163 (rule `nullable|numeric|min:0`), resources/views/customers/form.blade.php:56
- Repro test: tests/Feature/BugHunt/Bug008CustomerBlankCreditLimitCrashesTest.php
- What happens: The field is not marked required; a blank number input is posted as "" -> null, and `customers.credit_limit` is NOT NULL DEFAULT 0. Create and edit both end in a 500 (`NOT NULL constraint failed: customers.credit_limit`). Products already guard this exact case.
- Expected: Blank means 0 (or a validation error), never a 500.
- Cause (if known): Null written to a NOT NULL column.
- Fix notes: FIXED in 5a7e021. Cause: `nullable` rule + blank input -> null written to NOT NULL credit_limit. Fix: CustomerController::validateData() turns a present-but-blank credit_limit into 0 ("no credit", the column default and what the create form pre-fills); a request that omits the field keeps the stored limit. Files: app/Http/Controllers/CustomerController.php. New test: tests/Feature/Customers/CustomerCreditLimitTest.php. Same pattern NOT fixed here (other batches): BUG-009 categories.sort_order. `loyalty_points` is also `nullable|integer` on the same validator but the form has no such input, so it is not reachable from the UI (left alone).

## BUG-009 — Emptying "Sort" on a category row or the add-category form crashes with a NOT NULL violation
- Status: verified
- Verified: 2026-10-16. Blank sort_order becomes 0 on add and on the row; omitted field keeps the value. Repro intact (both methods).
- Severity: low
- Area: forms / categories
- Where: app/Http/Controllers/CategoryController.php:25,39 (rule `nullable|integer`), resources/views/categories/index.blade.php:32,57
- Repro test: tests/Feature/BugHunt/Bug009CategoryBlankSortOrderCrashesTest.php
- What happens: Same as BUG-008 for `categories.sort_order` (NOT NULL DEFAULT 0): store and update both 500. `ExpenseCategoryController` already does `(int) ($data['sort_order'] ?? 0)`.
- Expected: Blank means 0 (or a validation error).
- Cause (if known): Null written to a NOT NULL column.
- Fix notes: FIXED in 64e12d7. Cause: `nullable|integer` rule + blank input -> null written to NOT NULL sort_order. Fix: CategoryController::store() and update() turn a present-but-blank sort_order into 0; a request that omits the field keeps the stored value (BUG-008 precedent). Files: app/Http/Controllers/CategoryController.php. New test: tests/Feature/Products/CategorySortOrderTest.php. Same pattern elsewhere: none (ExpenseCategoryController already coalesces; no other controller writes categories.sort_order).

## BUG-010 — Purchase-order "Tax %" column takes a 0..1 fraction and accepts 11 as 1100%
- Status: verified
- Verified: 2026-10-16. Input is now a percentage and a hidden field submits the 0..1 fraction; server caps at max:1. Repro asserts the stored rate stays at most 1, intact.
- Severity: medium
- Area: forms / purchases / money
- Where: resources/views/purchases/form.blade.php:94,116, app/Http/Controllers/PurchaseOrderController.php:253 (rule `nullable|numeric|min:0`)
- Repro test: tests/Feature/BugHunt/Bug010PurchaseOrderTaxRateAcceptsPercentTest.php
- What happens: The header says "Tax %" and the PO page shows the stored rate as `rate * 100 %` ("11.00%"), but the edit input is pre-filled with the raw fraction (0.11) and has no upper bound. Typing 11 (what "%" asks for) stores a 1100% tax: line total, PO total and the amount added to the supplier's balance on receipt are inflated ~12x.
- Expected: Rates stay in 0..1 (CLAUDE.md convention): either show/enter a percentage and convert, or relabel and cap with `max:1`. A stored rate above 1 must be impossible.
- Cause (if known): Unit mismatch between label and input plus a missing `max:1`.
- Fix notes: FIXED in bffff77. Cause: "Tax %" label/PO page speak percent, the input spoke the raw 0..1 fraction, and the rule had no ceiling. Decision (not a business question; it follows the CLAUDE.md 0..1 rule and the label): the server/stored contract stays the 0..1 fraction; the form's visible input is now a percentage (stored 0.11 shows as 11; `min=0 max=100`), bound to `line.tax_pct`, and a hidden `items[N][tax_rate]` submits `tax_pct/100` (4 dp). The form's total preview uses the same fraction (PO form JS only; `computeCart()`/SaleService untouched). Server: `items.*.tax_rate` is now `nullable|numeric|min:0|max:1` with message messages.po_tax_rate_range (lang/en + lang/ar), so an out-of-range value from any caller is refused and nothing is saved. A blank percentage is still sent blank, so the server's fallback to the product's own tax is unchanged. Files: app/Http/Controllers/PurchaseOrderController.php, resources/views/purchases/form.blade.php, lang/en/messages.php, lang/ar/messages.php. New test: tests/Feature/Purchases/PurchaseOrderTaxRateTest.php (fraction stored/totalled, 11 refused with the message, edit form pre-fills a percentage). NOTE for BUG-025 (batch 3, rebuilding `lines` from old('items')): the Alpine line objects now carry `tax_pct`, not `tax_rate`, so old('items.N.tax_rate') (a fraction) must be converted with `round(rate*100, 4)` when seeding. Manual click-test: on a PO line, type 11 in "Tax %" and confirm the line/total preview shows +11%, then save and confirm the PO page shows 11.00%.

## BUG-011 — Two pending returns for the same sale line can both be approved, refunding it twice
- Status: verified
- Verified: 2026-10-16. Pending units are counted at creation and the approval claims units with one guarded UPDATE inside the approving transaction. Repro intact; a part-pending line (3 pending, manager returns the other 2, then approves the 3) ends consistent (returned 5, sale refunded, stock +5, refunds sum to the line). Residual: BUG-035 (duplicate lines) and BUG-036 (double approval) are separate holes.
- Severity: critical
- Area: returns / money / stock
- Where: app/Http/Controllers/ReturnController.php:97-103 (store), :182-202 (approve), :226-253 (processApproval)
- Repro test: tests/Feature/BugHunt/Bug011PendingReturnsCanRefundALineTwiceTest.php
- What happens: A cashier's return is created `pending`. `store()` checks the quantity against `qty - returned_qty`, but `returned_qty` only moves when a manager approves, so a second pending return for the same units passes the same check. `approve()` applies each one without re-checking: `returned_qty` ends at 4 on a line that sold 2, the customer is refunded twice and the units are restocked twice (stock the shop never had). A double click on "Process" is enough to create the pair.
- Expected: The quantity check must count units already tied up in pending returns (at creation) and/or be re-checked inside the approval transaction.
- Cause (if known): Remaining-quantity check ignores pending/approved-but-unapplied returns; approval does not re-validate.
- Fix notes: FIXED in ae2e984. Cause: store() checked qty - returned_qty, which only moves on approval, and approval never re-checked. Fix: SaleItem::pendingReturnQty()/returnableQty() (app/Models/SaleItem.php) make store() count units on pending returns; ReturnController::processApproval() (shared by approve() and the manager auto-approve path, so it runs inside the approving transaction) now claims the units with one guarded UPDATE (`qty - returned_qty >= requested`, CAST because PDO binds the float as text and SQLite sorts text above numbers). If the line no longer has them it throws a ValidationException (messages.qty_exceeds), everything rolls back and the return stays pending for the manager to reject. The return form shows the net remaining and "+N awaiting approval" (returns.qty_pending, en + ar). New test: tests/Feature/Returns/ReturnApprovalGuardTest.php. Known limit: store()'s check is outside its transaction, so two truly simultaneous creates can both be filed; the approval guard stops the second from being applied.

## BUG-012 — "Find sale" on the returns screen ignores its status filter for receipt-number lookups
- Status: verified
- Verified: 2026-10-16. The OR group is now wrapped under the status filter, one NOT_RETURNABLE constant shared by find/create/store. Repro intact. A fully refunded sale is now "not found" (per the ledger Expected) and a phone lookup skips voided/held/refunded latest sales and returns the latest returnable one: coherent.
- Severity: low
- Area: forms / returns
- Where: app/Http/Controllers/ReturnController.php:44-48 (findSale)
- Repro test: tests/Feature/BugHunt/Bug012ReturnLookupFindsVoidedSaleTest.php
- What happens: `where(receipt_number = ?)->orWhereHas(customer phone)->whereNotIn(status, [voided, on_hold])` binds as `A OR (B AND C)`, so the status exclusion guards only the phone branch. A voided or held sale found by receipt number sends the cashier to the return form, which answers with a bare 403 page instead of "no sale found". (Fully refunded sales behave the same way via `create()`'s 403.)
- Expected: A voided/held (and fully refunded) sale is "not found" with the normal message, whichever way it was looked up.
- Cause (if known): Missing closure around the OR group.
- Fix notes: FIXED in c0d8cf7. Cause: the OR group was not wrapped, so the status filter only bound to the phone branch. Fix: ReturnController::findSale() wraps the receipt/phone OR in a closure under the status filter. The list is now one constant, ReturnController::NOT_RETURNABLE = voided, on_hold, refunded, also used by create() and store() (which already refused exactly those three). Behaviour change on purpose, per the ledger's Expected: a fully `refunded` sale is now "not found" in Find sale instead of a 403, and a phone lookup skips a voided/held/refunded latest sale and finds the customer's latest returnable one. Files: app/Http/Controllers/ReturnController.php. New test: tests/Feature/Returns/ReturnFindSaleTest.php.

## BUG-013 — Counting one currency by denomination locks the other currency's total field at 0 (shift open/close)
- Status: verified
- Verified: 2026-10-16. Separate hasUsdDenom()/hasLbpDenom() on both pages; repro asserts the binding string only. Still needs the Electron click-test listed under Needs manual check. See BUG-039 for the unrelated LBP step on the same inputs.
- Severity: medium
- Area: forms / shifts / money
- Where: resources/views/shifts/close.blade.php:79-90, resources/views/shifts/open.blade.php:59-72
- Repro test: tests/Feature/BugHunt/Bug013ShiftCashFieldLockedByOtherCurrencyTest.php (checks the rendered Alpine binding; see Needs manual check)
- What happens: Every total input is bound `x-bind:readonly="hasAnyDenom()"` and `x-bind:value="<own>Total() || <old>"`, where `hasAnyDenom()` is "any USD OR any LBP denomination entered". A cashier who counts the dollar notes by denomination finds the LBP total read-only and showing 0, cannot type their LBP total, and the server records `closing_cash_lbp = 0` (it only trusts denominations for a currency whose denominations were sent): a variance of minus the whole expected LBP float. Same the other way round, and on the open-shift form.
- Expected: Each total field locks only on its own currency's denominations.
- Cause (if known): One shared `hasAnyDenom()` for both fields.
- Fix notes: FIXED in 9fe1167. Cause: one shared hasAnyDenom() drove `x-bind:readonly` on both total inputs (and the USD "auto-calculated" hint). Fix: resources/views/shifts/close.blade.php and open.blade.php replace it with `hasUsdDenom()` / `hasLbpDenom()` (inside each view's `denomCounter()`); USD input + hint use the USD one, LBP input uses the LBP one. No other view uses denomCounter. ShiftController is unchanged: it already uses denominations per currency (a currency with no non-zero counts takes its typed total); new regression test in tests/Feature/Shifts/ShiftCloseTest.php pins that (dollars by denomination + typed LBP total). The repro test only checks the rendered binding string. MANUAL CLICK-TEST NEEDED (Electron, both /shifts/open and /shifts/close): (1) type a count in any USD denomination -> the USD total field turns read-only and shows the sum, the "Auto-calculated from denominations above" hint appears under it, and the LBP total field stays editable: type an LBP total (e.g. 5,000,000), then change a USD count again and confirm the typed LBP total is NOT overwritten; (2) clear the USD counts -> the USD field is editable again; (3) the mirror: count LBP notes -> LBP field locks and sums, USD field stays editable and keeps a typed value; (4) submit with USD counted + LBP typed and confirm the closed shift shows the typed LBP counted figure (not 0) and a sensible LBP variance. Not changed (out of scope): no auto-calc hint is shown for the LBP field when it locks (the old form showed none either).

## BUG-014 — Saving the product edit form overwrites a unit that is not in the dropdown with "pcs"
- Status: verified
- Verified: 2026-10-16. The stored unit is rendered as an extra selected option when outside the list. Repro intact; a product with unit carton round-trips unchanged.
- Severity: medium
- Area: forms / products
- Where: resources/views/products/form.blade.php:205-209
- Repro test: tests/Feature/BugHunt/Bug014ProductEditResetsUnknownUnitTest.php
- What happens: The Unit select offers nine fixed values, but `unit` is a free string elsewhere (bulk import accepts any value up to 20 chars; the sell screen shows an unknown unit as-is). For such a product no option is selected, the browser submits the first one ("pcs"), and an unrelated edit (a price) silently changes "carton" to "pcs".
- Expected: The current unit is kept (e.g. render it as an extra option when it is not in the list).
- Cause (if known): Select options not derived from the stored value.
- Fix notes: FIXED in 4c432e5. Cause: select options came from a fixed list only, so a stored unit outside it had no selected option and the browser submitted the first ("pcs"). Fix: resources/views/products/form.blade.php renders `old('unit', $product->unit)` as an extra selected option (raw value as its label) when it is not one of the nine. List and server rule unchanged. This is the only unit select in the app.

## BUG-015 — Saving the user edit form changes the user's role when the plan no longer offers that role
- Status: verified
- Verified: 2026-10-16. The target's own role is accepted and shown as "(not in plan)"; a changed role still has to be in the plan and within the editor's privilege (admin editing a staff user cannot reach admin tier; admin targets still 403 for non super_admin). Repro intact.
- Severity: high
- Area: forms / users / authorization
- Where: resources/views/users/form.blade.php:42-57, app/Http/Controllers/UserController.php:157-176
- Repro test: tests/Feature/BugHunt/Bug015UserEditFormChangesRoleWhenRoleNotInPlanTest.php
- What happens: The Role select lists only roles the current tier allows (Starter: manager, cashier, plus super_admin for a super_admin editor). A user whose role is outside the list (the normal state after the vendor lowers a tier on the Plan tab) has no selected option, so the browser submits the FIRST option. For a super_admin editor that is "Super Admin": pressing Save on the client's admin (a rename is enough) promotes them to super_admin, the vendor account that is outside the seat count and the backup/plan gates. For an admin editor the first option is "Manager" (a stock keeper becomes a manager).
- Expected: Saving never changes the role unless the user picked a new one. Keep the current role as an option (marked as not in plan) or refuse with an error.
- Cause (if known): Options built from tier/privilege only, not including the target's current role.
- Fix notes: FIXED in 27bfe4f. Cause: Role options built from tier/privilege only, so a role the plan no longer offers had no selected option and the browser submitted the first one. Fix: the edit form keeps the current role as an extra option labelled "(not in plan)" (users.role_not_in_plan, en + ar), and UserController::update() accepts the target's own current role even when the plan does not offer it (roleLicenceError() takes the target; the role rule is `in:assignable + current role`). A role that CHANGES still must be in the plan and within the editor's privilege; guardPrivilegedTarget() is untouched, so only super_admin edits admin-tier accounts and an admin cannot assign admin. New test: tests/Feature/Users/UserRoleKeptOnSaveTest.php (covers keep-on-save, the not-in-plan option, no assigning a disallowed role, and no admin escalation by an admin).

## BUG-016 — Once one line of a sale is fully returned, no further return can be filed for the sale from the UI
- Status: verified
- Verified: 2026-10-16. A line with nothing left renders no inputs at all (decided by the same returnableQty() as the server). Repro intact.
- Severity: high
- Area: forms / returns
- Where: resources/views/returns/create.blade.php:62, app/Http/Controllers/ReturnController.php:74
- Repro test: tests/Feature/BugHunt/Bug016ReturnFormBlockedByFullyReturnedLineTest.php
- What happens: The form disables the quantity input of a line with nothing left to return (`:disabled="true"`), so the browser does not send `items[N][qty_returned]` for it, but it still sends that line's hidden `sale_item_id` and the ticked Restock box. The rule `items.*.qty_returned => required` then rejects the whole request ("The items.0.qty_returned field is required."), so the customer's other items cannot be returned.
- Expected: The return is created for the lines that have quantity left; a fully-returned line is ignored (do not send its inputs, or default a missing qty to 0).
- Cause (if known): Disabled input + hidden id + required rule.
- Fix notes: FIXED in ed92ce1. Cause: disabled quantity input + enabled hidden id/restock + `qty_returned required`. Fix: resources/views/returns/create.blade.php renders no inputs at all for a line with nothing left (decided by the same SaleItem::returnableQty() as the server, so units on a pending return count). The restock=0 fallback of BUG-005 lives inside the same guard. Controller rules unchanged. No other view uses the `:disabled="true"` pattern. Extra test: the form omits the inputs for a pending-tied line (tests/Feature/Returns/ReturnApprovalGuardTest.php).

## BUG-017 — The "Stock adjustment" form leaves no audit-log entry
- Status: verified
- Verified: 2026-10-16. stock_adjust entry with before/after, action, qty and reason, written only after a successful adjustment. Repro (add, remove, set, count) intact.
- Severity: medium
- Area: audit / stock
- Where: app/Http/Controllers/ProductController.php:221 (adjustStock)
- Repro test: tests/Feature/BugHunt/Bug017StockAdjustmentIsNotAuditedTest.php
- What happens: CLAUDE.md lists "stock adjustments" among the actions that must call `AuditLog::record()`. `adjustStock()` writes an `inventory_adjustments` row only, so the audit trail does not show who added, removed or reset (`set`/`count` can erase a quantity) stock.
- Expected: Each adjustment writes an audit entry (action, product, before/after, reason).
- Cause (if known): Missing `AuditLog::record()`.
- Fix notes: FIXED in fa445d9. Cause: adjustStock() never called AuditLog::record(). Fix: ProductController::adjustStock() records action `stock_adjust` on Product::class/{id} with old_values `{stock_qty: before}` and new_values `{stock_qty: after, action, qty, reason}` after the InventoryService call (the quantities are the service's return value; no stock arithmetic added in the controller). A removal refused with InsufficientStockException returns before the log line, so nothing is logged. Controller-side (like PO receive / product CRUD) rather than in InventoryService::adjust(), because adjust() is also the sale/return/PO-receive/opening path and those are audited by their own documents. `adjustStock()` is the only manual stock-change entry point (grep of InventoryService callers). Files: app/Http/Controllers/ProductController.php. New test: tests/Feature/Stock/StockAdjustmentAuditTest.php (old/new/reason, `set`, refused removal).

## BUG-018 — Activate / Deactivate on the users list leaves no audit-log entry
- Status: verified
- Verified: 2026-10-16. Audit entry per toggle with old/new is_active; refusals log nothing. Repro intact.
- Severity: medium
- Area: audit / users
- Where: app/Http/Controllers/UserController.php:211 (toggleActive)
- Repro test: tests/Feature/BugHunt/Bug018UserActivateDeactivateIsNotAuditedTest.php
- What happens: Create, update, reset-password and reset-PIN are audited; the toggle (which blocks or restores a login) is not.
- Expected: An audit entry per toggle.
- Cause (if known): Missing `AuditLog::record()`.
- Fix notes: FIXED in 1e85b57. Cause: toggleActive() never called AuditLog::record(). Fix: it records action `update` on User::class/{id} with old `{is_active: before}` and new `{is_active: after}` right after the change (same action name as UserController::update; `activate` is taken by the trial unlock). The refusals (own account, last admin) return earlier, so they log nothing. Files: app/Http/Controllers/UserController.php. New test: tests/Feature/Users/UserToggleAuditTest.php. The edit-form "Active" box already goes through update(), which was audited.

## BUG-019 — Adding, editing or deleting a tax rate leaves no audit-log entry
- Status: verified
- Verified: 2026-10-16. create/update/delete audited with old and new values. Repro (3 methods) intact.
- Severity: medium
- Area: audit / settings
- Where: app/Http/Controllers/SettingController.php:342-402 (storeTax, updateTax, destroyTax)
- Repro test: tests/Feature/BugHunt/Bug019TaxRateChangesAreNotAuditedTest.php
- What happens: `update()` audits the other settings tabs; the three tax actions do not. A VAT change alters every sale's tax from that moment and nothing records who did it.
- Expected: An audit entry with old/new values for each of the three actions.
- Cause (if known): Missing `AuditLog::record()`.
- Fix notes: FIXED in ade9c94. Cause: storeTax/updateTax/destroyTax never called AuditLog::record(). Fix: `create` / `update` / `delete` on Tax::class/{id} with name, name_ar, rate, is_inclusive, is_default, is_active (const SettingController::TAX_AUDITED): create has only new values, update old + new, delete only old. destroyTax() now takes the Request (actor) and logs after the delete; the "tax in use" refusal returns before it. Files: app/Http/Controllers/SettingController.php. New test: tests/Feature/Settings/TaxAuditTest.php. Not logged: other rates losing is_default when a new default is chosen (implied by the edited row).

## BUG-020 — Creating, renaming or deleting a product category leaves no audit-log entry
- Status: verified
- Verified: 2026-10-16. create/update/delete audited. Repro (3 methods) intact.
- Severity: low
- Area: audit / categories
- Where: app/Http/Controllers/CategoryController.php:19-55
- Repro test: tests/Feature/BugHunt/Bug020CategoryChangesAreNotAuditedTest.php
- What happens: None of store/update/destroy calls `AuditLog::record()`.
- Expected: An audit entry per action.
- Cause (if known): Missing `AuditLog::record()`.
- Fix notes: FIXED in b8e0fd0. Cause: store/update/destroy never called AuditLog::record(). Fix: `create` / `update` / `delete` on Category::class/{id}, same shape as ExpenseCategoryController (create: new values; update: old + new of the submitted fields; delete: old name/name_ar/sort_order/is_active). destroy() takes the Request for the actor; the "category has products" refusal returns before the log. Files: app/Http/Controllers/CategoryController.php. New test: tests/Feature/Products/CategoryAuditTest.php. CategoryController is the only writer of `categories` (product import does not create categories).

## BUG-021 — A partially refunded sale drops out of the shift's expected cash and revenue
- Status: verified
- Verified: 2026-10-16. partial_refund sales now count in sales_count, revenue and expected cash. Repro intact (asserts 90..100 expected, 1 sale). The fix is correct for what the ledger asked, but see BUG-033: with a CASH refund the shift now over-expects by the refund (error grew from $20 to $80 in the 4-of-5 example).
- Severity: critical
- Area: shifts / money
- Where: app/Services/ShiftService.php:35-37 (totals)
- Repro test: tests/Feature/BugHunt/Bug021PartiallyRefundedSaleDropsOutOfShiftCashTest.php
- What happens: `totals()` sums only sales whose status is exactly `completed`. Returning one item flips the sale to `partial_refund` (ReturnController::processApproval), so the whole sale, whose cash is already in the drawer, stops counting. Sell $100 in cash and refund $10: the shift now expects $0 from that sale instead of $90-$100, the close screen and the stored `expected_cash`/`variance` show a ~$90 overage, and `sales_count` / `total_revenue_usd` miss the sale.
- Expected: `partial_refund` sales still count (their cash was taken). A fully `refunded` sale needs a decision together with BUG-028 (refund cash-out).
- Cause (if known): Status filter too narrow.
- Fix notes: FIXED in 3d22978. Cause: ShiftService::totals() summed only `completed` sales. Fix: `partial_refund` sales count too (app/Services/ShiftService.php), so expected cash, sales_count and total_revenue_usd keep them. Revenue is gross (the refund is not deducted), which matches "refunds are not counted here". NOT decided here: a fully `refunded` sale is still left out and refund cash-out is still not counted (BUG-028 stays needs-decision). Same status filter, deliberately NOT changed (outside this batch; whether reports are gross or net of returns is a decision): ReportService.php lines ~28, 54, 251, 262, CustomerController.php:60-61 (customer totals), resources/views/dashboard.blade.php:6 all count `completed` only, so a partially refunded sale is missing from those reports/dashboard/customer totals too.

## BUG-022 — Opening and saving the edit form of a product that has no tax assigns it the default VAT
- Status: verified
- Verified: 2026-10-16. The default tax is only preselected when the product does not exist yet. Repro intact.
- Severity: high
- Area: forms / products / money
- Where: resources/views/products/form.blade.php:225
- Repro test: tests/Feature/BugHunt/Bug022ProductEditAssignsDefaultTaxToUntaxedProductTest.php
- What happens: The tax select preselects `old('tax_id', $product->tax_id ?? $defaultTaxId)`. The fallback is right for a new product, but on edit a product whose `tax_id` is NULL ("No tax": a tax-free item, or any product imported without a tax column) is shown with the default tax selected. The next Save, even to fix a price typo, attaches 11% VAT, and `SaleService` (tax only when `product->tax` exists) starts adding 11% at the till without anyone choosing it.
- Expected: On edit, an untaxed product stays "No tax" unless the user changes it; the default applies only to the create form.
- Cause (if known): `?? $defaultTaxId` applied on edit as well as create.
- Fix notes: FIXED in ca77466. Cause: `old('tax_id', $product->tax_id ?? $defaultTaxId)`. Fix: the default tax is only preselected when `! $product->exists` (resources/views/products/form.blade.php). Test added to tests/Feature/Products/ProductCrudTest.php (default still preselected on create). Still open under BUG-004: the tax dropdown lists active taxes only, so once rates can be deactivated an edit of a product on a deactivated rate would show "No tax"; keep the current tax in the list when fixing BUG-004.

## BUG-023 — The demo build's lock on business identity and currency is bypassed by posting the keys under another group
- Status: verified
- Verified: 2026-10-16. DEMO_LOCKED_KEYS refuses the locked keys under any group; non-demo builds unaffected. Repro intact.
- Severity: low
- Area: authorization / demo gating / settings
- Where: app/Http/Controllers/SettingController.php:110 (DEMO_LOCKED_GROUPS check), :145-160 (key map)
- Repro test: tests/Feature/BugHunt/Bug023DemoLockBypassedByPostingToAnotherGroupTest.php
- What happens: A demo request is refused only when the posted `group` is `general` or `currency`; which keys are written is decided separately, by key name. `group=pos` with `settings[exchange_rate]` / `settings[business_name]` is saved in a demo build, changing the baseline the demo exists to show.
- Expected: In a demo build the locked keys (business_name*, address, phone, email, tax_number, exchange_rate, lbp_rounding_step, base/secondary currency) are refused whatever group they arrive under.
- Cause (if known): Lock keyed on the group, not on the keys.
- Fix notes: FIXED in 4e83fc8. Cause: the demo lock keyed on the posted `group` (general / currency) while the keys written are decided by name, so `group=pos` carrying `settings[exchange_rate]` / `settings[business_name]` passed. Fix: SettingController::update() now also refuses (same `demo` error, nothing written) a demo request carrying any key in the new DEMO_LOCKED_KEYS (business_name, business_name_ar, address, phone, email, tax_number, exchange_rate, lbp_rounding_step, base_currency, secondary_currency) whatever the group; the group check is kept so general/currency posts behave exactly as before. BUG-007 validation and the tax audit entries are untouched. No other route writes these keys (CurrencyService::updateRate has no caller). Non-demo builds unaffected (guard starts with Demo::enabled()). Files: app/Http/Controllers/SettingController.php. No new test beyond the repro (DemoModeTest already covers the group path).

## BUG-024 — Product form "LBP price" is hard-coded to step 1000, whatever LBP rounding step the store uses
- Status: verified
- Verified: 2026-10-16. The step follows the rounding step (or any when 0); repro intact. Follow-on found: BUG-034 (a stored LBP price that is not a multiple of the step now blocks Save) and BUG-039 (shift LBP count inputs).
- Severity: low
- Area: forms / products / currency
- Where: resources/views/products/form.blade.php:164
- Repro test: tests/Feature/BugHunt/Bug024ProductLbpPriceStepContradictsRoundingStepTest.php
- What happens: Settings > Currency offers rounding steps of 100, 500, 1000, 5000, but the LBP price input is `step="1000"`, so on a 500 or 100 step the browser refuses a valid price such as 2,500 LBP ("nearest valid values are 2000 and 3000") and the explicit/forced LBP price cannot be entered.
- Expected: The step follows the configured rounding step (or is `any`).
- Cause (if known): Hard-coded step.
- Fix notes: FIXED in 0398670. Cause: hard-coded step="1000". Fix: the price_lbp input's step is CurrencyService::getRoundingStep() (setting lbp_rounding_step), or "any" when rounding is off (step 0). Input attribute only. Files: resources/views/products/form.blade.php. New test: tests/Feature/Products/ProductLbpPriceStepTest.php (100 -> step 100, 0 -> any). Not changed on purpose: step="1000" on the shift opening/closing LBP cash-count inputs (shifts/open|close.blade.php) and the sell-screen LBP tender input (pos/sell.blade.php:436): those count physical notes, not priced amounts; flag to Mouhamad if a 500-step store should tender 500 LBP.

## BUG-025 — A purchase order that fails validation comes back with all its line items gone
- Status: verified
- Verified: 2026-10-16. Lines are rebuilt from old('items') (tax fraction converted back to a percentage, blank stays blank); qty and cost inputs are required. Repro intact. Adjacent: BUG-037 (PO edit hides a deactivated supplier).
- Severity: low
- Area: forms / purchases
- Where: resources/views/purchases/form.blade.php:10-17,146-149 (lines built only from `$items`), :108-117 (line inputs lack `required`)
- Repro test: tests/Feature/BugHunt/Bug025PurchaseOrderFormLosesLinesOnValidationErrorTest.php
- What happens: The qty/cost/tax inputs carry no `required`, so a cleared cost reaches the server, which rejects it (`items.*.cost_usd` required) and redirects back with the old input flashed. The Alpine `lines` come only from `$items` (empty on create, the stored lines on edit), never from `old('items')`, so the typed order disappears (on edit: silently reverts to the stored lines).
- Expected: The lines are rebuilt from `old('items')` after a failed submit; the line inputs are `required`.
- Cause (if known): `old()` ignored for the dynamic line table.
- Fix notes: FIXED in 0515f8c. Cause: the Alpine `lines` came only from $items (empty on create, stored on edit), never from old('items'). Fix: resources/views/purchases/form.blade.php rebuilds `lines` from old('items') whenever session()->hasOldInput() (so a user who removed every line also gets none back): product_id/id cast to int (addLine's strict duplicate check), qty and cost exactly as typed (a cleared cost stays blank), tax_rate fraction converted back with round(rate*100, 4) into tax_pct (blank stays blank so the server's product-tax fallback is unchanged), product name looked up from the product list / stored lines because the form posts no name. First-time create and edit still seed from the model. The qty and cost line inputs are now `required`. New test: tests/Feature/Purchases/PurchaseOrderFormOldInputTest.php (failed create and failed edit keep each line's product/qty/cost/tax as typed; fresh create is empty). PurchaseOrderTaxRateTest untouched and green.

## BUG-026 — After a validation error a checkbox the user had just un-ticked comes back ticked
- Status: verified
- Verified: 2026-10-16. Hidden 0 before each checkbox on the customer, supplier, user and product forms. The repro's three methods are intact; the fixer's extra test covers the user form and all five product boxes. A no-edit save of a customer, supplier, user, category row, tax row and a product with every box off and odd values changes nothing.
- Severity: medium
- Area: forms / old() repopulation
- Where: resources/views/customers/form.blade.php:64,68; resources/views/suppliers/form.blade.php:63; resources/views/users/form.blade.php:90; resources/views/products/form.blade.php:170,179,234,241,247
- Repro test: tests/Feature/BugHunt/Bug026UntickedCheckboxReticksAfterValidationErrorTest.php
- What happens: Laravel flashes only what the browser sent, and an un-ticked checkbox sends nothing. The views repopulate with `old('x', $model->x)`, so with no old value they fall back to the stored/default value: the form is redisplayed with the box ticked again beside an error about a different field. Fix the email and press Save, and the customer is saved tax-exempt after all. On the product create form (defaults: active, taxable, discountable, track stock all true) an un-ticked "Track stock" is re-ticked after a duplicate SKU/barcode error, so a service item is saved as stock-tracked and shows "out of stock" at the till. (`StoreProductRequest::prepareForValidation()` merges the booleans into the FormRequest only, not into the flashed input.)
- Expected: The redisplayed form shows the boxes as the user left them.
- Cause (if known): No hidden `value="0"` fallback input before each checkbox (which would also fix BUG-001..004's "absent means default" if the controllers default to true), or the views must detect "this was a resubmission" (`session()->hasOldInput()`).
- Fix notes: FIXED in 1e1dd2f. Cause: an un-ticked checkbox sends nothing, so after a validation error old() was empty and the view fell back to the stored/default value (box re-ticked). Fix: the batch-2 convention, a hidden `<input type="hidden" name="X" value="0">` immediately before each checkbox (a ticked box is sent after it and wins; old("0") is falsy for @checked). Controllers untouched. Files: resources/views/customers/form.blade.php (tax_exempt; is_active already had it from BUG-002), suppliers/form.blade.php (is_active), users/form.blade.php (is_active), products/form.blade.php (force_lbp_price, track_stock, is_taxable, allow_discount, is_active). Not given one: product `remove_image` (always rendered un-ticked, not repopulated from old()), filter/search checkboxes (GET), the per-row tax is_inclusive/is_default and settings tabs (they are not repopulated from old(); the stored value is the truth there), return-form Restock (BUG-005). The supplier change also made BUG-003 pass (see BUG-003). New test: tests/Feature/Forms/CheckboxRedisplayTest.php (user form; all five product boxes on edit).

---

## BUG-027 — Settings fields that are saved but never read (backup schedule, retention, idle logout, show cost, receipt characters per line, points expiry, invoice prefix)
- Status: needs-decision
- Severity: high
- Area: settings
- Where: resources/views/settings/index.blade.php (Backup tab: frequency/retention; POS tab: idle logout, show cost; Receipt tab: chars per line; Loyalty tab: points expire; Numbering tab: invoice prefix), app/Http/Controllers/SettingController.php:119-143
- Repro test: none (business question)
- What happens: These form fields save to the `settings` table, but nothing in app/ or resources/ reads them: `backup_frequency` / `retention_days` (no scheduler job and no pruning exists; `BackupService` only does a manual copy), `idle_timeout_min` (no idle-logout code), `pos_display_cost`, `paper_width_char`, `expiry_days`, `invoice_prefix`. The Backup tab is the worst: a store owner who sets "Daily" and "keep 30 days" believes backups are being taken and rotated, and no backup is ever made.
- Question for Mouhamad: implement them, or remove the fields until they work? At minimum the backup schedule should not be shown as if it were live.
- Fix notes: (filled by fixer)

## BUG-028 — Cash refunds and customer/supplier account payments never reach the shift's expected cash
- Status: needs-decision
- Severity: high
- Area: shifts / returns / customers / suppliers
- Where: app/Services/ShiftService.php:24-28 (docstring: "Refunds and customer payments are not counted here"), ReturnController::processApproval, CustomerController::recordPayment, SupplierController::recordPayment
- Repro test: none (documented design; business question)
- What happens: A return refunded in `cash_usd`/`cash_lbp`, a customer paying off their balance in cash, and a supplier paid from the till create no cash movement. The drawer holds more (customer payment) or less (refund, supplier payment) than the system expects, so every such transaction shows up as a variance at close. Also decides what BUG-021 should do for fully `refunded` sales.
- Question for Mouhamad: should these create cash movements on the cashier's open shift (and what if the user has no open shift)? Refunds probably yes; supplier payments only if paid from the drawer.
- Addendum (verification round): the partial-refund half of this is now a provable bug on its own, BUG-033 (same shift: sale and cash refund both in the open shift). Still a question here: a cash refund paid in a LATER shift than the sale (the cash leaves the later drawer; the earlier shift is already closed and reconciled) and a `cash_lbp` refund of a USD sale.
- Fix notes: (not fixed; still needs-decision). Partial progress from BUG-033 (ba5caa3): cash refunds are now netted against the shift that rang the sale (as cash out, USD and LBP kept apart; fully refunded sales carry their cash and their refund together). That settles only the SAME-shift case. Still the open question here: a refund paid in a later shift than the sale (the later drawer that physically paid is not reduced; the earlier closed shift is frozen), a refund filed by a different user than the sale's cashier while both shifts are open (which drawer paid?), a refunding user with no open shift, a refund paid from the safe, a `cash_lbp` refund of a USD sale (expected_lbp now drops by the LBP paid; the LBP float must cover it), and customer / supplier payments (untouched). Proper answer probably needs a shift_id on returns or a CashMovement written at approval time.

## BUG-029 — Receiving a purchase order adds only goods cost to what we owe the supplier (no tax, no shipping)
- Status: needs-decision
- Severity: medium
- Area: purchases / suppliers / money
- Where: app/Http/Controllers/PurchaseOrderController.php:221-230
- Repro test: none (business question)
- What happens: The PO total is `subtotal + tax + shipping`, but on receipt the supplier balance is increased by `cost * qty` only. The supplier's real invoice (with VAT and freight) is higher than the balance the app shows as owed, and `recordPayment` refuses to pay more than that balance.
- Question for Mouhamad: should the owed amount include tax and shipping (pro-rated for partial receipts)?
- Fix notes: (filled by fixer)

## BUG-030 — A customer or supplier with an outstanding balance can be deleted
- Status: needs-decision
- Severity: medium
- Area: customers / suppliers
- Where: app/Http/Controllers/CustomerController.php:79, app/Http/Controllers/SupplierController.php:71 (destroy)
- Repro test: none (business question)
- What happens: Both soft-delete with no check of `balance` (or loyalty points). The row vanishes from every list and from the payment screens, so the receivable/payable is invisible and cannot be settled; past receipts show no customer. The confirm dialog says nothing about the balance.
- Question for Mouhamad: refuse deletion while `balance != 0`, warn, or allow? (Deactivation, once BUG-002/003 are fixed, is the usual alternative.)
- Fix notes: (filled by fixer)

## BUG-031 — An invalid date range on the Expenses filter is rejected without any message
- Status: verified
- Verified: 2026-10-16. The from/to validation message is shown (Arabic too) and the entered dates are kept. Repro intact (it accepts the English text or the red box).
- Severity: low
- Area: forms / expenses
- Where: resources/views/expenses/index.blade.php:20-25 (renders only the `expense` and `demo` error keys), app/Http/Controllers/ExpenseController.php:124-130 (`to` is `after_or_equal:from`)
- Repro test: tests/Feature/BugHunt/Bug031ExpenseFilterErrorIsNotShownTest.php
- What happens: Picking an end date before the start date and pressing Apply redirects back with the error in the session; the page renders no `to`/`from` error, so Apply appears to do nothing and the previous results come back. (The stock-movements filter, which has the same rule, does show the message.)
- Expected: The validation message is shown (e.g. `@if ($errors->any())` like the other list pages).
- Cause (if known): View only handles two error keys.
- Fix notes: FIXED in bb3b3f5. Cause: expenses/index.blade.php rendered only the `expense` and `demo` error keys. Fix: the view shows the from/to validation message (existing validation.after_or_equal in en and ar, no new strings) and the From/To inputs use old('from'|'to', ...) so the entered dates stay. ExpenseController::filters() passes reports.from / reports.to as attribute names so the message reads "The To field..." / the Arabic equivalent instead of the raw `to`/`from`. Files: resources/views/expenses/index.blade.php, app/Http/Controllers/ExpenseController.php. New test: tests/Feature/Expenses/ExpenseFilterErrorTest.php (Arabic message shown, both dates kept). Not changed: the category/kind/paid-from selects still show the previous filter after a rejected range (only the dates were asked for); StockMovementController and ReportController have the same rule but already display the error.

## BUG-032 — With "Require open shift" turned off, the till opens but every sale is refused
- Status: needs-decision
- Severity: medium
- Area: forms / settings / shifts
- Where: app/Http/Controllers/SaleController.php:51-60 (409 when no open shift), app/Http/Middleware/ShiftRequired.php:15, resources/views/settings/index.blade.php:180
- Repro test: tests/Feature/BugHunt/Bug032SellWithoutShiftWhenShiftNotRequiredTest.php
- What happens: Settings > POS > "Require open shift" off makes `ShiftRequired` let the cashier reach `/pos`, but `SaleController::store()` independently demands an open shift and answers 409 "no open shift" (and `sales.shift_id` is NOT NULL). The same request succeeds (201) once a shift is open. The setting therefore does nothing except reach a screen on which nothing can be sold.
- Expected: Either a sale can be completed without a shift when the setting is off (e.g. an implicit shift, or a nullable `shift_id`), or the setting is removed. If sales without a shift are allowed, decide how cash for them is reconciled (see BUG-028).
- Cause (if known): Two independent shift checks that disagree.
- Fix notes: NEEDS-DECISION (not fixed; repro test left failing on purpose, the test is correct). Traced end to end: `ShiftRequired` (routes/web.php `shift` group wraps /pos, the products/barcode/customer/hold APIs and POST /pos/api/sales) passes when `require_shift` is off; `SaleController::store()` then looks up the user's open shift itself and 409s; `SaleService::process(int $shiftId)` does `Shift::findOrFail` and refuses a closed shift; `sales.shift_id` is NOT NULL + FK restrictOnDelete (create_sales_table). Nothing else reads a sale's shift except `ShiftService::totals()` (cash reconciliation), so the only thing a shift-less sale changes is where its cash is accounted. Every possible fix is a money-reconciliation rule, not a code bug: (a) make `sales.shift_id` nullable (new migration; on SQLite a table rebuild of the `sales` table on every client DB) and let a sale be shift-less: its cash is then in NO drawer count, never in an expected-cash/variance figure; (b) auto-open an implicit shift (opening cash 0) when none is open: it stays open forever because nothing prompts a close, so the first real close reports a huge variance and later turning "Require" back on finds a stale shift; (c) remove the setting (a client who already unticked it loses that behaviour). QUESTION FOR MOUHAMAD: when "Require open shift to sell" is off and a cashier sells with no shift open, (1) should the sale be allowed at all, and (2) if yes, should its cash simply be untracked (nullable shift_id: "no shifts = no drawer reconciliation"), or tied to an auto-created shift? My recommendation if you just want it unblocked: (a), nullable `sales.shift_id`, because a store that switches shifts off has chosen not to reconcile drawers; shift-less sales then simply never appear in ShiftService::totals(). This ties into BUG-028 (refund / customer-payment cash movements also need a shift).

## BUG-033 — A partly refunded cash sale counts in full in the shift's expected cash, so a cash refund shows up as a big shortage (follow-up to BUG-021)
- Status: verified
- Verified: 2026-10-16. Cause fixed in ShiftService::totals() (not the test): every non-voided, non-held sale of the shift contributes its tender less change, completed cash refunds of those sales are cash out in the currency paid. Both repro methods unchanged and green (cash refund leaves $20; the `account` guard still expects $100). ShiftController showClose()/close() still read the one totals() call, so the close screen and the stored expected/variance agree; closed shifts are never recomputed (only open shifts call totals()); sales_count and revenue still count completed + partial_refund only; ShiftCloseTest/ShiftLbpCountTest untouched. Residual (already BUG-028, needs-decision): a refund paid in a later shift.
- Severity: critical
- Area: shifts / returns / money
- Where: app/Services/ShiftService.php:40 (`whereIn('status', [completed, partial_refund])` with no refund deducted; docstring "Refunds ... are not counted here")
- Repro test: tests/Feature/BugHunt/Bug033PartialCashRefundIsNotDeductedFromShiftExpectedCashTest.php (covers the SAME-shift case; the file also holds a passing guard that an `account` refund must NOT reduce expected cash)
- What happens: $100 cash sale (5 x $20); a manager returns 4 units and refunds $80 in cash. The sale becomes `partial_refund`, `totals()` now counts the whole $100 (BUG-021) and ignores the $80 that left the drawer: expected_usd = 100 but the drawer holds $20, variance -$80. Before the BUG-021 fix the sale was excluded (expected 0, variance +$20), so the error for this example grew from $20 to $80; it is smaller only when the cash refund is under half of the sale. The same sale refunded 5 of 5 is excluded again (expected 0, variance $0, which happens to be right), so the figure jumps from -$80 to $0 between 4 and 5 units. Refunds to the customer account or by exchange and card/credit sales are unaffected (those do not move cash).
- Expected: expected cash = what is physically in the drawer: a cash refund handed back in this shift reduces it ($20 in the example), whether the fix nets the refund against the sale or records it as a cash-out movement. Account/exchange refunds leave it alone.
- Cause (if known): the BUG-021 fix includes `partial_refund` sales gross; the refund cash-out was left to BUG-028, which is still undecided.
- Fix notes: FIXED in ba5caa3. Cause: BUG-021 made `partial_refund` sales count gross and the refund cash-out was left to BUG-028. Fix (app/Services/ShiftService.php, totals()): the drawer = opening + tendered-less-change of EVERY sale of the shift that was not voided/held (completed, partial_refund AND refunded) - completed cash refunds paid back for those sales. Refunds are netted against the shift that rang the sale and added to cash_out_usd (method `cash_usd`, refund_amount_usd) / cash_out_lbp (method `cash_lbp`, refund_amount_lbp), i.e. USD and LBP stay separate exactly as the sale payments are; `account` and `exchange` refunds move no cash. The close screen already prints cash out ("Paid out of drawer"), so a refund shows there with no new string. This avoids the double-subtract: a fully refunded sale now carries both its cash and its refund, so a full cash refund nets to 0 (same as before when it was excluded), and it also fixes two cases the old exclusion got wrong: a full refund to the ACCOUNT (the $100 stays in the drawer, expected now includes it) and a part-account/part-cash full refund. `sales_count` and `total_revenue_usd` are unchanged (completed + partial_refund only; a fully refunded sale is still not counted). No schema change, no new arithmetic helper (stored refund amounts are read as written; the LBP amount was already produced by CurrencyService::usdToLbp at refund time). Same-shift partial, same-shift full, account guard: variance 0 on a correct drawer (tests/Feature/Shifts/ShiftRefundCashTest.php covers full cash, full account, part account + part cash, partial with a real shift close, cash_lbp, pending return moves no cash). Behaviour change to be aware of: a fully refunded sale whose refund went to the account now adds its cash to expected (before it was dropped, giving an overage). OPEN QUESTION, added to BUG-028, NOT settled: a cash refund paid in a LATER shift than the sale (sale in shift A, refund filed in shift B): with this rule it is netted against shift A (the sale's shift), which is already closed and frozen, so shift B's drawer, the one that physically paid, is still short by the refund, exactly as before this fix (not worse, not better). Also open: a refund filed by a different user than the sale's cashier while both shifts are open (which drawer paid?), the refunding user having no open shift, a refund paid from the safe, and a `cash_lbp` refund of a USD sale (it now reduces expected_lbp by the LBP paid, expected_usd is untouched, which matches the notes handed over but needs the LBP float to cover it). Mouhamad: should a cash refund be recorded as a cash-out movement on the shift that pays it (needs a shift_id on returns or a CashMovement written at approval - schema/behaviour decision), rather than netted against the sale's shift? Original note follows. Not covered by the test, and a business question (add to BUG-028): a refund paid in a LATER shift than the sale. Physically the cash leaves the later shift's drawer and the earlier (closed, already reconciled) shift's stored expected figure must not change, which points at a cash-out movement on the shift that pays (BUG-028), not at netting against the sale's shift; and what if the refunding user has no open shift, or pays the refund from the safe. A `cash_lbp` refund (refund_amount_lbp) is also not covered: the refund is paid in LBP notes while the sale may have been USD.

## BUG-034 — The product edit form cannot be saved when the stored LBP price is not a multiple of the LBP rounding step (follow-on to BUG-024)
- Status: verified
- Verified: 2026-10-16. step="any" only when the held value is not a multiple of the step (or the step is 0); on-step and empty values keep the rounding step, so Bug024 stays green. Both data sets (5000/12000, 1000/13500) intact.
- Severity: medium
- Area: forms / products / currency
- Where: resources/views/products/form.blade.php:166 (`step="{{ $lbpStep ... }}"` on `price_lbp`)
- Repro test: tests/Feature/BugHunt/Bug034ProductLbpPriceStepBlocksSavingExistingPriceTest.php (2 data sets)
- What happens: the BUG-024 fix made the LBP price input's `step` the store's rounding step. Settings > Currency offers 100/500/1000/5000, and a number input whose value is not a multiple of its step fails the browser's own validation on submit (the form has no `novalidate`). The input is on the hidden Pricing tab, so Chrome refuses the submit with "An invalid form control is not focusable" and no visible message. After a store moves to a 5,000 step, every product whose stored `price_lbp` is, say, 12,000 can no longer be saved for ANY change (price, stock limits, name). The same already happened with the old hard-coded 1000 for values that arrive by bulk import (13,500), which is the second data set. `price_lbp` is not read by the sell screen or the sale code, so refusing the stored value protects nothing.
- Expected: the edit form stays saveable: keep the step at the finest value (or `any`), and validate against the step server-side only if at all.
- Cause (if known): HTML `step` used as a business rule on a field that already holds free-form data.
- Fix notes: FIXED in bcff351. Cause: BUG-024's step = rounding step is enforced by the browser on a value that may already be off-step (step raised later, bulk import), and the input is on the hidden Pricing tab, so Save is silently blocked. Fix (resources/views/products/form.blade.php): the input keeps `step = CurrencyService::getRoundingStep()` (BUG-024 test untouched and green), except that when the value it holds (`old('price_lbp', $product->price_lbp)`) is not a multiple of that step it renders `step="any"` for that product. No server change (price_lbp is not validated against the step and is not read by the sale code). New test: tests/Feature/Products/ProductLbpPriceOffStepTest.php (on-step keeps the step, null keeps the step, off-step is any). Same pattern elsewhere: shift LBP count inputs (BUG-039, fixed separately). The "Needs manual check" Electron note for this one still applies for the product form's other required field in the hidden tab, not for this input.

## BUG-035 — Posting the same sale line twice in one return refunds it twice
- Status: verified
- Verified: 2026-10-16. Duplicate entries are summed per sale_item_id before the returnableQty() check; both data sets (5+5, 3+3) intact and green. The merge key is the RAW posted value, so "+5" still slips through: separate hole, BUG-042.
- Severity: high
- Area: returns / money / stock
- Where: app/Http/Controllers/ReturnController.php:97-125 (store: `$refundAmount += $lineRefund` per entry, `$perLine[$saleItem->id] = ...` overwritten per entry)
- Repro test: tests/Feature/BugHunt/Bug035ReturnWithSameSaleLineTwiceDoublesRefundTest.php (2 data sets: 5+5 and 3+3 on a line that sold 5)
- What happens: each posted entry is checked against the line's returnable quantity on its own, so two entries of 5 on a line of 5 both pass. The header `refund_amount_usd` sums both ($200 for a $100 line), but only the last entry becomes a return-item row (qty 5, $100). For an `account` refund the customer's balance is credited the header amount (-$200), for cash the cashier is told to hand over $200; stock and returned_qty move by 5 only. A cashier can file this as a pending return with a hand-built payload and a manager's own request is auto-approved. (The rendered form never sends a duplicate id.)
- Expected: duplicate lines are merged (quantities summed, then checked against the returnable quantity) or the request is refused; the header refund always equals the sum of its item rows and never exceeds what the line sold for.
- Cause (if known): refund summed per entry while the per-line map is keyed by sale_item_id.
- Fix notes: FIXED in 43aa9ed. Cause: each posted entry was validated against returnableQty() alone and added to the header refund, while the per-line map kept only the last entry. Fix (ReturnController::store()): the positive entries are grouped by sale_item_id and their quantities summed (the last entry's restock/condition are kept, as the old overwrite did) BEFORE the returnableQty() check, so a merged total past the line is refused with the existing messages.qty_exceeds and the header refund always equals the sum of its item rows. The pending path and the manager auto-approve path share store(), so both are covered. Chose merge over reject (no new string). New test: tests/Feature/Returns/ReturnDuplicateLineTest.php (3+2 merges into one 5-unit item refunded once and credited once; a cashier's pending 1+1 merges to 2, $40, and applies once on approval; 3+3 is refused and nothing is created). processApproval() is unchanged.

## BUG-036 — A pending return can be approved twice (double click or two managers), applying the restock and the account credit twice
- Status: verified
- Verified: 2026-10-16. claimPending() is a conditional UPDATE ... WHERE status = 'pending' (first statement inside the approving transaction; reject uses it too). Repro (stale second instance) green; ReturnTransitionGuardTest covers approve-twice, reject-then-approve and approve-then-reject, all refused with messages.return_not_pending and no stock/credit/audit change; cashier-filed pending then manager approve applies once. A processApproval() failure rolls the claim back, so the return stays pending.
- Severity: high
- Area: returns / money / stock
- Where: app/Http/Controllers/ReturnController.php:193-205 (approve: status checked outside the transaction, `update(['status' => approved])` unconditional)
- Repro test: tests/Feature/BugHunt/Bug036ReturnCanBeApprovedTwiceTest.php (simulates two overlapping requests with two model instances that both hold the stale `pending` state; a real interleaving cannot be produced in a single-process test, see Needs manual check)
- What happens: line sold 5, pending return of 2. Two approvals that both loaded the return while it was `pending` both pass the `!== pending` check; the second one's unconditional UPDATE sets the row back to `approved` and `processApproval()` runs again: returned_qty ends at 4, stock +4, and for an account refund the customer is credited twice. BUG-011's guard only stops `returned_qty` passing the quantity sold, so it does not help while the line has spare quantity. The "Approve refund" button on returns/show has no double-submit protection, and with SQLite the second request just waits for the write lock and then runs.
- Expected: the transition is conditional: inside the transaction `UPDATE ... WHERE id = ? AND status = 'pending'` and abort when 0 rows changed (the same goes for reject). The second approval is refused.
- Cause (if known): check-then-act outside the transaction.
- Fix notes: FIXED in f8b9838. Cause: approve() read `status` before the transaction and updated unconditionally; reject() had the same check-then-act. Fix (ReturnController): new private claimPending() does `UPDATE returns SET status, approved_by, approved_at WHERE id = ? AND status = 'pending'` and succeeds only if exactly one row changed. approve() calls it as the FIRST statement inside the DB::transaction that applies the approval (then processApproval + audit); on 0 rows it returns false, so nothing is restocked, credited or audited, and the user is sent back with the existing messages.return_not_pending (error key `status`, as before). reject() uses the same claim, so a reject cannot re-open an applied return and an approve cannot apply a rejected one. No new strings. New test: tests/Feature/Returns/ReturnTransitionGuardTest.php (approve twice, approve after reject, reject after approve: stock/credit/returned_qty/audit untouched). The repro simulates the overlap with stale instances; with SQLite the second request waits for the write lock and its UPDATE then matches 0 rows. The Electron double-click check under "Needs manual check" still applies.

## BUG-037 — Editing a purchase order whose supplier has since been deactivated forces the user to pick another supplier
- Status: verified
- Verified: 2026-10-16. Edit lists active suppliers plus the PO's own, labelled "(Inactive)"; create() unchanged (active only). Repro green. Adjacent crash found: BUG-043 (a DELETED supplier breaks its PO pages).
- Severity: low
- Area: forms / purchases / suppliers
- Where: app/Http/Controllers/PurchaseOrderController.php:93 (edit lists `Supplier::where('is_active', true)`), resources/views/purchases/form.blade.php:59
- Repro test: tests/Feature/BugHunt/Bug037PurchaseOrderEditHidesDeactivatedSupplierTest.php
- What happens: since BUG-003 a supplier can be deactivated. A draft/sent PO for that supplier opens with no option for its own supplier; the select is `required` with an empty first option, so no change to the PO (date, notes, a line) can be saved unless it is re-pointed at another supplier. Create correctly offers active suppliers only.
- Expected: the PO's current supplier stays selectable (and selected), marked inactive, as the expense form does for its category and the product form does for its tax (BUG-004).
- Cause (if known): select built from the active subset only.
- Fix notes: FIXED in 9b15775. Cause: PurchaseOrderController::edit() listed `Supplier::where('is_active', true)` only. Fix: edit() lists active suppliers plus the PO's own supplier (`is_active OR id = purchase.supplier_id`, same pattern as ProductController::edit() for the tax) and resources/views/purchases/form.blade.php suffixes an inactive option with "(Inactive)" (existing common.inactive, en + ar). create() is unchanged (active only); update() already accepted any existing supplier. Files: app/Http/Controllers/PurchaseOrderController.php, resources/views/purchases/form.blade.php. New test: tests/Feature/Purchases/PurchaseOrderInactiveSupplierTest.php (labelled own supplier, other inactive suppliers not offered on edit, create offers active only). The other PO supplier selects (purchases index filter) list all suppliers and are unaffected.

## BUG-038 — A return refused because the sale can no longer take returns lands on a list that shows no message
- Status: verified
- Verified: 2026-10-16. returns/index renders $errors->first() in the red box (existing messages.sale_cannot_return, en + ar); controller untouched. Repro green; a plain visit shows no box.
- Severity: low
- Area: forms / returns
- Where: app/Http/Controllers/ReturnController.php:73-75 (redirects to returns.index with the `sale` error), resources/views/returns/index.blade.php (renders no `$errors`)
- Repro test: tests/Feature/BugHunt/Bug038ReturnRefusedForNonReturnableSaleShowsNoMessageTest.php
- What happens: submitting the return form for a sale that was voided or fully refunded after the form was opened (second till, or the Process button pressed twice) redirects to the returns list with `withErrors(['sale' => ...])`; the list page prints only `session('success')`. Nothing is created and nothing is explained. (Same class as BUG-031; the other return pages do render their errors.)
- Expected: the refusal message is visible (render the error on the list, or redirect to a page that shows it).
- Cause (if known): redirect target does not render errors.
- Fix notes: FIXED in 0095cc1. Cause: returns/index.blade.php rendered only session('success'). Fix: it now renders `$errors->first()` in the same red box as returns/show and search-sale (the layout renders no flash/errors itself; every page does its own, so this follows the sibling return pages). The text is the existing messages.sale_cannot_return (en + ar), the controller is unchanged, no new strings. Files: resources/views/returns/index.blade.php. New test: tests/Feature/Returns/ReturnRefusedMessageTest.php (Arabic user sees the Arabic refusal and nothing is created; a plain visit shows no error box).

## BUG-039 — Open/close shift LBP cash-count inputs refuse any amount that is not a whole thousand, though the store can round LBP to 500 or 100
- Status: verified
- Verified: 2026-10-16. Both LBP count inputs are step="any"; server rule unchanged (numeric|min:0). Both data sets (open, close) intact and green.
- Severity: low
- Area: forms / shifts / currency
- Where: resources/views/shifts/close.blade.php:87 and shifts/open.blade.php:69 (`step="1000"` + `required`, native form submit)
- Repro test: tests/Feature/BugHunt/Bug039ShiftLbpCashCountStepContradictsRoundingStepTest.php (open and close, rounding step 500)
- What happens: the part of BUG-024 the fixer deliberately left ("those count physical notes"). With a 500 or 100 LBP rounding step `calculateChange()` hands LBP change in 500s, so the drawer legitimately holds, and `expected_lbp` shows, e.g. 1,234,500; the browser rejects that as the counted total (nearest valid values 1,234,000 / 1,235,000), so a cashier whose drawer matches exactly is forced to record a 500 LBP variance, or cannot open a shift on a float of 750,500. (The sell-screen LBP tender input is also step 1000 but is not inside a `<form>`, so it only affects the spinner.)
- Expected: the counted/opening LBP field accepts multiples of the store's rounding step (or `any`).
- Cause (if known): hard-coded step.
- Fix notes: FIXED in df1f4ed. Cause: hard-coded step="1000" on the two LBP total inputs of a natively submitted form. Fix: `step="any"` on `opening_cash_lbp` (shifts/open.blade.php) and `closing_cash_lbp` (shifts/close.blade.php). Why `any` and not the rounding step: expected_lbp can legitimately be off the rounding step (LBP drawer expenses and cash movements take any amount, a float counted under a different step, 500/100-step change), and a count records a physical fact, so a browser step there can only manufacture a false variance. The server rule is unchanged (numeric|min:0). Left alone on purpose, as BUG-024 decided: the sell-screen LBP tender input (not inside a `<form>`, spinner only) stays at step 1000. New test: tests/Feature/Shifts/ShiftLbpCountTest.php (500 step: a 750,500 float opens a shift, the same count closes it with zero variance). Not taking the "whole thousands" reading: nothing in the app rounds LBP counts to 1000 (denominations go down to 1000 only as note values).

## BUG-040 — Deactivating a tax rate does not stop it being charged at the till
- Status: needs-decision
- Severity: medium
- Area: settings / tax / money
- Where: app/Services/SaleService.php:333-341 (uses `$product->tax` with no `is_active` check), resources/views/settings/index.blade.php:139, app/Http/Controllers/ProductController.php:104
- Repro test: none (business question). Verified by a throw-away probe: a product on a 11% tax with `is_active = false` still gets $1.10 tax on $10.
- What happens: since BUG-004 the Active box of a tax row really saves. It only changes whether the rate is OFFERED in the product form; every product already on that rate keeps charging it, and the product edit form now deliberately keeps the retired rate selected ("(Inactive)"). An owner who "retires" the old VAT rate expects it to stop applying; it silently keeps applying to every old product.
- Question for Mouhamad: what should Active mean? (a) only "hide from new products" (today; rename the box/explain it), (b) refuse deactivating a rate that products still use (like delete: "tax in use"), or (c) inactive rates stop being charged (risky: products silently become untaxed).
- Fix notes: (filled by fixer)

## BUG-041 — Deactivating a category only hides its chip on the sell screen; its products stay sellable
- Status: needs-decision
- Severity: low
- Area: categories / pos
- Where: app/Http/Controllers/PosController.php:19-35 (categories filtered by `is_active`, products only by their own `is_active`)
- Repro test: none (business question)
- What happens: BUG-001 made the category Active box work. The category button disappears from the sell screen but all of its products are still listed under "All" and in search and can be sold. BUG-001's description said the point was a category "the owner meant to hide".
- Question for Mouhamad: should an inactive category hide its products from the till (and from the products API), or is "Active" just the filter chip?
- Fix notes: (filled by fixer)

## BUG-042 — A sale line posted as "5" and "+5" in one return still refunds twice (BUG-035's merge is bypassed)
- Status: verified
- Verified: 2026-10-16. Fix is on the cause: entries are grouped on `(int) sale_item_id` (the id the row is looked up by) and the header is `round(array_sum(per-line refunds), 4)` after the loop, so it cannot differ from the item rows. All 4 data sets intact and green (plus-sign manager cash / manager account / cashier pending, plain duplicate), no assertion weakened. Extra probes (deleted afterwards) all green: "5"+"+5" on a line that already has a pending return for part of it is capped by returnableQty (4 > 2 refused, nothing created; 1+1 accepted), cashier-filed pending with "+id" and a second line approved by a manager (header = item rows = 67.50, account credit -67.50, one item row per line, last entry's restock wins), JSON `true` / `5.0` / `" 5 "` spellings merge, a "+id" from another sale is refused, create form for a partly returned + pending sale hides the exhausted line, shows max=remaining and its posted shape is accepted.
- Severity: high
- Area: returns / money / stock
- Where: app/Http/Controllers/ReturnController.php:91-94 (`groupBy('sale_item_id')` on the raw posted value), :103 (`SaleItem::where('id', $i['sale_item_id'])`), :117 and :123 (`$refundAmount +=` per group, `$perLine[$saleItem->id]` overwritten)
- Repro test: tests/Feature/BugHunt/Bug042ReturnMergeBypassedByEquivalentSaleItemIdTest.php (4 data sets: "+id" as manager with cash, as manager with account, filed pending by a cashier; plus the plain duplicate as a passing guard for BUG-035)
- What happens: found by the currency-tax-guardian, reproduced here. The `integer` rule accepts "+5" (it uses filter_var), TrimStrings does not strip a plus sign, and `SaleItem::where('id', '+5')` matches row 5 on SQLite. So "5" and "+5" are two groups; each passes returnableQty() alone because nothing is persisted until the end; the header refund is added twice while the per-line map (keyed on the real id) keeps one row. Line of 5 x $20 = $100 returned in full as a manager (cash_usd): 302 with no errors, `refund_amount_usd` = 200.0000 but the one return_item row is $100, `cash_out_usd` 200 and the shift's expected_usd -100 on a $100 sale; with `account` the customer is credited $200. A cashier can file the same thing pending. Probed spellings (form post and JSON): only "+N" passes; " N" is trimmed and merged, "N.0", "0N" and "+0N" fail the integer rule, JSON 5.0 merges, so the hole is the plus sign.
- Expected: duplicates are merged on the integer id (or the request is refused), and the header refund always equals the sum of its item rows and never exceeds what the line sold for.
- Cause (if known): merge key is the raw request string, not the id the row is looked up by.
- Fix notes: Fixed in 1592569. Cause: BUG-035 grouped duplicates on the raw posted string; "+5" passes the integer rule and SQLite matches row 5, so "5" and "+5" were two groups and the header refund was summed twice while the per-line map kept one row. Files: app/Http/Controllers/ReturnController.php (groupBy on `(int) sale_item_id`, merged row carries the int id; `$refundAmount` is now `round(array_sum(refund of $perLine), 4)` after the loop, so the header cannot differ from the item rows; the lbp amount, audit amount, shift cash and the account credit in processApproval() all read that one figure). Manager auto-approve and cashier pending paths share this code. Refund math otherwise unchanged. Not changed: a pending return created BEFORE this fix with a doubled header would still credit the doubled header when approved (processApproval reads the stored header, as does ShiftService); no such rows can be created any more. Same pattern elsewhere: no other controller groups or keys request ids (PO receive loops per entry and checks the remaining qty each time). Extra test: none (the 4 data sets cover plus-sign/plain, manager cash/account, cashier pending).

## BUG-043 — Deleting a supplier that has an open purchase order makes the PO page crash and goods can never be received
- Status: verified
- Verified: 2026-10-16. Both layers are on the cause: destroy() refuses while a draft/sent/partial PO exists (no delete, no audit entry; message in en and ar, shown in the edit page's error box), and `PurchaseOrder::supplier()` is `withTrashed()` so show/index/receive keep working for already-deleted suppliers. Repro test unchanged and green; the fixer's tests/Feature/Suppliers/SupplierDeleteOpenPurchaseOrderTest.php (4 tests) is meaningful: it would fail without the refusal (draft/sent/partial, Arabic text, no audit row), guards against over-blocking (received/closed/cancelled POs still allow the delete) and covers the withTrashed layer (open order of a deleted supplier opens, lists and receives into stock, balance +$50). Extra probes (deleted afterwards) green: refusal rendered on the edit page, cancelling the PO then deleting soft-deletes with exactly one audit entry, supplier index/show/edit and PO index/show/filter work, partial receive + close on a deleted supplier works (balance still incremented on the soft-deleted row). PO edit form for a deleted supplier: the select has the "— Select —" placeholder and `required`, nothing is pre-selected, an empty supplier is refused and the stored supplier is untouched, so saving cannot silently change or clear it (the user must pick one on purpose). Not logged, see Needs manual check.
- Severity: high
- Area: purchases / suppliers
- Where: app/Http/Controllers/SupplierController.php:71 (destroy: no open-PO check), resources/views/purchases/show.blade.php:33 (`$po->supplier->name`), app/Http/Controllers/PurchaseOrderController.php:231 (`$purchase->supplier->increment(...)`), app/Models/PurchaseOrder.php:37 (plain `belongsTo`, soft-deleted suppliers are hidden)
- Repro test: tests/Feature/BugHunt/Bug043DeletedSupplierBreaksItsPurchaseOrderTest.php (passes if the delete is refused; otherwise the PO page must open and receiving must book the stock)
- What happens: the supplier is soft-deleted, so `$po->supplier` is null. Opening the PO is HTTP 500 ("Attempt to read property "name" on null" in purchases/show) and POST /receive is HTTP 500 (the whole receipt rolls back, stock stays 0, status stays `sent`). The PO list still opens; the edit form opens too but offers no supplier option (same lock-in as BUG-037). There is no way to restore a supplier from the UI, so goods that physically arrived for an order already sent can never be booked in. Related to BUG-030 (needs-decision: delete with a balance) but a separate, provable crash.
- Expected: either refuse the delete while the supplier has open (draft/sent/partial) purchase orders, or keep the supplier readable on its orders (`belongsTo(...)->withTrashed()`) so show, edit (marked inactive/deleted) and receive keep working.
- Cause (if known): no integrity check on supplier delete plus a relation that hides soft-deleted rows.
- Fix notes: Fixed in a9e18d8. Cause: SupplierController::destroy() had no open-PO check and PurchaseOrder::supplier() was a plain belongsTo, so soft-deleted suppliers were hidden and show/receive dereferenced null. Files: app/Http/Controllers/SupplierController.php (refuse while a draft/sent/partial PO exists: `back()->withErrors(['delete' => ...])`, shown on the edit page error box like the category refusal, no audit entry), app/Models/PurchaseOrder.php (`supplier()` uses `withTrashed()`, so the PO list, show and receive work for an already-deleted supplier), lang/en/messages.php + lang/ar/messages.php (`supplier_has_open_pos`). Added test tests/Feature/Suppliers/SupplierDeleteOpenPurchaseOrderTest.php (refusal for draft/sent/partial, Arabic text, received/closed/cancelled orders do not block, and the withTrashed layer: an already-deleted supplier's PO opens, lists and receives, balance goes up). The repro test alone passes via the refusal and would not exercise the withTrashed layer. Not changed: deleting a supplier with an outstanding balance (BUG-030, needs-decision). Left as is: the PO EDIT form for a PO whose supplier is already deleted still offers no supplier option (the supplier select reads active suppliers only), so such an order can be opened and received but not re-saved without picking another supplier; new deletes can no longer create this state.

## BUG-044 — Deactivating the default VAT leaves it flagged "default", so every new product is saved tax-free
- Status: verified
- Severity: medium
- Area: settings / tax / products / money
- Where: app/Http/Controllers/SettingController.php:437-461 (updateTax: `is_default` kept as submitted), app/Http/Controllers/ProductController.php:91-92 (create: `defaultTaxId` ignores `is_active`; edit() line 105 has the same lookup but the view only uses it for a new product), resources/views/products/form.blade.php:244 (tax select)
- Repro test: tests/Feature/BugHunt/Bug044DeactivatedDefaultTaxMakesNewProductsTaxFreeTest.php (3 methods; all fail today)
- What happens: found by the final laravel-code-reviewer pass; regression exposed by BUG-004, which made the tax row's Active box really save. Settings > Tax: "VAT 11%" with Default ticked, un-tick Active, Save: the row is `is_active=0, is_default=1`. Products > New: `create()` lists only active taxes but takes `defaultTaxId` from `Tax::where('is_default', true)` with no `is_active` check, so no option matches, the select shows only "No tax", and the product is saved with `tax_id = NULL`. `SaleService` charges VAT only when the product has a tax, so it sells with no VAT. Reproduced through the rendered Settings tax form and the rendered product-create form: the tax row stays default+inactive, and the new product's tax_id is NULL while a default tax still exists.
- Expected: a deactivated rate cannot remain the default: either deactivating the default rate is refused with a message (it stays active + default and the new-product form preselects it), or `is_default` is cleared when a rate is deactivated. Independently, `create()` must never preselect an inactive tax (rows already saved in that state on client databases keep working, so clearing the flag in updateTax alone is not enough).
- Cause (if known): `updateTax()` does not reconcile `is_active` with `is_default`; `create()` looks up the default without `is_active`.
- Fix notes: Fixed in 8416f13. Cause: updateTax() left is_default as submitted (so a rate could be is_active=0 AND is_default=1) and ProductController::create() took defaultTaxId from the default flag without checking is_active; the product form lists active taxes only, so the browser submitted "No tax" and the product was saved with tax_id NULL (no VAT at the till). Fix, two layers: (1) SettingController::updateTax() refuses a save that is default AND inactive (`back()->withErrors(['is_active' => messages.tax_default_inactive])`, en + ar, shown in the Settings error box like the tax_in_use refusal; nothing changes, no audit entry); the owner makes another tax the default first, or unticks Default. The hidden is_active=0 convention (BUG-004) and the BUG-019 audit entries are untouched; destroyTax unchanged. (2) ProductController create()/edit() take defaultTaxId from an ACTIVE default tax only, so legacy rows already stored as inactive+default stop producing tax-free products (the Settings row of such a legacy tax is still shown default+inactive; saving it is refused until the owner unticks Default or re-ticks Active). Files: app/Http/Controllers/SettingController.php, app/Http/Controllers/ProductController.php, lang/en/messages.php, lang/ar/messages.php. New test: tests/Feature/Settings/TaxDefaultDeactivateTest.php (refusal in en and ar leaves the row and audit log untouched; non-default rates and an unflagged default can still be deactivated). No tax arithmetic touched. Related: BUG-040 stays needs-decision. Verified (round 4): all 3 repro methods pass unchanged (assert either refusal or cleared flag, plus the legacy inactive+default state); fix addresses the cause at both layers (updateTax refuses default+inactive, create()/edit() preselect only an ACTIVE default); owner can still move the default, rename, change rate, toggle inclusive, deactivate a non-default tax and re-activate; refusal renders in en and ar; tax tab, product create (incl. saving with no active default) and edit of a product on an inactive tax all behave.

## BUG-045 — Saving Settings > Currency silently resets an LBP rounding step the select does not list (0 or any odd value becomes 100)
- Status: verified
- Severity: low
- Area: forms / settings / currency
- Where: resources/views/settings/index.blade.php:113-116 (select offers only 100, 500, 1000, 5000)
- Repro test: tests/Feature/BugHunt/Bug045CurrencyTabResetsRoundingStepNotInSelectTest.php (2 data sets: step 0, step 250)
- What happens: same class as BUG-014 (unit) and BUG-015 (role). `CurrencyService` treats a step of 0 as "no rounding" (`usdToLbp` rounds to 2 dp; BUG-024's fix relies on it) and the server rule is `lbp_rounding_step => required|numeric|min:0`, so 0 or any other number is a legal stored step (seed, env, client profile). The stored value matches no `<option>`, the browser submits the first one (100), and changing only the exchange rate overwrites the step: tested with 0 and 250, both become 100. Every LBP price and change figure then rounds differently, with no message.
- Expected: the stored value is always representable in the select (an extra selected option for an unlisted stored value, or an explicit "no rounding (0)" option), so a save that changes only the exchange rate keeps the step.
- Cause (if known): select options come from a fixed list, not from the stored value.
- Fix notes: Fixed in 3a8f0b8. Cause: the lbp_rounding_step select offered a fixed 100/500/1000/5000 list while the server rule is numeric|min:0 (0 = no rounding), so a stored 0 or 250 matched no option and a save of only the exchange rate submitted 100. Fix: resources/views/settings/index.blade.php renders a stored step not in the list as an extra selected option, labelled with the existing settings.lbp_step (no new strings; same keep-current-value pattern as BUG-014/015/037). The unset case now reads CurrencyService's default (1000) instead of an empty value. Server rule unchanged; no explicit "0 - no rounding" option was added (would need new en + ar strings, and a stored 0 already shows as "0 LBP"). New test: tests/Feature/Settings/CurrencyRoundingStepSelectTest.php (0 and 250 become the selected extra option, a listed step adds none). Verified (round 4): both data sets (0, 250) pass; the view now renders an unlisted stored step as an extra selected option and falls back to CurrencyService's default (1000) when unset; saving only the exchange rate keeps the step for stored 0, 100, 250, 1000, 5000 and unset.

## BUG-046 — The demo lock by key leaves out `language`, so the store language can still be changed in a demo build
- Status: verified
- Severity: low
- Area: authorization / demo gating / settings
- Where: app/Http/Controllers/SettingController.php:45-48 (DEMO_LOCKED_KEYS, hand-copied) and :128-131 (the guard)
- Repro test: tests/Feature/BugHunt/Bug046DemoLockMissesStoreLanguageTest.php (2 methods: `language` under group=pos, and every `settings[...]` key of the rendered General + Currency tabs under group=pos; today `language` is the only one that gets through)
- What happens: found by the final reviewer pass. `language` is a field of the locked General tab (`settings[language]`) but is not in DEMO_LOCKED_KEYS, which BUG-023's fix copied by hand from the type map. With `pos.demo_mode` on, POST `group=pos, settings[language]=ar` is accepted and changes the store language of a demo that is meant to start identical on every launch (before BUG-023 it could only arrive through the locked General tab).
- Expected: every key that belongs to the demo-locked General / Currency tabs is refused in demo mode whatever group it is posted under; ideally derived from the settings type map instead of a second hand-written list.
- Cause (if known): a copied key list that drifted from the General tab.
- Fix notes: Fixed in b57b9a8. Cause: BUG-023's DEMO_LOCKED_KEYS was a hand-copied list that left out `language`, a General-tab field. Fix: SettingController's inline type map is now a class constant SETTING_TYPES grouped by form (general, currency, pos, receipt, numbering, loyalty, backup, appearance); the locked key list is derived from the groups named in DEMO_LOCKED_GROUPS (general, currency), so it cannot drift, and update() builds its flat $typeMap from the same constant (written keys unchanged). `language` moved from the appearance group into the general group of the map (the General tab is the only form that posts it via settings.update; setup writes it through SetupController and the profile/user language is a users column, none of them go through this guard; rtl and dark_mode stay under appearance, no form posts them). Refusal message and non-demo behaviour unchanged; BUG-023's tests green. Files: app/Http/Controllers/SettingController.php. New test: tests/Feature/Demo/DemoLockedKeysTest.php (language refused in a demo under general/appearance/pos while an unlocked key still saves; still saved from the General tab outside a demo). Verified (round 4): both repro methods pass; SETTING_TYPES flat map is identical to the old inline type map (same keys, same casts); every rendered settings[...] key of every tab is in the map; all tabs re-posted as rendered (with and without the default seeder, demo on/off) store identical values and types; only general/currency are refused in demo; rtl/dark_mode/language still save outside demo; unknown keys still never written; role matrix on the settings routes unchanged.

---

## Needs manual check
- Categories (`categories/index`) and tax (`settings` Tax tab) rows put a `<form>` directly under `<tr>` with the `<td>`s inside it. This is invalid HTML; it works because browsers associate controls created while the parser's "form element pointer" is set (the Save button is before `</form>`). Confirm in the Electron build that Save works on a category row and on a tax row, and that Enter inside a row field saves that row. The expense-category rows (`settings/_expenses`) use the safe `form="id"` pattern; the other two should be moved to it.
- BUG-013 (fixed in 9fe1167, still needs a click-test): confirm in the app that, after typing a USD denomination count on the open/close-shift screens, the USD total locks and sums but the LBP total field stays editable (and vice versa), and a typed LBP total survives further USD counting. The repro test asserts the Alpine binding string, not the running behaviour. Full steps are in the BUG-013 Fix notes.
- BUG-010 (fixed in bffff77): on a PO line type 11 in "Tax %" and confirm the preview adds 11% and the saved PO page shows 11.00%. The Alpine arithmetic on the form is not covered by a JS test.
- BUG-001/002/004 (fixed): on the category row, customer form and tax row, un-tick Active and Save; confirm it stays inactive after the reload (the category/tax rows are the `<form>` directly under `<tr>` forms mentioned above, so also confirm the hidden `is_active` field is submitted there).
- BUG-026 (fixed in 1e1dd2f): in the app, un-tick "Track stock" on a new product, enter an existing SKU, Save; confirm the form returns with the box still un-ticked and the SKU error, and that after fixing the SKU the product saves with stock tracking off. Same on the customer form (Tax exempt / Active), supplier form and user form (Active).
- Product form (`products/form.blade.php`): `price_usd` is `required` but lives in the hidden Pricing tab. If it is empty while another tab is shown, Chrome blocks the submit with no visible message ("invalid form control is not focusable"). Confirm in Electron.
- Settings > Receipt "Print test" button posts the form with `formtarget="_blank"`; confirm Electron opens the test receipt in a window/print dialog and not a blank tab.
- BUG-036 (fixed in f8b9838): the repro simulates two overlapping approvals with two stale model instances. On the desktop build, double-click "Approve refund" on a pending return with spare quantity (line sold 5, return of 2) and confirm the stock goes up by 2, not 4 (NativePHP may serve requests one at a time, which would hide the race).
- Shift open/close LBP total (BUG-039, fixed in df1f4ed) and product LBP price (BUG-034, fixed in bcff351): confirm in Chrome/Electron that typing 1,234,500 on the close-shift form, and saving a product whose LBP price is not a multiple of the step, are no longer blocked by the browser tooltip (the inputs now render step="any").
- BUG-033 (fixed in ba5caa3): in the app, sell $100 in cash, return 4 of 5 units refunded in cash, and open Close shift: "Paid out of drawer" shows the $80 and Expected cash is opening + $20. Also try a full cash refund (expected back to the opening float) and a full refund to the customer's account (the sale's cash stays in expected).
- BUG-043 leftover (low, not logged): `exists:suppliers,id` in PurchaseOrderController::validateData() also accepts a soft-deleted supplier. A PO form left open in a stale tab while another user deletes the supplier (no open order yet, so the delete is allowed) saves an open PO against a deleted supplier; confirmed with a hand-built POST (302, PO created). It stays fully usable (show, receive and close work through `withTrashed()`), and its edit form offers no supplier, so the user must pick another one on purpose (placeholder + `required`, nothing is changed silently). Same state exists for orders made before the BUG-043 fix. Only matters if the owner wants `Rule::exists('suppliers','id')->whereNull('deleted_at')`; receiving also books the balance onto a supplier no screen lists (BUG-030 territory).
- Not swept this round (forms only): POS sell screen internals (payment, discounts, holds), reports, backups restore, product import parsing, stock/PO receiving maths, role matrix on every route. Audit gaps noticed but not yet tested: shift open/close, profile password/PIN change, backup create/restore/delete.

## Observed, not logged (money review, round 2)
Pre-existing behaviours seen while reviewing returns and shifts. Not counted as bugs; listed so the owner can decide whether any deserves a ticket.
- A return does not touch loyalty: points earned on the original sale are not taken back and points redeemed on it are not given back (ReturnController has no loyalty code).
- Return refund methods are `cash_usd`, `cash_lbp`, `account`, `exchange` only: there is no card/bank refund method, so a card sale can only be refunded in cash, to the account or by exchange.
- Return quantities carry a 0.0001 tolerance (`$qty > $remaining + 0.0001` in store(), `qty - returned_qty >= qty - 0.0001` in processApproval()): negligible for whole units, only matters for fractional quantities.
- A `cash_lbp` refund converts the USD amount at the exchange rate in force when the refund is made, not the rate stored on the sale (`CurrencyService::usdToLbp` in store()).
- Shift open and close write no audit-log entry (ShiftController has no `AuditLog::record()`); the close only stores `closed_by` and the variance on the shift row.
- A cash refund is netted against the shift that rang the sale (BUG-033 fix); a refund paid in a later shift, by another user, or from the safe is the open BUG-028 question.
- When a line is posted twice in one return the merged entry keeps the LAST entry's restock/condition (BUG-035 fix); only reachable with a hand-built payload.

## Observed, not logged (final review)
From the final laravel-code-reviewer pass over branch `bugfix/sweep-2026-10-16`. Style or latent points, not bugs (no failing test can be written today); listed so the owner can decide whether any deserves a ticket.
- returns/create and store each compute `pendingReturnQty()` and then `returnableQty()` again for every line (two queries per line).
- `ReturnController::processApproval()` lazy-loads `$ri->product` per line.
- `ShiftService::totals()` uses a large `whereIn('sale_id', ids)` where a subquery would do.
- Latent: if a void UI is ever added, `approve()` / `processApproval()` must re-check `NOT_RETURNABLE`. Approving a pending return on a voided sale would flip it back to `partial_refund`, and `ShiftService` would count its cash again.
- `max_cashier_discount_pct`, `points_per_dollar` and `idle_timeout_min` are `nullable`, so a blank field is stored as '' and read as 0 (a blank cashier discount cap means 0%, not the config default).
- The `settings_update` audit entry records only the group, not which keys changed.
- Close-shift "Paid out of drawer" now includes cash refunds (BUG-033) while the "Drawer expenses" list shows only expenses, so the two cannot be reconciled on screen.
- `products/form.blade.php` calls `CurrencyService` inline for the LBP price step (belongs in the controller).
- Purchase-order `exists:suppliers,id` accepts soft-deleted or inactive suppliers (see also the BUG-043 leftover under Needs manual check).
- Reports, dashboard and customer totals count only `completed` sales (see BUG-021's note: `partial_refund` sales are left out of them).

## Round log
- 2026-10-16 | forms (every Blade form vs its route/controller) | 28 new bugs (BUG-001..026, 031, 032) + 4 needs-decision (BUG-027..030). Critical (3): 005, 011, 021. High (5): 006, 007, 015, 016, 022. Medium (12): 001, 002, 004, 008, 010, 013, 014, 017, 018, 019, 026, 032. Low (8): 003, 009, 012, 020, 023, 024, 025, 031. Baseline 581 passed; with the repro tests: 581 passed, 45 failed (every failure is a new repro test, 28 files).
- 2026-10-16 | forms, verification of the 27 fixed bugs + money-check follow-ups | 27 verified, 0 reopened. 7 new bugs (BUG-033..039) + 2 needs-decision (BUG-040, 041). Critical (1): 033. High (2): 035, 036. Medium (1): 034. Low (3): 037, 038, 039. Needs-decision (2): 040 (medium), 041 (low). Suite before the new repros: 667 passed, 1 failed (Bug032, needs-decision).
- 2026-10-16 | forms (returns / shifts / products / purchases), verification round 2 | 7 verified (033, 034, 035, 036, 037, 038, 039), 0 reopened. 2 new bugs: BUG-042 (high, "+5" bypasses the BUG-035 merge) and BUG-043 (high, deleted supplier crashes its PO show/receive). Suite before the new repros: 698 passed, 1 failed (Bug032, needs-decision); with them: 699 passed, 5 failed (Bug032 + 4 failing repro cases in 2 new files; the 699th pass is the BUG-042 plain-duplicate guard).
- 2026-10-16 | forms (returns / supplier+PO, verification round 3) | 2 verified (042, 043), 0 reopened, 0 new bugs. Suite: 707 passed, 1 failed (Bug032, needs-decision). One low leftover noted under Needs manual check (PO validation accepts a soft-deleted supplier id).
- 2026-10-16 | final laravel-code-reviewer findings (round 4, HUNT/ADD only, no new sweep) | 3 new bugs: BUG-044 (medium, deactivated default VAT makes new products tax-free), BUG-045 (low, LBP rounding select cannot show step 0 / odd steps), BUG-046 (low, demo lock misses `language`). Suite before the new repros: 707 passed, 1 failed (Bug032, needs-decision).
- 2026-10-17 | verification round 4 (settings tax/currency tabs, product create/edit, demo gating) | 3 verified (044, 045, 046), 0 reopened, 0 new bugs. Suite: 720 passed, 1 failed (Bug032, needs-decision). Regression sweep: every settings tab re-posted as rendered, demo on and off, seeded and unseeded; tax default move/rename/rate/inclusive/deactivate/reactivate; no-active-default product create/edit; step select with 0/100/250/1000/5000/unset; role matrix on the settings routes.

## Observed, not logged (round 4 money review)
- Sell screen shows "NaN LBP" when the LBP rounding step is 0: `resources/views/pos/sell.blade.php` (~line 1393) does `Math.round(n / this.lbpStep) * this.lbpStep`. Pre-existing (`PosController` already passed 0), but BUG-045 now keeps a stored 0 across Currency-tab saves, so it is worth a guard (`lbpStep > 0 ? round : n`). Outside the forms area, so not swept.
- Deleting the default tax (unused) or untick Default + Active together leaves no default tax, so new products are saved tax-free. Explicit owner action; the product form shows "No tax" openly.
- `SettingController::updateTax` / `storeTax` clear other defaults and update the tax without a `DB::transaction`.
- A `lbp_rounding_step` row with NULL value shows 1000 in the select but `CurrencyService` reads 0.

## Parked: BUG-032 repro test
`pending/Bug032SellWithoutShiftWhenShiftNotRequiredTest.php` in this folder is a copy of the repro. It is NOT in `tests/` on this branch on purpose: it fails until the owner decides the rule (see BUG-032), and the branch's suite is otherwise green. Once decided, move it to `tests/Feature/BugHunt/` and make it pass. (An untracked original may still sit in the working tree at `tests/Feature/BugHunt/`.)
