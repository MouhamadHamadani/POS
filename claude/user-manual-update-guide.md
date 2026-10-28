# User manual update guide — direct printing, the admin role, recurring expenses

The manual is `Claude outputs/LebaSouk POS - User Manual (EN-AR).docx` (English and Arabic). This file
lists what changed in the product and what each section of the manual needs, so the next edit pass
does not have to read the code. Wording below is the on-screen wording (`lang/en`, `lang/ar`); keep the
manual's screenshots and terms in line with it.

## 1. Receipts and invoices print straight to the printer

**What changed.** In the installed desktop app nothing opens a print window or the Windows print
dialog any more. A receipt, an A4 invoice, a Sales History reprint and the Settings "Print Test
Receipt" go straight to a printer:

- to the printer chosen in **Settings → Receipt → Receipt printer** (small receipts) or **A4 printer**
  (invoices);
- or, when that choice is left on **Default Windows printer**, to the printer Windows has set as default.

**Manual sections to change**

- *Printing a receipt / finishing a sale*: remove the steps about the print dialog and about choosing
  the printer each time. Say: "the receipt prints by itself on the receipt printer".
- *Settings → Receipt*: describe the three print settings — **Print format** (small receipt, A4 invoice,
  or ask after each sale), **Receipt printer**, **A4 printer** — and that an empty printer means the
  Windows default. New label: "Default Windows printer" (Arabic: "طابعة ويندوز الافتراضية").
- *Settings → POS → Auto-print receipt after sale*: when on, the sale prints on completion with no click
  (and with **Ask after each sale** the cashier picks Small receipt / A4 invoice on the confirmation
  panel instead; nothing prints until then). When off, the cashier presses **Print receipt** / **Print A4**.
- *If printing fails* (new): a red message appears at the bottom of the sell screen — "The sale is
  completed, but the receipt could not be printed. Check that the printer is on and connected, then press
  Retry." (Arabic: "تمت عملية البيع، لكن تعذّرت طباعة الإيصال. …") with **Retry** and **Dismiss**. The sale is
  already saved; only the paper is missing. Sales History shows the same message with **Retry** above the list.
- *Sales History → reprint*: unchanged for the user (the reprint is stamped COPY / نسخة and logged), but it
  now also prints silently.
- *Ctrl+P / Ctrl+Shift+P*: unchanged (default format / A4); they never open the system dialog in the app.
- *Troubleshooting*: "Nothing prints" → check the printer is on, the cable, that Windows shows it as
  ready, and that the printer chosen in Settings still exists; then press Retry.
- A browser tab (development only) still opens the receipt in a small window and uses its print dialog.

**Paper.** The receipt is as wide as **Settings → Receipt → Paper width** (58 or 80 mm), with no
margins and no browser header or footer. Arabic uses the bundled Cairo font.

## 2. The Admin role on every plan; who sees which Settings tab

**Roles** (unchanged names): Super Admin (Build Syntax's own support account), **Admin** (the shop
owner), Manager, Cashier, Stock Keeper.

- **Admin is now available on every plan, Starter included.** Starter allows **2 user accounts**
  (it was 1): normally the owner's Admin plus one more. Standard allows 3, Premium unlimited. The Super
  Admin account never counts. *Manual*: update the plan comparison table (users row, roles row).
- Only a Super Admin can create an **Admin** account (Users → New user). An Admin can create and edit
  Manager, Cashier and Stock Keeper accounts, but cannot create, edit, deactivate or reset another Admin
  or the Super Admin. *Manual, Users section*: on a new installation, Build Syntax signs in once and
  creates the shop owner's Admin account; the owner then adds the staff.
- **Settings tabs**

  | Tab | Admin | Super Admin only |
  |---|:--:|:--:|
  | General, Currency, Tax | ✔ | |
  | POS, Receipt (printers, test print, receipt template), Numbering | ✔ | |
  | Loyalty, Expenses (categories) | ✔ (if in the plan) | |
  | Plan & Features, Activation / trial | | ✔ |
  | Backup | | ✔ |
  | Permissions (bulk-upload switches) | | ✔ |

  The Admin does not see the right-hand tabs at all, and the server refuses them if someone tries.
- The Admin keeps everything for running the shop: Users, Reports (including Profit & Loss where the
  plan has it), Expenses, Sales History, Returns approval, Products, Customers.

## 3. Recurring (automatic) expenses

**Where.** *Expenses → Recurring* (a tab next to the expense list; same plans and same people as
Expenses: Admin and Manager).

**What it does.** A recurring expense is a template: category, description, amount (USD or LBP), how
often, and from when. The program then books a real expense by itself — you do not enter it each month.

- **How often**: *Weekly* (pick the weekday), *Monthly* (pick the day 1–31; in a shorter month it falls on
  the month's last day, so "31" books on 28/29 February and 30 April), *Yearly* (the start date's day
  and month each year; 29 February books on 28 February in other years).
- **Starts on / Ends on**: nothing is booked before the start date or after the end date.
- **When it is booked**: when the program starts, when anyone signs in, daily when the scheduler is running,
  and when someone presses **Generate now**. If the program was closed on the due day, the missed
  expenses are booked on the next start or sign-in, each dated on its own due day. Nothing is ever
  booked twice.
- **The booked expense** is a normal expense in Expenses and in the reports, marked with an **Auto**
  badge, with the template's description in Notes. It is paid from *Outside the drawer*, so it never
  changes a shift's cash count. It can be edited or deleted like any expense; a deleted one is not
  booked again.
- **Changing a template** (for example a rent increase) only affects expenses booked from then on; the
  old ones stay as they were. **Pause** stops it until you **Resume** (resuming books what fell due
  meanwhile); **Delete** removes the template but keeps everything it booked.
- **The list** shows each template's schedule, **Next due** date (red once it is due), and status
  (Active, Paused, Ended).
- LBP templates keep the lira amount; the dollar value follows the exchange rate on the day it is booked.

**Not included**: paying a recurring expense from the cash drawer (the drawer needs a person with an
open shift; automatic expenses are always paid outside it).
