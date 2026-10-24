# Parlay Intelligence Engine — NOT BUILT (superseded)

> **Status: descoped.** The decision is to bet singles only and combine
> selections by hand on the ticket. No parlay tables, combinatorics,
> correlation model or SGP handling will be built. This document is kept for
> the three findings in section 2, which still apply to a hand-built ticket,
> and so the reasoning is on record if it is ever revisited.
>
> **The finding that matters most if you are combining picks yourself is 2.3:
> putting three picks on one ticket instead of three separate tickets roughly
> halves long-run growth, with the same legs, the same edge and the same
> bankroll.**

---

Written before any code changes, as requested. Nothing existing is rebuilt or
overwritten: this is additive.

## 1. What exists today

| | |
| --- | --- |
| Framework | Laravel 12.70, PHP floor 8.3 (CI runs 8.3 and 8.4) |
| Frontend | Livewire 3.6 + Volt, Blade, Tailwind 3.4, Vite 7 |
| Tests | Pest 4, 34 files, 256 tests / 745 assertions, CI green on SQLite and Postgres |
| Database | 44 tables, 13 append-only with Postgres triggers |
| Routes | 5 only. No parlay surface yet |
| App code | 36 models, 20 enums, 20 services, 18 modelling classes, 8 commands, 7 jobs |
| Auth | Single admin, registration deleted, seeded from env |

Directly reusable: odds ingestion and snapshots, team resolution, closing-line
marking, the odds maths, Kelly staking with caps and loss limits, Hard Rock as
the pricing book, Elo and the per-sport pricers, calibration, and the metrics
used for scoring.

## 2. Three constraints that shape the design

### 2.1 The provider has no parlay prices

Verified against The Odds API market documentation: single selections only -
h2h, spreads, totals, alternates, team totals, player props, period markets.
**There is no parlay or same-game-parlay endpoint.**

That splits the product in two:

- **Cross-game parlays.** Books price these by multiplying the leg decimals,
  so the offered price is computable from data already held. These can be
  priced end to end with no new feed.
- **Same-game parlays.** Books apply a correlation adjustment, and the result
  is not derivable from the legs. Per the spec these must never be fabricated
  by multiplication, so the offered SGP price has to be **entered by hand from
  the bet slip**. Until it is, the parlay is UNVERIFIED and cannot be
  recommended.

### 2.2 Parlays multiply whatever edge you have, in both directions

Computed with the odds maths already in the app, legs at -110:

| Legs | No edge (true 50%) | Real +3% edge per leg |
| --- | --- | --- |
| 1 | -4.55% | +5.73% |
| 2 | -8.88% | +11.78% |
| 3 | -13.03% | +18.18% |
| 4 | -16.98% | +24.94% |
| 5 | -20.75% | +32.10% |

Parlay EV is the product of (1 + edge) across legs, minus one. So a parlay is
not inherently a bad bet: it is a **leverage multiplier**. With genuinely +EV
legs the EV compounds upward; with -EV legs the vig compounds just as fast.
The folk wisdom that parlays are always bad is really the observation that
most legs are -EV.

### 2.3 EV is not growth, and that is where parlays actually lose

Three legs each carrying a real +3% edge, staked at quarter Kelly:

```
3 separate bets:    stake 1.57% each,  expected log growth 0.002368
1 three-leg parlay: stake 0.76%,       expected log growth 0.001192
                    singles are 1.99x the growth of the parlay
```

Same legs, same edge, same bankroll. The parlay shows the higher EV percentage
and delivers **half the long-run growth**, because variance rises faster than
return and correct Kelly sizing collapses to compensate.

So the most useful output of this engine is not a parlay. It is the
comparison. Every recommended parlay will carry a line stating what the same
legs bet separately would be worth, and will say plainly when separates win.

## 3. Sequencing

The engine consumes model probabilities. There is still no trained model and
no backtest verdict, so **every parlay would be UNVERIFIED today** - which is
what the spec demands when evidence is missing, but also means a dashboard
built first would be a dashboard of nothing.

1. **Stage 0** - finish Phase 2: trainer, walk-forward backtest, CLV, the
   proven/unproven/negative verdict. Without it nothing can ever be a BET.
2. **Stage 1** - parlay domain: schema, combinatorics, independent pricing,
   the singles-versus-parlay comparison.
3. **Stage 2** - correlation: estimation, storage, SGP with manual price entry.
4. **Stage 3** - new sports: soccer (three-way, draws) and tennis.
5. **Stage 4** - the Parlay Command Center UI.
6. **Stage 5** - audit logging and the security items.

Stages 1 and 2 can start before Stage 0 finishes. They simply cannot produce a
recommendation until it does.

## 4. File-by-file plan

### Stage 1 - parlay domain

**Migrations** (forward-only, additive)

- `..._create_parlay_tables.php` - `parlays`, `parlay_legs`
- `..._create_parlay_recommendation_tables.php` - `parlay_scans`,
  `parlay_recommendations`
- `..._create_correlation_tables.php` - `correlation_estimates`
- `..._create_wager_tables.php` - `wagers`, `wager_legs`, `wager_events`
- `..._add_parlay_guards.php` - append-only triggers and check constraints for
  the new history tables, matching the existing pattern

**Schema outline**

- `parlays` **[append-only]** - sport_scope, leg_count, joint_probability,
  independent_probability, correlation_adjustment, fair_decimal,
  offered_decimal, offered_source (computed/manual/provider), ev_per_dollar,
  edge, verdict, is_same_game, built_at
- `parlay_legs` - parlay_id, event_id, market, side, line, prediction_id,
  model_version_id, leg_probability, leg_decimal, book_id, sequence
- `parlay_scans` - one sweep, mirroring `value_scans`
- `parlay_recommendations` **[append-only]** - parlay_id, scan_id,
  recommendation, probability_rank, ev_rank, suggested_stake_cents,
  parlay_growth, singles_growth, prefer_singles, pass_reason, reason
- `correlation_estimates` **[append-only, versioned]** - sport, market_a,
  market_b, context, rho, method, sample_size, computed_at, trained_through_at
- `wagers`, `wager_legs`, `wager_events` - recorded bets, mirroring the
  existing append-only `bets` / `bet_events` projection pattern

**Models** - `Parlay`, `ParlayLeg`, `ParlayScan`, `ParlayRecommendation`,
`CorrelationEstimate`, `Wager`, `WagerLeg`, `WagerEvent`, reusing the existing
`AppendOnly` trait wherever the table is history.

**Enums** - `ParlayType` (cross_game / same_game), `OfferedPriceSource`
(computed / manual / provider), `ParlayVerdict` (bet / unverified / no_bet),
plus new `PassReason` cases: `sgp_price_not_supplied`, `leg_unproven`,
`prefer_singles`, `correlation_unknown`.

**Modelling** in `app/Modeling/Parlay/`

- `ParlayCombinator.php` - candidate combinations from the qualifying leg
  pool, bounded by leg count and a hard cap, excluding mutually exclusive legs
- `JointProbability.php` - independent product, plus a Gaussian-copula
  adjustment when a correlation estimate exists
- `ParlayPricer.php` - fair decimal, offered decimal, EV, edge, break-even
- `SinglesComparison.php` - expected log growth of the parlay against the same
  legs staked separately; produces `prefer_singles`
- `ParlayKelly.php` - Kelly on the joint outcome, then the existing caps,
  floor and loss limits applied unchanged

**Services** - `Services/Parlay/ParlayScanner.php` orchestrates a scan;
`Services/Parlay/SgpPriceEntry.php` records a hand-entered bet-slip price with
provenance.

**Jobs** - `Jobs/Parlay/ScanParlays.php`, `GradeWagers.php`,
`EstimateCorrelations.php`, all using `TracksJobRun`.

**Commands** - `edge:parlay:scan`, `edge:parlay:preview`.

**Tests** - combinatorics and exclusion rules; the EV table above pinned as a
test; the singles-versus-parlay growth comparison; SGP refusing to price
without a supplied price; Kelly caps honoured on joint outcomes; append-only
enforcement on the new history tables.

### Stage 2 - correlation

- `CorrelationEstimator.php` - empirical correlation between leg outcomes from
  settled history, by sport and market pair
- `GaussianCopula.php` - joint probability under a correlation matrix
- Tests: positively correlated legs must price **above** the independent
  product and negatively correlated **below**; an unknown pair falls back to
  independence **and** marks the parlay `correlation_unknown` rather than
  silently assuming it

### Stage 3 - soccer and tennis

Config plus adapter only, as the design intends:

- `config/sports.php` - soccer (three-way, draws, `h2h_3_way`) and tennis
- `app/Enums/Market.php` - add `ThreeWay`
- ESPN already covers both; add the paths and verify live as was done for the
  first six sports
- Tests: a three-way market sums to one across home/draw/away, and the
  existing two-way assumptions do not leak into it

### Stage 4 - Parlay Command Center

`app/Livewire/Parlay/` - `CommandCenter`, `TopProbability`, `BestEv`,
`BestOdds`, `SgpAnalyzer`, `CustomBuilder`, `Performance`, `RiskManager`, with
views under `resources/views/livewire/parlay/`. Built on the existing
`x-brand-styles` tokens, filterable by sport, date, book, leg count,
probability and EV.

Every card shows, without exception: joint win probability, wins about X out
of 10, fair versus offered price, EV, the stake, the singles comparison, and a
DEMO badge while fixture data is in use.

### Stage 5 - audit and security

- `..._create_audit_events.php` and `Services/Ops/AuditLogger.php`, append-only
- Middleware asserting auth on every parlay route
- Credentials stay in env; nothing new is written in plain text

## 5. What this will honestly show

Until Stage 0 lands and a market is proven, every parlay renders UNVERIFIED
with the reason stated. Once models are proven, a parlay becomes BET only when
its legs are individually proven, a price exists at Hard Rock, and the EV
clears the threshold - and even then it carries the line saying whether
betting the legs separately is the better use of the same bankroll.

No screen will show a guaranteed win, and no SGP will ever be priced by
multiplication.
