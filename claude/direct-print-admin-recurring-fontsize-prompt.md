# LebaSouk POS: direct receipt printing, admin role restored, recurring expenses, font sizes

Read CLAUDE.md first. Work on a new branch from main. Follow existing conventions: Setting::get/set, AuditLog::record on mutations, bilingual lang/en + lang/ar for every new string, feature gating through Feature::, tests in Pest/PHPUnit style like tests/Feature/Pos/InvoiceA4Test.php. Run the full test suite before finishing and report results.

## 1. Receipt must print directly and automatically, never open a window

Current behaviour: the thermal receipt opens a popup with window.print() (system dialog). An uncommitted change on main already adds a thermal_printer_name setting and POST /pos/receipts/{sale}/print-thermal (ReceiptController::printThermal, ReceiptPrintService::printThermalSilently). Review it and finish it to this spec:

- Inside the NativePHP shell (config('nativephp-internal.running')), receipts ALWAYS print silently. Use the printer in thermal_printer_name if set, otherwise the Windows default printer (omit deviceName / let Electron use the default). Settings label: "Receipt printer", empty option = "Default Windows printer".
- Never fall back to window.open or the print dialog inside the desktop app. If the silent print fails, show an inline error on the sell screen (bilingual) with a Retry button; the sale itself must stay completed.
- Outside the desktop app (browser/dev), keep the existing popup behaviour.
- Same rule for A4 invoices: silent to a4_printer_name or default printer in the app, no dialog.
- Sales History reprints follow the same rule and stay audited as reprints.
- Respect the auto_print setting and receipt_format (thermal / a4 / ask). "ask" should show an in-page chooser, not a system dialog.
- Verify thermal output: width follows receipt_width (58/80 mm), no browser header/footer, no margins, Arabic renders (Cairo font is bundled; data: URL loading must not break fonts, check and fix if needed).
- Tests: route authorization, fallback JSON when not in shell, audit on reprint, settings save/validation, sell screen flags.

## 2. Restore the admin role (super_admin / admin / manager / cashier / stock)

Roles stay: super_admin (vendor only), admin (client owner), manager, cashier, stock.
- Admin must be available on EVERY plan including Starter: update config/features.php plan roles and any License/UserController/users form logic. Raise Starter max_users from 1 to 2 (seat count already excludes super_admin). Update the plan docs/feature-matrix and the Plan tab text.
- An admin can create/manage manager, cashier and stock users but not admin-tier accounts (existing rule, keep it).
- Settings access split:
  * admin sees: General, POS behavior, Receipt (incl. printer settings and receipt template), Numbering, Loyalty, Appearance, Currency.
  * super_admin ONLY: Plan & Features, Backup, Permissions, Activation/trial, creating admin accounts.
  * Enforce on the server (routes/middleware/controller), not just by hiding tabs. A crafted POST from an admin to a super_admin-only group must be rejected (403).
- Check every place that gates on ROLE_ADMIN vs isSuperAdmin() (User model helpers, RoleMiddleware, request classes, reports, sales history, expenses) so admin keeps full day-to-day store operations including revenue/P&L reports.
- Make sure a fresh install flow (SuperAdmin::ensure, DatabaseSeeder, ResetData) still works and that the first client admin can be created by the super_admin from Users.
- Tests: admin allowed/forbidden matrix per settings tab and route, Starter plan can assign admin, admin cannot create admin.

## 3. Recurring (automatic) expenses

Add recurring expense templates under Expenses (gated by the existing `expenses` feature).
- New table recurring_expenses: expense category/type (reuse the existing expense type/category model), description, amount (USD base, follow CurrencyService conventions), frequency (weekly | monthly | yearly), day_of_week (weekly) or day_of_month 1-31 (monthly; if the month is shorter use the last day), start_date, optional end_date, is_active, last_generated_for (period), created_by. Bilingual name fields if expenses use them.
- Generation: an idempotent service (RecurringExpenseService::generateDue()) that creates real Expense rows for every due occurrence up to today, including missed ones. Idempotency: unique key (recurring_expense_id, period_date) so reruns never duplicate. Generated rows are flagged as automatic (recurring_expense_id FK + visible badge in the expense list), created_by = null/system, dated on the occurrence date.
- Trigger: (a) Laravel scheduler daily (check app/Console/Commands/PatchNativePhpScheduler.php and how the scheduler runs under NativePHP), AND (b) catch-up on app launch (NativeAppServiceProvider) and on user login, because the desktop app may be closed on the due day. Must be safe and fast when nothing is due.
- Generated expenses do NOT deduct from the shift drawer. Add an option per template "paid from drawer" default off only if the existing drawer-expense logic makes that simple; otherwise skip.
- UI: Expenses page gets a "Recurring" tab/section: list, create, edit, pause/resume, delete (deleting a template keeps already generated expenses). Editing the amount affects future entries only. Show next due date. Include a "Generate now" button for admin/manager.
- Permissions: admin, manager (and super_admin) can manage templates, same as who can manage expenses today.
- Audit log every create/update/pause/delete and every generation batch.
- Tests: weekly/monthly/yearly due calculation, day 31 in short months, missed months backfill, no duplicates on rerun, paused/ended templates, end date, launch catch-up, permissions, feature gating.

## 4. Font size options

A) Receipt text size (install-wide)
- New setting receipt_font_size (small | normal | large, default normal) in Settings -> Receipt, saved with the rest of the receipt group; validate with Rule::in.
- receipts/thermal.blade.php and receipts/a4.blade.php: replace the hardcoded font sizes (11px body, 10px small, 13px grand total, etc.) with a CSS variable scale (small 0.9, normal 1, large 1.2) so all text scales together while keeping the layout intact. The Arabic font (Cairo) must scale too.
- Custom print templates (ReceiptRenderer) are not scaled; say so in the setting help text (en/ar).
- The existing Settings -> Receipt test print must reflect the chosen size.
- Check 58 mm and 80 mm widths at "large": no clipped columns, totals and payment lines stay readable, Arabic wraps correctly.

B) Interface size (PER USER)
- New nullable column users.ui_scale (allowed values 90 | 100 | 110 | 125; null = 100). Each user picks their own; it is NOT an install-wide setting and an admin cannot force it on others.
- Every role, cashiers included, can change their own size without needing any Settings access: add it to a small "My preferences" entry in the user menu (a select or four-step switcher), saved via a self-service route that only ever updates the authenticated user's own row. Validate with Rule::in; audit is not required.
- Apply it as the root font-size percentage on <html> in the app and pos layouts, read from the authenticated user, so rem-based Tailwind scales. Avoid CSS zoom hacks. It must apply on login and survive reloads, with no flash of the wrong size.
- Audit the POS sell screen, payment modal, shift screens and reports at 125% on 1366x768, in English and Arabic RTL; fix any hardcoded px sizes that overflow or hide controls.
- First check whether Electron zoom (Ctrl +/-) is disabled by NativePHP; if it works, mention it in the manual, but still ship the setting.
- Demo builds and the login page use 100%.
- Tests: a user can only update their own ui_scale, invalid values rejected, layout carries the user's scale, two users see different scales.

## Deliverables
- Code + migrations + lang (en/ar) + tests, all green.
- Update CLAUDE.md conventions, claude/feature-matrix.md, and the user-manual-update-guide notes for: direct printing, admin role/settings split, recurring expenses, font sizes.
- Commit in logical commits. List anything you decided on your own and anything you could not verify (real printer, Windows default printer behaviour, 1366x768 layout).
