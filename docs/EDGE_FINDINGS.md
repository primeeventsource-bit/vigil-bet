# Does this engine have an edge?

Measured 23 October 2026, against closing lines, using the best model the
engine has. The answer is **no** — in every market that can be scored, by
a statistically significant margin.

This document exists so nobody has to rediscover it, and so nobody ships
a pick on the assumption that the gate is being fussy. It is not.

## What was measured

Regularized logistic regression — the engine's better model, not Elo —
walked forward and scored against the closing line each game actually
had. Features available to it: Elo logit, home and away form, margin
form, rest differential, short week, divisional, indoors, retractable.

| market | predictions | AUC | model log loss | market log loss | improvement | t-stat | flat ROI |
|---|---|---|---|---|---|---|---|
| NFL moneyline | 517 | 0.682 | 0.6446 | 0.5912 | −0.0514 | **−4.80** | −7.68% |
| NBA moneyline | 1,334 | 0.718 | 0.6106 | 0.5768 | −0.0231 | **−3.72** | −6.18% |
| NCAAB moneyline | 509 | 0.764 | 0.4780 | 0.3926 | −0.0946 | **−5.09** | −7.95% |
| NBA spreads | 1,333 | 0.502 | 0.7353 | 0.6932 | −0.0421 | **−4.94** | −1.24% |
| NBA totals | — | — | — | — | — | — | refuses to score |

Every market: `NEGATIVE EDGE`. Every t-statistic between −3.7 and −5.1.

Totals is not scored at all, and that is correct: the walk-forward asks
"did the home side win outright", which is the wrong question for a
total, and answering it would report a false edge against a benchmark
that closes near even money. `UnmodelledMarket` throws rather than
returning a number somebody might believe.

## What this means, precisely

**The model is not stupid.** AUC between 0.68 and 0.76 on moneyline is
real predictive power — it is better than a coin flip at picking winners,
and the features it keeps are sensible ones. Elo dominates, recent form
adds, divisional games are closer, rest matters a little.

**It is less informed than the market.** The closing line already
contains injuries, lineups, weather, public money and sharp action. Elo
plus recent form is a strict subset of what the market knows, so it
cannot systematically beat it, and the measurement says it gives up
2–9 points of log loss trying.

**On spreads it has no power at all.** AUC 0.502 is a coin flip, which is
the entire design intent of a point spread. Predicting the side of a
spread means beating the line, not predicting the game.

**More data will not change the sign.** NBA moneyline has 1,334 scored
predictions. That is a real sample, not a thin one, and the result is
−3.72 standard errors. Backfilling more history makes the negative
*more certain*, not less. Anybody hoping volume fixes this should read
the t-statistics again.

## What would actually be required

Beating a closing line consistently is what professional syndicates do,
with alternative data and teams of quantitative researchers. It is not
what Elo plus recent form does, and no amount of engineering around the
edges changes that. The honest options:

1. **Information the market prices slowly.** Injury and lineup news
   before it is public, weather revisions, referee assignments. The
   engine already has `injury_reports`, `probable_starters`,
   `weather_readings`, `team_game_stats` and `feature_sets` tables that
   the current model does not read. That is the cheapest unexplored
   avenue and it is still a long shot against a liquid market.
2. **Markets that are genuinely soft.** Player props, small leagues,
   in-play. Props are already ingested, deliberately isolated from the
   model pipeline by the schema. Softness is where edges live.
3. **Predict the line, not the game.** Forecasting line *movement* is a
   different and more tractable problem than forecasting outcomes, and
   it is what closing line value actually measures.
4. **The things that need no model at all.** Line shopping, arbitrage
   and middles are arithmetic on prices from different books. They are
   real, checkable value, they are already built, and they do not depend
   on any of the above.

## The part that is working

Vigil's gate has been refusing to sell from these markets the whole
time, for exactly the right reason, and saying so on the page. That is
the product behaving as specified: it only sells what has been proven,
and nothing has been proven.

A version of this product that showed picks today would be selling bets
that lose roughly seven percent flat. The gate is not an obstacle to
shipping. It is the only reason this is worth shipping at all.

---

## Re-measured after fixing the data, 24 October 2026

Three defects were found and fixed between the first measurement and
this one. None of them changed the answer.

**What was fixed**

1. `MarkClosingLines` ran one `updateOrCreate` per book, market and side
   — about three hundred queries a game — and died on max attempts
   whenever given a real window. Now one chunked upsert.
2. Venue coordinates were null for all 1,234 venues, which silently
   broke weather ingestion (Open-Meteo has nowhere to point) and made
   every place-based feature impossible. `edge:venues:locate` existed
   and had never been run; running it located 501 venues, including
   817 of 817 scoreable NFL games and 1,342 NBA.
3. **Rest days arrived only from the NFL feed.** Every other sport had
   null for every game, so `rest_diff`, `home_short_week` and
   `away_short_week` were constant zero and the regularizer dropped
   them. The backtest printed `rest_diff +0.0000`, which reads as "no
   signal" and meant "no data". Now derived from the schedule:
   37,042 figures across five sports.

**What changed in the models**

The rest features are real and the model uses them. In NBA they rank
fifth, sixth and seventh by weight, above recent form:

    home_short_week  +0.1576   (was +0.0000)
    rest_diff        +0.1275   (was +0.0000)
    away_short_week  -0.1032   (was +0.0000)

Calibration improved materially. NBA's calibration error fell from
0.0249 to 0.0184 against a market at 0.0195 — the model is now as well
calibrated as the closing line, which it was not before.

**What did not change**

| market | predictions | improvement | t-stat | flat ROI | before |
|---|---|---|---|---|---|
| NBA moneyline | 1,340 | −0.0233 | −3.72 | −6.48% | t −3.72 |
| NCAAB moneyline | 509 | −0.0835 | −4.67 | −7.95% | t −5.09 |
| NHL moneyline | 333 | −0.0163 | −2.16 | −1.13% | not measured |

Every market still `NEGATIVE EDGE`. NBA did not move at all, which is
the most informative result here: **the market already prices
back-to-backs.** That is unsurprising — it is the most worked angle in
basketball — but it had never actually been measured on this data
before, because the data was not there to measure.

NCAAB improved slightly and remains decisively negative.

**The one worth watching**

NHL is the closest to break-even anything has been: t-stat −2.16, flat
ROI −1.13%, and a calibration error of 0.0115 that is better than the
market's. It is also the thinnest sample — 333 paired games, from 358
games with odds out of 2,222 settled. That is the market where more odds
coverage would most change the answer, in either direction, and it is
the one to re-measure once the historical backfill finishes.

Note that NHL's headline log loss (0.6809) is *lower* than the market's
(0.6943). That is not an edge: the headline figure is over all 1,610
scored predictions while the comparison is paired on the 333 that have a
closing line. Reading the two as comparable would manufacture an edge
out of two different samples.

---

## The coverage ceiling was a default, not the data

**24 October 2026.** Earlier notes in this file treat the odds coverage gap
as a property of what is obtainable. It is not. It was a flag I did not
pass.

`edge:odds:backfill` documents its own default plainly:

    {--from= : Start of the window, any parseable date. Defaults to one year ago}

Both backfills were run without `--from`, so both bought the last twelve
months and nothing earlier. Coverage by season says exactly that:

| year | settled games | with odds | coverage |
|---|---|---|---|
| 2023 | 4,778 | 255 | 5.3% |
| 2024 | 7,908 | 273 | 3.5% |
| 2025 | 6,950 | 1,966 | 28.3% |
| 2026 | 1,234 | 970 | 78.6% |

The second backfill added 2.4 million snapshots and only 364 new games
with any odds, which was recorded here as "depth rather than breadth" and
read as a limit of the feed. It was a limit of the window: the extra
snapshots were more line movement on games already inside the last year.

**What the rest is worth.** `--estimate` prices the missing window without
spending anything:

| sport | requests | credits |
|---|---|---|
| NBA | 1,068 | 64,080 |
| MLB | 1,849 | 110,940 |
| NHL | 1,033 | 61,980 |
| NCAAF | 820 | 49,200 |
| NCAAB | 143 | 8,580 |
| **total** | **4,913** | **294,780** |

294,780 credits against 22,308,998 spendable this month: **1.3% of the
quota**, well inside the per-sport ceiling of 5.6 million. The command is:

    php artisan edge:odds:backfill all --from=2023-01-01 --to=2025-10-01

**Why it is worth more than its price.** Every negative verdict in this
file rests on a paired sample bounded by closing-line coverage - NHL on
333 games, NCAAB on 509, NBA on 1,340. Those samples are small because
the odds are only there for one season, not because three years of games
do not exist: 20,852 settled games are in the schedule and 3,462 of them
have a price. This window is the difference between measuring the model on
one season and measuring it on four.

It does not make the model better. It makes the measurement believable,
and a t-statistic of -2.16 on 333 games is exactly the figure that most
deserves a larger sample before anyone trusts it in either direction.

**Two things to do first, in order.**

1. **Deploy the closing-line fix.** More snapshots per event makes the
   currently deployed version of `MarkClosingLines` worse, and it is
   already failing every run - `odds:close` failed 28 times in the last 24
   hours. Buying history before deploying the fix would add load to the
   one job that cannot currently carry it.
2. **Find out why 15 `BackfillSeasonChunk` jobs died on max attempts.**
   That is the schedule-and-results backfill rather than the odds one, so
   it does not block this, but a run of 4,913 jobs is a poor moment to
   discover a failure mode that already showed up fifteen times.

**One caveat on what the purchase buys.** The historical endpoint returns
the board as it stood at a timestamp, and `timestampsFor` samples the
window in buckets - about two requests a day for MLB across 2.75 years.
So a closing line bought this way is the last quote inside its bucket
rather than the last quote before kickoff. `closed_at` records which, so
the gap is visible per row rather than assumed away, but a CLV figure
computed against a bucketed close is slightly noisier than one computed
against a true close. It is still the right benchmark; it is not quite the
same benchmark as the live-pulled seasons, and a measurement that mixes
the two should say so.
