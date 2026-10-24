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

---

## Step 6 — Payments

Done, with one honest caveat: **no processor has ever answered.** There is
no merchant account, so the NMI driver has been exercised against a faked
HTTP layer and NMI's published v5 reference, never against NMI. The first
real transaction is the first real proof. Everything above the driver — the
event log, the projection, webhook verification, dunning, the Account page
— is tested and does not depend on which processor is live.

258 tests pass.

### Why there are two drivers before there is one customer

§5 asks that the processor can change without touching application code,
and the reason is specific: sports-information subscriptions are routinely
classed as high risk, and Stripe's prohibited-business list names sports
forecasting with a monetary or material prize. Vigil sells forecasts. The
switch may therefore have to happen at short notice, under pressure, while
the current processor is closing the account — which is not the week to be
rewriting controllers.

So `PaymentGateway` is the only thing the product talks to, `PaymentDrivers`
is the only place either class is named, and §13's check is tested by doing
it: the same subscribe request runs twice with nothing changed but
configuration, and both produce the same billing event.

`PAYMENT_DRIVER=none` is the current and default setting, and it resolves to
a driver that refuses everything. Deliberately — the obvious alternative, a
driver that returns plausible ids and pretends the subscription worked, would
grant a paid tier to somebody who was never charged, and would do it
silently.

### Subscription state is derived, not stored

Everything lands in an append-only `billing_events` table and
`SubscriptionProjector` folds the log into the `subscriptions` row. That row
is a cache of the answer, never the answer.

Billing disputes are arguments about history — *"we cancelled on the third"*,
*"you charged us twice"* — and a row updated in place eleven times cannot
settle one. A log can, and a projection can always be thrown away and
rebuilt. The rebuild is a full replay rather than an incremental update, so
the recovery path is the path that runs every day and cannot rot unnoticed.

One exception, found by a test: a log with no `subscription.started` in it
cannot be the whole story — the `vigil:owner` command writes a subscription
row and no events. Replaying that from empty read the customer's first failed
payment as proof they had never subscribed, and dropped them to Free. An
incomplete log now starts from what is already there. Without that fix the
owner account on production would have been downgraded by its first webhook.

### Webhooks

Three properties, each the kind of thing that is fine until the day it is
not:

- **Signature-verified**, in the driver, over the untouched bytes, before the
  JSON is parsed — parsing and re-encoding first breaks the HMAC, and there
  is a test that signs a body with odd whitespace to prove it does not.
  With no signing key configured every webhook is refused: an unverified
  webhook is an anonymous stranger asking for a free Pro subscription, and
  this endpoint cannot sit behind a login.
- **Idempotent**, by a unique column on the processor's event id. Every
  processor eventually delivers the same event twice; here the duplicate
  fails at the database rather than in a handler somebody might refactor.
  NMI's signature carries a *nonce* rather than a timestamp, so there is no
  replay window to enforce and deduplication is the whole defence. Stripe's
  is a timestamp, and that window is enforced too.
- **Unable to grant a tier.** The tier comes from what we recorded when the
  plan was bought, never from the payload. A webhook that could name the tier
  would be a webhook that could hand out Pro.

The driver is resolved from the URL path, not from `PAYMENT_DRIVER`, so both
processors can be live during a switchover without one's payload being handed
to the other's verifier and rejected as a mystery.

An event that verifies but matches no subscription is stored without a user
rather than dropped — it is the only evidence that the processor believes it
is billing somebody on our behalf. An event naming nobody at all is not
matched to the first row in the table, which is a mistake a `when()` chain
makes very easily.

### Dunning

The retries are the processor's; it holds the card. What Vigil owns is the
deadline, and it owns it because a processor that gives up quietly sends
nothing to react to — a customer left `past_due` would otherwise keep a paid
tier indefinitely. `vigil:dunning` runs daily at 06:10 and ends it on either
condition: three failures, or seven days.

An email goes at each failure, naming the date access actually stops, because
a customer told only "there is a problem with your payment" cannot tell
whether they have an hour or a fortnight. At the end the account moves to Free
and **nothing is deleted** — the logged bets, the record and the settings all
stay, and the email says so.

### Cancelling

One POST from one button. No confirmation step, no retention offer in the
way, nothing taken away today — §4 asks for one click and no dark patterns,
and a test counts the occurrences of the cancel URL on the page to stop a
funnel growing back.

Access then stops on the **date**, not on a webhook: `effectiveTier()` drops a
scheduled cancellation once the paid period ends, so a processor that goes
quiet cannot leave somebody on Pro forever. The asymmetry is deliberate — an
`active` subscription past its period end with *no* cancellation keeps its
tier, because that is a renewal message we missed, and locking out a paying
customer over our own missed message would be the wrong way to be wrong.

A cancellation the processor refuses is still recorded. The customer asked to
leave; that is a fact about them rather than about the processor.

### Cards

Never touch these servers. The browser mints a token against the processor's
own hosted field and the driver sends only that, which is the whole of this
product's PCI position. The form field is a hidden input rather than a text
box, so there is nowhere for a customer to type sixteen digits; a test asserts
nothing resembling a card number leaves the application, and failed calls are
logged without the request body — a token in a log is a token in every backup
of that log.

### Still missing

- No mailer (`MAIL_MAILER=log`), so dunning emails are written to the log
  rather than sent. That has to be wired before any plan goes on sale.
- `customerPortalUrl` returns null on NMI, which has no hosted page for a
  customer to manage a card on. Account says so rather than linking nowhere,
  but changing a card on NMI currently means emailing support.
- The browser side of the card field is a placeholder: the hosted field is
  described in the markup and not yet mounted, because there is no merchant
  account to mount one against.

---

## Step 7 — The Vigil Read

Built early, when the AI layer was first wired, and audited against §6
rather than rebuilt. Three things were missing, and all three mattered.

284 tests pass.

### The fact sheet was incomplete

§6 lists what the sheet must contain, and each item on that list is a thing
the model would otherwise invent or write around. Three were absent:

- **Teams and kickoff.** Without them the model had to write about "this
  market" in the abstract. Read from the mirrored event rather than parsed
  out of the selection string — a selection is a label, not a data source.
  The kickoff goes in twice, ISO and in words, because the validator would
  otherwise see the `8` in `8:15pm` as a figure it had to find in the sheet.
- **Model version and its gate result.** This is the honest answer to "why
  should I believe the probability": not because a model said so, but
  because *this version* of it was measured against the closing line, over
  this many predictions, against these thresholds — all carried verbatim.
  A Read that says "proven" without them asks the customer to take the word
  on trust.
- **Line movement.** Written as a sentence rather than a list of quotes, so
  the model can restate it without doing arithmetic: every figure it might
  quote is already in the string, and the validator reads numbers out of
  strings too. One stored quote produces *"not enough quotes to describe how
  this price has moved"* — "unchanged" would be a claim about a history we
  do not have. A test checks the direction reads the right way round, since
  −110 → −130 is *shorter* however it looks as an integer.

### Reads were being written and never shown

`vigil:reads` generated them, `vigil_reads` stored them, and the only place
they could appear passed `$read ?? null` — always null. The dashboard now
loads them and the card and the board both render one.

### "One Vigil Read a day" was configured and unenforced

Two separate limits, easy to confuse:

- **Viewing** is what the free plan *is* (§4). Board Reads are generated
  once and shared, so an extra view costs nothing — the limit is the
  product, not the bill.
- **Generating** is what §6 rate limits per tier, because every call costs
  money. Counted from `ai_usage`, the row that proves money was spent: a
  limit enforced against a counter that can be cleared is a limit that can
  be cleared.

`read_views` is keyed `(user, read, day)` rather than being a counter,
because re-opening a Read already read must not cost a second one — and a
counter cannot tell reading two Reads from reading one twice. The free
allowance is claimed in pick order, so it is spent on the strongest pick
rather than on whichever row the database returned first. When it is spent,
the card says so and links to the plan that lifts it: a customer who cannot
tell a used-up allowance from a missing explanation will reasonably conclude
the product is broken.

`ai_usage` now carries a nullable `user_id`. The nightly board backfill is
attributed to nobody, so loading a page cannot spend a customer's
allowance — and the per-customer AI cost becomes answerable, which admin
needs at step 11.

### Still to come

Reads on a parlay grade and on a customer's own bet. §6 names both, and
both need subjects that do not exist until step 8 — `user_bets` and the
parlay slip. The fact-sheet methods for them (including parlay hold and
shaded edge) land with those models rather than being written against
nothing.

---

## Step 8 — My Bets, Bankroll, Parlay, Props, Model Lab, Tools

Done. 370 tests pass.

### My Bets

Nothing here places a bet. Vigil holds no money, has no sportsbook and
cannot move a cent at anybody's book — these rows record that a customer
*says* they backed a selection at a price, so their record, their ROI and
their closing line value have something to measure.

Every change is an event first (`user_bet_events`, append-only) and a
projection second. A track record is a claim about the past, and a claim
about the past that lives in a mutable row is not evidence of anything. It
also means a settlement bug can be fixed and replayed rather than leaving
every affected row permanently wrong.

Grading is automatic, from the final score Edge Lab mirrored, and the event
log records whether a settlement came from a score or from a person — those
are different claims about a record. A game with no final score stays
pending; a market nobody wrote a rule for stays pending *and is counted*,
so it shows up as a gap rather than as a silent loss.

**Push and void** both return the stake and are not the same thing: a push
was a prediction that got tested, a void was a game that never ran. Only
the push is in the record. Settlement is idempotent, because the settler
runs hourly and re-settling would invent money in the ledger.

**Closing line value** is read from quotes stored *before kickoff* and from
nowhere else. There is no backfill that fetches a price nobody wrote down
at the time — that would be a fabricated measurement of exactly what this
product sells. An in-play quote is ignored: −400 at half time would report
an enormous and entirely false edge.

The CSV is the customer's own bets, scoped in the query rather than
filtered afterwards. A test puts a board full of prices in the database and
asserts none of it appears — §9 forbids an odds export and a bet export is
exactly where one would be smuggled in.

### Bankroll — the part that matters

§8 asks for loss limits "with no override" and Edge Lab's behaviour is
ported deliberately. A limit a customer can lift mid-session is not a
limit; it is a dialog that appears at the worst possible moment and asks
somebody who is losing whether they would like to continue, and it gets
answered yes.

So: tightening applies at once, loosening applies tomorrow, and removing a
limit entirely counts as the largest loosening there is — otherwise `null`
slips through as "not a bigger number" and the limit vanishes on the spot.
There is no confirm-and-proceed, no "I understand the risks" checkbox, and
no parameter anywhere that skips the check. A test fires `force`,
`override`, `confirm` and `acknowledge_risk` at the endpoint to prove it.

The limit counts a **running stake**, not just a settled loss. Otherwise
somebody stakes their way past it on a Sunday afternoon and finds out on
Monday. A winner that puts them back to even lifts the block, because the
limit is about being down rather than about having staked.

Kelly is scaled (quarter by default, capped below full) and then capped
flat. The cap is the part that saves people: Kelly on an over-confident
probability recommends a stake that ruins somebody. A bet with no edge gets
a stake of **zero**, not a small one — there is no stake at which a
negative-edge bet becomes a good idea.

### Parlay

The slip lives in the session, not the database: a half-built combination
is not a decision, and writing every keystroke to a table would bury the
parlays somebody actually weighed under the ones they were idly poking at.
Only a grade is stored, append-only, because it is what a Read attaches to,
what the daily cap is counted against, and what a customer should be able
to look back at.

Vigil computes none of the maths. Legs on the same game are correlated, and
multiplying leg probabilities on this side would produce a number that
looked like a parlay probability and was not one — which is how a parlay
product flatters itself. Edge Lab's judge answers; its whole response is
stored verbatim, including fields Vigil knows nothing about.

Three refusals, each a thing a customer would otherwise discover from a
grade that made no sense: the same selection twice, two sides of one market
(not a correlation — they cannot both win), and more legs than the judge is
useful for. A leg from an unproven market is refused too.

The page leads with **what the book keeps**, because the hold compounds with
every leg and showing that figure is more useful and harder to argue with
than a warning. An unreachable judge is reported as a question nobody
answered rather than a slip with no value, and the Read says the same — so a
fallback explanation cannot quietly become a verdict the engine never gave.

### Props — collected, not modelled

Edge Lab ingests props into a column the model pipeline cannot read, and
the schema enforces that rather than a convention. So there is no
probability, no edge and no verdict on this page, the copy says so in the
first sentence, and a test asserts no edge or confidence column appears.

What it *is* for is line shopping: the same player at the same number
priced differently across books is a real, checkable advantage needing no
model at all. Best price is marked, and the spread between best and worst
tells the customer whether shopping was worth the trouble. `+105` beats
`−130`, which is true across the sign boundary and is the kind of thing
that gets written wrong.

### Tools — arbitrage and middles

Arithmetic on prices already stored. Nothing is fetched to build the page
and nothing is written back, so §2's read-only boundary and §9's
no-bulk-odds rule both hold.

It only sees games somebody has already opened, because that is all the
display mirror contains. **That is the licence boundary working, not a
missing feature** — a cross-book scanner over every game in every league is
precisely the bulk-odds product the licence forbids, and the page says so
rather than implying coverage it does not have.

Two guards worth naming: one book's own two sides are never called an
arbitrage (a book does not price itself into one; if it looks like it has,
the data is stale), and only the newest quote per book and side counts,
since two stale quotes are not two prices a customer could take. Middles
report the gap *and* what it costs when the result lands outside it — a
middle is not free money.

### Model Lab

The transparency page, and nothing on it is filtered to flatter: a market
with a negative verdict appears with its number. The gate is read off the
models themselves rather than restated, and when the models disagree about
their thresholds the page says so instead of showing one as though it
applied to all.

The calibration chart §8 asks for is **not drawn**. Edge Lab computes and
stores the bins; its read-only API does not expose them. One endpoint
(`calibration_bins`) would finish the panel. Until then the page says so —
a fabricated calibration curve on the page whose entire purpose is
transparency would be the single worst thing in this product.

---

## Step 9 — The public track record

Done. 404 tests pass.

§7 calls this "the single most important trust and sales asset", and the
reason is narrow: it is the only page on the site a sceptic will take
seriously. Everything else is a claim about a model. This is a claim about
what the model actually said, in public, before the games.

### Two tables, both append-only

`published_picks` holds the pick: the price and book that were available,
the model probability, the market's fair probability, the edge at that
price, the suggested stake fraction and the model version that produced
it — all **stored at publication**, never looked up afterwards. A record
built from prices fetched later is a claim about prices nobody was
offered.

`pick_results` holds what happened. Separate rather than columns on the
pick, for one reason: the pick row must never be written to again after
publication and a result arrives hours later. Two tables is what lets both
be append-only.

### §13's acceptance check, enforced where it cannot be bypassed

*"A published pick timestamped after kickoff is rejected by the database."*

Not by a validator, not by a model observer — those can be bypassed by a
console command, a seeder, or an import script written next year by
somebody who never read this file. Postgres gets a `CHECK` constraint;
SQLite, which cannot add one to an existing table, gets a `BEFORE INSERT`
trigger that raises. Same guarantee, same place, so it holds in the test
suite as well as in production. A pick published at *exactly* kickoff is
refused too — "before kickoff" has to mean before.

Both tables also refuse `UPDATE` and `DELETE`, in the model and in
Postgres.

### The publisher refuses more often than it publishes

Four refusals, each one a way the record could quietly become misleading:

- **Not proven.** The gate is checked again here, at the one write that
  cannot be taken back.
- **Already published.** One row per board entry, for ever. A record that
  can be padded by re-running a command is not a record.
- **No price.** A record entry with no price is not a claim anybody can
  check, which makes it worse than no entry.
- **Too close to kickoff.** A pick published eight seconds before kickoff
  is technically pre-kickoff and practically unplayable; a record built
  from those would be true and misleading at once. The floor is 15 minutes.

### Settlement shares its rules with My Bets

The same grading code decides what a 3-point win against a 3-point line
is. Two copies of that logic would eventually disagree, and then the public
record and a customer's own record would disagree about the same game.

Results are in **units**, flat-staked. A public record in dollars would
imply a stake size nobody was told to use, and staking to the suggested
fraction would produce a different, much better-looking number that no
reader followed. Closing line value comes from a quote stored before
kickoff and from nowhere else.

### Every rate carries its sample, or is not shown

§7: *"always with sample size. With zero settled picks, show no rate at
all."* Every figure is `null` rather than zero when there is nothing behind
it, because a 0% ROI and an unmeasured ROI look identical on a page and
mean entirely different things — and the second is the current state of
this product.

A push counts toward the sample but not toward the win rate (it is not half
a win). A void is kept out of the record entirely (a game that never ran is
not a result). Closing line value averages only over picks that have one;
counting the rest as zero would drag a real figure toward nothing.

### What the page is right now

Empty, and it says why: no market has cleared the gate, so there is nothing
to publish and there will be nothing until one does. The copy says that a
record page with numbers on it before any pick had been published "would
tell you nothing except that we were willing to make numbers up."

Filterable by sport, market and month — with filter values read from the
published rows, so a filter never offers an option with nothing behind it.
Every pick has a permanent URL keyed on a ULID rather than a row id, and
that page shows **both** timestamps so a reader can check the pick was
published before kickoff rather than take it on trust.

Losers, pushes and still-running picks are all in the same table and the
same totals as the winners. The page closes by stating what is not on it:
no backtests, no paper trading, no simulated results — and the guarantee is
structural rather than a promise, because `published_picks` only ever
contains picks that were published before kickoff.

### One fix found along the way

`tests/Feature/ExampleTest.php` is the only PHPUnit-style test in the
suite, and Pest's `RefreshDatabase` binding does not reach a plain
`TestCase` subclass. It had been running against whatever schema the last
migration left behind — which went unnoticed until the home page first
touched a table.

---

## Step 10 — Alerts

Done. 430 tests pass.

Email and web push, for a pick being published and for a line moving on a
game the customer watches.

### The failure this is designed against

Not a missed notification. **Eleven of them in an afternoon** — which gets
an application uninstalled, a sending domain blocked, and a push endpoint
rate-limited. Almost every decision here follows from that:

- An alert is **unique per subject** in the database, so a pick still on the
  board does not raise a fresh alert every hour.
- A delivery is **unique per (alert, customer, channel)**, so nobody is told
  twice however often the command runs.
- There is a **daily ceiling per channel**, counted from append-only
  delivery rows so it cannot be reset by anything short of time passing.
- An alert older than three hours is **dropped, not delivered**. A queue
  stuck overnight must not empty itself into somebody's phone in the
  morning, and a notification about a game that has already started is
  worse than silence.
- **Quiet hours**, in the customer's own timezone, crossing midnight
  correctly. §9 is about responsible gambling and a 3am line-move alert is
  the opposite of it.

### Raising and delivering are separate

An alert is a *fact* — this pick was published, this line moved — and it is
the same fact for everybody. Who hears about it is a different question,
answered later against each customer's preferences. Mixing the two would
mean re-deciding what happened once per customer and getting a different
answer for the second one.

So the line-move scan runs once at a floor threshold and each customer's own
threshold is applied at delivery.

### Every skip is recorded with its reason

`alert_deliveries` holds the sends **and** the skips: `not_entitled`,
`type_not_wanted`, `sport_not_wanted`, `quiet_hours`, `below_threshold`,
`not_watching`, `email_unverified`, `daily_cap`, `no_push_subscription`.

That is what makes the Alerts page honest rather than a set of switches:
"why did I not get that one" is answerable, and a silent skip is
indistinguishable from a bug. The log is on the page, skips included.

Two of those reasons are worth naming:

- **`not_entitled`** is checked at delivery as well as on the page, so a
  customer who downgrades stops being emailed without anybody remembering
  to turn it off.
- **`email_unverified`** exists because sending to an unconfirmed address is
  how a domain earns a spam reputation.

### Both channels start off

An account that began emailing somebody because they signed up would be
sending unsolicited mail whatever the footer said. Nothing is sent until a
customer turns a channel on.

### Web push

Real push over VAPID, through `minishlink/web-push`, behind a `PushSender`
interface — the same seam the Claude integration has, and for the same
reason: the library does its own HTTP transport, so a test faking Laravel's
client would pass while a real notification went out, or while none did and
nobody noticed.

A browser's own keys are stored; they are useless without the VAPID private
key, which lives in the environment and never in the database.
Re-subscribing replaces the row rather than accumulating. Turning push off
**deletes** it — a row kept "in case they come back" is a row somebody will
eventually send to. An endpoint returning 404 or 410 is dropped at once,
because a browser that has been uninstalled returns it for ever; a 500 is
retried up to five times.

The service worker does one thing: show the notification and open the right
page. Deliberately no caching — a service worker that cached pages would
start serving a stale odds board from disk, and a stale price is the one
thing this product must never show.

Generate the keys with `php artisan vigil:vapid`. It needs an OpenSSL build
that can create prime256v1 keys, which rules out some Windows installs, so
the command says to run it on the deployed environment instead.

### Messages are built from stored payloads

Every figure in an email or a push comes from the payload recorded when the
alert was raised. Nothing is looked up at send time: a notification built
from a live read could quote a price that has since moved, which is the one
thing a price alert must never do. The email and the push share their title
and body, so a customer with both channels on does not get two differently
worded accounts of one event.

The pick email carries the gate with it — *"this comes from a market that
has beaten the closing line… it is not a prediction that it will win"* —
because a forwarded email without that caveat reads like a tip.

### Blocked on one thing

**`MAIL_MAILER=log` on production.** Every email path in the product —
alerts, dunning, email verification, password reset — currently writes to a
log file instead of sending. The alert pipeline is complete and tested and
will deliver nothing until a mailer is configured. Web push does not depend
on it and works as soon as VAPID keys are set.

---

## Step 11 — Admin

Done. 457 tests pass.

§11 asks for users, subscriptions, billing events, refunds, AI cost, sync
health, the published-picks ledger and feature flags — with every admin
write audited. All of it is here.

### 404, not 403

A 403 confirms the page exists. The admin area is the one part of this
application where that is worth hiding, so somebody probing for `/admin`
gets the same answer as somebody asking for `/nonsense`. A signed-out
visitor still hits the login first, so a stranger cannot tell a non-admin
account from a non-existent page either.

`is_admin` is a column rather than a role table, because there are two
kinds of person in this product and a permissions system for two cases is
one nobody maintains. `vigil:owner` sets it — that command is the only way
the first admin can exist, and an admin area nobody can reach is not an
admin area.

### Reads and writes are different controllers

Everything in `AdminController` is a question and none of it can change
anything. `AdminActionController` holds five writes and no more:

- grant or withdraw an entitlement override
- remove an override
- set a tier by hand
- refund a charge
- throw a feature flag

**A test asserts that list is exactly five.** An admin area that can change
anything is an admin area that eventually does — so there is no way here to
edit a bet, a published pick, a billing event or an audit entry, and the
tables behind all four are append-only in the database. The controller
could not write them if it tried, and a test proves an audit entry still
refuses to be rewritten *from admin*.

### Every write carries a reason, and the reason is validated

Minimum eight characters, so a full stop will not do. An entitlement
granted with no note is indistinguishable from a bug a month later, and
"support said it was fine" is not a record of anything — a blank reason
would make the audit log a list of timestamps.

The audit entry stores the reason **and** the before/after values, because
what somebody meant and what actually changed sometimes differ.

### A hand-set tier is a billing event

Not a write onto the subscription row. The projection stays derived from
the log, the history stays complete, and a tier set by an admin is the same
kind of fact as a tier set by a payment. That is the only way the two can
be reconciled later.

### Refunds refuse more often than they fire

Three guards, each one a way to send money to the wrong place: an event
that charged nothing, an event whose stored payload carries no processor
charge id, and an amount larger than the original charge. The refund is
recorded **only if the processor accepted it** — recording first would show
a customer money back that nobody sent.

### Feature flags

Five declared switches, each with a sentence saying what it does. In the
database rather than config for the only reason flags are ever worth
having: turning something off at two in the morning should not need a
deploy.

An unknown key reads as **off** — a typo must not switch something on, and
a flag that does not exist is not a feature that is enabled. A flag nobody
has touched reads at its documented default rather than null. The admin
page lists every declared flag, not only the ones somebody has written, so
a switch cannot hide by never having been thrown.

### Learning and sync health moved

Both were behind the ordinary login because an admin area did not exist
yet. §11 puts them here, so they are now at `/admin/learning` and
`/admin/sync-health`, and the Learning link is out of the customer nav.

### One figure worth explaining

The overview says **"Charged, all time"**, not "revenue". It is the sum of
money that actually moved, read from `billing_events` — not subscriber
count times list price, which is what a product *hopes* it is charging and
diverges from reality the first time a payment fails.

---

## Step 12 — Hardening

Done. 513 tests pass.

§12 asks for tests on entitlements, append-only guards, the pre-kickoff
constraint, AI number validation, webhook idempotency, no-odds-export, and
a load test on the board. Most of those were written alongside their
features, so this step was an audit rather than a sprint — and the audit
found three real things.

### Correction to the step 5 note

That note said `audit_log` "refuses updates and deletes, in the model and
in Postgres." **The first half was true and the second was not.** The
trigger had never been written.

Six tables were in the same position: the four Edge Lab mirrors,
`vigil_reads`, and `audit_log`. All six carried the `AppendOnly` trait and
so refused writes in PHP, with nothing behind them in the database.

That matters most for `audit_log`, because a trait fires on an Eloquent
event and is bypassed by anything that does not go through Eloquent — a
raw query, a seeder, a tinker one-liner, an import script written next
year. The audit log's entire purpose is to be evidence of what somebody
did, and the thing it protects against is precisely somebody wanting to
change it.

Fixed by a forward-only migration adding all six triggers. The claim is now
true.

### The test that found it

`AppendOnlyTest` derives the list of append-only models **from the models**
— it reflects over `app/Models`, collects everything using the trait, and
asserts each one refuses an update, refuses a delete, and has a trigger in
some migration. A hand-kept list would have been a list somebody forgot to
extend, which is exactly how six tables went unprotected for seven build
steps.

It also asserts the count, so if the sweep ever finds fewer models than the
product has, the sweep itself fails rather than quietly testing nothing.

### An N+1 on the dashboard, found by the load test

The board load test asserts the thing that actually breaks a dashboard:
**query count that grows with the data.** Not wall-clock time — a
benchmark on SQLite on a laptop says nothing about Postgres under traffic,
and a timing assertion fails on a busy CI box for reasons unrelated to the
code.

A sixteen-game board with six books and three quotes each ran **42
queries**, against 22 on an empty board, and the count grew as the board
grew. The cause: `Entitlements::can()` queried `entitlement_overrides` on
every call, and the picks table asks `@can('board.full')` once per row.

Overrides are now memoized for the life of a request. The same page now
runs **11 queries**, and doubling the board does not increase it. Memoized
per request rather than cached across requests, because an override granted
by an admin must take effect on the customer's next page load and not
whenever a cache expires — and `AdminActionController` clears the memo when
it writes one, so a page can see its own change.

### "locked" added to the banned words

A fixture-set test for the Read validator asserted that *"the price is
locked in"* would be refused. It was not: the banned list had `lock`,
matched at word boundaries, which does not catch `locked`. "Locked in" is
exactly the tipster register §0.7 forbids, so the word is now on the list.

### What the fixture set covers

§13 asks that the number validation be "tested across a fixture set", and
it now is — nine accepted cases and nine refused, each one a way a model
has actually been observed to go wrong or a way a validator can be fooled:
an invented probability, an edge off by a decimal place, a "helpfully"
rounded figure, arithmetic the model did itself (correct arithmetic is
still an unsupported claim), an invented sample size, an implied payout, a
year.

One case moved from accepted to refused while writing it. A Read saying
`140` when the price is `-140` was refused, and that is correct: `+140` and
`-140` are completely different prices, so an unsigned figure is not a
sloppy way of writing one of them — it is a number that could mean either.

### §13's eight checks, in one file

`AcceptanceTest` asserts each of the spec's acceptance criteria, numbered,
in addition to wherever the feature's own tests live. The duplication is
deliberate: a check listed in the spec should be findable by reading the
spec, not by knowing which of forty test files happens to cover it.

Two of them changed shape while being written:

- **13.5** (no odds export) now proves the rule from both ends: no
  machine-readable endpoint exists, *and* the one export that does — the
  customer's own bets, which §8 asks for by name — contains one bet and
  one header row with four games and seventy-two quotes sitting in the
  database beside it.
- **13.6** (renaming the product) sets the legal entity name as well. That
  is a separate setting with its own environment variable, and correctly
  so: a company does not rename itself at Companies House because the
  product got a new name.

### Still outstanding, and not code

- **`MAIL_MAILER=log` on production.** Every email path writes to a log
  instead of sending: alerts, dunning, email verification, password reset.
  This is the single biggest blocker in the product — it gates alerts,
  gates dunning, and means nobody but the owner can finish signing up.
- **`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` unset.** Web push is built and
  tested and sends nothing until they are. `php artisan vigil:vapid`
  generates a pair on the deployed environment.
- **`BRAND_POSTAL_ADDRESS` unset**, which CAN-SPAM requires in a marketing
  send. Not needed until one is sent.
- **Two Edge Lab endpoints.** `calibration_bins` would finish Model Lab's
  calibration chart and `backtest_predictions` the Learning Center's
  per-prediction view. Both exist in Edge Lab and neither is exposed by its
  read-only API.

---

## One application

Edge Lab and Vigil were two Laravel applications. Edge Lab ingested odds,
trained models, scanned for value and published a private read-only HTTP
API; Vigil authenticated customers, took payment, and read that API over
the network into its own mirror tables. They are now one codebase sharing
one database, which is what this section records.

The chosen shape was one deployment and one database. **Edge Lab's
database is the one that was kept** — it holds 3.3 million odds snapshots,
21,834 events and a historical backfill that was still draining, against a
handful of accounts on Vigil's side that `vigil:owner` can recreate.
Moving the product onto the engine's data is a few rows; the reverse is
millions.

That choice decided several others. The engine's migrations are already
recorded as run on that database, so none of them could be edited — which
is why the one real table collision resolved the way it did.

### What came across, and what did not

The engine's own tree moved unchanged: `app/Modeling`, `app/Data`,
`app/Enums`, `app/Exceptions`, its models, its 28 `edge:*` commands, its
jobs, its ingestion and value services, and its migrations less the three
Laravel scaffold files.

Dropped with the deployment it belonged to: the engine's authentication
(its own `users` table, 2FA, `login_activity`, `audit_events`, password
links, admin-account commands), its nine Livewire operator screens, and
the internal HTTP API — routes, controllers, middleware, rate limiter and
request log.

**Dropping the operator screens is a real loss and worth naming.** The
Command Center, Model Lab, Jobs, Cross Book, Performance and Bankroll
pages were richer than the product's admin area. They were dropped because
they were built on the engine's single-user login, which no longer exists,
and because the product has its own controllers covering most of the same
ground. The code is in the `edge-lab` repository's history if any of it
turns out to be missed.

### Eight collisions

Only eight files existed at the same path in both trees, and six were
trivial — an identical base `Controller`, an empty `AppServiceProvider`,
and four that belonged to the auth system being dropped. Two were real:

- **`App\Services\Bets\BetLedger` existed twice**, meaning two different
  things. The engine's is the operator's own bet ledger, used for
  measuring closing line value; the product's is the customer's. The
  engine's moved to `App\Services\Engine\Bets`, and its stake sizer with
  it, so the boundary is legible in the namespace. The rename was scoped
  to engine files for exactly this reason: a blanket replace would have
  repointed the product's six callers at the operator's ledger.
- **`bankroll_settings` existed twice**, with different schemas and
  different meanings — the engine's is one global, append-only, versioned
  row set; the product's is per customer with a cooling-off date on
  loosening a limit. The product's table was renamed to
  `user_bankroll_settings`. That direction was forced by the database
  choice above, and it is the better name anyway: the engine's is the
  house bankroll and the product's is the customer's.

### The wire is gone

`EdgeLabClient` kept its name and its entire public surface — `board()`,
`events()`, `models()`, `event()`, `odds()`, `predictions()`,
`evaluateParlay()` — and had its body replaced with local queries. The
mirror jobs that consume it needed no edit at all, because the rows are
still built by `App\Support\Internal\Present`, which is the same code that
built the HTTP payloads.

Keeping that seam was deliberate. The product's read model is still fed
through one narrow, named interface rather than by queries spread across
controllers, and the rule the whole thing rests on is unchanged: nothing
in there interprets a number. The edge, the verdict, the stake and the
reason are read off stored rows, never derived. That was true across a
network and it is true across a function call.

Gone with the network: the bearer token, the HTTP timeouts, the retry
policy, and the circuit breaker. The breaker protected a per-minute rate
limit on an API that no longer exists, and a breaker with nothing to break
would have sat on the health page reporting "closed" forever — which is
worse than no panel, because it reads as a working safeguard. Its state
display came off the sync-health and admin pages with it.

`ResilienceTest` lost two thirds of its tests for the same reason. What
remains is the part that was never about transport — a failure is recorded
rather than swallowed, and a failed run does not move the cursor past rows
it never read — plus one test that is new and only possible now: **the
projection reads the engine without writing to it.** That rule used to be
enforced by the shape of an API with no write routes. With the tables
directly in reach, nothing enforces it but a test that notices.

### The mirror was kept, and is now redundant storage

`vl_events`, `vl_board_entries`, `vl_odds_display` and `vl_models` are
still there, still append-only, still rebuilt on a schedule. They are now
a second copy, in the same database, of data that is already immutable in
the engine's own tables.

That is worth saying plainly rather than leaving for somebody to discover.
The mirror was kept because collapsing it means rewriting every read path
in the product — the board, the dashboard, the fact sheets, the publisher,
the parlay judge — and their tests, which is a large change with real risk
on top of a merge that already touched well over a hundred files. It still
earns part of its keep: `vl_board_entries` is a deliberately denormalised
read model, so the board filters and sorts on one table instead of a
four-table join, which is what the dashboard's query count depends on.

Collapsing it is the next simplification available, and it is now possible
where it was not before.

### Three things the merge found

Each of these was a real defect, live in production, and each was found
because the two halves could finally be tested against each other.

**Vigil's migrations did not roll back.** Thirteen had no `down()`. A
rollback dropped the mirrors, left every table that referenced them
standing, and then failed on the first drop that made SQLite check a
foreign key. Found by the engine's `MigrationsTest`, which asserts the
whole chain runs forwards and comes back; the product had no equivalent
test. All thirteen now have one, and the chain reverses cleanly.

**Four of seven columns on every parlay grade were null.** `ParlayJudge`
read `win_probability`, `fair_price_american`, `shaded_edge` and `verdict`
from the judge's answer. The engine calls those `model_joint_prob`,
`ev_shaded` and `recommendation`, and has never had a field by any of the
other names. Every parlay a customer graded stored nulls in those columns.

The reason it survived step 8 is the more useful part. The test fixture's
own header said it:

> these fixtures are the contract written down a second time. If Edge
> Lab's shape changes, they keep passing while production breaks.

It was not a shape change — the shape was never right. The fake agreed
with the code, the code agreed with the fake, and neither had ever been
compared to the engine. That gap cannot exist now, so
`engineParlayVerdict()` builds its answer by running `Present::parlay()`
over a real `ParlayEvaluation`, which makes the field names in a test the
field names in the database by construction.

The engine had no fair parlay price to give, so `Present::parlay()` now
converts its stored `fair_joint_prob` into one using the engine's own
converter — the same relationship `offered_decimal` already has to
`offered_price_american`. A unit conversion of a stored probability, not a
second opinion about one, and the conversion stays on the engine's side.

**An append-only trigger guarded a table nothing in PHP admitted to.**
Deleting the now-dead `InternalRequest` model left `internal_requests`
protected in Postgres with no model declaring it. The hardening sweep from
§12 caught it within one run. The model was restored — the rows are real
history on the database still in use — with a comment saying why it stays
when nothing writes it.

### The projection tests no longer fake anything

`SyncTest` seeds real `ValueBet`, `Event` and `ModelVersion` rows and
asserts the mirror agrees with them. Where it used to compare a mirrored
column against a hardcoded `0.58`, it now compares it against the engine
record itself — so a column the projection quietly stops copying fails a
test, instead of matching a number somebody typed into a fixture.

The engine's `ViewsCompileTest` was promoted out of the engine subtree
while moving, because it walks `resource_path('views')` and so now covers
the product's templates too. That is coverage the product did not have.

### Counts

1,354 tests pass. That is fewer than the 549 plus 1,158 the two suites
had, and the difference is almost entirely the tests that covered the
dropped operator UI and the dropped authentication. The full migration
chain — 50 migrations — runs forwards and backwards against an empty
database. 37 artisan commands and 57 scheduled entries, in one
application.

### Still outstanding from this merge

- **Production environment variables.** `ODDS_API_KEY`, `CFBD_API_KEY` and
  `CBD_API_KEY` are set on the engine's Laravel Cloud application, not the
  product's. Nothing ingests until they are copied across.
- **The `EDGE_LAB_*` variables are dead** and should be removed from the
  deployed environment: there is no URL to call and no token to present.
- **The two missing endpoints noted above are no longer endpoints.**
  `calibration_bins` and `backtest_predictions` were listed as Edge Lab
  tables its API did not expose. There is no API; they are local tables
  now, and Model Lab's calibration chart and the Learning Center's
  per-prediction view are ordinary queries away.

### Deploying the merge: where it stands

The merged code is on `main`. The deployment is prepared but **not
switched over**, because the one step that switches it needs a permission
this session does not have. Everything around that step is done.

**Why the engine's deployment is the one that survives.** It holds both
things that cannot be moved cheaply: the odds history, and the domain.

| | engine deployment | product deployment |
|---|---|---|
| database | Neon, us-east-2, 5.7M odds snapshots, 21,834 events | Neon, us-east-1, 2 accounts, 8 reads |
| domain | `parlayq.com` | none, vanity URL only |
| odds API keys | set | not set |

The two databases are in different clusters in different regions, so
there is no cross-schema copy available, and Laravel Cloud's CLI cannot
move an environment onto another cluster. Moving the product onto the
engine's deployment is a repository change; the reverse would be a
cross-region transfer of 5.7 million rows.

**What was verified against the live database before touching anything.**
Not inferred from the repository - asked of production directly:

- 33 migrations recorded as applied, 47 tables present.
- **No table collisions.** None of the product's 22 pending migrations
  creates a table the engine already has. The `bankroll_settings` rename
  is what bought that.
- **One column collision**, now handled. The engine's own two-factor
  migration had already added `two_factor_secret` and its three
  siblings to `users`, from a file that is no longer in this repository.
  The product's copy of that migration is now column-guarded, and was
  tested in exactly the production configuration: columns present, its
  `migrations` row deleted, `migrate` run - it reports DONE and changes
  nothing.
- Two tables on that database have append-only triggers and no migration
  file any more: `login_activity` and `audit_events`, from the engine's
  dropped authentication. They are retired history and are left alone.
  The §12 hardening sweep reads migration files, so it does not see them.

Neon does not support Laravel Cloud snapshots, so there is no snapshot to
point at; Neon's own point-in-time restore is the recovery path. That is
why the checks above were done against the live schema rather than
trusting the local one.

**Done already:**

- The product's configuration is on the engine's environment: brand name,
  short name, tagline, legal name, minimum age, log level, mailer, the
  Anthropic credentials, and `ENGINE_ENABLED=true`.
- `APP_URL` corrected to `https://parlayq.com`. It was pointing at the
  Laravel Cloud vanity URL, which would have made every absolute link in
  a signed URL or an email wrong.
- `APP_KEY` deliberately **not** copied. The surviving database holds data
  encrypted with the engine's key; nothing on the retired database needs
  carrying, because password hashes are bcrypt rather than encrypted.
- The old product deployment is neutralised: push-to-deploy off and its
  deploy command replaced with `php artisan --version`. Without this,
  pushing to `main` would have started a second scheduler pulling odds
  against the wrong database and spending real API quota.
- The deploy command on the surviving environment is also
  `php artisan --version` for now, so the switch cannot migrate 5.7
  million rows of production data unattended.
- Everything the deploy itself does was run locally and passes:
  `config:cache`, `route:cache` (87 routes, no duplicate names),
  `view:cache` (every Blade compiles), `event:cache`, `composer validate`,
  and a `--no-dev` install dry run.

**The three steps left, in order:**

1. Point the application at this repository:
   `cloud application:update app-a307a4b6-8877-4ec1-a815-9080baced557
   --repository="primeeventsource-bit/vigil-bet" --source-provider=github
   --name="Vigil"`
2. Set the deploy command back to `php artisan migrate --force`, or deploy
   first and run `php artisan migrate --force` by hand and watch it. The
   second is better: between the switch and the migration the product's
   pages have no tables to read and will error, so it is worth being
   present for.
3. Recreate the two accounts, which do not exist on the surviving
   database. `vigil:owner` prints each password once:
   `php artisan vigil:owner primeeventsource@gmail.com --name="Prime"` and
   `php artisan vigil:owner bettor@vigil.test --name="Bettor" --tier=pro
   --no-admin`.

**Two things to know about the result:**

- `parlayq.com` will serve a product branded Vigil. The domain was the
  engine's and the brand is the product's. A Vigil domain is a
  `domain:create` and a DNS record away, and nothing in the code depends
  on which it is.
- The engine's database already has one account, `dior@parlayq.com`. After
  the migration it becomes an ordinary Vigil user: not an admin, email
  unverified. It is the engine's old single-user login and can be deleted.

**Left undone, deliberately:** `VIGIL_INTERNAL_TOKEN` and
`EDGE_DEMO_MODE` are still set on the surviving environment. Nothing reads
either - the internal API is gone and `edge.demo_mode` has been removed
from the config - so they are cosmetic. Deleting an environment variable
needs a permission this session does not have.

The old product deployment and its database are still there, untouched and
no longer deploying. Worth keeping until the switch is known to be good,
then deleting.

### The engine's documentation, which the merge first left behind

The code moved and the docs did not. That was an oversight rather than a
decision, and 2,237 lines of it were still sitting in the other
repository. Brought across now:

- `DATA_SOURCES.md` - where every fact comes from, per sport.
- `SCHEMA.md` - the engine's tables and what each is for.
- `PARLAY_ENGINE_PLAN.md` - how correlated legs are priced.
- `DEPLOY_LARAVEL_CLOUD.md` - the resources the engine needs: serverless
  Postgres, one queue worker, the scheduler, a key-value store.
- `EDGE_FINDINGS.md` - the measurements, including the ones that say there
  is no edge.
- `DECISIONS.md` arrived as `ENGINE_DECISIONS.md`, because both halves
  kept a decision log and both are worth having. The product's covers the
  product's choices; the engine's covers Laravel and Livewire versions,
  why the modelling is PHP rather than a Python service, and the
  leakage rules.

Two things were deliberately not brought across.

`INTERNAL_API.md` documented the private read-only HTTP API between the
two deployments, and there is no such API any more. The part of it worth
keeping was never the endpoint list but the reason the odds endpoint took
one event at a time: the feed licence permits showing prices inside a paid
interface and forbids repackaging the feed, and the practical difference
is almost entirely whether a caller can pull a whole slate in one request.
That constraint now lives where it is enforced - in the comments on
`EdgeLabClient::odds()` - and is asserted by `NoOddsExportTest`, including
a test that no method exists which could fetch odds in bulk. A document
describing a deleted API would have been the third place that rule was
written down and the first place it stopped being true.

`docs/reference/` held an alternate build prompt and a dashboard mockup,
plus `.duplicate` copies of both that look accidental. Design scratch for
a UI that no longer exists; it stays in the `edge-lab` repository rather
than being deleted or carried.

### It is deployed

**24 October 2026.** `parlayq.com`, `www.parlayq.com` and the vanity URL
all serve the merged application. Deployment `depl-a30eb349`, 53 seconds,
and the 22 product migrations ran as part of it.

The two-factor guard behaved exactly as designed, which is the one thing
that could not be proved before the deploy: it reported DONE in 188ms and
changed nothing, because the engine's own copy of that migration had
already added those four columns to `users` from a file no longer in this
repository.

One thing the plan missed. Repointing the application at a different
repository leaves the environment's branch reference stale, and the first
deploy was refused with "The environment's branch is no longer available."
Re-setting the branch to `main` on the environment fixed it. Worth knowing
for any future repository move: the application's repository and the
environment's branch are two separate settings and both have to be set.

**Verified after the switch**

- `/`, `/record`, `/legal/terms`, `/register`, `/login` all 200, and the
  page title is "Vigil — AI-Driven Edge" rather than the engine's old
  branding.
- **The projection works against local tables.** `vigil:sync` queued its
  three feeds and `vl_board_entries` began filling from the engine's
  16,112 `value_bets` with no network in between. That is the merge
  proved end to end on real data: the same `Mirror` and the same `Present`
  shapes, with the HTTP client replaced underneath them.
- The engine's own scheduler is unaffected: 58 `job_runs` in the twenty
  minutes after the deploy.
- No errors in the application log since the deploy.
- Both accounts recreated with `vigil:owner`, since neither existed on
  this database.

**The first catch-up is slow, and that is expected.** `Mirror::write`
costs a select and an insert per row and the projector pages 200 at a
time with a 25-page ceiling per run, so a cold mirror of 16,112 board rows
takes several hourly ticks to fill. After that each run only carries the
delta, which is what the digest and the cursor are for. Worth watching
rather than fixing: if a cold start ever needs to be fast, the fix is a
bulk insert of the rows whose digest changed, not a bigger page.

**Left standing on purpose**

The old product deployment and its database still exist, untouched, with
push-to-deploy off and a no-op deploy command. It holds nothing that was
not recreated here - two accounts and eight Reads - and is worth keeping
until this deployment has a few days behind it, then deleting.

`VIGIL_INTERNAL_TOKEN` and `EDGE_DEMO_MODE` are still set on the
environment and are read by nothing.

### Which domain, and the brand

`parlayq.com` was the engine's domain and the product is branded Vigil, so
the site now serves Vigil from an address named after the engine. Nothing
in the code depends on which: `APP_URL` is the only place it is stated,
and a different domain is a `domain:create` plus a DNS record. Flagged
rather than decided, because it is a naming choice rather than a technical
one.

---

## The black-and-white terminal

**24 October 2026.** The palette moved from navy and neon green to a
matte-black trading terminal, a dark/light switch was built, and light
mode turned out never to have worked.

### The palette

Every colour still crosses from PHP into CSS in exactly one place, so
this was a change to `config/brand.php` and almost nothing else. The
values were measured rather than chosen by eye: the lowest ink-on-surface
contrast in either mode is 4.67:1, above the 4.5 bar for body text, and
the surfaces sit 1.12:1 apart so a panel reads as raised by a hair rather
than as a slab, with the border doing the separating.

| | dark | light |
|---|---|---|
| page | `#101113` | `#F7F7F8` |
| panel | `#1B1D20` | `#FFFFFF` |
| primary ink | `#FFFFFF` | `#101113` |
| secondary ink | `#A5A9B0` | `#5E6269` |

### The accent was doing four jobs

The green was not only the verdict colour. It filled every primary
button, stroked every chart series, drew the confidence meters, painted
the hero glow, the engineering grid, the rising trace, the section rules,
the lit panel edges, the step numbers, the active tab, the checkbox tick
and the logo itself.

Which meant the one colour this product reserves for *the model has a
proven edge here* was also the wallpaper - on a front page whose entire
argument is that nothing is proven yet.

So the jobs were separated:

- **Buttons** are white with black type. 18.9:1.
- **Chart series** got their own tokens and are drawn in white and silver.
- **Decoration** got a `decor` token and is silver.
- **Chrome** - active tabs, nav items, form ticks, step numbers - is white.
- **The verdict green stayed green**, and now appears only where it means
  something: a positive number, a proven market, an improving trend, a won
  bet, a live feed, a bar that beat the close. Two uses survived on the
  whole marketing page, both of them judgements.

That is the "subtle indicators for performance" in the brief, and it is
also the rule this codebase already claimed to follow.

### Light mode had never worked

The stylesheet has carried an `html[data-theme='light']` block for the
whole life of the product. It never did anything, and could not have.

The palette is written as a `:root` block in an inline `<style>` in the
head - that is what keeps `config/brand.php` the only place colour
crosses into CSS. That block is unlayered. Everything in `app.css` sits
inside `@layer`, and **an unlayered declaration beats a layered one
whatever the specificity**, so `:root` outranked the override every time.

Nothing revealed it because nothing ever set the attribute: there was no
switch. The first time one existed, the mode it was supposed to switch to
turned out to be dead code.

The fix is to keep those rules outside every layer, which looks like an
oversight and is load-bearing. `ThemeTest` walks the stylesheet counting
layer depth at the selector's position and fails if it is greater than
zero. That detector was checked against a synthetic stylesheet with the
bug reintroduced, including the nastier case of the rule nested inside a
media query inside a layer, because a regression test that does not fail
on the regression is decoration.

### The switch

Three states, not two. **System** is the default and is a real choice:
somebody who flips their machine to light at dusk gets a light app
without coming back. Choosing light or dark pins it.

An inline script in the head applies the stored choice before the first
paint. It has to be inline and it has to be above the stylesheet - the
alternative is a visible flash of the wrong theme on every page load,
because a deferred module runs after the browser has already painted. The
button renders `hidden` and the script reveals it, so it is never a dead
control for somebody with scripting off.

### The hero console

A mockup of the product on an angle, with cards floating in front of the
glass, built from the reference provided.

**It carries no numbers, deliberately.** A hero mockup of a betting
dashboard wants to be covered in figures - a win rate, units won, an
average confidence - and §0.7 allows a win rate on a marketing page only
when it is computed live from the public track record and shown with its
sample size. The record is currently empty, because nothing has been
proven. Invented figures would be the page contradicting the four
promises printed directly beneath it, one of which is *never invents a
number*.

So the figures are redacted into bars. What is real is the shape: the
navigation is the actual navigation, the columns are the actual columns,
every row says **Pass** because on the day it was built every row did,
and the trace is the same abstract one used elsewhere - no axis, no
scale, no numbers.

It flattens below `lg`, where a tilted dashboard is unreadable long
before it is decorative, and whenever motion is reduced.

---

## The engine, after the merge

**24 October 2026.** `edge:doctor` run against production, three things
fixed, and the models retrained on everything the engine has collected.

### The closing-line fix worked

`odds:close` had been failing every run and getting killed by the reaper
at thirty minutes. After the window-function rewrite it runs in **14.6
seconds** and writes up to 4,312 markers a pass. Markers went from 178,818
to 268,236, across 3,476 events.

That is what made the retraining worth doing: NBA now scores **2,606
predictions** against the closing line where it scored 1,340 in the last
measurement, and MLB 3,134. More games became scoreable because more games
got a closing line to be scored against.

### Retrained on the full history

Every sport has a logistic model now, trained on everything accumulated
and stored as a candidate so nothing live changed while the verdicts were
read.

| sport | predictions | log loss | verdict |
|---|---|---|---|
| MLB | 3,134 | 0.6877 | insufficient_data |
| NBA | 2,606 | 0.6109 | negative |
| NHL | 1,612 | 0.6810 | negative |
| NFL | 530 | 0.6446 | negative |

The answer has not changed and the larger samples did not change it. No
market has cleared the gate, so the product sells nothing and the public
record says so in those words.

Worth noting what the active models were before this: every one of them
was Elo, and NCAAB's was trained through **2025-11-25** - eleven months
stale. The weekly retrain only covers sports with enough new graded games
to be worth re-running, so a sport that goes quiet stops being retrained
and nobody is told.

### 459 unmatched team names, 83 of them fixed

`edge:doctor` reported 459 names the feeds send that could not be matched
to a team - 409 of them college basketball, carrying 14,058 hits. Every
one is an odds row that could not be attached to a game.

Most of it needed no code. `edge:teams:resolve` already derives
truncations and abbreviations and had never been run against this data: it
linked **70** on its own, including `GW` to George Washington and
`Loyola (Chi)` to Loyola Chicago.

**That is the second time this session the right move was to run the
command that already existed rather than write one.** The first was the
venue locator. Both times the code was already there and had simply never
been run.

Thirteen more went into `database/data/college_aliases.php`, each checked
against the teams table rather than recalled, and four were deliberately
left alone because the school genuinely is not in the table - an alias
pointing at nothing is worse than an unmatched row, because the log stops
showing the problem.

### The tick was starving the thing it exists to feed

The odds tick queued one job per sport per minute and let the job decide
it was not due: 8,640 jobs a day, about ninety-eight per cent of them
recording a skip. On one worker that left a standing backlog of about a
hundred jobs, so everything ran a quarter of an hour late.

The tightest cadence is **every two minutes inside the hour before
kickoff** - precisely the window a closing line is taken from. A
sixteen-minute lag there is not a tidy-queue problem; it is closing line
value, the measure the whole product rests on, quietly getting worse.

The cadence is now decided in the scheduler, which is two indexed reads
and no network. The reason the work was queued - a slow source must not
block the tick - was never a reason to queue the question of whether to
do the work.

### Still true, and still the constraint

Odds coverage. 3,472 settled games have a closing line out of 20,852
settled games in the schedule, because both historical backfills ran
without `--from` and bought only the last twelve months. The window from
2023-01-01 to 2025-10-01 is priced at **4,913 requests and 294,780
credits**, 1.3% of the 22.3 million spendable this month:

    php artisan edge:odds:backfill all --from=2023-01-01 --to=2025-10-01

That is the single thing that would most change what can be said about
the models, and it is not queued because it spends real money.
