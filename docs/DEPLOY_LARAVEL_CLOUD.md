# Deploying to Laravel Cloud

The app is 100% PHP by design: no Python service, no Node runtime, nothing that
needs a server-level install. Laravel Cloud runs PHP applications, so every
piece of modelling math lives in `app/Modeling/` as plain PHP.

## Which application is production

One Cloud application, and these are its ids. They are written down rather
than remembered because there used to be two.

| | |
| --- | --- |
| Application | `app-a307a4b6-8877-4ec1-a815-9080baced557` ("Vigil", slug `edge-lab`) |
| Environment | `env-a307a4b9-3ec3-4f35-8c14-5d23798471f2` |
| Database | schema `67484972` |
| Domains | parlayq.com, parlayhq.online |

**Deployment is `git push origin main`.** `usesPushToDeploy` is on, so the push
is the deploy; an explicit `cloud deploy <app-id>` is only for redeploying
without a commit.

A second application, `vigil-bet`, was built from this same repository and was
deleted on 25 October 2026. It had no domains, a partial schema and returned
500, and a day of `cloud deploy app-a30bf63f…` commands reported success while
rebuilding it - the live site updated anyway, because every push deployed the
real one. Both things were true at once, which is exactly why nothing looked
wrong for a day.

The lesson outlives the duplicate: a deployment that reports success is not
evidence that the thing you are looking at is the thing you deployed. Check the
domain, not the exit code.

Run one-off commands against the live environment:

```
cloud command:run env-a307a4b9-3ec3-4f35-8c14-5d23798471f2 --cmd='php artisan …'
```

## Resources

| Resource | Setting |
| --- | --- |
| Database | Serverless Postgres |
| Queue | One worker cluster, default queue, `database` driver |
| Scheduler | Enabled |
| Cache, sessions, locks | Postgres. There is no key-value store |

There is no Redis and nothing needs one. Cache, queue, sessions and the
`withoutOverlapping` locks all run on the database, which holds across
instances - a file-backed lock on ephemeral containers would not, and that is
the failure this table used to invite by claiming a key-value store that was
never provisioned.

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
