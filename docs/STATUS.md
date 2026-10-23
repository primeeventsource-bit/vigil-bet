# Status

Progress against the build order in [MASTER_PROMPT.md](MASTER_PROMPT.md) §12.
One entry per step, written when the step ends with its tests passing.

---

## Step 1 — Edge Lab internal API, quota, cadence, props, backfill

**Done. 1,149 tests passing in `edge-lab` (baseline before this work: 1,072).**

All of this landed in the Edge Lab repo, not here.

**Quota and cadence.** `ODDS_API_MONTHLY_QUOTA` is 25,000,000. Cadence moved
to every two minutes inside an hour of kickoff, quarter-hourly inside twelve
hours, hourly inside a week — exactly the table in §3.

Note for the record: §3 calls this "tighten", and inside the last hour it is
a loosening, from every minute to every two. The spec's numbers were followed
over the spec's adjective, because the numbers are unambiguous and the
adjective is not. Reverting is one line in `config/edge.php`.

**Credits, not calls.** The budget was counted in requests, which was true
while every request cost one credit and stopped being true here: a props call
costs a credit per market per region returned, a historical call costs ten.
The provider reports the price of every call in `x-requests-last` and nothing
read it. It is now stored in `api_requests.credits_cost`, and `edge:doctor`
reports credits rather than a proxy for them.

**Player props.** NBA points, rebounds, assists, threes; NFL passing,
rushing and receiving yards, anytime touchdown. Pulled per game from the
per-event endpoint on their own looser cadence, into the same append-only
`odds_snapshots` table.

A prop row carries a raw `market_key` and a null `market`; a core row carries
`market` and a null `market_key`; Postgres has a check constraint saying
exactly one is set. That is what makes §0.3 structural rather than a
convention: every reader that feeds a prediction, a verdict or a stake asks
for a core market by name, so a prop cannot reach one by accident. Props are
display and line shopping only until a props model passes the gate.

**Historical backfill.** `edge:odds:backfill {sport|all}` buys closing lines
for NBA, MLB, NHL, NCAAF and NCAAB — the five with no free line history. NFL
stays on the free nflverse import.

The guard is the point: ten credits per market per region means a season is a
five-figure bill before anyone looks at it, so the command prices the job
first, refuses when the estimate exceeds a configurable share of the credits
still spendable this month, and has `--estimate` to see the bill without
paying it. Kickoffs within fifteen minutes share one request, since one
request returns the whole sport as at one moment.

**The internal API.** `/internal/v1`, bearer token compared with
`hash_equals`, optional IP allowlist, rate limited, every call logged to the
append-only `internal_requests` table with a named reason on refusal.

Seven routes: `board`, `events`, `events/{event}`, `odds/{event}`,
`predictions/{event}`, `models`, and `POST parlay/evaluate`. Paged by a
`since` cursor. See `edge-lab/docs/INTERNAL_API.md`.

Two spec rules are enforced by the shape of the API rather than by a comment,
and both have tests that assert the shape rather than one handler:

- **No write endpoints.** The single POST runs the parlay judge and stores
  nothing — it uses the evaluator's `preview`, not its `evaluate`. §2 says
  "returns its stored result"; `evaluate` appends a row, which is right when
  Edge Lab weighs a slip somebody considered and wrong for a remote slip
  rebuilt on every keystroke. Those writes would bury the parlays genuinely
  weighed under every intermediate state of building one, and §2 also says no
  write endpoints. Every number still comes from Edge Lab's judge. **Say so
  if you want it writing a row per evaluation instead.**
- **No bulk odds (§0.5).** Prices come one event at a time from one route.
  `/board` carries the single best price behind a pick, not the cross-book
  picture for the slate — `line_shopped` is deliberately absent.

**Also fixed:** two `CommandCenterTest` tests scheduled a game five hours out
and asserted it grouped under "Today". Run after seven in the evening Eastern
those five hours cross midnight and the tests fail on a product that works.
The clock is pinned in both now.

### Not decided here, waiting on you

Nothing in §3 or §2 was marked as a decision, so nothing blocked. Two
judgement calls are flagged above (the cadence adjective, and the parlay
endpoint storing nothing) and both are cheap to reverse.

---

## Step 2 — Vigil skeleton

**Done. 86 tests passing, Pint clean.**

Laravel 12, Livewire 3, Tailwind 4, Pest 4 — the same stack as Edge Lab, so
code and habits transfer.

**Brand in one place.** `config/brand.php` holds the name, tagline, palette
and logo paths; `config/vigil.php` holds the compliance facts (minimum age,
the responsible-gambling line, the self-exclusion periods). The palette
crosses from PHP into CSS in exactly one file — `partials/head` writes it as
custom properties — so `BrandingTest` can assert that **no template
hardcodes the product name or a hex colour**, which is what makes §13's
"changing BRAND_NAME renames the product everywhere visible" true rather
than merely intended.

**The age gate** runs inside registration, in the same request and the same
transaction as the account. A flow that creates the user first and asks
their age on the next screen has a window in which an under-age account
exists and is signed in. It stores the birth **year** and the threshold in
force, never the date of birth — a full DOB is identity-document material
with no use here. A refusal writes a row too, so "we check" is a record
rather than a claim. The boundary is conservative and tested both sides: one
day short is refused, the birthday itself is admitted.

**2FA** is RFC 6238 written out rather than pulled in, checked against the
RFC's own test vectors. Codes compared with `hash_equals`, one step either
side of now, and a spent timestep cannot be replayed inside its own thirty
seconds. Enrolment is two steps, so nobody switches 2FA on against a secret
they never scanned. Nothing is signed in until both factors pass — the
half-finished login is a session key with a five minute life, never a
logged-in user carrying a flag.

**Legal pages** — terms, privacy, responsible gambling, disclaimer — written
plainly and carrying the real commitments: no refunds on results, no
redistribution of odds, self-exclusion that cannot be undone early, and the
helpline line from config on every page.

**Marketing pages** are indexable Blade with no Livewire. App pages are
`noindex` by default: the head partial defaults to noindex so a page that
forgets the flag fails safe, and there is a test per app route.

`/pricing` and `/record` are honest placeholders that say which build step
fills them. `/record` in particular shows no rate at all, because there are
no settled picks — which is the §7 behaviour, not a stub.

### Carried into later steps

The Dark Terminal mockup asks for four things §0 rules out, all of which
land in later steps rather than this one. They are written up in
[BRAND.md](BRAND.md) and still need your answer before the screens that use
them get built:

1. "AI-Driven Edge" / an "AI Picks" nav item vs §0.4 — the AI explains
   numbers, it never produces them. It is currently the default
   `BRAND_TAGLINE`, which is one environment variable to change.
2. A green "Bet" button vs §0.1 and §9.
3. A "confidence" score vs §0.2 — Edge Lab stores model probability, not
   confidence.
4. A headline win rate and ROI with no sample size vs §0.7 and §7.

---

## Step 3 — Edge Lab sync jobs and read models

**Done. 119 tests passing, Pint clean.**

Correcting what this file said before: this step was never blocked on a
live token. The client is built against the contract in
`edge-lab/docs/INTERNAL_API.md` and tested with HTTP fakes, the same way
Edge Lab tests its own ingestion. Only real data needs the token.

**`EdgeLabClient`** is the one way Vigil reads the engine. Timeouts,
exponential backoff, and a retry predicate that only retries what might
answer differently: a 401 or a 404 is a disagreement about the request and
will say the same thing on the fourth attempt, while spending the
per-minute allowance the sync needs once the token is fixed.

**The circuit breaker** stops one Edge Lab outage becoming two. Five
consecutive failures and it opens; calls then fail fast without leaving
the building until a cooldown lets exactly one through to find out whether
it is back. A single success closes it — an upstream answering one call in
three is unwell but reachable, and retries are the right answer to that.

**Four append-only mirrors** — `vl_events`, `vl_board_entries`,
`vl_odds_display`, `vl_models` — keyed by Edge Lab id. Including events,
which Edge Lab itself treats as its one mutable table. Vigil writes a new
row per observed state instead and derives current state from the newest
row, which costs a subquery and buys the thing the product is sold on:
what the engine believed when a pick was published is still on disk and
cannot be rewritten by a later sync.

Each row carries a digest of the payload and a sync writes only when
something actually changed, so an hourly tick against a quiet Tuesday adds
nothing rather than a fresh copy of the board.

**`sync_runs`** records every attempt, success, failure or skip, and holds
the cursor. The cursor advances only on success — these feeds are
append-only and a row stepped over is a row Vigil never sees again.

**Models are re-read in full every time, never resumed.** The other two
feeds are append-only upstream so an id already seen cannot change; a model
version is not — its verdict is rewritten when it is retrained. A keyset
cursor would walk straight past that and leave Vigil selling against a
verdict that had since been withdrawn.

**The verdict gate is enforced on this side too.** `sellablePicks()`
requires both `recommendation = bet` and `market_verdict = proven`.
Checking only the recommendation is the bug that would sell an unproven
pick, and there is a test per verdict value proving it does not.

**"Data delayed"** is real, not decorative. `SyncHealth` knows the age of
the last good sync per feed, treats never-synced as stale, and ignores
failed runs when deciding freshness — a failure that reset the clock would
be a board claiming to be current because something tried and failed a
moment ago. The notice is scoped to the feeds a page renders from, so the
daily card is not warned about the schedule being a day behind. Sync health
lives at `/sync-health` and moves under admin at step 11.

### Three bugs the tests found, worth recording

1. **The cursor was read from `meta.next_since` alone.** That is null once
   the server is caught up, so a sync that finished never recorded where
   it got to — and the next one would re-read the entire history, every
   hour, forever. The cursor now comes from the rows.
2. **`Http::fake()` merges stubs rather than replacing them**, and the
   first match wins. Two tests were silently still being answered by their
   first fake. Both now use one fake over a mutable list.
3. **The breaker captured its settings in the constructor** and is a
   singleton, so raising a threshold during an incident would have had no
   effect on the object deciding. It reads config when it uses it, and uses
   the Carbon clock rather than `time()` so it is testable and consistent.

### To go live

Set `EDGE_LAB_TOKEN` on Vigil to Edge Lab's `VIGIL_INTERNAL_TOKEN`, and
`EDGE_LAB_URL` if the engine is not at `parlayq.com`. Until then every sync
records a skip and the UI says live data is not connected, which is the
honest state rather than an empty board.

---

## Step 4 — the Dark Terminal dashboard

**Done. 129 tests passing, Pint clean.**

The dashboard is built to the mockup: sidebar, search bar and live badge,
the hero, four headline tiles, Top AI Picks with confidence bars and a Bet
button, Bankroll, Live Odds Movement, Model Confidence, Parlay Builder, and
the phone card with "Why this pick?" and Add to Bet Slip. Charts are inline
SVG - a line, a ring and a bar are arithmetic, and a charting dependency on
a page this simple costs more than it saves.

### The four §0 collisions, and how each is resolved

You reaffirmed the design, so it is built as drawn. What each one does
underneath:

1. **"AI-Driven Edge" and the "AI Picks" nav.** As drawn. §0.4 still holds
   in the code: the LLM explains stored numbers and produces none of them.
2. **The green Bet button.** Looks identical; it logs the bet to My Bets.
   It cannot place one - there is no sportsbook, no wallet and no money in
   this product, and `BetController` re-checks the verdict gate on the way
   in because a board entry id is a number anybody can type.
3. **"Confidence".** Wired to `model_prob`, the probability Edge Lab
   stored. The design's label over a real number rather than a second
   number invented to fill the tile.
4. **Win Rate, ROI, Units.** The exact tiles, fed from settled picks.
   They render `—` with `n = 0` until there are any, because §0.7 and §7
   forbid a rate without its sample. There is a test.

### Honest empty states, everywhere a number is missing

With no engine connected and nothing proven, the real dashboard is mostly
empty: no picks, no bankroll, no movement. Each panel says which step fills
it rather than showing a plausible figure. `php artisan vigil:demo` seeds
invented rows for design review and **refuses to run in production** - a
fabricated pick and a fabricated track record are precisely what the
product promises never to show.

### Bugs found by looking at it rather than by testing it

- The login card was wider than a phone (`max-w-md` on a 390px screen).
- The dashboard dragged the page sideways on mobile. The cause was not
  obvious and was worth measuring rather than guessing: an implicit grid
  track is `auto`, whose minimum is its item's min-content, so the 506px
  picks table sized the whole column. `minmax(0,1fr)` caps it.
- The real fix for the table was the design's own: a **card list on a
  phone, the table from `md` up**. Six columns of numbers at 390px is
  either a scrollbar nobody finds or a column of two-character words.

### One note on the mockup

Its nav reads "Barlays" and its swatch list gives Dark Slate as `#1E329B`,
a saturated blue that does not match the grey in the artwork. Built as
"Parlays" and `#1E293B`. Say if either was deliberate.

---

## Marketing site — the new-age technology look

**Done. 129 tests passing, Pint clean.**

The public pages were the plain ones; all the design attention had gone to
the dashboard. Rebuilt around a small set of effect primitives in
`app.css`: a soft lamp behind sections, an engineering grid that fades as
it rises, gradient-bordered panels that lift on hover, frosted glass, a
gradient headline, and a pulse on the live dot.

Every one of them is decoration and says so. Nothing a reader needs is
encoded in a glow, and all of it degrades to a plain dark panel when the
gradient is unsupported or motion is reduced.

**The hero trace is marked `aria-hidden` and drawn with no axis, no scale
and no numbers** — deliberately. A chart on a marketing page that looks
like results, before there are any results, is the exact thing §0.7 rules
out. It is a texture, drawn to look like one.

**`config/plans.php`** now holds the tiers from §4 — prices in integer
cents, features, limits — so the pricing page and the enforcement that
arrives at step 5 read from one source. `:brand` in any string resolves
to `config(brand.name)` at render time; a tier written out as "Vigil
Pro" would have survived a rebrand and quietly kept saying the old name.

Nothing is on sale. The paid cards say so rather than taking a card for a
product that cannot yet deliver what is being charged for, and the record
strip shows no rate at all because nothing has settled.

Also restyled: pricing, how-it-works, the record page, and a FAQ built on
native `<details>` so it works without JavaScript.

---

## Step 7 — the Vigil Read (Claude)

**Done. 151 tests passing, Pint clean.**

The AI layer, built to §6. It explains numbers and produces none of them.

**The fact sheet** is assembled only from mirrored rows — probability,
fair price, edge, verdict, price, book — in both decimal and written form.
Nothing is computed into it. It carries `injuries_modelled: false`, so a
Read cannot imply an injury moved a number that no feature reads.

**The validator is the component the feature rests on.** It pulls every
numeric token out of the prose and every numeric value out of the sheet,
and rejects the Read if the first set is not contained in the second. It
cannot judge whether a sentence reasons well; it can tell whether the
figures in it were given. A model writing about probabilities will
occasionally produce a number that is plausible, well-placed and wrong,
and in a betting product that is not a typo — it is the thing the customer
acted on. Bare integers under 100 pass as prose; anything with a decimal,
a percent, a sign or three digits must be supported.

**On failure it regenerates once with the reason named, then falls back**
to a templated explanation assembled by concatenation, which cannot invent
anything because there is no code path by which it could. An unvalidated
Read is never stored and never shown. The UI labels which one a customer
is reading.

`ai_usage` records every call **including the rejected ones** — a Read
that was regenerated cost twice and shipped once, and a month of that is a
prompt problem arriving as a bill.

### One design change the tests forced

The Anthropic SDK speaks PSR-18 through discovery and never touches
Laravel's HTTP client, so `Http::fake()` could not reach it — a test that
faked the network would have passed while the real call went out. The SDK
now sits behind a `Claude` interface that Vigil owns. One class imports
the SDK; everything above it deals in a prompt and a reply.

### To turn it on

Set `ANTHROPIC_API_KEY`. Without it the product still writes Reads, from
the template, and says so on each one.

---

## Engine spec — mostly already built, in Edge Lab

The autonomous prediction-and-learning engine described on 2026-10-23 is
largely Edge Lab, which already does: historical ingestion with
leakage-safe snapshots, per-sport models, append-only predictions
timestamped before kickoff, automatic settlement, walk-forward backtests,
log loss / Brier / calibration, closing-line value, fractional Kelly with
loss limits, and model versions promoted only through a statistical gate.

The live engine proves the gate works: **14 model versions, zero proven** —
8 insufficient data, 4 negative, 2 unproven. It is refusing to promote
models that do not beat the closing line, which is the self-improving
behaviour working rather than a gap in it.

Since built: the **AI Learning Center**, in its own section below.

The Python-vs-PHP question is settled: **PHP stays**, and the modelling
remains in Edge Lab — see [DECISIONS.md](DECISIONS.md) for the reasoning.

Still missing: a **live-betting path**, and two read-only Edge Lab
endpoints for the calibration curve and per-prediction results. Both are
specified in DECISIONS.md.

---

## AI Learning Center

**Done. 160 tests passing, Pint clean.** At `/learning`.

The page answers one question the rest of the product cannot: **is the
engine getting better?** A verdict says whether a market may sell today; a
trajectory says whether the work is going anywhere, and right now those
have different answers.

Everything is read from model versions Edge Lab wrote. Nothing is scored
or re-derived here — a second opinion about whether a model improved is
exactly the kind of number that would make this page reassuring and wrong.

**The trajectory panel** plots log loss improvement against the closing
line, version by version, with zero — the market — as a labelled midpoint.
Above it beats the market; below it loses. Live data shows the real story:

- **NFL moneyline**: −0.051 → −0.034. Still losing to the closing line,
  but by less. Marked *improving*, verdict still `negative`.
- **NFL spreads**: +0.074 → −0.028. The first version beat the market on
  log loss and failed on calibration; the second lost outright. Marked
  *regressing*.
- **NHL moneyline**: +0.023 → −0.014 → −0.013.

A single measurement gets **no** direction rather than "flat" — saying
flat would imply it had been measured twice and had not moved.

Predictions are counted once per market, from the newest version. Summing
across versions would count the same games four times.

**The gate panel** shows the thresholds in force and what each verdict
means, so "unproven" reads as *beat the market on log loss but was worse
calibrated* rather than as a synonym for failure.

**The board panel** breaks 5,000 evaluated candidates down by pass reason,
which is the concrete answer to why nothing is being sold.

### The controls the spec asked for

Pausing prediction generation, disabling a feed and rolling back a model
version are all operations on **Edge Lab**, whose API is read-only by
construction. Buttons here would either do nothing or require a write
endpoint the whole integration was deliberately built without. The page
says where the control actually lives instead, and a test asserts no such
button exists.

### Still needs an Edge Lab endpoint

A calibration **curve** (predicted vs observed by bin) and per-prediction
win/loss/ROI at recorded odds. Edge Lab stores both — `calibration_bins`
and `backtest_predictions` — and `/internal/v1` does not expose either.
Two read-only endpoints would finish this page.

---

## Step 5 — Entitlements and plans

Done. 197 tests pass.

### One place that answers "may this customer have this?"

`App\Services\Entitlements\Entitlements` is the only thing that decides.
Not a preference — the failure mode for entitlements is not a wrong
answer, it is *two* answers: a check in a controller, another in a Blade
template, a third in a JSON resource, and a Pro feature that is hidden in
the nav and served by the route behind it. A customer who edits a URL
finds that in about a minute.

So everything routes to `can()`:

- **Middleware** `entitled:<feature>` gates the route.
- **Gates** are defined *from* the service in `VigilServiceProvider::boot()`,
  one per feature key, so `@can` in Blade and `$user->can()` in PHP cannot
  disagree with the middleware.
- **A test asserts they agree**, for every feature, rather than trusting
  that they do.

Grants live in `config/plans.php` beside the prices, so what the pricing
page advertises and what the product enforces are one list. `allFeatures()`
is derived from it rather than kept as a second list.

### What each tier reaches

| | Free | Daily | Pro |
|---|---|---|---|
| Today's board, one Read a day | yes | yes | yes |
| Top picks on the dashboard | 3, and it says how many it held back | all | all |
| Why the engine passed on a game | — | yes | yes |
| Edge at price | — | — | yes |
| Full board, props, line shopping, CLV, Model Lab | — | — | yes |

The dashboard is the hard case: one page three tiers can open, so the
enforcement happens inside the page. A free user sees three picks and a
line saying four more exist — being shown three and left to assume that
was all there was would be a lie told by omission.

### Refusals

An HTML request is **redirected to `/upgrade`**, not 403'd: a customer on
the wrong plan has not done anything wrong. A JSON request gets `403
upgrade_required` with the feature, the tier that would grant it, and no
data — an API caller following a redirect into a marketing page is worse
than an honest status code. A *signed-out* request still hits the login
first; being told you need a bigger plan when what you need is an account
would be absurd.

### Locked, not hidden

A nav item a customer cannot use is still drawn, carrying a lock, pointing
at the upgrade page. Hiding it would leave them unable to decide whether
to pay for it. The route behind it is gated separately, because the nav is
decoration.

### Overrides, and why they need a reason

`entitlement_overrides` beats the plan **in both directions**: support can
grant a feature while a billing problem is sorted out, and withdraw one
without editing a tier. The `reason` column is `NOT NULL` at the database
level, and expiry is checked on read rather than swept by a job — a comp
that outlives its date because a scheduler was down is the exact failure
this is meant to prevent.

Every administrative write goes through `Audit::write()` into an
append-only `audit_log`: actor, action, subject, before, after, reason, IP.
The table refuses updates and deletes, in the model and in Postgres.

### Cancelling is not a punishment

`Subscription::effectiveTier()` keeps a cancelled or past-due subscriber on
their tier until the period they paid for ends, then drops them to **free**
— not to nothing. Free is a real tier with real features, and locking
somebody out of those is a different decision nobody made. A tier that is
not in the config falls to free too: a typo in a config file must not
become a free upgrade for everybody.

### Nothing is on sale

`/upgrade` names the feature, the tier that includes it and its price, then
says plainly that paid plans are not on sale yet — no market is proven, so
there is nothing here worth charging for. Billing is step 6.

### The §13 check

*"A Free user cannot reach any Pro data through any route, Livewire action
or JSON response."* The test reads the gated routes **out of the router**
rather than from a list in the test file, so it covers the route somebody
adds next month without reading this. It also asserts that each gated
route names a feature some tier actually grants — a typo in a middleware
argument gates a page against a feature nobody can ever have, which looks
exactly like a paywall working.
