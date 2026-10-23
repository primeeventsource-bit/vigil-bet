# Decisions

Choices that were genuinely open, and what was settled. Each one records
the reasoning as well as the answer, because a decision without its
reasoning gets re-litigated the first time it is inconvenient.

---

## The modelling stays in PHP. There is no Python service.

**Settled 2026-10-23.**

The autonomous-engine brief asked for "a separate Python machine-learning
service for training and inference, deployed on infrastructure appropriate
for its compute requirements". That is the one point in it that conflicts
with what exists, and the answer is: keep PHP.

What that settles:

- Edge Lab stays 100% PHP. Every predictor, the walk-forward backtester,
  the calibration maths and the verdict gate stay in `app/Modeling/`.
- Training and inference run as Laravel queue jobs on Laravel Cloud
  workers. No second runtime, no model-artifact transfer protocol, no
  service to keep in sync with the schema it reads.
- Vigil is unaffected either way — it reads verdicts over the internal
  API and does no modelling of its own.

The argument for Python is real and worth stating rather than waving
away: scikit-learn, LightGBM and the rest are better tools for this than
anything in PHP, and nobody writes isotonic regression by hand for fun.

The argument against is that it buys a second deployment target, a second
set of credentials, a serialisation boundary between the model and the
data it was trained on, and a new way for the two halves to disagree
about what a feature means — in exchange for algorithms that are, for
this problem, a few hundred lines of arithmetic. Laravel Cloud runs PHP;
a Python service runs somewhere else, and "somewhere else" is where the
operational cost lives.

The present evidence supports the cheaper answer. The engine is not
failing for want of a better optimiser: it has 14 model versions and zero
proven markets, and the verdicts say why — *insufficient data*, *no
closing lines to measure against*, *12 of 300 graded predictions needed*.
Those are data problems. A Python service would not have produced a
single additional proven market, and would have been blamed for none of
the failures either.

Revisit if, and only if, a market is clearing the gate and the ceiling is
plainly the model rather than the data.

---

## Two read-only endpoints would finish the Learning Center

**Open. Needs a change in Edge Lab, which is not this repository.**

`/learning` reports what it can read, and two of its most useful panels
cannot be built against the current contract. Edge Lab already stores
both; `/internal/v1` does not expose either.

**`GET /internal/v1/calibration/{model}`** — the rows of
`calibration_bins` for a model version: `bin_index`, `p_low`, `p_high`,
`n`, `mean_predicted`, `observed_rate`.

That is the calibration *curve*, and it is the only honest way to answer
"when it says 70%, does it happen 70% of the time". The page currently
shows a single calibration error per model, which is a summary number
that can hide a model confident in exactly the wrong places. The sample
per bin matters as much as the position — a bin holding seven games can
sit far off the diagonal and mean nothing.

**`GET /internal/v1/backtest/{run}/predictions`** — graded predictions
from `backtest_predictions`: `prob`, `market_fair_prob`,
`price_at_predict_american`, `closing_price_american`, `result`,
`pnl_flat_cents`, `clv_prob_delta`.

That gives win/loss/push, ROI at the prices actually recorded, and
closing line value per market — the figures the brief asks for, which
Vigil must not compute for itself.

Both are reads over append-only tables, paginated by the same `since`
cursor as everything else, and neither weakens the licence boundary: no
bookmaker prices leave in bulk, only the engine's own graded output.

---

## Judgement calls made without blocking

Recorded because each was a choice, and each is cheap to reverse.

- **Cadence.** The spec's §3 calls the new cadence a tightening, and its
  numbers loosen the last hour before kickoff from one minute to two. The
  numbers were followed over the adjective. One line in Edge Lab's
  `config/edge.php`.
- **`POST /parlay/evaluate` stores nothing.** §2 says "returns its stored
  result" and also "no write endpoints". It uses the evaluator's preview,
  so a remote slip rebuilt on every keystroke does not bury the parlays
  somebody actually weighed. Every number still comes from the engine's
  own judge.
- **"Barlays" and `#1E329B`.** The Dark Terminal mockup's nav reads
  "Barlays" and its swatch list gives Dark Slate as `#1E329B`, a
  saturated blue that does not match the grey in the artwork. Built as
  "Parlays" and `#1E293B`. Confirmed 2026-10-23.
- **The design's four collisions with §0.** Resolved in
  [BRAND.md](BRAND.md) and [STATUS.md](STATUS.md): the AI naming stands,
  the Bet button logs rather than places, "confidence" is the stored
  model probability, and the rate tiles show nothing until there is a
  sample behind them.
