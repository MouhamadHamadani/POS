# POS — Add customer search / quick-add to the sell screen

## Context

`CLAUDE.md` at the repo root has the active-development conventions
(CurrencyService, AuditLog::record(), Setting::get()/set(), role middleware,
bilingual fields) — read it first.

**What already exists (don't rebuild these):**

- `App\Http\Controllers\CustomerController::search()` — GET endpoint at
  `pos/api/customers/search?q=…`, returns up to 10 active customers matching
  name/phone (`id, name, phone, customer_group, balance, loyalty_points,
  credit_limit, tax_exempt`). Its doc comment literally says "AJAX endpoint
  used by the POS typeahead."
- `App\Http\Controllers\CustomerController::quickAdd()` — POST endpoint at
  `pos/api/customers/quick-add`, takes `name` (required), `phone` (optional,
  validated Lebanese format), `customer_group` (optional, defaults `retail`),
  returns the created customer as JSON (201). Doc comment says "used by the
  '+ Customer' modal in POS."
- `App\Services\SaleService::process()` already accepts a `customerId` and
  fully handles it: customer-group pricing lookup, tax exemption, credit-sale
  validation against `credit_limit`, loyalty point awarding.
- `SaleController::store()` already validates and passes through
  `customer_id` from the request.
- `HeldSaleController::store()` already accepts and stores `customer_id`.

**What's actually missing:** despite all of the above being ready, the sell
screen itself (`resources/views/pos/sell.blade.php`, the `posApp()` Alpine
component) has **no UI at all** for picking a customer — no search box, no
"+ Customer" control, no selected-customer display. Two consequences:

1. There is no way for a cashier to attach a customer to a sale from the POS
   screen today.
2. `submit()` (the function that POSTs to `/pos/api/sales` to complete a
   sale) doesn't include `customer_id` in its request body **at all** — so
   even if a customer were selected some other way, a direct sale would
   never actually record it. (By contrast, `submitHold()` already sends
   `customer_id: this.customer?.id || null` — that half of the wiring was
   built, the UI that sets `this.customer` was not.)

Also check `HeldSaleController::recall()`: it currently returns only
`customer_id` (the bare id) in its payload, not the customer's name/phone/
group/balance. If the sell screen is going to show a "customer" chip after
recalling a held sale, it needs the full object, not just an id it would
have to re-fetch.

## Do

1. **`HeldSaleController::recall()`** — eager-load the `customer` relation
   and include the same shape `CustomerController::search()` returns
   (`id, name, phone, customer_group, balance, loyalty_points, tax_exempt`)
   in the response payload, alongside the existing `customer_id`, so the
   frontend can restore the customer chip without a second request.

2. **`resources/views/pos/sell.blade.php`** — add a customer picker:
   - A small control in the cart panel (e.g. just below the "Cart (n)"
     header, above the line-items list): when no customer is attached, a
     "+ Add customer" button; when one is attached, a chip showing name,
     phone, group, and outstanding balance if any, with "Change" and "×"
     (remove) actions.
   - A modal (match the visual style of the existing Hold modal) with: a
     debounced search input hitting `pos/api/customers/search?q=`, a result
     list (click to select), and a "+ New customer" toggle that reveals a
     small form (name, phone, group) posting to
     `pos/api/customers/quick-add`. Selecting a result or successfully
     quick-adding sets the component's `customer` state and closes the
     modal.
   - Add `customer_id: this.customer?.id || null` to the JSON body
     `submit()` sends to `/pos/api/sales` — this is the actual functional
     fix, not just UI.
   - In `recallHold()`, set the component's `customer` state from the
     enriched `data.customer` the backend now returns (per item 1), so
     recalling a hold that had a customer attached shows it again.
   - Reset `customer` to `null` after a completed sale (there's already a
     `this.customer = null;` in `submit()`'s success path and in
     `submitHold()` — these were pre-written for this feature, just never
     had anything setting `customer` in the first place. Leave them as-is,
     just make sure your new code actually populates and clears the same
     property).
   - Optional but cheap, matching the existing F4/F5/F6 pattern: bind `F3`
     to open the customer modal.

3. Keep this scoped to the sell screen wiring — don't touch the `/customers`
   CRUD pages (`CustomerController@index/create/store/...`), they're
   unrelated and already work.

## Note, not required tonight

Cart lines capture `unit_price` at add-to-cart time using the retail price
(`p.price_usd`), and `submit()` sends that exact price through. Attaching a
wholesale/VIP customer *after* items are already in the cart will not
retroactively re-price those lines — `SaleService::totalsFromCart()` only
falls back to `priceForGroup()` when a line has no explicit `unit_price`,
and the frontend always sends one. If dynamic re-pricing on customer
selection is wanted, that's a separate, larger change (re-price the whole
cart when `customer` changes) — flag it back rather than doing it
speculatively.

## Acceptance criteria

- From the sell screen, a cashier can search an existing customer by name or
  phone and attach them to the current sale, or create a new customer
  inline via "+ New customer" and have them attached immediately.
- Completing a sale with a customer attached results in a `Sale` row with
  the correct `customer_id` — verify directly in the database or via
  `SaleController::show`, not just that the UI looked right.
- A credit sale (`payment.amount_credit > 0`) still enforces "customer
  required" and credit-limit checks exactly as `SaleService` already does —
  don't bypass that by, say, defaulting to a fake customer.
- Holding a sale with a customer attached, then recalling it, shows the
  same customer chip again (not blank).
- Existing sell-screen tests (`tests/Feature/Pos/*`) still pass; add a
  feature test asserting a sale's `customer_id` is actually persisted when
  one is selected in the request.
