# CLAUDE.md — POS Repository

> ⚠️ READ-ONLY REFERENCE — Do not modify, refactor, or commit anything to this repo.
> Read this codebase for patterns and logic only. All SaaS work happens in mena-business-os/.

## What this repo does
Standalone POS system. Product search, cart, checkout (cash/card),
receipt generation, daily sales summary.
This app works correctly and is used in production. Do not touch it.

## Models to read and understand
- Sale / Transaction
- SaleItem
- PaymentMethod (cash, card, credit)
- Shift / CashDrawer (if exists)

## Key files worth reading for the SaaS
- app/Models/ — understand the data structure
- The React or Blade cashier screen — copy the UI approach
- app/Http/Controllers/SaleController.php — understand the sale flow
- database/migrations/ — understand the schema

## What to take from this repo into the SaaS
- The cart management logic
- The split payment handling (cash + card)
- The receipt generation approach
- The daily summary query

## Do NOT do any of the following
- composer require anything
- php artisan make:anything
- Edit any file
- Create any migration
- Commit anything
