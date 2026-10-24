# Schema

Thirty-three application tables plus Laravel's own. The shape follows two
rules: money is integer cents, and history is never rewritten.

Conventions: `timestamptz` everywhere (UTC), `numeric` for probabilities and
lines, `jsonb` for payloads, enums as PHP enums backed by varchar columns.
Tables marked **AO** are append-only - Postgres triggers refuse UPDATE and
DELETE, and the `AppendOnly` model trait refuses them everywhere else.

## Reference

| Table | Purpose |
| --- | --- |
| `venues` | Stadiums, with coordinates for weather and a dome flag |
| `teams` | One row per franchise per sport |
| `team_external_ids` | Source id to team, the only fully safe match |
| `team_aliases` | Known names, former names and dataset abbreviations |
| `unmatched_names` | Names we could not resolve, with hit counts. Logged, never guessed |
| `books` | Sportsbooks, plus the untracked `historical_close` bucket |
| `seasons` | Backfill bookkeeping |

## Events

| Table | Purpose |
| --- | --- |
| `events` | The one mutable table: a game legitimately moves from scheduled to final |
| `event_external_ids` | Source id to event, which is what keeps a doubleheader from collapsing into one row |

## Odds

| Table | Purpose |
| --- | --- |
| `odds_snapshots` **AO** | One row per quote per pull. The movement of a line over time is the data |
| `event_line_markers` | Derived opening and closing quote per event, book, market, side. Closing is the last quote strictly before kickoff |
| `consensus_prices` **AO** | No-vig fair probability across books at a point in time. The benchmark every model is scored against |

## Stats and features

| Table | Purpose |
| --- | --- |
| `team_game_stats` | Box score per team per game |
| `injury_reports` **AO** | Point-in-time availability |
| `probable_starters` **AO** | Starting pitcher (MLB), starting goalie (NHL) |
| `weather_readings` **AO** | Forecast before, observation after |
| `feature_sets` **AO** | A feature vector plus `max_source_at`, the newest source timestamp that fed it. Must precede kickoff |

## Models

| Table | Purpose |
| --- | --- |
| `model_versions` | Frozen weights, K-factors and calibration maps, versioned |
| `predictions` **AO** | Every prediction, timestamped before kickoff, tied to the exact model version |
| `backtest_runs` | Walk-forward runs with metrics, the market benchmark and a verdict |
| `backtest_predictions` | Per-game backtest rows with closing price and CLV |
| `calibration_bins` | Calibration curve points for plotting |

## Value finder

| Table | Purpose |
| --- | --- |
| `value_scans` | One sweep of the board, so the picks shown are one consistent snapshot |
| `value_bets` **AO** | Every evaluated candidate, pick or pass |
| `arbitrage_opportunities` **AO** | Cross-book arbs |
| `middle_opportunities` **AO** | Spread and total middles |

`value_bets` is shaped around the question "which bets should I place today":
`rank` orders the picks, `suggested_stake_cents` and `suggested_stake_units`
size them, `best_book_id` and `best_price_american` name where to get it,
`break_even_price_american` is the model fair price,
`min_acceptable_price_american` is the worst price still worth taking, `reason`
carries one plain sentence, and anything that is not a pick carries a
`pass_reason` so the board can label it rather than stay silent.

## Bankroll and bets

| Table | Purpose |
| --- | --- |
| `bankroll_settings` **AO** | Versioned settings, so an old stake is still explainable |
| `bet_events` **AO** | The ledger of truth: placed, graded, voided, corrected |
| `bets` | Read model projected from `bet_events` |
| `bankroll_ledger` **AO** | Running balance |
| `loss_limit_periods` | Self-imposed daily and weekly limits. Once locked, never cleared inside the period |

## Operations

| Table | Purpose |
| --- | --- |
| `job_runs` | One row per job, success, skip or failure. No silent failures |
| `api_requests` | One row per outbound call, with provider quota |
| `app_settings` | Small key-value settings |

## Guards

Postgres-only, created by `2026_10_20_000900_add_integrity_guards`:

- `edge_deny_mutation()` trigger on all thirteen append-only tables;
- `predictions.predicted_at < event_starts_at`;
- `predictions.prob` strictly between 0 and 1;
- `feature_sets.max_source_at <= event_starts_at`;
- American prices on `odds_snapshots` and `bets` are `<= -100` or `>= 100`;
- stakes are non-negative.
