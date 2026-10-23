# Vigil

Public, subscription sports-betting analytics. *AI-Driven Edge.*

Vigil is the customer-facing product. The modelling, odds ingestion and value scanning come from
[Edge Lab](https://github.com/primeeventsource-bit/edge-lab), which stays private and single-user
and is read through its internal API. Vigil adds accounts, subscriptions, an explanation layer, a
public track record and a marketing site.

The full build spec is [docs/MASTER_PROMPT.md](docs/MASTER_PROMPT.md); progress against its build
order is in [docs/STATUS.md](docs/STATUS.md), and the visual direction is in
[docs/BRAND.md](docs/BRAND.md).

## Requirements

PHP 8.3+, Composer, Node 20+. SQLite locally, PostgreSQL in production.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run build
php artisan serve
```

## Tests

```bash
php artisan test        # whole suite
./vendor/bin/pint       # format
```

Feature tests call `withoutVite()`, so the suite runs straight after a clone without building the
front end first. CI proves the assets compile in their own step.

## Rules the code enforces

These are not conventions, they are tested:

- **Vigil never takes a bet, holds money, or runs a contest.** No wallet, no payout, no prize.
- **Vigil never invents a judgement.** Every edge, verdict, stake, pick and pass is read from a row
  Edge Lab wrote. Nothing is recomputed on this side.
- **No pick from an unproven market.** A market produces picks only once Edge Lab's verdict gate
  says so. Everything else is labelled *not yet proven*.
- **Odds are displayed, never redistributed.** No public API, no odds export, no feed. Customers
  can export their own bets.
- **The brand lives in one place.** `config('brand.*')`, from the environment. Changing
  `BRAND_NAME` renames the product everywhere visible, and `BrandingTest` proves it — including
  that no template hardcodes the name or a hex colour.
- **21+ only.** The age gate runs inside registration, in the same transaction as the
  account, and stores the birth *year* only — never the date of birth.
- **App pages are noindex, marketing pages are not.** The head partial defaults to noindex so a
  page that forgets the flag fails safe.

## Layout

```
app/Http/Controllers/Auth    sign in, sign up with the age gate, 2FA, password reset
app/Services/Security/Totp   RFC 6238, checked against the RFC's own vectors
app/Support/Age              the age gate as arithmetic, in one place
config/brand.php             name, tagline, palette, logo paths
config/vigil.php             minimum age, the responsible-gambling line, self-exclusion periods
resources/views/components   layouts, the brand lockup, form fields
resources/views/legal        terms, privacy, responsible gambling, disclaimer
```
