## Prompt for Claude Code — run this in the `POS_website` repo

Copy everything below into Claude Code, working in `MouhamadHamadani/POS_website` (the marketing site — Laravel 11 + Blade + Tailwind, `.claude/agents/marketing-site-builder.md`, `bilingual-i18n-auditor.md`, and `laravel-code-reviewer.md` already exist there; use them for their respective parts of this work, and run `bilingual-i18n-auditor` and the site's existing `tests/Feature/SiteAuditTest.php` before calling this done).

---

### Context

The site is being rebranded from "POS Pro" to **LebaSouk**, and the pricing page needs to ship real numbers instead of the `$___` placeholders. Both the brand identity and the pricing were verified directly against the actual product codebase (`MouhamadHamadani/POS`, the NativePHP desktop app) — this isn't speculative copy, it's confirmed against what's actually built. Do not re-derive pricing or feature claims from your own judgment; use what's specified below.

**Naming:** the real app's `APP_NAME` is `LebaSouk` (see its `.env`/`.env.example` and `README.md`, which is titled `# LebaSouk`) — not "LebaSouk POS". Use **"LebaSouk"** as the brand name everywhere a brand name is used standalone (logo/nav, footer, email sender/signature, structured data `name`). "LebaSouk POS" can be used descriptively where it reads more clearly (page `<title>` tags, meta description, the pricing page H1) — e.g. `<title>Pricing — LebaSouk</title>` or "LebaSouk POS pricing," your judgment on which reads better in each spot, but never invent a third variant.

---

### Part 1 — Brand colors

The actual POS app defines a real brand palette in its `tailwind.config.js` — the website should match it, not keep its own approximate teal/navy. Source of truth (from the POS app, comments included):

```js
colors: {
    brand: {
        50:  '#EAF5F5',
        100: '#C9E6E5',
        200: '#93CDC9',
        300: '#5DB3AD',
        400: '#2E9A92',
        500: '#1D7D76',
        600: '#17635E',
        700: '#124F4A', // Primary teal (LebaSouk)
        800: '#0C3733',
        900: '#06211E',
    },
    accent: {
        DEFAULT: '#E2673F', // Terracotta (LebaSouk)
        light:   '#EF9271',
        dark:    '#B84F2E',
    },
    success: '#27AE60',
    warning: '#E67E22',
    danger:  '#C0392B',
},
```

Usage hierarchy in the actual app (grep'd from its `resources/views`, so this is real usage frequency, not a guess): `brand-700` is the dominant primary (buttons, links, headings — ~70 uses), `brand-800` for hover/pressed states, `brand-600`/`brand-500` for secondary emphasis, `brand-50`/`brand-100` for light tinted backgrounds, `brand-300`/`200` for borders. **`accent` (terracotta) is used sparingly** — only a handful of times, for one-off highlights, not as a second primary color.

**How to apply this to the website** (Tailwind v4, CSS-based `@theme` in `resources/css/app.css`, per `WEBSITE-AUDIT.md`'s stack notes — confirm the current mechanism before editing):
- Redefine the site's existing `teal-*` scale to these `brand-*` hex values (so `bg-teal-600`, `text-teal-700`, `ring-teal-500` etc. across the existing Blade files pick up the real brand color automatically — don't do a mass find-and-replace of class names, just repoint the underlying CSS variables/hex values).
- Add a new `accent`/`terracotta` token using the values above, and use it the same way the real app does: sparingly, for one or two intentional highlights (e.g. the "Most Popular" pricing-card ribbon, or a single stat/badge) — not as a second button color.
- Decide what to do with the site's current `navy-700` (used for dark headings/footer) — either keep it as a secondary dark neutral (fine, it doesn't clash) or fold it into `brand-800`/`900` for one consistent dark tone. Use judgment; don't over-rotate the whole page to teal-on-teal.
- Spot-check contrast after the change (a prior audit on this site verified AA on the existing teal-on-white pairs at ~6.3:1 — re-verify with the new hex values, since they're darker/different).
- The POS app's icon files (`public/favicon.ico`, `public/icon.ico`, `public/icon.png`) exist in the POS repo if you want to align the website's favicon/OG image with the product's actual icon — optional, flag it rather than guessing if you don't have access to pull those files in.

---

### Part 2 — Brand rename (POS Pro → LebaSouk)

This touches more than the pricing page. Update every instance of "POS Pro":
- `README.md`
- `resources/views/partials/footer.blade.php` (logo text + copyright line)
- `resources/views/layouts/app.blade.php` (`<title>` fallback, OG tags, commented structured-data `name`)
- `resources/views/emails/client-confirmation.blade.php` and `admin-order.blade.php` (headings, signature, "POS Pro — A Build Syntax product" footer line)
- `app/Mail/ClientOrderConfirmation.php` and `AdminOrderNotification.php` (email subject lines: `"We received your POS Pro order"`, `"New POS Pro Order — ..."`)
- `.env` / `.env.example`: `APP_NAME="LebaSouk"`, and update `ADMIN_EMAIL` off the `pospro.com.lb` domain if a real LebaSouk domain/email exists — ask Mouhamad for the correct address rather than guessing one.
- The `pp_lang` locale cookie name in `app/Http/Middleware/SetLocale.php` is cosmetic and low-priority — rename to something brand-neutral only if convenient, not worth a special pass on its own.
- Grep the repo for any other literal `"POS Pro"` / `pospro` strings you find beyond this list and fix those too.

---

### Part 3 — Pricing page (final copy)

Replace the current three pricing cards' content. **Starter and Standard get fixed prices; Premium does not** — it keeps the site's existing "contact for quote" pattern (same as today's Enterprise card: `plan_contact` CTA → `route('contact')`, no price rendered). Don't build new logic for this — just relabel and swap in the new content.

**Starter — $350–$500 one-time license** — "For a single till, one Lebanese business."
- Sales, cart, payments (USD/LBP dual currency)
- Shift open/close
- Barcode-based inventory (scan-to-sell, products, stock levels)
- Manager + Cashier roles
- Standard reports (daily sales, shift summary)
- Email support, install included
- 1 device / 1 user seat

**Standard — $700–$1,000 one-time license — "Most Popular"** — "For growing stores that need full control."
Everything in Starter, plus:
- Returns with manager approval
- Suppliers & purchase orders
- Customer profiles + loyalty program (points, tiers)
- Full roles: Cashier, Stock Keeper, Manager, Admin
- Fuller reporting (inventory valuation, margin/P&L)
- Up to 3 devices/users
- Priority email + WhatsApp support

**Premium — "Contact us for a custom quote"** (no price) — "For high-volume or multi-branch operations."
Everything in Standard, plus:
- Customizable receipts (header, footer, paper width)
- Priority phone support + faster bug-fix SLA
- Unlimited devices/users
- On-site installation/training visit

**Do not add a multi-branch feature line anywhere.** It isn't built in the product yet (verified directly against the codebase — no Branch/Location/Warehouse model exists at all). If a lead needs it, that's a sales conversation during the quote process, not a page claim. Same reasoning for "custom print templates" being worded as "customizable receipts" — the real app has a `PrintTemplate` model but it isn't wired into the receipt controller yet (`ReceiptController` hardcodes one Blade view), so don't claim more than header/footer/paper-width customization.

**Implementation specifics:**
- `resources/views/components/pricing-card.blade.php` and `pages/pricing.blade.php` currently only pass 4 feature strings per card (`f1`–`f4`). Starter and Standard both need 7. Expand the component and add `f5`–`f7` keys (or switch to passing an array directly) in both `lang/en/site.php` and `lang/ar/site.php` — never add an English key without its Arabic counterpart, per this repo's own i18n rule.
- Keep the existing plan slugs (`starter` / `professional` / `enterprise`) in `OrderRequest` validation, the Filament `OrderResource`/`OrderForm`/`OrdersTable`, and the `orders.plan` column — don't rename the underlying values, just change what's displayed for "professional" (→ "Standard") and "enterprise" (→ "Premium") in `lang/*/site.php`'s `plan_pro` / `plan_enterprise` keys and the order-form radio labels.
- **Fix the confirmation emails to match**: `resources/views/emails/client-confirmation.blade.php` and `admin-order.blade.php` currently print the raw `{{ $order->plan }}` value capitalized — so a customer who picks "Standard" would see "Professional" in their confirmation email today. Add a label map (e.g. a small array or a method on the `Order` model) so the email shows the same customer-facing tier name as the pricing page.
- Never hardcode an LBP price anywhere on the site — the product's exchange rate is a manually-entered `Setting` with no external sync, so a static LBP figure would drift from what a customer actually pays. USD stays the only price shown.
- "Returns with manager approval" is worded deliberately — in the real app a cashier's return goes into a pending state and needs a separate Manager+ login on the return's own page to approve/reject, not an in-the-moment PIN at checkout. Keep that precision if this copy gets reused anywhere else on the site (features page, demo script).

---

### Acceptance checklist before you call this done
- [ ] No literal "POS Pro" / "pospro" strings remain anywhere in the repo
- [ ] `lang/en/site.php` and `lang/ar/site.php` have matching keys for every new/changed string (run `bilingual-i18n-auditor`)
- [ ] Pricing page renders 7 features for Starter and Standard, no price shown for Premium, no multi-branch line anywhere
- [ ] Order-form plan radio labels and Filament admin labels say Starter/Standard/Premium; underlying `plan` values unchanged
- [ ] Both order-confirmation emails display "Standard"/"Premium" correctly, not "Professional"/"Enterprise"
- [ ] New brand colors applied via the `@theme` CSS variables (not a mass class rename), accent used sparingly, contrast re-checked
- [ ] `php artisan test` / `vendor/bin/pest` passes, including the existing `SiteAuditTest`
