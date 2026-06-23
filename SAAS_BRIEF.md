# MENA Business OS — Master Project Brief

## What we are building
A multi-tenant SaaS platform for Lebanese and MENA small businesses.
One subscription gives any business: an online store, a WhatsApp commerce bot,
a booking/appointment system, and a POS terminal — all managed from one dashboard.

Think: Shopify + Calendly + Square, Arabic-first, WhatsApp-native.

## The three source repositories
| Repo | Current state | Role in SaaS |
|------|--------------|--------------|
| ecommerce-repo | Standalone Laravel app | → Storefront + catalog module |
| appointments-repo | Standalone Laravel app | → Booking module |
| pos-repo | Standalone Laravel app | → POS terminal module |

## Target: one unified Laravel app
All three repos will be refactored into modules inside a single Laravel 12
application. Each business (tenant) gets their own isolated database schema.

## Tech stack (do not change without asking)
- Backend: Laravel 12
- Admin panels: Filament 3
- Frontend components: Livewire 3 + Alpine.js
- SPA modules: React + Inertia.js (for POS and storefront)
- CSS: Tailwind CSS
- Database: MySQL (per-tenant schema via stancl/tenancy)
- Cache / queues: Redis + Laravel Horizon
- Payments: Paymob (Lebanon/Egypt), Stripe (Gulf)
- WhatsApp: Meta Cloud API (direct, no Twilio)
- Storage: Spatie Media Library
- Search: Laravel Scout + Meilisearch
- Hosting: Hetzner VPS

## Multi-tenancy architecture
Package: stancl/tenancy v3
Strategy: one database per tenant (NOT shared DB with tenant_id columns)
Routing: subdomain — tenant.yourdomain.com
Tenant resolution: by subdomain in TenantMiddleware

## Payment rules
- Always store prices in USD (source of truth)
- LBP display = USD × configurable exchange rate (stored in tenant settings)
- Never hardcode currency symbols — use a CurrencyHelper
- Lebanon: Paymob integration (already built in ecommerce-repo — reuse it)
- Gulf: Stripe

## Internationalization
- All user-facing strings go in lang/en/ and lang/ar/
- Arabic is RTL — all Blade/React components must support dir="rtl"
- Dates: use Carbon with locale('ar') for Arabic display

## WhatsApp bot
- Webhook endpoint: POST /api/whatsapp/webhook
- Verify token + HMAC signature on every incoming request
- Session state stored in Redis (key: wa_session:{phone_number})
- Must respond to Meta within 200ms — dispatch a queued job immediately
- Bot handles: browse catalog, place order, book appointment, check order status, handoff to human
- Payment link: generate Paymob checkout URL and send as WhatsApp message

## Coding standards
- Repository pattern for all DB access (no queries in Controllers)
- Service classes for business logic
- Form Requests for all validation
- All external API calls (WhatsApp, Paymob) must go through a Service class and be queued via Horizon
- No raw SQL queries
- Feature tests for every new endpoint
- Pest for testing (not PHPUnit)

## What NOT to touch
- .env files (never modify, never read secrets from)
- storage/ directory contents
- The main/production branch (work on feature branches only)
- Existing migrations (only add new ones, never modify old ones)

## Definition of done for each task
1. Code written and follows the standards above
2. Migration runs cleanly: php artisan migrate
3. Feature test written and passing: php artisan test
4. No new N+1 queries (use eager loading)
5. Arabic + English strings extracted to lang files
