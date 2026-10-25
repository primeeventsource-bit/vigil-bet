# Deploying to Laravel Cloud

The app is 100% PHP by design: no Python service, no Node runtime, nothing that
needs a server-level install. Laravel Cloud runs PHP applications, so every
piece of modelling math lives in `app/Modeling/` as plain PHP.

## Which application is production

There are two Cloud applications built from this repository, and only one of
them is real. Getting this wrong is easy and quiet, so the ids are written
down here rather than remembered.

| | Live | Duplicate |
| --- | --- | --- |
| Application | `app-a307a4b6-8877-4ec1-a815-9080baced557` ("Vigil") | `app-a30bf63f-7487-4a51-84cf-bab09ad55ff1` ("vigil-bet") |
| Environment | `env-a307a4b9-3ec3-4f35-8c14-5d23798471f2` | `env-a30bf641-c724-49a1-83bb-55debc6d79e1` |
| Database | schema `67484972` | schema `1562204` |
| Domains | parlayq.com, parlayhq.online | none |
| State | serving | HTTP 500, partial schema |

**The live application deploys on push to `main`** — `usesPushToDeploy` is true
on it and false on the duplicate. So `git push origin main` is the deploy, and
an explicit `cloud deploy <app-id>` is only needed to redeploy without a commit.

This was found the hard way: a day of `cloud deploy app-a30bf63f…` commands
reported success while rebuilding the broken duplicate, and the live site kept
updating anyway because every push deployed it. Both things were true at once,
which is why nothing looked wrong.

Run one-off commands against the live environment:

```
cloud command:run env-a307a4b9-3ec3-4f35-8c14-5d23798471f2 --cmd='php artisan …'
```

## Resources

| Resource | Setting |
| --- | --- |
| Database | Serverless Postgres |
| Queue | One worker cluster, default queue |
| Scheduler | Enabled |
| Key-value store | Enabled - used for cache and job locks |

## Build and deploy commands

```
# Build
composer install --no-dev --optimize-autoloader && npm ci && npm run build

# Deploy
php artisan migrate --force
```

Health check path: `/health`. It returns 200 when the database answers and
migrations have run, and 503 otherwise. It reports whether any job failed in
the last six hours, and nothing else - no counts, no data.

## Environment variables

Set everything from `.env.example`. The ones that matter in production:

```
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql          # Cloud injects the rest

CACHE_STORE=redis            # the Cloud key-value store
QUEUE_CONNECTION=redis
SESSION_DRIVER=database

ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=              # long and random; used once at seed time
EDGE_DEMO_MODE=false         # turn demo data off in production

ODDS_API_KEY=
ODDS_API_MONTHLY_QUOTA=      # set to your actual plan size
CFBD_API_KEY=
```

`EDGE_DEMO_MODE=false` matters. With it on, seeded fixture rows render behind a
DEMO badge; with it off, the app shows only real data and says so when there is
none.

## First deploy

```bash
php artisan migrate --force
php artisan db:seed --force          # books, teams, admin, bankroll settings
php artisan edge:teams:import all    # college team lists and ESPN ids
php artisan edge:backfill all 5      # queues chunked history jobs
php artisan edge:verify:guards       # confirms Postgres refuses to rewrite history
```

The backfill queues one job per sport per season rather than looping in a single
process, so it fits inside worker memory and time limits and resumes cleanly if
a worker restarts.

## What runs on the schedule

Defined in `routes/console.php`. Everything queues a job rather than working in
the scheduler process, so a slow upstream cannot block the tick.

| Job | Cadence |
| --- | --- |
| Odds pull, per sport | every 5 minutes, but each sport decides if it is due |
| Schedules, per sport | twice daily |
| Results, per sport | hourly |
| Mark closing lines | hourly |

## Postgres-only guarantees

Two migrations do nothing on SQLite and matter in production:

- append-only triggers on `odds_snapshots`, `predictions`, `bet_events`, the
  bankroll ledger and the rest of the history tables;
- check constraints that reject a prediction timestamped after kickoff, a
  feature set built from data newer than kickoff, and an invalid American price.

`php artisan edge:verify:guards` proves they are in force. CI runs it against a
real Postgres service container on every push.

## Rollback

`php artisan migrate:rollback` works, but migrations are forward-only by
policy: once a migration is committed it is never edited. Fix forward with a
new migration.
