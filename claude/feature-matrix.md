# Plans & Features — Phase 0 discovery and proposed feature matrix

Branch: `feat/plans-and-features` (off `main`, `9f68579`). Nothing is built yet; this file is
what needs approving before Phase 1.

## 0. The important finding: a tier system already exists

`main` already ships Starter / Standard / Premium. The feature layer is therefore an
**evolution of what is there**, not a greenfield build. What exists today:

| Piece | Where | State |
|---|---|---|
| Plan storage | `Setting` key `tier` (`starter` / `standard` / `premium`) | Live on installs. Unset reads as `premium` (`pos.default_tier`) so pre-tier installs never lose anything. A freshly provisioned install is written `starter` by `SuperAdmin::ensure()`. |
| Plan presets | `config/pos.php` → `tiers.*.features` | 8 keys, plus per-plan `max_users`, `max_devices`, `roles`. |
| Resolver | `App\Support\License` (`tier()`, `allows()`, `allowedRoles()`, `maxUsers()`, `seatsUsed()`, `setTier()`) | `setTier()` already audits as `tier_change`. |
| Route gate | `FeatureGate`, alias `feature:` | HTML: redirect back + flash. JSON: 403. Applied to `loyalty`, `suppliers`, `returns`, 3 report routes, 1 settings route. |
| UI hiding | `License::allows()` called from sidebar, reports index, sell screen (Add customer), settings tabs, sale detail (Return button) and two services | Hand-written `match` in the sidebar. |
| Admin UI | Settings → **Plan** tab (super_admin only) | Tier dropdown, seat / device info, trial activation, manual lock. |
| Tests | `TierEnforcementTest` (≈20), `PlanTierActivationTest` (≈20), `LicenseBackfillTest`, others | All green today; they assert `premium` as the grace default and `starter` for a fresh install. |

**Recommendation: extend this, don't build a second parallel system.** Concretely:
`App\Support\License` stays as a thin delegate to the new `FeatureService` (so the ~25 existing
call sites and tests keep working), the `tier` setting stays the plan storage, the `feature:`
alias and `FeatureGate` are reused, and the existing Plan tab grows a Features section.

## 1. Module inventory (from the real code)

Roles come from `routes/web.php` middleware. `super_admin` passes every `role:` gate.
"Gate today" is what already protects the route; "—" means it ships on every plan.

| # | Module / feature | Routes (name) | Roles | Gate today | Depends on |
|---|---|---|---|---|---|
| 1 | Login, logout, password reset, PIN, profile | `auth.php`, `/profile*` | all | — | — |
| 2 | Locale switch, About, Locked screen | `/locale/{l}`, `/about`, `/locked` | all | — | — |
| 3 | Dashboard (today's revenue, items sold, active products, low-stock alerts, recent sales, low-stock list) | `/dashboard` | manager, admin (sidebar) | — | sales, products |
| 4 | Shifts: open / close, denomination count, drawer expenses on close | `/shifts/open`, `/shifts/close` | all | — | — |
| 5 | **POS sell screen**: products, barcode, preview-change, create sale, hold / recall, price lists (retail / wholesale / VIP), line + cart discounts, USD / LBP / card / credit tender | `/pos`, `/pos/api/*` | cashier, manager, admin, stock; needs an open shift | — | shifts, products |
| 5a | – Customer picker + quick add (F3) on the till | `/pos/api/customers/*` | same | `feature:loyalty` | customers |
| 6 | Receipts: thermal print, reprint with COPY stamp, A4 invoice to a chosen printer | `/pos/receipts/{sale}/print`, `…/print-a4` | all | — | sales |
| 7 | Sales History (list, detail, reprint) | `/sales`, `/sales/{sale}` | admin, manager, cashier | — (deliberately ungated) | sales |
| 8 | Products CRUD, stock adjust, barcode check, variants / bundles | `/products*` | admin, manager, stock | — | categories, tax |
| 9 | Categories | `/categories*` | admin, manager, stock | — | — |
| 10 | **Stock Movements** (read side of `inventory_adjustments`) + export | `/products/movements`, `…/export` | admin, manager, stock | — | products |
| 11 | Bulk product upload | `/products/import*` | per-role switch (Permissions tab) | `can:bulk-upload-products` | products |
| 12 | Deleted-products trash | `/products/trashed` | super_admin | role | products |
| 13 | **Customers & loyalty**: CRM, credit balance, payment on account, points, tiers | `/customers*`, till customer API | admin, manager | `feature:loyalty` | — |
| 14 | **Suppliers & Purchase Orders**: suppliers, supplier payments, PO create / receive / transition | `/suppliers*`, `/purchase-orders*` | admin, manager, stock | `feature:suppliers` | products |
| 15 | **Returns**: search sale, create, approve / reject | `/returns*` | cashier, manager, admin (approve: manager, admin) | `feature:returns` | sales history |
| 16 | **Expenses**: fixed + running, optional cash-out of the open drawer, duplicate-fixed, export | `/expenses*` | manager, admin | — | shifts |
| 17 | Reports index | `/reports` | manager, admin | — | — |
| 17a | – Daily sales | `/reports/sales/daily` | manager, admin | — (key `reports.daily_sales` exists in config but **no route uses it**) | sales |
| 17b | – Sales by product | `/reports/sales/by-product` | manager, admin | `feature:reports.sales_by_product` | sales |
| 17c | – Stock levels + Inventory by date | `/reports/inventory/*` | manager, admin | `feature:reports.stock_levels` | products |
| 17d | – Expenses report | `/reports/financial/expenses` | manager, admin | — | **expenses** |
| 17e | – Profit & Loss (nets expenses off gross profit) | `/reports/financial/pnl` | manager, admin | `feature:reports.profit_loss` | **expenses**, sales |
| 18 | Users | `/users*` | admin | role; seat + role caps from the plan | — |
| 19 | Settings tabs: General, Currency, Tax, POS, Receipt, Numbering | `/settings`, `/settings/tax*` | admin | role; tax + currency are demo-locked | — |
| 19a | – Loyalty tab | `/settings` (`group=loyalty`) | admin | tab hidden + `update()` refuses | customers |
| 19b | – Expenses tab (category list) | `/settings/expense-categories*` | admin | — | expenses |
| 19c | – Receipt Template tab | `/settings/receipt-template` | admin | `feature:receipts.custom_templates` | receipts |
| 19d | – Permissions tab | `/settings/permissions` | super_admin | role + `demo:blocked` | — |
| 19e | – Backup tab | `/settings/backup/*` | super_admin | role + `demo:blocked` | — |
| 19f | – Plan tab | `/settings/plan`, `/settings/activation*` | super_admin | role + `demo:blocked` | — |
| 20 | Demo reset | `/demo/reset` | any | `demo:only` | demo build |

Dashboard widgets: none is feature-keyed today and none needs to be. Every widget reads
core data (sales, products), so the dashboard stays core.

## 2. Proposed feature keys

`core` keys are always on, can't be toggled, and exist only so labels, grouping and `requires`
have something to point at. Names of the existing 8 keys are **kept** so no route, view or test
needs renaming.

### Core (not toggleable)

`auth`, `pos` (selling, holds, price lists, discounts, tenders), `shifts`, `receipts`
(thermal + reprint + A4 invoice), `sales_history`, `products`, `categories`, `users`,
`dashboard`, `reports` (the index), `reports.daily_sales`.

`reports.daily_sales` is core because it is in every preset today and gates nothing.

### Toggleable

| Key | Group | Label (EN) | Requires | Gates (middleware + UI) |
|---|---|---|---|---|
| `reports.sales_by_product` | Reports | Sales by product report | `reports` | existing route; reports-index card |
| `reports.stock_levels` | Reports | Stock levels & inventory-by-date reports | `reports` | existing 2 routes; card ×2 |
| `reports.profit_loss` | Reports | Profit & Loss report | `reports`, **`expenses`** | existing route; card |
| `returns` | Sales | Returns & refunds | `sales_history` | existing group; sidebar; Return button on sale detail; stock-movement link |
| `sales_channels` | Sales | On-site and online sales | — | no route to gate: the on-site / online toggle on the till, the Sales History filter and badge, the receipt line, the daily-sales channel section. Off forces every sale on-site |
| `loyalty` | Customers | Customers & loyalty | — | existing routes; sidebar; Add-customer button on the till; Loyalty settings tab |
| `suppliers` | Purchasing | Suppliers & purchase orders | `products` | existing group; 2 sidebar items; stock-movement PO link |
| `receipts.custom_templates` | Receipts | Custom receipt templates | `receipts` | existing route; Receipt Template tab |
| **`expenses`** *(new key)* | Finance | Expenses | `shifts` | `/expenses*` group; sidebar; Settings → Expenses tab; Expenses report card |
| **`stock_movements`** *(new key)* | Inventory | Stock movements history | `products` | `/products/movements*`; sidebar item |

Only **one real dependency edge exists in the code today: `reports.profit_loss` → `expenses`**
(the P&L subtracts expenses; with expenses off it would show a silently wrong profit). The rest
are core edges. The engine is still built generally, and Phase 4 tests two-way dependency
rules against both the real edge and a synthetic config.

### Deliberately NOT keyed (and why)

- **Bulk upload**: already switched per role by the super_admin in the Permissions tab. A
  second plan-level switch would be two controls for one thing.
- **Hold / recall, price lists, discounts, card / credit tender**: in-module buttons. You said
  gate at module / page level only.
- **Split payments and Wish** (cash USD / LBP + card + Wish + on account in one sale, on-site or
  online): **core on every plan**, not a feature key. A plan cannot switch off a way of being paid;
  the on-account part still follows `loyalty` (it needs a customer), and nothing else does.
- **Trashed products, Backup, Permissions, Plan**: super_admin-only already; the client never sees them.
- **A4 invoice print**: optional. I can add `receipts.a4_invoice` (requires `receipts`) if you
  want to sell it separately. Off the list unless you say yes (decision 6).

## 3. Presets

The rule that keeps "no existing install loses a feature": **every preset keeps exactly what its
tier includes today; both new keys are on in all three.** Restricting Starter further (e.g.
`expenses` is Standard+) is a pricing call, not a migration, so it is left for you.

| Key | Starter | Standard | Premium |
|---|:--:|:--:|:--:|
| core keys | ✔ | ✔ | ✔ |
| `expenses` *(new)* | ✔ | ✔ | ✔ |
| `stock_movements` *(new)* | ✔ | ✔ | ✔ |
| `reports.sales_by_product` | – | ✔ | ✔ |
| `reports.stock_levels` | – | ✔ | ✔ |
| `reports.profit_loss` | – | ✔ | ✔ |
| `returns` | – | ✔ | ✔ |
| `suppliers` | – | ✔ | ✔ |
| `loyalty` | – | ✔ | ✔ |
| `sales_channels` | – | ✔ | ✔ |
| `receipts.custom_templates` | – | – | ✔ |

Per-plan **limits** are not features and stay as plan attributes (moved next to the presets in
`config/features.php`): Starter 1 user / 1 device / roles manager+cashier; Standard 3 / 3 /
+stock+admin; Premium unlimited. Overrides apply to features only, not to seat or role caps.

Example you gave, in real keys: Starter + override `reports.stock_levels = true` → that report
(both routes + card) appears, nothing else changes.

## 4. How the pieces fit (short)

- `config/features.php`: `features` (label EN/AR, group, `requires`, `core`), `plans`
  (`id => ['features' => [...], 'limits' => [...]]`). `config/pos.php` `tiers` and `default_tier`
  move there; `License` reads the new config.
- Storage: `tier` stays (plan). New `feature_overrides` (JSON via `Setting::set(..., 'json')`).
  No table, no change to `SettingController::update()`'s whitelist, so the client admin still can't write either key (test added).
- `Feature::enabled($key)` order: demo build → true; core → true; override; plan preset; then
  `requires`. Unknown or stale override keys are ignored and pruned on the next write.
- Per-request cache keyed on the raw `(tier, overrides)` pair, not a bare static. A direct
  `Setting::set('tier', …)` (which a lot of the existing tests do) invalidates it by itself.
- `FeatureGate` keeps its redirect-or-403 behaviour and calls `Feature::enabled()`. `@feature` /
  `@endfeature` and `feature()` are added; the sidebar `match` and the reports-index array are replaced with them.
- Enforcement adds `feature:expenses` and `feature:stock_movements` to their route groups.
  Data keeps being written when a module is off: sales still write `inventory_adjustments`,
  and the shift close screen still shows drawer expenses already recorded.
- Settings → **Plan** tab becomes "Plan & Features": tier dropdown with a confirm step and
  on / off diff, then grouped toggles with a source badge (plan / override / blocked by
  dependency), then "Reset to plan defaults", then the existing activation block unchanged.
  New routes `settings.features.update` and `settings.features.reset` sit in the existing
  `role:super_admin` + `demo:blocked` group, and the features section is hidden while the install is locked.

## 5. Decisions I need from you

1. **Build on the existing tier system** (recommended, above) rather than a parallel one. Yes?
2. **"Pro" vs "Premium".** You wrote Pro. The code, the tests, the marketing-site pricing and
   the stored value say `premium`. I'll keep `premium` as id and label. Say "rename" and I'll
   change the label (and optionally the id plus a data migration).
3. **Default plan.** Unset `tier` → `premium` (existing grace behaviour, matches "defaults to
   Pro"). A freshly provisioned vendor install still lands on `starter` (existing, tested
   behaviour). I'm keeping both. OK?
4. **Plan tab.** Extend the existing Plan tab into "Plan & Features" (one dropdown, no
   duplicate) rather than adding a second tab. OK?
5. **New keys `expenses` and `stock_movements`**, on in all three plans. Right call, or should
   either be Standard+?
6. **Optional keys.** Add `receipts.a4_invoice`? Split `suppliers` into suppliers +
   purchase orders, or `loyalty` into customers + loyalty? I recommend no to all three for now.
7. **super_admin and feature gates.** Today the vendor account is subject to tier gates like
   everyone else (only `role:` gates have the superset pass). I'm keeping that. OK?
8. **"Hide POS-screen buttons"** vs **"never individual buttons".** I'm reading this as: hide
   only the entry points *to* a gated module (Add-customer on the till, Return on sale detail,
   links), never controls inside a module. Correct?

## 6. Risks I'm watching

- **Existing tests pin the current behaviour** (`premium` grace, fresh = `starter`, `pos.tiers`
  referenced in about 38 spots across 5 files). Keeping `License` as a delegate and the `tier`
  key unchanged is what keeps them green; moving the config will touch the ones that read `pos.tiers` directly.
- **`Cache::flush()`** in `SettingController::update()` and `rememberForever` in `Setting::get()`
  mean the cache story already works; the resolver memo must hang off the raw values (above) or tests that flip `tier` mid-test go stale.
- **Locked installs**: `ActivationGate` allow-lists specific route names, so the two new
  feature routes are not reachable while locked unless added. Intentional (unlock first).
- **Vortex branch**: `feature/vortex-client` carries its own licence backfill. This branch is
  cut from `main`; merging back should be a cherry-pick of `FeatureService` + config, not a merge of that branch.

## 7. Needs a human in the NativePHP desktop build (list grows after Phase 4)

- Plan change → next window navigation shows or hides sidebar items without a restart.
- Settings → Plan & Features in RTL on the packaged app (Cairo font, toggles aligned).
- A packaged upgrade on a machine that already has `tier` set: toggles show the stored plan, no feature lost.

## 8. As built (differences from the plan above)

All eight decisions went with the recommendations, plus: demo builds have every feature on;
the Plan & Features tab and its endpoints are never gated by a feature.

- **Labels live in lang files**, not in `config/features.php`: `features.labels.<key, dots as
  underscores>` and `features.groups.<group>` in `lang/{en,ar}/features.php`, so `LangParityTest`
  covers them like every other string (`CLAUDE.md` i18n rules).
- **Locked installs.** Section 4 said the features section would be hidden while the install is
  locked. As built the tab and both feature routes stay reachable on a locked install, like the
  tier route: the vendor can change a plan but not its features would be an odd split.
- **Per-product Movements tab.** The product edit page's movements tab and its "full history" link
  are the stock-movements history, so they go with `stock_movements`.
- **Customer inputs on the sale endpoint.** With `loyalty` off, `POST /pos/api/sales` refuses
  `customer_id`, `payment.amount_credit` and `payment.loyalty_points_redeemed` (403), and holds are
  stored and recalled as walk-in carts. The sale itself is untouched.
- **No new default migration.** `2026_10_18_000001_backfill_license_settings` already pins `tier`
  on installs that predate plans, and an unset tier reads as `premium`; unset `feature_overrides`
  means none. `FeatureUpgradeAndCatalogueTest` covers it.
- **Overrides are sparse.** A state equal to the plan's is stored as "no override"; stale or core
  keys are ignored when read and dropped on the next write.
- **Memo.** The resolved map is memoised per request and flushed by `Setting`'s saved / deleted
  hook for `tier` and `feature_overrides`, so even a plain `Setting::set()` is seen at once.
