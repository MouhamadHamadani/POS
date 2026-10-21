# Fake data mode

A fake business to develop and test against, and a guaranteed way back to a clean database.

It is for development and testing only. It never runs in a production environment, never in a demo
build (which has its own seeded template, see `docs/demo-build.md`), and never into a database that
already holds data it did not put there.

## What you get

`php artisan pos:fake-data` seeds a Lebanese mini-market that has been trading for three weeks
(`--days=N` to change it, `--seed=N` for a different shop; the same seed gives the same shop):

| | |
|---|---|
| Accounts | `fake_admin`, `fake_manager`, `fake_cashier`, `fake_cashier2` (Arabic UI), `fake_stock`; password `test1234` (`POS_FAKE_PASSWORD`) |
| Catalogue | 7 categories, 37 bilingual products (VAT and exempt, wholesale / VIP prices, a no-stock delivery service), opening stock through the movement log |
| People | 12 customers (retail / wholesale / VIP, credit limits, loyalty points), 4 suppliers, 4 purchase orders (received, partial, sent, draft) |
| Trading | a closed shift per day with a counted drawer (some days a little off), today's shift open for `fake_cashier` so the till works at once, ~300 sales |
| Sales mix | cash USD and LBP, mixed cash, card, Wish, cash + Wish and the other splits, on account; about one in seven an online order (WhatsApp / Instagram / Facebook / phone); discounts, price lists, voids, returns refunded the way the customer paid |
| Around it | drawer and outside expenses, fixed bills, stock counts and write-offs, a few products out of stock or low, two held carts |

It is not inserted rows. Every sale goes through `SaleService`, every stock movement through
`InventoryService`, every expense through `ExpenseService`, and every shift is closed against
`ShiftService::totals()`, with the clock faked day by day. So stock matches its movement log, a closed
shift's expected cash matches its sales, payment lines add up to totals, and every `created_at` falls
on the day it belongs to. `FakeDataModeTest` checks all of that, and opens every screen on the result.

Everything is plainly invented: `Test` in every customer name, `(Test)` on suppliers, `+961 70 100 xxx`
phone numbers, `fake_` accounts.

## Switching modes

| You do | What happens |
|---|---|
| `POS_FAKE_DATA=true`, `APP_ENV=local`, first launch with an empty database | the ordinary first-launch seeding (`DatabaseSeeder`) also lays the fake business down |
| `php artisan pos:fake-data` | seeds on demand, whatever `POS_FAKE_DATA` says |
| `php artisan pos:fake-data --fresh` | wipes the fake data and builds it again |
| `php artisan pos:fake-data --purge` | back to a clean, freshly installed database |
| launch with `APP_ENV=production` | a database carrying the fake-data marker is reset to a clean install, once, before the window opens |
| `php artisan pos:fake-data --status` | what is in this database, and what mode it is in |

**Clean** means what a freshly installed machine looks like: every table emptied (the migration
ledger and the expense categories its own migration inserted excepted), then the config defaults and
the vendor's built-in `super_admin` laid down again, the same path `NativeAppServiceProvider` takes
for an empty database. Fake accounts, the fake shop's exchange rate and business name, its plan and
activation state all go; a clean install starts its own 14-day trial.

The case this exists for: a development build and a production build installed on one machine share
the app's data directory, so the production build would otherwise open on the development build's fake
shop. Launched with `APP_ENV=production`, it finds the marker and cleans it.

## Why it cannot reach a client

1. **It refuses.** `App\Support\FakeData::problem()` says no in a production environment, in a demo
   build, and for a database holding business data without the marker. The seeder, the command and the
   automatic path all ask it.
2. **Everything it writes is marked.** The seeder's first write after the config defaults is the
   `fake_data_seeded` setting, so even a seed that dies halfway is recognisable and purgeable.
3. **Only marked databases are ever reset.** A database without the marker is never wiped by any of
   this: `--purge` refuses it, a production launch leaves it alone. `php artisan pos:reset-data` is the
   deliberate way to clear real data.
4. **Release builds refuse the flag.** `ReleaseEnv` (and so `native:build`) fails when `.env` has
   `POS_FAKE_DATA` on.

## Notes

- With `POS_FAKE_DATA=true` the desktop app's first launch seeds before the window opens: about 10
  seconds for three weeks of trading.
- A purge also removes any real-looking data someone added to a marked database after it was seeded.
  Once you add data you want to keep, start from a database without the marker.
- The seeder ships inside the app like every class under `database/`, but nothing in a production
  environment can reach it.
