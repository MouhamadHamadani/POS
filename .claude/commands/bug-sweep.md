---
description: Hunt for bugs across the POS app, fix them, verify, and repeat until no open bugs remain
argument-hint: "[area] [--max-rounds=N]  (area optional: money, stock, shifts, auth, forms, validation, reports, other)"
---

Run a find → fix → verify loop on this repo using the `pos-bug-hunter` and `pos-bug-fixer` subagents. You (the main session) are the orchestrator; subagents can't call each other, so you call them in turn and read their summaries.

Arguments: `$ARGUMENTS`. If an area is given, sweep only that area. Default max rounds is 5.

## Setup
1. Make sure the working tree is clean (`git status`). If not, stop and tell me.
2. Create and switch to branch `bugfix/sweep-<YYYY-MM-DD>` from `main` (if it already exists, continue on it — the ledger carries over).
3. Run `php artisan test` once and note the baseline.
4. Ensure `.claude/bug-hunt/` exists.

## Round 1 — hunt
Call `pos-bug-hunter` once per area, **one at a time** (they share the ledger, so never in parallel), in this order unless an area was given:
`money`, `stock`, `shifts`, `auth`, `forms`, `validation` (incl. audit), `reports`, `other`.
Tell each call which area it owns and that earlier areas are already in the ledger.

## Each round
1. **Fix:** if any bugs are `open` or `reopened`, call `pos-bug-fixer` with "all open" (or split into batches of ~8 IDs, critical first, if there are many).
2. **Money check:** if any fix touched currency, VAT, discounts, cart totals, refunds or stock quantities, call `currency-tax-guardian` on the changed files and treat its confirmed problems as new bugs — call `pos-bug-hunter` to add them to the ledger with repro tests.
3. **Verify:** call `pos-bug-hunter` in verification mode. Also ask it to re-sweep only the areas whose code changed this round, looking for regressions.
4. **Decide:** read its `NEW_BUGS` and `OPEN_BUGS` lines.
   - `OPEN_BUGS: 0` and `NEW_BUGS: 0` → done, go to Finish.
   - Otherwise start the next round.
   - Stop early if a round fixed nothing and found nothing new (no progress), or max rounds is reached.

Between rounds, send me a one-line status (round N: X found, Y fixed, Z open).

## Finish
1. Run `php artisan test` and confirm it's fully green.
2. Call `laravel-code-reviewer` on the whole branch diff (`git diff main...HEAD`). Treat any confirmed correctness/security finding as a new bug and run one more round for it; style notes go in my report, not the loop.
3. Report to me:
   - Bugs fixed (ID, severity, title, commit) — grouped by severity
   - `needs-decision` items, each with the question I need to answer
   - `Needs manual check` items (hardware/printing/native window) — these need testing on the real machine
   - Final test result and the branch name
4. Don't push or merge. I'll review the branch.

## Rules
- Never let the fixer edit or skip a repro test to get green; the hunter checks this in verification.
- Never touch `.env` secrets, shipped migrations, or `storage/`.
- If the same bug is `reopened` twice, mark it `needs-decision` and stop retrying it.
