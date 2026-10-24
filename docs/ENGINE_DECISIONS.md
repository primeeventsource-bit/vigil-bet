# Decisions, trade-offs and open questions

Written during Phase 1. Anything marked **needs a decision** is waiting on you.

## Settled

**Laravel 12 and Livewire 3, as the spec says.** The installer now defaults to
Laravel 13 and Livewire 4. The spec pins 12 and 3, so that is what is here.
Worth revisiting before Phase 5, which is the first phase where the Livewire
version actually affects the code written; until then the cost of switching is
close to zero. Laravel 12 has security support into 2027.

**SQLite locally, Postgres in production.** `pdo_pgsql` is not in the local PHP
build, and the spec allows SQLite for dev. The two Postgres-only migrations
(append-only triggers, check constraints) are no-ops on SQLite and are proven
by a Postgres job in CI.

**ESPN for NBA and NCAAB.** `cdn.nba.com` returns 403 to datacenter IPs, which
is what Laravel Cloud is, so the NBA-run endpoints were never going to work in
production. ESPN is keyless and currently answers. It is undocumented, so it is
labelled `unofficial` everywhere it appears.

**Odds for an unknown event create the event.** If both teams resolve but no
scheduled game exists yet, the odds pull creates it rather than dropping the
prices. Schedule sync fills in the rest later. Dropping real prices on the
floor is worse than an event row with thin metadata.

**Events are the only mutable table.** A game genuinely changes state, so
`events` is updated in place. Everything around it - odds, predictions, value
scans, bet events, bankroll - is append-only.

**Historical lines live under a fake book.** NFL closing lines imported from
nflverse are written under a book keyed `historical_close`, untracked, so they
never compete in line shopping and can never be confused with our own
snapshots.

## Needs a decision

**1. The Odds API plan size.** The cadence is designed around
`ODDS_API_MONTHLY_QUOTA`. One pull costs one request per sport. With three or
four sports in season the current cadence spends roughly 6,000 to 10,000
requests a month. The free tier is 500. Set the variable to your real plan, or
tell me the plan and the cadence will be tuned to it.

**2. Historical odds for the other five sports.** NFL is covered free. NBA,
MLB, NHL, NCAAF and NCAAB have no free line history, so their market benchmark
and CLV start from the day snapshots begin, and those markets will honestly
report `not enough data yet` for months. The alternative is paying for
historical odds, which the spec says to ask about first. Options:

   - accept forward-only collection for those sports (current behaviour);
   - buy historical odds from The Odds API and backfill;
   - find a per-sport free archive, which I have not found one of.

**3. The second spec in the repo.** `design-reference (2).png` and `(3).png`
are the same markdown file saved with a `.png` extension: a second, differently
worded implementation prompt titled "Edge Lab Quantum Terminal". It agrees with
`MASTER_PROMPT.md` on nearly everything but conflicts on one point that matters:
it asks for a **separate Python modelling service**, while `MASTER_PROMPT.md`
requires 100% PHP because Laravel Cloud runs PHP only. This build follows
`MASTER_PROMPT.md`. It also references "the included Blade/CSS/JS starter",
which is not in the repo - only a screenshot of it, saved as
`terminal (1).js` and `(2).js`, which are PNGs, not JavaScript. If you have
those starter files, they belong in Phase 5.

**4. Account deletion.** ~~Breeze ships a "delete your account" control on the
profile page. In a single-user app that locks you out permanently.~~
**Settled: removed.** The control and its Volt component are gone, and
`ProfileTest` now asserts their absence so restoring the Breeze view fails
the build rather than quietly putting the button back. Removed rather than
guarded, because a destructive control that always refuses is a worse
version of one that is absent.

## Known gaps, carried forward

- NCAAF cannot be exercised until `CFBD_API_KEY` is set. The adapter is written
  and reports itself unavailable without one.
- **Injuries are ingested and shown, but no model reads them, and that is
  on purpose.** The adapter is built and verified live across all four pro
  leagues; `FeatureSchema::VERSION` stays at 1 because the feed is a
  current snapshot rather than an archive, so no historical training row
  can have injury data and a feature absent from all history is one the
  model cannot learn a weight for. The full account, including the
  two-timestamp leakage rule that makes this subtle, is under *Availability
  is collected now, and modelled later*.
- **Weather is ingested as forecasts, and has a trainable history.** All 47
  venues are located and the roofs are derived from the schedules rather
  than recalled. Observed game-time weather is still refused - a decision
  `NflverseSource` made in Phase 1 and this upheld - but Open-Meteo's
  archive of past forecast *runs* is usable, and backfilling three NFL
  seasons produced 525 readings at a one-day lead, 358 of them with wind.
  This is the one of the three sources that can actually be modelled. See
  *Correction: weather is trainable after all*.
- **Probable pitchers are in; NHL starting goalies never will be.** No
  official pre-game feed exists for goalies, so that column stays empty
  rather than filled from a beat reporter. See *Probable pitchers, and the
  goalie column that stays empty*.
- **All three sources are ingested; only weather has history to train on.**
  `FeatureSchema::VERSION` is still 1. Availability and probable pitchers
  are forward-only, so no historical training row has them and no model can
  learn a weight for a feature absent from all of history. Weather is the
  exception and is the next piece of modelling work: a schema version 2
  carrying weather features, which forces a retrain and a fresh backtest
  rather than quietly changing what a stored model means.
- Seven npm advisories exist in the Breeze dev toolchain. They affect build-time
  packages, not anything shipped to a browser.

## Added after Phase 1, from how you actually bet

**Stakes of $100 to $1,000 per bet.** Configured as `EDGE_MIN_STAKE_CENTS` and
`EDGE_MAX_STAKE_CENTS`, with the unit set to $100 so a stake shown in units
means something. Two consequences worth knowing:

- At the spec default of 2% max per bet, a $100 minimum needs about **$5,000**
  of bankroll before any bet clears the floor, and a $1,000 bet needs about
  **$50,000**. `edge:doctor` says so out loud when the numbers disagree.
- When correct sizing lands below the floor, the bet is **passed, not rounded
  up**. A thin edge that Kelly sizes at $29 is not a $100 bet; taking it at
  $100 is how a real edge turns into a real loss. The pick card shows the
  reason rather than hiding the bet.

Confirmed as the plan for month one, so the stake range now governs and the
percentage guard moves to accommodate it rather than silently clamping a bet
that has already been decided on. What the guard would have hidden is reported
instead: `edge:stake:preview` prints the whole ladder, and `edge:doctor` names
the risk band.

At the currently configured $5,000 bankroll, a $1,000 maximum bet is **20% of
bankroll**, which is in ruin-risk territory on any conventional reading. It is
also mostly theoretical: at quarter Kelly against a $5,000 bankroll, even a 10%
edge only sizes to $262, so the $1,000 ceiling is a rail that never gets
touched, and every edge under 4% is passed for falling below the $100 floor.
That combination means month one would see a handful of flat $100-ish bets and
nothing else.

The bankroll is the number that makes the range work, and it should be yours,
not a default:

| Bankroll | $1,000 max bet is | Stake range actually used |
| --- | --- | --- |
| $5,000 | 20% - ruin risk | $100 to ~$260, most edges passed |
| $10,000 | 10% - very aggressive | $100 to ~$525 |
| $20,000 | 5% - aggressive | $105 to $1,000 at a 7% edge |
| $50,000 | 2% - conservative | $262 to $1,000 at a 4% edge |

`bankroll_settings` is append-only and versioned on `effective_from`, so month
two is a new row and month one stays explainable exactly as it was set.

**Hard Rock Bet, with DraftKings as the alternative.** `EDGE_MY_BOOKS` holds
the books an account exists at, Hard Rock first as the default. The split that
matters:

- the **no-vig fair price** keeps using every tracked book, because a wider
  sample is a sharper estimate of the true probability;
- the **recommendation** is priced only at your books, because a price you
  cannot reach is not an edge.

When the board has a better number somewhere you cannot bet, the pick records
it in `market_best_price_american` and shows it as context, so you can see what
staying on Hard Rock costs you. If nothing at your books clears the threshold,
it is a pass even when another book is offering a great number.

Hard Rock is served under The Odds API `us2` region, so `ODDS_API_REGIONS` must
keep `us2` in it or its prices never arrive.

## PHP version floor, and why the lock is pinned

The README promises PHP 8.3 or newer. The first CI run proved that promise
was not true: `composer.lock` had been resolved on local PHP 8.4, which pulled
Symfony 8.1 packages requiring 8.4.1, so `composer install` failed outright on
an 8.3 runner.

Fixed by pinning `config.platform.php` to 8.3.35 in `composer.json` and
re-resolving, which stepped six Symfony packages back to the 7.4 line. Nothing
else moved and all 127 tests still pass.

The point of the pin is that lock resolution now follows the floor the project
claims, rather than whatever PHP happens to be installed on the machine that
last ran `composer update`. CI runs the suite on both 8.3 and 8.4 so the claim
stays tested rather than assumed.

If the floor is ever raised to 8.4, change the platform pin and the matrix
together, or this comes back silently.

## Hard Rock is the pricing book, not one of several

Everything actionable is measured at one book, because that is where the bet
gets placed. `EDGE_PRICING_BOOK` holds it, Hard Rock by default, switchable to
DraftKings without touching code.

What that changes:

- the price on a pick is the Hard Rock price, not the best on the board;
- the edge is the model win chance minus the break-even rate **at the Hard
  Rock price**, which is the number that decides whether a bet makes money;
- a bet that only clears the bar somewhere else is a pass labelled
  **price too short here**, with the better number shown so the cost of
  staying on one book is visible rather than hidden.

Other books are still pulled and still matter. They build the no-vig fair
probability, and a wider sample is a sharper estimate of the true price. They
are never recommended.

### Two edges, kept apart

`edge_at_price` is the model minus the break-even rate at the Hard Rock price.
It gates every pick.

`edge` is the model minus the no-vig market consensus. It says whether the
model disagrees with the sharp money, which is a statement about the model,
not about this bet.

Collapsing them into one number is how a tool recommends something that is
theoretically sound and practically unprofitable.

## Ranking is ordering, never selection

Two orderings are stored on every scan, so the board can switch without
rescanning and without numbers moving underneath:

- **most likely to win**, which is the default;
- **best value**, which leads with the bet paying most for its risk.

Both only ever reorder candidates that already passed the value test. Sorting
by likelihood alone would put heavy favourites on top, and a heavy favourite
at a short price is a losing bet: at 70.4% the same selection is +3.7% at -200
and -4.6% at -300. `min_win_probability` (default 0.55) is a display floor on
top of that, not a substitute for it.

### What the numbers are not

A 7-out-of-10 pick loses three times in ten, and the dots on the card are
there to keep that visible. Nothing in this app can promise a winning bet, and
the Performance screen exists to say plainly when the picks are not beating
the closing line. Parlays are deliberately unsupported: each extra leg lowers
the chance of winning and books pay less than the true combined odds.

## Review findings, Phase 1 and 2 part one

Three defects found reviewing what had landed. Two were fixed because they
cause silent data loss or a hung worker; one was a design smell.

**Closing lines were lost permanently after any outage.** `MarkClosingLines`
only looked at games that started within a fixed 48 hour window. If the
scheduler were down longer than that - a deploy, a dead worker, a Cloud
incident - those games fell out the back and their closing lines were never
computed. Closing lines are the entire basis of CLV, which is the measure
that decides whether the model is real, so this was the most damaging bug in
the pipeline and it would have failed silently. It now marks anything started
and never marked, however old, plus anything recent in case later snapshots
arrived, bounded by a row limit per run.

**The NHL backfill could spin forever.** The feed supplies `nextStartDate`
and the loop followed it blindly. A repeated or backwards date would never
advance the cursor, hanging a queue worker and burning API calls until
something killed it. The cursor now only accepts a date that actually moves
forward.

**`value_bets.rank` was ambiguous.** Once both orderings existed, a bare
`rank` silently meant whichever mode the scan ran in, so reading it without
also reading `rank_mode` would give a plausible wrong answer. Dropped in
favour of the two explicitly named columns.

### Known and accepted, not fixed

- `edge:backfill` counts seasons back from the current calendar year, so a
  season in progress is not included. Schedule sync covers current games, so
  this only affects how much history training sees.
- Breeze still ships a delete-your-account control on the profile page. In a
  single-user app that is a way to lock yourself out permanently. Phase 5.
- `my_books` and `pricing_book` overlap: the first says where accounts exist,
  the second which one prices a pick. Both are needed, but it is worth
  collapsing if a second bettable book never gets used.

## Two models of the same game, and no assumption about which is right

Elo converts a rating gap into a win probability with a logistic curve. The
margin model converts the same gap into scoreboard points and then into a
probability through a normal distribution with a per-sport sigma. On a large
favourite these disagree by several points of probability - at a 200 point
rating gap in the NFL, 81 percent against 76 percent - which is a great deal
of money.

Neither is assumed correct. They are two candidate models, both get
backtested against the closing line, and the data decides which one, if
either, earns the right to produce a pick. A test pins the divergence so it
stays visible rather than being discovered later as a surprise.

The practical split is that Elo's logistic is how ratings get *updated*,
while pricing goes through the distribution that matches how the sport
actually scores.

### Totals are a placeholder until a real model exists

Elo only ever learned about margins. The projector therefore builds totals
off a league baseline and says so. That is honest scaffolding, not a
prediction, and the totals market should stay unproven until something that
actually models pace and scoring is trained. The projector takes a total
override for exactly that reason.

## Rebrand to ParlayQuantum

User-facing naming now comes from `config/brand.php`: name, tagline, palette
and logo paths, emitted as CSS custom properties so no Blade file or
stylesheet hardcodes a hex value or a product name. A test proves the rename
works from config alone.

The supplied artwork was not available as a file, so the mark and favicon in
`public/images/brand` are authored SVG in the same palette - near black,
brushed silver, electric blue, neon teal. Dropping the real PNG or SVG into
that folder and pointing `BRAND_MARK` at it is the whole swap.

### What was deliberately not renamed

The PHP namespace, the `edge:` artisan commands, the `edge.*` config keys and
the repository name all still say Edge Lab. That is internal naming with no
user-facing surface, and renaming it means touching nearly every file for no
behavioural gain, with a real chance of breaking a command name that a
scheduler or a deploy script depends on. Worth doing deliberately if wanted,
not as a side effect of a brand change.

### One thing the name implies that the app does not do

The app does not support parlays, on purpose: every added leg lowers the
chance of winning, and books pay less than the true combined odds, so a
parlay is a worse bet than its parts. Nothing in the build changes with the
name. If parlay pricing is actually wanted - correlated legs, true combined
odds against the offered price, which is a real and interesting problem -
that is a feature to decide on rather than something to infer from branding.

## Singles only. Parlays descoped.

The engine is not being built. Bets are placed as singles, and selections are
combined by hand on the ticket when wanted.

That keeps the roadmap as originally specced - models, value finder, bankroll,
dashboard - with no parlay tables, combinatorics, correlation estimation or
same-game handling. It also removes the one feature that had no honest data
path, since the odds provider supplies no same-game parlay prices.

### What still applies when you combine picks on one ticket

Combining picks by hand is still a parlay in every way that affects money, so
two numbers from the research are worth keeping:

- **Edge compounds in both directions.** Parlay EV is the product of
  (1 + edge) across legs. Three legs at a real +3% each is +18.18%; three
  legs with no edge at -110 is -13.03%.
- **Growth is the better measure, and it goes the other way.** Three legs at
  +3%, staked at quarter Kelly: as separate bets the expected log growth is
  0.002368, as one three-leg ticket it is 0.001192. Same legs, same edge,
  same bankroll, **roughly half the long-run growth**, because variance rises
  faster than return.

So the app will keep sizing and recommending singles, and the honest guidance
is that placing them as separate bets grows a bankroll faster than stacking
them on one ticket. A stake suggested for a single is sized for that single:
once several are combined on one ticket, the risk is no longer what the app
calculated.

## The verdict gate had to become statistical

The first version asked whether the model beat the market on log loss at all,
with a zero threshold. A test then fed it a model that *was* the market -
same probabilities, only integer-rounded through American prices - and it
came back **Proven**. Rounding noise cleared the bar.

That is the failure this whole app exists to avoid, so the gate now compares
the two **game by game** rather than comparing two aggregates. It keeps the
paired per-game difference in log loss, its mean and its standard error, and
requires the improvement to sit at least 1.64 standard errors above zero - a
one-sided 95 percent bar. A model indistinguishable from the market now
returns Unproven, which is the honest answer.

The same guard applies downward: a market is only called Negative when it is
significantly worse, not merely worse. Mildly worse is Unproven.

### Why both conditions are required, with a worked example

Closing line value on its own is gameable, and not even deliberately. CLV
here asks what a **home** bet struck at the model price would have been worth
against the close. A model systematically biased toward the away side
therefore shows positive CLV on every market while being badly wrong.

A test does exactly that: a model that predicts the opposite of the truth
scores **+3.9% average CLV** and still loses to the market on log loss. It is
refused because both conditions must hold. Either one alone would have passed
it.

A later refinement worth making: measure CLV on whichever side the model
actually wants to bet, rather than always the home side. The AND-gate covers
the hole in the meantime.

## The first real verdict: NFL moneyline is NEGATIVE

The backtest has now run against 1,615 real NFL games from 2020 onward, with
real closing lines imported free from nflverse. 1,415 of them scored.

```
log loss 0.6437   brier 0.2258   calibration error 0.0482   auc 0.684
market log loss 0.6103   improvement -0.0334   t-stat -5.75
average CLV -1.82%   flat ROI -4.65%

NEGATIVE EDGE
Loses to the closing line by 0.0334 on log loss with -1.82% average CLV.
```

Tuning the K factor on early seasons and scoring only on later ones improved
the model to 0.6381 and changed nothing about the conclusion: the market
still wins by 0.0307, at 4.26 standard errors.

This is the system working. Elo is a real model - an AUC of 0.684 means it
orders games well, and a calibration error under 5 points means its
probabilities are roughly honest. It is simply not better than a market that
has absorbed injury news, weather, lineups and sharp money. Almost nothing
is.

**No NFL moneyline pick can be produced until a model beats this.** That is
the entire purpose of the gate, and the first thing it did with real data was
refuse the baseline model.

What would plausibly change it: the feature model rather than ratings alone,
rest and travel, injuries and quarterback availability, weather for outdoor
games, and the markets where the closing line is softer than the moneyline.
Those are Phase 2 work that now has a measuring stick.

## Still private. The paid-member system is not being built.

A spec arrived for paying members, plans, subscriptions, payments and
revenue reporting. It is discarded: this remains a private, single-user tool
and nothing is being sold. That matches `MASTER_PROMPT.md`, which has said so
from the first line.

Not built, and not planned: roles, membership plans, subscriptions, payment
records, invitations, member management screens, revenue reporting.

The security half of that request **was** built, because a private tool
holding its own money deserves it more than a product would.

## Master login and the security log

- `edge:admin:create` makes the one account. The password is prompted with
  terminal echo off, never passed as an argument - arguments land in shell
  history and in the process list where any other user on the machine can
  read them - validated at 12 characters with letters, numbers, symbols and
  a breach check, hashed immediately, then overwritten in memory. The audit
  row records that an account was created and nothing about the password. A
  second account is refused outright.
- `login_activity` and `audit_events` are append-only, with Postgres
  triggers. A security log that can be quietly rewritten is worse than none,
  because it looks like evidence.
- Logging hangs off the framework auth events rather than the login form, so
  any future login path is covered without anyone remembering to add it.
- `SecurityLogger::scrub()` strips anything credential-shaped at any depth
  before writing. These tables cannot be edited afterwards, so a leak into
  them would be permanent.
- Breeze already rate limits five attempts per email and IP and regenerates
  the session on login; both are now covered by tests rather than assumed.

Tests assert the things that are usually only claimed: a failed attempt
stores the email tried and not the password, the sixth attempt is refused
even with the correct password, the session id changes on login, the log
refuses to be edited, and every private route redirects a stranger.

Still to do: TOTP multifactor, and a security screen to read the log.

## The betting day is yours, not the server's

The daily loss limit and the daily exposure cap were measured over a UTC
day, because `now()` runs on the app timezone and the app timezone is UTC.
Laravel Cloud and the database both want UTC, and that part is right.

The window cut from it was not. UTC midnight is 8pm Eastern in summer and
7pm in winter — not a quiet hour, but the middle of the evening card, with
the primetime game still to come. So a loss limit reached during the
afternoon games released itself a few hours later, and the exposure cap
started the evening fresh at the full 6% on top of whatever was already in
play. The one subsystem whose entire purpose is to be un-overridable had a
standing nightly override, and the README's claim that "a lockout has no
override" was false every evening between 8pm and midnight.

The limits now run on `APP_DISPLAY_TIMEZONE` — the same setting the board
already used to decide which games are "tonight". The boundary is computed
in one place, `LimitGuard::window()`, and the tests assert against that
method rather than keeping a second copy of the convention, because a
second copy is how the page and the guard came to disagree about what day
it was in the first place.

Two details worth keeping:

- Period rows are keyed on the **local** calendar date. `placed_at` is
  stored in UTC and a Carbon binds as whatever wall time its own zone
  reads, so the window is converted back to UTC for the query rather than
  handed to the database as local time.
- This was found by a test that failed only after 8pm Eastern. It had been
  passing all day, every day, for as long as the suite had been run before
  the evening. A time-dependent test that fails some of the time is
  reporting a real bug about some of the time, and the hours it picked
  here were exactly the hours the tool is used.

### Still a server day, deliberately

Nothing else moves. The scheduler has no daily jobs, odds snapshots and
predictions stay stamped in UTC, and the append-only history is unchanged.
Only the two self-imposed limits care which day it is, because only they
reset.

## Both bonus tools, and the one thing a middle finder is for

Phase 3 asked for an arbitrage detector and a middle finder as bonus tools.
Both tables had been laid down in Phase 1 and nothing had ever written a row
to either. Building them turned up three things worth keeping.

**A middle can never be worth taking on the board alone.** Not usually - 
never. To middle a market you have to buy the low leg at the book showing
the low number and the high leg at the book showing the high one, and each
of those is the favoured side of its own market; that is exactly what makes
the gap a gap. So the chance of the gap landing is already priced into both
legs, and each book's margin is charged on top of it. Formally: the
break-even hit rate is the overround on the two prices taken, while the
market-implied hit rate is the two no-vig probabilities less one, and the
second is below the first for any pair of prices where both books hold a
margin. A unit test asserts it over thousands of random boards under both
vig methods.

The first version of that test asserted the opposite - it was written
expecting to find a board where a wide gap paid - and it failed. The
counterexamples it produced all had a book with a *negative* hold, which is
an arbitrage inside one book and belongs to the other finder entirely. Once
those are excluded the inequality is strict.

So the feature's purpose is narrower than it looks: a middle is worth taking
only when the model says the result lands in the gap more often than the
price implies. The finder records the board's estimate and the model's side
by side and never averages them, because when they disagree the
disagreement is the finding. Width is deliberately not the headline.

**Spread arbitrage was impossible to find, and nothing would have said so.**
The two sides of a spread are stored at opposite signs of the same number -
home at -3, away at +3 - so the first version, which filed both sides of a
market under a shared line key, never put the two legs of a spread in the
same bucket and silently found nothing. Totals hide the bug completely,
because both sides there are quoted at the same number, and a sweep over
real data returned zero without complaint. The quote index is now keyed by
side before line, and a test pins the -3/+3 pairing specifically.

**Staleness is the real failure mode, not arithmetic.** Nearly every
arbitrage a sweep turns up is one book that has moved and one that has not
been polled since, so stale quotes are refused and the age of the older leg
is recorded on every row. A row with one leg at a book with no account is
kept and marked unplaceable rather than hidden. The profit quoted is what
survives rounding the split into whole cents, which on a thin arbitrage at a
small stake is nothing at all.

Neither tool consults the model to find anything, neither produces a pick,
and neither is sized. The reference stake is only the yardstick the split
and the profit are quoted against: an arbitrage has no edge to size against,
and a middle is sized by what you are prepared to lose on a miss.

## The second factor, and why nothing logs in until it passes

TOTP is written here rather than pulled in - RFC 6238 is about forty lines
of HMAC and base32, every line checkable against the RFC's own test vectors,
and a dependency in the login path is a dependency that can change under
you. The tests check the implementation against those published vectors
rather than against itself, because an implementation that only agrees with
its own output is consistent and possibly useless; what has to be true is
that a phone app written by someone else from the same RFC produces the same
digits.

Four decisions in it:

- **Nothing calls `Auth::login` until both factors are in.** The password
  step leaves a pending marker in the session and returns. That means the
  framework's `Login` event, and the success row the audit log writes off
  it, fires only for a sign-in that actually completed. A log that records a
  success at the password step reads as evidence that someone got in, which
  is worse than recording nothing. `Auth::validate` does not fire `Failed`,
  so the login form raises it by hand - without that, the failed-attempt log
  would have gone quiet for any account with a second factor on.
- **Enrolment is not finished until a code has been proved.**
  `two_factor_confirmed_at` is a separate column from the secret for that
  reason. A secret saved without the proof would turn the factor on for a QR
  code that might never have been scanned, and lock the only account out of
  a single-user app.
- **A used code cannot be replayed.** TOTP accepts a code for its whole
  thirty-second window, which is the one attack the algorithm does nothing
  about by itself. The accepted timestep is stored and anything at or before
  it is refused afterwards. The challenge is also rate limited in its own
  right: six digits is a million guesses, and a limiter that only counted
  password attempts would leave the weaker of the two factors unguarded.
- **The secret is encrypted, not hashed, and never serialised.** It has to
  be read back to check a code against, so it cannot be hashed; that makes
  it the only secret in this database that can be read, and it is hidden
  from array and JSON serialisation so a log line or a queued job payload
  cannot carry a working second factor.

The security screen shows the setup key only while enrolment is unfinished
and never again, because a screen that will redisplay a working secret to
whoever holds the session has given away the thing it was protecting.
Turning the factor off and replacing the recovery codes both require the
password, so a hijacked session cannot quietly remove what would have
stopped it.

There is no QR image. Drawing one would mean adding a dependency to the
login path, and every authenticator app accepts a typed key.

## Availability is collected now, and modelled later, for a specific reason

The injury adapter is built and verified against live data: one keyless
ESPN request per league, 800 NFL reports across all 32 teams, 71 NBA, 279
MLB and 127 NHL, with every team resolved and not one status the mapper
could not read. The status vocabularies were read off those live responses
rather than guessed, which matters because every league uses its own -
the NFL says Questionable and Injured Reserve, the NBA says Day-To-Day,
and MLB has 7, 10, 15 and 60 day injured lists alongside suspension and
paternity leave. NCAAF reported three team names it could not resolve and
logged them instead of attaching them to the nearest match; NCAAB returns
nothing at all.

An unrecognised label is stored as `InjuryStatus::Unknown` with the
source's own wording kept beside it, and `Unknown->availability()` returns
**null** rather than a number. Mapping an unknown label to Active would
declare a possibly-absent player fit, and mapping it to Out would bench a
possibly-fit one; a null forces a caller to decide what to do about not
knowing instead of handing it something that looks like an answer.

### Why no feature reads it yet

`FeatureSchema::VERSION` is still 1, and this is the reason.

The feed publishes availability **as it stands now**. It is not an archive.
So for a game played in March there is no record of what the injury report
said that week - only today's snapshot, which lists the injuries that are
*still* listed today. Using that for a past game would be reading which
injuries turned out to last, and how long an injury lasted is a fact from
after the game. It is leakage, and it is the kind that looks completely
innocent: the rows have honest February dates on them.

Filtering on `reported_at` therefore is not enough, and that is the trap
worth writing down. The rule is the stricter of the two timestamps - a
report is usable for a game only if it was both **reported** and
**fetched** before kickoff - and `InjuryReport::availableBefore()` is the
only correct way to read the table. It exists so that nobody has to
remember this, and four tests pin it, including the February-dated report
pulled in October that must not be visible to a March game.

Which means every historical training row has no injury data and cannot
get any. A feature absent from all of history is one the model cannot
learn a weight for, so adding it would bump the schema version, force a
retrain and a fresh backtest, and buy nothing. The same shape of problem as
the historical odds gap for the five non-NFL sports: forward-only
collection, honest about it, for months.

So availability is collected from today forward and shown on the Game
detail page, where it says in as many words that no model reads it. When
enough forward-collected history exists, the work is a schema version 2
that adds the features, which forces the retrain and backtest rather than
quietly changing what a stored model means.

### Weather and probable starters: what is actually blocking them

Not adapters. Reference data.

- **Weather** needs venue coordinates, and `venues` has none: 40 rows, zero
  with `lat`/`lon`, and `is_dome` false on every one of them including SoFi
  and Mercedes-Benz, which are both indoors. Open-Meteo answers happily
  without a key and the `weather_readings` table is already shaped for it -
  `is_forecast`, `valid_at` and `fetched_at`, which is exactly the right
  shape for the leakage rule above. The blocking work is populating venue
  coordinates and roof flags, which is reference data entry, not
  engineering. Until that exists a weather adapter would have nowhere to
  point.
- **Probable starters** are tractable for MLB today: the StatsAPI schedule
  endpoint answers with `hydrate=probablePitcher` and was confirmed
  responding. NHL starting goalies have no equivalent official pre-game
  feed, so that half would be unofficial scraping or nothing, and the
  honest version of the table would carry MLB pitchers and leave the goalie
  column empty rather than guess.

Both inherit the same history problem as injuries: a forecast fetched today
is no use to a game last March.

## Weather is collected, and forward-only for the same reason as injuries

The weather adapter is built and verified against the live endpoint:
forecasts at kickoff for outdoor venues, keyless from Open-Meteo. Seven
forecasts written on the first real run, 53 to 72 degrees across a late
October slate, Seattle the only one carrying rain. Domes are skipped
outright - a roof has weather and it is always the same weather.

### Forecasts only, and why

There was a decision already in the codebase here, and it was right.
`NflverseSource` has carried this comment since Phase 1:

> Game-time temp and wind are in this file too and are deliberately not
> taken: they are recorded after the fact, and training on them would be
> leakage wearing a weather costume.

The nflverse games file does hold observed `temp` and `wind` for every NFL
game since 1999, which is tempting precisely because it is a complete free
archive for the sport that needs weather most. It is still the wrong data.
What a bettor has before kickoff is a forecast, with a forecast's error in
it. A model trained on what the weather turned out to be would look
sharper in a backtest than it could ever be in use, and the gap would show
up as mysterious underperformance rather than as an obvious bug.

Open-Meteo publishes a reanalysis archive, and separately an archive of
past forecast runs. The second would be exactly right - what the forecast
actually said beforehand - and it does respond. It is not used, because the
API does not state which model run a historical request is answered from,
and a training set whose provenance cannot be stated is not one worth
having. If that can be pinned down, this is the one missing feature with a
genuine path to being trainable, which is more than injuries have.

So weather sits where availability sits: collected from today forward,
`is_forecast` true, `fetched_at` before kickoff, and no feature reading it.
Every run writes a new row rather than replacing the last, because a
forecast three days out and one three hours out are different facts and the
gap between them is the error any future model would have to live with.

### Putting venues on the map first

None of it worked at first, because `venues` held 40 rows with a name and
nothing else: no coordinates, and `is_dome` false on every one including
stadiums that are plainly indoors. A forecast needs somewhere to point.

Coordinates now come from whichever source knows best:

- **MLB publishes its stadiums' real coordinates**, one request for the
  league, which beats a geocoded town centre.
- **ESPN gives the city and the roof**, but only from its per-team
  endpoint - the team *list* omits the venue - so it costs a request per
  team. Fine for the four professional leagues, about 120 requests for a
  command run by hand; not fine for the college sports, where it would be
  several hundred, so those are left to the schedule pull.
- **Everything else is geocoded from its town**, which is accurate to a
  mile or two. That is the same weather and the command says so; nothing
  here should be read as a stadium-precise location.

Two details that cost a debugging round each. The geocoder sorts by
population rather than relevance, so "Springfield" has to be disambiguated
by state or half the American venues land in the wrong one and the weather
is confidently wrong rather than missing. And ESPN publishes "Highmark
Stadium (Old)" for a stadium the schedule source calls "Highmark Stadium",
so an exact name match loses a current venue over a parenthetical.

The result is 27 of 45 venues located and 8 roofs found. The remaining 18
split cleanly: NFL international games (Wembley, Azteca, Allianz Arena,
Arena Corinthians, Stade de France, Tottenham, Deutsche Bank Park) and
stadiums under previous sponsor names that nflverse's twenty-six years of
history still carries (Heinz Field, CenturyLink Field, Paul Brown Stadium,
FedExField). Neither group is a current home venue, and neither is worth
hand-maintaining.

`is_dome` is only ever set true, never back to false. It shares its value
with "never been told", so a feed going quiet must not be able to open a
roof. For the NFL the roof comes from nflverse's own word rather than from
ESPN, and "open" is read as a retractable roof that was not used - a
stadium is not a dome merely because it has a roof it did not close.

## Probable pitchers, and the goalie column that stays empty

The last of the three feature sources. MLB publishes probable starting
pitchers days ahead on its own schedule endpoint, and it is the single
biggest pre-game input in the sport. Verified live: the chain runs schedule
pull to event id to probable pitcher to handedness, and the first real run
named Jacob Misiorowski (R) for Milwaukee against Tampa Bay.

Three things worth keeping.

**Handedness is worth a second request.** It is not on the schedule
payload - nested hydration does not produce it either, which was worth
checking - so it comes from the players endpoint, batched: one request for
every pitcher on the slate, not one each. Left-handedness against a lineup
is most of why knowing the starter matters, so the extra call earns itself.

**Nothing claims to be confirmed.** MLB calls these *probable* and does not
publish a moment at which one becomes official, so `is_confirmed` stays
false on every row rather than being inferred from how close kickoff is. A
side with nobody named yet is counted and left empty, which days out is
most of the slate.

**Games are matched on the gamePk, never on teams and a date.** A
doubleheader is two games between the same teams on the same day, and a
name-and-date match would collapse them into one. Writing that test turned
up the fact that `events` carries a unique natural key of sport, start time
and the two teams - so the thing that actually distinguishes a doubleheader
in this schema is the start time, and a fixture that gave both games the
same one failed on the constraint rather than on the assertion.

### The goalie half is not being built

`probable_starters` has a `role` column with `pitcher` and `goalie` in it,
and only pitchers will ever be written to it. The NHL publishes no official
pre-game starting-goalie feed. The alternatives are scraping a beat
reporter's social account or inferring from warmups, and both produce a
column that looks like data and is a rumour.

An empty column is a statement that we do not know. A guessed one is a
statement that we do, and in a tool whose whole purpose is to be honest
about what it knows, that is the more expensive mistake. So the role stays
in the enum, the column stays empty, and this paragraph is the record of
why rather than a TODO that someone later mistakes for an oversight.

## All three sources are in, and none of them is modelled

With pitchers done, every feature source the spec asked for is ingested,
scheduled, tested and verified against live data. Not one of them feeds a
model, and `FeatureSchema::VERSION` is still 1.

> **Superseded in part.** The weather bullet below was wrong, and the
> section after this one says why and what replaced it: the archive of past
> forecast runs *is* usable, and weather now has 525 trainable readings.
> Availability and pitchers remain forward-only, so the heading still holds
> for two of the three.

That is the honest state and it is the same reason three times over:

- **Availability** comes from a current-snapshot feed with no archive, so
  no historical training row can have it.
- ~~**Weather** has an archive, but of what the weather *was*, and training
  on that overstates what a bettor could have known. The archive of past
  forecast runs would be right and its provenance cannot be established.~~
  **Wrong - see the next section.**
- **Probable pitchers** start accumulating from today, like availability.

So availability and pitchers are forward-only, and a feature absent from
every training row is one no model can learn a weight for. Adding those two
now would bump the schema version, force a retrain and a fresh backtest,
and buy nothing but the appearance of a richer model.

The work when the history exists is one piece: a `FeatureSchema` version 2
that adds the features, which forces the retrain and the backtest rather
than quietly changing what a stored model means. Everything up to that
point is done.

All three are shown on the Game detail page, which says in as many words
that no model reads them.

## Correction: weather is trainable after all

The previous entry said the archive of past forecast runs "would be the
right source" but was unusable because "the API does not make clear which
model run a historical request is answered from". That was wrong, and the
conclusion drawn from it - that weather is forward-only like availability -
was wrong with it.

Open-Meteo has a **previous-runs** endpoint that exposes each run as its own
series: `temperature_2m` beside `temperature_2m_previous_day1` and
`_previous_day3`. The values differ between them - 15.7, 16.2 and 19.0 for
one hour of one March afternoon - which is the evidence that these are
distinct forecasts rather than one number repeated under three names. A
`_previous_day1` reading is what the forecast said the day before, so
nothing in it was known after the game.

Coverage was measured rather than assumed, and it is not uniform:
temperature runs reach back to at least 2022, while wind, precipitation,
humidity and pressure thin out before 2024. Backfilling three seasons of
NFL games produced 525 readings at a one-day lead, of which 358 carry wind.
Both clear the verdict gate's 300-prediction floor, so a weather feature is
genuinely trainable - the only one of the three missing sources that is.

The reanalysis archive of what the weather actually *was* is still refused,
for the original reason, which has not changed and which `NflverseSource`
stated first.

### Lead time had to become explicit

Fixing this exposed a real flaw in the first version. It stored only
`fetched_at` - when we asked - which is meaningless for a backfill, where we
ask today about a game two seasons ago. Worse, it left live forecasts taken
six days out and six hours out indistinguishable, so a feature reading "the
forecast" would have silently mixed lead times and learned from a blend of
confident and speculative numbers.

So every reading now carries `lead_days` and the `run` it came from, and
`WeatherReading::atLeadDays()` is how a model asks. The fixed lead is the
point: training on whatever forecast happened to be newest and then
predicting from a six-day-out guess compares two different quantities and
calls the difference skill. The scope uses `>=` so a game missing its
one-day run falls back to an earlier, less informed one and never to a
later, better one.

Nothing was backfilled onto the rows written before the column existed.
`weather_readings` carries an append-only trigger in Postgres, so a
migration that amended them would have been refused by the database -
correctly - and the attempt was caught before it shipped only because the
guard list was checked. Those rows are live forecasts whose lead is
recoverable from their two timestamps, and `leadDays()` derives it.

### A retractable roof belongs to the game, not the building

The venue-level `is_dome` flag turned out to be the wrong granularity, and
the stored data said so plainly: NRG Stadium has 48 games with the roof
closed and 3 with it open, Lucas Oil 43 and 8, Mercedes-Benz Stadium 39 and
10. Any single flag calls those all indoor or all outdoor, and both answers
are wrong for some of the games. Whether to fetch weather now reads the
event's own roof word first and the venue only as a fallback, and "open"
counts as outdoors - a roof that was not used.

### Venues: all 47 located, and the roofs read rather than recalled

The weather backfill was initially limited not by the archive but by
venues: 74 of the games in range sat at venues with no coordinates. Those
were stadiums under previous sponsor names, which nflverse keeps for its
twenty-six years of history while ESPN reports only the current one, and
the neutral sites of international games, which belong to no team and so
appear in no team's payload. Supplying their cities by hand - geography,
and nothing else - took coverage to 47 of 47.

The roofs were deliberately **not** supplied by hand, and that restraint
paid for itself immediately. `markFixedDomes()` derives them from the roof
words already on the events, and it found SoFi Stadium and the
Mercedes-Benz Superdome both marked outdoor - 150 indoor games that would
have been given wind readings. A venue counts as a fixed dome only when
every game there with a known roof was covered, which is what keeps the
retractables out of it.

## The weather audit, and why there is no weather feature

With weather finally trainable, the obvious next step was a `FeatureSchema`
version 2 carrying wind and temperature. That step was not taken, and the
reason is a measurement rather than a hunch.

Two things made it worth measuring first. The feature model serves the
**moneyline only** - `ModelTrainer`, `TrainingSetBuilder` and `Forecaster`
all default to `Market::Moneyline`, and the moneyline is the market weather
touches least. And the live engine set the precedent: audit the feed before
modelling it, because a model built on data nobody checked produces signals
that are confident, consistent and wrong.

So `edge:weather:audit` asks three questions in the order that matters.
Does weather move the game? Does the market already know? And is anything
left over? The third is the only one with money in it, which is why it is
the headline: an audit that led with "wind correlates with scoring" would
be true, impressive and useless.

### The answer, on 525 NFL games

| Variable | Moves the game | Market prices it | Left over |
| --- | --- | --- | --- |
| Wind | -2.92 pts / 10 mph (t = -1.77) | -1.91 pts / 10 mph (**t = -4.00**) | -1.01 pts / 10 mph (t = -0.64) |
| Temperature | +0.43 pts / 10°F (t = +1.28) | +0.19 pts / 10°F (t = +1.83) | +0.24 pts / 10°F (t = +0.76) |
| Precipitation | -11.3 pts / inch (t = -0.35) | +10.0 pts / inch (t = +1.04) | -21.3 pts / inch (t = -0.69) |

This is the textbook pattern and it is worth reading carefully. Wind
genuinely suppresses scoring - about three points per ten miles an hour -
and the market knows: the closing total drops nearly two points per ten
miles an hour, and that is the only strongly significant number in the
table. What is left after the closing line has had its say is about a
point per ten miles an hour, on 358 games, at t = -0.64. That is noise.

Resolving a leftover that small would take roughly **3,400 games** -
decades of outdoor NFL football - and that figure is itself optimistic,
because it assumes the effect is real and exactly this size. So the
honest conclusion is not "weather does not matter". It is that weather
matters, the market has already charged for it, and the residue is too
small to find with the data that will ever exist.

`FeatureSchema::VERSION` therefore stays at 1. A weather feature would fit
the noise, and the backtest would flatter it.

### Two bugs the audit caught on itself

The first run reported wind leaving a strongly significant residue,
t = +4.00, "worth a model". It was an artefact, and the giveaway was that
every `actual_total` correlation came back as exactly 0.000 while every
residual was exactly minus its closing-total figure. Both are the signature
of a constant: the audit had asked for `home_score` and `away_score`, and
this schema calls them `final_home_score` and `final_away_score`, so every
total was zero. A constant correlates with nothing, and the residual
collapsed to the negative of the closing-line term. The number looked like
a finding and was arithmetic.

The second was quieter. `Correlation` looped positionally over arrays that
arrive from a filter, which leaves gaps in the keys, and read past the end.
It now re-indexes inside `assertPaired` rather than trusting every caller
to remember.

Both are the reason `WeatherAuditTest` builds boards whose answer is known
by construction - an effect fully priced, and the same effect only half
priced. An audit that cannot detect a surviving signal on a board built to
contain one has not earned the right to report that there is none.

## Two silences that looked identical, and the one market nobody prices

Opening the app showed every Spread and Total cell reading "NOT ENOUGH DATA
YET / no backtest", in grey, next to an NFL Moneyline reading "NEGATIVE
EDGE". The moneyline label is true. The other seventeen were not, in the
specific way this app is supposed to avoid: "not enough data yet" is a
promise that collecting more will settle it, and nothing will ever settle
those cells, because no model has ever been trained to price a spread or a
total. They would have sat there for years looking like work in progress.

They now read **"No model - nothing prices this market"**, which is a
different claim and the true one. A backtest run counts as evidence the
market was modelled whether or not a version row survived it, so a real
verdict is never hidden behind the new label; that distinction cost one
regression in an existing test, which is how it was found.

### Why no spread model, when the pricer can already price one

`NormalMarginPricer` supports all three markets and handles the push mass on
whole numbers, which in the NFL sits heavily on 3 and 7. The machinery is
there. It is not wired, and the reason is worth stating rather than leaving
as an apparent oversight.

The Forecaster derives its margin as `sigma * inverseCdf(probability)` - the
margin consistent with the win probability under a fixed per-sport sigma. It
is a restatement of the moneyline number, not a second opinion, and the code
already says so. A spread probability computed from it would therefore carry
no information the moneyline does not already carry: same model, different
hat. Shipping it would add a column, a verdict and a retrain, and tell
nobody anything new.

A real spread model would need its own margin estimate - ratings that learn
margin directly rather than a probability converted into one. That is the
same conclusion the totals note reached from the other direction.

The backtest would also need surgery to judge one. `BacktestGame` carries
closing *prices* but no closing *line*, so grading a cover means loading the
closing handicap per game, deciding pushes, and excluding them from log loss
- changes to the most safety-critical module here, the one the leakage tests
and the verdict gate both sit on. That is not work to do speculatively for a
model that restates another.

## Phase 6, verified rather than asserted

The claims in the README about setup were checked rather than trusted:

- **It runs with no API keys.** A clean database migrated and seeded with
  `ODDS_API_KEY` and `CFBD_API_KEY` empty produces six games across three
  sports, 36 priced lines, 288 snapshots and a working odds-movement
  sidebar. Every row is a pass, labelled "No scan has run yet", because the
  fixtures deliberately write no predictions.
- **`composer run dev` resolves.** All four processes it starts - serve,
  queue listener, pail, vite - are installed.
- **The npm advisories are dev-only, and there are still seven.**
  `npm audit --omit=dev` reports zero; the full audit reports 2 moderate and
  5 high, all in the Breeze build toolchain. Not force-upgraded: the build
  works, nothing reaches a browser, and a major-version bump to silence a
  build-time advisory is a worse trade than the advisory.

## A pre-game table that could be written after the game

Found by asking what the leakage test actually covers. It guards
`feature_sets` - the feature vector path - and nothing else, which is
correct for what currently feeds a model but says nothing about the three
point-in-time tables added this week.

Checking those against their own rules turned up a real hole.
`MlbProbablePitcherSource` asks the schedule for today through today plus
five, and today's window includes games that have already started. A
probable pitcher written for one of those is stamped `reported_at = now()`,
which is after its own first pitch: a box-score fact sitting in a table
whose whole purpose is to record what was known beforehand. No feature
reads it today, which is exactly why it was worth fixing now - the moment
one does, the row reads as foreknowledge and nothing in the schema would
object.

Games that have already started are skipped, counted separately, and the
leakage test now carries four data-level guards beside the feature-set
ones: that no starter is written for a game in progress, that no stored
starter postdates its own kickoff, that an injury report fetched after
kickoff is invisible to `availableBefore` whatever date it carries, and
that a weather reading with no lead time never reaches `atLeadDays`.

## The Postgres job stays a CI-only proof

Three attempts, and it cannot be run on this machine. Local PHP ships
`php_pdo_pgsql.dll` but does not enable it, which is a flag away and fine.
Docker Desktop is installed and its Linux engine returns a 500 because
**WSL is not installed at all**, and installing WSL needs elevation and a
reboot. The remaining route is installing PostgreSQL as a service, which is
a persistent change to someone's machine and not a thing to do unasked for
a verification CI already performs on every push.

What was done instead, and what it is worth:

- Every migration's DDL was compiled against the Postgres grammar offline -
  alters and rollbacks - and all of it is clean. This catches unsupported
  column modifiers, and confirmed the `after()` calls are silently ignored
  there rather than rejected, which is the thing worth knowing since they
  are MySQL-only.
- `MigrationsTest` runs the whole chain forwards and rolls all of it back
  against a throwaway SQLite file in a separate process. One fresh database
  puts every migration in a single batch, so one rollback reverses the lot,
  which is what CI does.

Neither proves a trigger fires. The append-only triggers and the check
constraints exist only in Postgres, so the first real execution of them is
CI. That is stated here rather than left as an assumption, because the
earlier `lead_days` migration would have passed everything above and still
failed on CI - it tried to UPDATE a table carrying an append-only trigger,
and was caught by reading the guard list rather than by any test here.

## Settled: the app reads its own plan size

*Needs a decision #1* asked which Odds API plan this runs on, because the
cadence spends thousands of requests a month and the free tier is 500. It
no longer needs answering by hand. The provider states how many requests
are used and how many remain on every response, those two add up to the
plan, and both have been recorded in `api_requests` since Phase 1 without
anything ever reading them.

`ApiMeter::observedQuota()` reads them now, and the budget guard reserves
against the real plan rather than the configured guess. `edge:doctor`
prints both and says plainly when they disagree.

This fixed a silent failure that would have hit on the first real key. The
reserve was a share of `ODDS_API_MONTHLY_QUOTA`, which defaults to 20,000.
On a free tier of 500 that reserve is 2,000, so `remaining > reserve` was
false on the very first check: every pull refused, 480 requests sitting
unused, and nothing on any screen explaining why the odds never arrived.
The configured number can now be wrong in either direction without
breaking anything, which is the right property for a value nobody can
verify before their first call.

## CI found what three local checks could not

The push that followed turned up both halves of what pushing was for.

**The Postgres job passed.** Migrations apply, `edge:verify:guards` confirms
the append-only triggers actually refuse an update, and the whole chain
rolls back - on real Postgres, which is the one thing that could not be run
here. The triggers and check constraints exist nowhere else, so this was
their first real execution.

**And one test failed, which was mine.** `MigrationsTest` shelled out to
`php artisan tinker --execute="... fn($t) => $t['name'] ..."`. Inside a
double-quoted shell word `$t` is a variable to every shell except the one
on this machine, so on Linux it expanded to nothing, the closure returned
an array, and the probe printed "Array". It passed locally and failed on
both PHP versions in CI.

It now reads the table list straight off the SQLite file with PDO. No
shell, no quoting, no platform difference - and the lesson is the narrow
one: a test that drives the app through a shell is testing the shell too.

## One character was dropping four college teams

The unmatched name log had five NCAAF entries, 25 hits each, last seen
minutes before they were found. Every game those teams played was being
discarded on every pull, and nothing anywhere said so - the log is exactly
the place that was supposed to say so, and nobody was reading it.

All five were the odds feed and ESPN disagreeing about how to write a
college, and they split three ways.

**One was punctuation.** ESPN writes Hawai'i; the odds feed writes Hawaii.
`NameNormalizer` turned every mark into a space, which is right for a
hyphen joining two words and wrong for an apostrophe inside one: it made
`hawai i` of the first and `hawaii` of the second. Apostrophes are now
removed instead, which also fixes St John's and anything else carrying one.
`Str::ascii` folds the okina and the typographic quote to a plain mark
first, so one character covers all three spellings a feed might send.

**Two were truncations.** App State for Appalachian State, Southern Miss
for Southern Mississippi. `edge:teams:resolve` required every word of the
unmatched name to appear in the team's, so the long form never reached the
short one. It now also accepts a target word that is the front of a raw
word. The "exactly one candidate" rule is untouched and is what makes that
safe: widening can only turn a miss into a link or into an ambiguity, and
an ambiguity is still printed for a person.

**Two were neither.** UMass is not a truncation of Massachusetts, and Sam
Houston State lost a word rather than shortening one. Those are facts about
two names for one school, so they are written down in
`database/data/college_aliases.php` and seeded - in the repo rather than
aliased by hand in production, so a fresh install gets them.

### And the fix found the previous two bugs waiting for each other

Re-importing NCAAF produced a second Hawai'i row. The import keyed on the
slug, the apostrophe fix changed Hawai'i's slug, and the id lookup that
should have caught it found nothing - because `espn:62` for NCAAF had been
claimed by the NCAAB team back when source ids were not scoped by sport.
Three bugs, one row: a husk with the old slug and no history, and a new row
with the right slug, the ESPN id, a live game and 34 snapshots.

The import now finds a team by its source id and treats the slug as a
label, which is what it always was. `edge:teams:dedupe` merges rows that
are one team twice, and which copy survives is not a preference: four of
the tables pointing at a team are append-only behind a trigger, so their
rows can be neither repointed nor cleared by a cascade, and whichever team
they reference is the only one that can live. Both copies referenced that
way is refused and printed, because which team really played is a question
about the world. `edge:doctor` counts duplicate names now - two have
happened and both were found by accident.

### The pure-read bug underneath the cleanup

Hawai'i resolved without anything being aliased, so its log row survived
describing a problem that no longer existed. Clearing it looked like one
line - ask whether the name resolves, delete the row if it does - and that
line was a bug. `resolve()` is the pipeline's door and is not a pure read:
a miss is logged for a human and memoised on the instance, and the instance
is container-scoped. Asking it wrote a null that outlived the alias written
two lines later, and the same process kept returning the failure it
remembered. There is now `matches()`, which answers and changes nothing,
and `learnAlias()` forgets what it had memoised about the name.

The delete stays out of the ingest path on purpose. One per successful
resolve is a query per team per pull, and this project has already paid
twice for that shape.

## The app had no opinion, and the reason was never code

Production had been ingesting cleanly for days - half a million odds
snapshots, injuries flowing, the cross-book scanner running every five
minutes - and had produced exactly nothing to bet on. Not because anything
was broken. There was no trained model, so `model:predict` skipped every
hour, so there were no predictions, so the board had nothing to compare
the market against.

`edge:backfill` had never been run. The database was three days old and
held sixty completed games, against a verdict gate that will not speak
below three hundred predictions.

Three seasons for every sport fixed the input. Two further links were
missing behind it, and both were the same shape - a step that only ever
runs on fresh data, applied to history that arrived all at once:

**Chained jobs land at the back of the queue.** `Bus::chain` dispatches the
next job when the previous finishes, which puts the NFL line imports
behind every other sport's schedule chunks - tens of thousands of college
basketball games. Nothing failed; the one sport with free line history was
simply going to wait hours for the data that proves it. Run directly
instead.

**Closing lines are not where the trainer reads them.** The import writes
`odds_snapshots`; `TrainingSetBuilder` reads `event_line_markers`, and
`MarkClosingLines` is what promotes one to the other. It runs hourly over
games that just started, so three seasons of backfill were invisible to
it. The job is written to pick up "anything started and never marked,
however old" - that line was already there, and it is the only reason a
manual pass could rescue this - but nothing was going to invoke it.

### And then the answer was no

With 816 NFL games carrying a closing line to measure against:

| model | log loss | auc | versus the close |
|---|---|---|---|
| Elo | 0.6224 | 0.719 | -0.0336, -3.3 standard errors |
| Regularized logistic | 0.6448 | 0.682 | -0.0515, -4.8 standard errors |

Both lose. The feature model loses by more than the rating it was built to
improve on, which is worth knowing on its own.

This is the result, not a stage on the way to a better one. The NFL
moneyline is the most efficient market in sport and three seasons of Elo
does not beat it; -3.3 standard errors is not noise to be tuned through.
The gate holds on its own - eighteen candidates, no picks, every one
passed as unproven - which is the whole reason the gate was built before
any model existed.

What this does not say: that there is no edge anywhere. It says there is
none here, in this market, from this model. Spreads and totals are
untested, the less efficient leagues are untouched, and the cross-book
scanner needs no model at all.

## Vigil, and the three open questions it closes

Edge Lab is being consumed by a second product — Vigil, a public
subscription front end in its own repository. The modelling, the ingestion
and the verdict gate stay here and stay private; Vigil adds accounts,
billing, an explanation layer and a public track record, and reads this app
through a private read-only API.

That settles three things that have been sitting in this file.

**1. The Odds API plan size, settled: 25,000,000 credits.**
`ODDS_API_MONTHLY_QUOTA` is now 25000000, and the cadence has moved to every
two minutes inside an hour of kickoff, quarter-hourly inside twelve hours,
hourly inside a week.

One correction that came with it: the budget was being counted in *calls*,
which was correct while every call cost one credit and stops being correct
the moment props and history are pulled. A props call costs a credit per
market per region returned; a historical call costs ten. The provider has
reported the price of every call in `x-requests-last` since the beginning and
nothing read it. It is stored now, in `api_requests.credits_cost`, and
`edge:doctor` reports credits rather than a proxy for them.

**2. Historical odds for the other five sports, settled: buy them.**
`edge:odds:backfill` pulls closing lines from the historical endpoint for
NBA, MLB, NHL, NCAAF and NCAAB. NFL stays on the free nflverse import and is
not in the default set.

The guard matters more than the command. Ten credits per market per region
means a season of a sport that plays most nights is a five-figure number
before anyone has looked at it, so the command prices the job first, refuses
outright when the estimate is more than a configurable share of the credits
still spendable this month, and has an `--estimate` flag that spends
nothing. The share is of *remaining* credits rather than of the plan, because
the plan is not what can be spent — what is left is.

One request returns every game in the sport as at one moment, so kickoffs
within fifteen minutes of each other share a request. That is the difference
between a Saturday of college football costing one request and sixty.

**3. "Still private. The paid-member system is not being built", revised.**
Still true of *this* app. Edge Lab has one login and gains no customers, no
billing and no public pages. What it gains is `/internal/v1`, a bearer-token
read path with an IP allowlist, a rate limit and an append-only access log in
`internal_requests`. See `docs/INTERNAL_API.md`.

### Player props go in the same table, and can never become a pick

Props are stored in `odds_snapshots` beside everything else, because a prop
quote is the same kind of fact as a moneyline quote — a book, a side, a
price, a moment — and two tables would mean two closing-line markers, two
line shoppers and two places to remember that a twenty-minute-old quote is
not a price on offer.

What a prop is *not* is a `Market`. That enum exists so the three core
markets can be matched exhaustively: it answers `sides()`, `hasLine()`, which
pricer to run and how to label a selection, and a prop has none of those
answers. Adding prop cases to it would have broken every one of those matches
in a way PHP does not check until runtime.

So: a prop row carries a raw `market_key` and a null `market`, a core row
carries `market` and a null `market_key`, and Postgres has a check constraint
saying exactly one of them is set. The practical effect is the one worth
having — every reader that feeds a prediction, a verdict or a stake asks for
a core market by name, so a prop cannot reach one by accident. The two
readers that genuinely wanted every quote on a game, `MarkClosingLines` and
`CrossBookScanner`, now say `coreMarkets()` out loud.

Props are display and line shopping only until a props model passes the gate,
and that is now a property of the schema rather than a convention somebody
has to remember.

`Side` gained `Yes` and `No` for one market: anytime touchdown scorer, which
a book prices as a single selection rather than as two halves of a line.
Nothing in a core market can produce one.

### A time-of-day flake, fixed while passing

`CommandCenterTest` had two tests that scheduled a game five hours out and
asserted it grouped under "Today". Run live after seven in the evening
Eastern — which is when a board is actually read — those five hours cross
midnight and the assertion fails on a product that is working correctly. The
clock is pinned in both now. A test that only passes before dinner is worse
than no test.
