# Moving the database into the application's region

Vigil's application cluster is in **us-east-1**. Its database is in
**us-east-2**. Every query pays for the distance, and this is what it
costs, measured from inside the app container on 27 Oct 2026:

```
select 1   min 30.18ms   median 30.76ms   p90 31.16ms   max 31.63ms
```

A same-region Neon endpoint answers `select 1` in 1–3ms. So the split is
roughly a **tenfold tax on every query the application makes** — a page
doing twenty queries spends about 600ms purely on geography.

It also explains a production failure precisely. The first version of
`SyncOddsDisplay` did a digest check per quote, about 4,000 queries a
run. At 31ms each that is 124 seconds of pure latency, which is why it
exhausted its attempts on the first real slate rather than merely being
slow. That job now does two queries per game, but the tax is still being
paid on everything else.

## Why there are two clusters

| | `parlayquantum` | `vigil-bet` |
|---|---|---|
| Cluster id | `late-pine-27266206` | `cold-fire-56331171` |
| Region | us-east-2 (Ohio) | **us-east-1 (Virginia)** |
| Storage | 4.2 GB | 43 MB |
| Writes, 7 days | active | **0** |
| Compute hours, 7 days | 29.65 | 1.06, all on 23 Oct |
| Max compute units | 1 | 0.25 |
| Schema id | 67484972 | 1562204 |

Vigil runs on `parlayquantum`, which is the old ParlayQuantum database it
inherited — the application was pointed at a database that already had
data in it rather than at one of its own.

`vigil-bet` is not a second copy to reconcile. 43 MB with zero writes in
a week is an empty Laravel install: somebody created the cluster on 23
Oct, ran `migrate`, and stopped. It is therefore not something to delete
but the obvious destination — already in the right region, already named
after the application.

So the region problem and the stray-cluster problem are one problem with
one answer.

## What makes this a ten-minute job rather than an afternoon

Measured, not estimated:

- `pg_dump`, `pg_restore` and `psql` are all present in the app
  container, which is itself in us-east-1. The copy runs server to
  server and never touches anybody's laptop.
- A full compressed dump is **216 MB and takes 61 seconds**. The raw
  database is 4.2 GB; `odds_snapshots` is 17.4M rows of repetitive
  numerics and compresses about 20:1.
- `pg_dump` in the container is 18.6 and the source server is 18.6, so
  there is no version skew to work around.
- A schema-only dump produced **87 tables, 78 triggers, 28 functions and
  158 indexes with no errors**. The 78 matters: those are the append-only
  guards, and they come across. `pg_restore` loads data before creating
  triggers, so the `BEFORE UPDATE OR DELETE` guards do not fight the
  `COPY`.

Credentials never need to leave the container. They live in its `.env`,
not in the command shell — a `pg_dump` that reads `$DB_HOST` from the
shell will fall back to a local socket and fail, which is the first thing
that goes wrong if you skip the `set -a; . ./.env` line below.

## Picking the window

Anything written between the dump and the cutover is lost. Most of what
this application writes in ten minutes is re-derivable — odds snapshots
re-pull on the next tick, results re-sync, `job_runs` is telemetry — so
the window is cheap, with two exceptions worth planning around.

1. **No game inside about half an hour of kickoff.** Closing line value
   is taken from the last quote mirrored before a game starts, and the
   public record is built on it. Losing the quotes either side of a
   kickoff puts a wrong number on a permanent record. `php artisan
   edge:doctor` prints the next kickoff per sport.
2. **Avoid :38 through :02.** That stretch holds the prop projections
   (:38), the scan (:45), the opinions publisher (:56), picks (:58),
   shadow (:59) and alerts (:02). Somewhere around :05–:30 past the hour
   is quiet. Overnight is quieter still.

Maintenance mode stops customer writes. It does not stop the scheduler,
which is why the clock above still matters.

## The procedure

Values to substitute are the **target** cluster's, from
`cloud database-cluster:get cold-fire-56331171 --show-sensitive --json`.

**1. Give the target room to work.** It is capped at 0.25 compute units,
which would throttle an index rebuild over 17M rows.

```bash
cloud database-cluster:update cold-fire-56331171 --cu-max=1
```

**2. Close the doors.**

```bash
cloud command:run production --cmd 'php artisan down --retry=60'
```

**3. Dump and restore in one invocation**, so the dump cannot go stale
between steps. Run it through the base64 wrapper if your shell mangles
`$` — `cloud command:run` strips double quotes, and the Laravel Cloud
CLI hands the string to `/bin/bash -c` on the far side.

```bash
cd /var/www/html
set -a; . ./.env >/dev/null 2>&1; set +a      # source creds, never printed
export PGPASSWORD="$DB_PASSWORD"

pg_dump -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -Fc --compress=6 --no-owner --no-privileges -f /tmp/vigil.dump

# Clear the abandoned 43 MB schema. Explicit rather than
# `pg_restore --clean`, so a failure here stops before any data moves.
PGPASSWORD='TARGET_PASSWORD' psql -h 'TARGET_HOST' -U 'TARGET_USER' -d 'TARGET_DB' \
  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'

PGPASSWORD='TARGET_PASSWORD' pg_restore -h 'TARGET_HOST' -U 'TARGET_USER' -d 'TARGET_DB' \
  --no-owner --no-privileges -j 4 /tmp/vigil.dump
```

If the target turns out not to be Postgres 18.x — the one thing that
cannot be checked without connecting to it — `pg_restore` says so
immediately and nothing has been changed.

**4. Count both sides before committing to the switch.** A restore that
half worked and a restore that worked look identical from the outside.

```bash
# against each host in turn
psql ... -c "select relname, n_live_tup from pg_stat_user_tables order by relname"
```

`odds_snapshots` should be ~17.4M, `events` ~35,333, `job_runs` ~26,000,
`published_picks` 8, `opinions` 24. Run `analyze` on the target first;
`n_live_tup` is an estimate and reads as zero on a freshly loaded table.

**5. Repoint the environment and deploy.** The binding is managed — the
app reads `DB_*` injected from `databaseSchemaId`, so there are no
environment variables to hand-edit.

```bash
cloud environment:update production --database-id=1562204
cloud deploy production
```

The deploy command is `php artisan migrate --force`, which is a no-op
against a restored database: `migrations` comes across with everything
else.

**6. Open up and confirm.**

```bash
cloud command:run production --cmd 'php artisan up'
curl -s https://pickvigil.com/health          # expect status ok
cloud command:run production --cmd 'php artisan edge:doctor'
```

Then re-measure the latency that justified all of this. Expect single
digits:

```php
DB::select('select 1');                        // warm the TLS handshake
$t = []; for ($i = 0; $i < 40; $i++) { $s = microtime(true); DB::select('select 1'); $t[] = (microtime(true) - $s) * 1000; }
sort($t); echo round($t[20], 2), "ms median\n";
```

**7. Leave the old cluster alone for a week.** It is the only rollback
that does not depend on the restore having been perfect, and rolling back
is one command:

```bash
cloud environment:update production --database-id=67484972 && cloud deploy production
```

Delete `parlayquantum` only once a week of real traffic has gone through
the new one — and remember it may still belong to the ParlayQuantum
project rather than to Vigil.

## What this does not fix

The database scales to zero after 300 seconds idle, in either region.
That is what produced `SQLSTATE[HY000]: General error: 7 no connection to
the server` on `value:scan` — a connection dropped by something merely
asleep. Two changes already landed against it: every job that retries
now waits 60 seconds first, and the application wakes every minute, which
keeps the connection warm enough that the 300-second timer rarely
expires. Moving regions shortens the reconnect but does not remove it.
