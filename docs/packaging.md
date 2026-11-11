# Pack / package / carton selling (client/tobacco)

A tobacco shop sells one product in more than one packaging unit:

| Unit | What it is | Barcode | Own prices |
|---|---|---|---|
| **Pack** | one cigarette pack | the product's own `barcode` | `price_usd` / wholesale / VIP (as always) |
| **Package** | a sleeve of N packs (N = `package_size`, 10 today) | none: the cashier reaches it by scanning any pack | `package_price_usd` / `_wholesale_` / `_vip_` |
| **Carton** | `packages_per_carton` packages (e.g. 20 x 10 = 200 packs) | `carton_barcode` | `carton_price_usd` / `_wholesale_` / `_vip_` |

Everything is opt-in per product. A product that has none of it behaves exactly as before.

## Where this lives

This is a **client-only build**: branch `client/tobacco`. It is never merged into `main` and never PR'd against it.

* `main` is merged **into** `client/tobacco` (`git merge main`, never rebase, never force-push: the branch is shipped).
* General fixes and features land on `main` first. Only client-specific work is committed on this branch.
* The whole feature sits behind `config('pos.features.packaging')` (env `POS_PACKAGING`, default `false`). Off: the product form has no
  Packaging section, the till ignores the fields, `Product::supportsUnit()` allows only `pack`, and the branch is `main`.
  `.env.tobacco.example` turns it on and gives the build its own app id.
* New code is in new files (below). Existing core files carry only thin hook-ins. If `main` ever wants this, the flag makes the code harmless.

When a merge from `main` conflicts, these are where to look (files `main` changes most that also carry a hook here):
`resources/views/pos/sell.blade.php` (by far the likeliest), `app/Services/SaleService.php`, `app/Http/Controllers/ProductController.php`,
`ReturnController.php`, `SaleController.php`, `resources/views/receipts/thermal.blade.php`, `config/pos.php`. Each hook is a few lines and marked
with a "packaging" comment or a `pkg*` / `packaging` name, so a conflict is quick to resolve by keeping both sides.

## The rules (decisions to keep)

1. **Stock is always in packs.** `products.stock_qty` is packs. There is no per-unit stock and no per-unit cost.
2. A sale line keeps its **quantity in sale units** (2 packages = `qty 2`) and snapshots the multiplier:
   `sale_items.sale_unit`, `unit_multiplier`, `base_qty = qty x unit_multiplier`. Stock moves by `base_qty`.
3. **The server is the source of truth.** The browser sends `product_id`, `qty` and the unit *name* (`pack|package|carton`). Price and
   multiplier come from `Product::priceForUnit()` / `multiplierFor()`. A posted price or multiplier is ignored. A unit the product does not
   sell (`supportsUnit()`: configured, priced, carton switched on, flag on) is a 422 on `cart.N.sale_unit`.
4. `sale_items.cost_usd` is the cost of **one sale unit** (pack cost x multiplier), so every existing `qty * cost_usd` margin / COGS sum is
   right without knowing about units. The product's own `cost_usd` stays per pack.
5. A package or carton uses the same price tiers: a missing wholesale / VIP price falls back to that unit's retail price
   (the `priceForGroup` rule).
6. Only simple products, without variants, can be packaged (form and server both refuse otherwise), and a packaged product cannot get
   variant rows either (`StoreProductRequest`). Variant barcodes, pack barcodes and carton barcodes are one namespace:
   `BarcodeService::findByBarcode()` answers `{product, variant, kind}` with `kind` = product | carton | variant. A variant is sold as a
   pack (`sale_unit` pack, multiplier 1) from its own stock; the till's cart line is product + variant + unit.
7. `sell_carton_at_pos` (default off) decides whether a carton can be rung up. Receiving by carton always works, switch or not.

## Files

New: `app/Models/Concerns/HasPackaging.php` (Product helpers, merges fillable / casts), `app/Services/PackagingService.php` (receiving
conversions), `app/Support/PackagingRules.php` (product form rules), `database/migrations/2026_11_11_000001_packaging_add_columns.php`,
`lang/{en,ar}/packaging.php`, `resources/views/pos/_packaging-{js,modal,badge}.blade.php`,
`resources/views/products/_packaging{,-receive}.blade.php`, `.env.tobacco.example`, and the tests `PackageCartonSaleTest`,
`PackagingProductTest`, `PackagingKeyboardTest` (+ `packaging_keys.cjs`).

## At the till

* Scan a **pack barcode** of a packaged product, or tap its tile: a small question opens, **Pack** or **Package xN** (and **Carton xN** when
  that product sells cartons at the till), each with the price on the active list. Keys: `1` / `2` / `3`, `Enter` = Pack, `Esc` cancels.
  Focus returns to the scan box. A scanner types digits within a few ms of each other, so a key that follows another within 60 ms is treated
  as part of a scan, and a digit is acted on only after 90 ms of quiet: scanning while the question is open never picks a unit.
* Scan a **carton barcode**: carton sold at the till -> a carton line is added, no question. Otherwise: "This is a carton barcode: cartons
  are not sold here. Use stock receiving."
* A line is identified by product **and** unit. Scanning the same product in the same unit increments it; another unit is a second line.
  The badge on a packaged line (`Package x10`) switches Pack <-> Package and re-prices it (a carton line stays a carton).
* Changing the price list re-prices every line, package and carton lines included. Held sales keep each line's unit; on recall every line
  is re-priced from current data and a line whose unit is no longer sold is left out with a message.
* The "type a number + Enter" quantity shortcut sets the quantity of the selected line in that line's own unit.

## Receiving stock

* **Stock adjustment** (product page): a Unit selector next to the quantity (Pack / Package xN / Carton xN, only what the product has).
  The server converts to packs; the movement reason records it ("... (received 3 x Carton = 600 packs)").
* **Scan carton** (same form): finds the product by its carton barcode and pre-fills *add, 1 carton*; each further scan adds one. A carton
  of another product opens that product's page with the same pre-fill. Nothing is saved until Adjust stock.
* **Purchase order receive**: a unit selector per line; quantity **and** the optional cost override are per that unit. The server converts
  to packs and the cost to per pack (carton cost / packs per carton, shown as a hint) before anything is written.

## Returns, voids, reports

* A return is in sale units (you cannot return half a package). Restock adds `qty_returned x unit_multiplier` packs. Refund per unit is
  still `line_total / qty`. A void restocks `base_qty`.
* Sales by product, the dashboard's items-sold, stock movements and inventory-by-date count **packs**. Sales by product also shows the
  packages / cartons split.
* Receipts print `Marlboro Red - Package x10`; an all-pack sale prints exactly as before.

## Language

Arabic unit words are **Pack = باكيت, Package = كروز, Carton = كرتونة**, "Sell cartons at the POS" = بيع الكرتونة في نقطة البيع. These are the
usual shop terms but must be confirmed with the client (`lang/ar/packaging.php`).

## Not supported (on purpose)

Opening a carton into packages automatically, per-unit stock or cost, excise / tobacco tax, bulk-import columns for packaging
(a carton barcode in an import file is still reported as a duplicate), coupons.

## Checked by hand, not by tests

Run on the real NativePHP app with the real scanner: scan a pack -> `1` / `2` / `Enter` -> focus back in the scan box, 20 times quickly;
the 80 mm and 58 mm thermal receipt with the longer line names; the Arabic layout of the question and the badges; a real carton barcode
on the stock-adjustment screen. The till flow, the receipt line, the carton scan on the stock screen and the Arabic layout were looked at once in a
desktop browser (not the NativePHP app, no real scanner, no 58 mm paper). The scanner timing rule is covered by
`PackagingKeyboardTest` on a virtual clock, which does not replace a real scanner.
