# Data sources

Every source is an adapter behind `App\Services\Ingestion\SportDataSource`.
Adding a sport is a config entry in `config/sports.php` plus one adapter class.

Each adapter declares how far it can be trusted. Anything not `official` gets a
label in the UI, so a silent upstream change looks like a problem rather than a
quiet week.

| Sport | Source | Key needed | Reliability | Verified live |
| --- | --- | --- | --- | --- |
| NFL | nflverse `games.csv` | no | community | yes |
| NBA | ESPN scoreboard | no | unofficial | yes |
| MLB | MLB StatsAPI | no | documented public | yes |
| NHL | `api-web.nhle.com` | no | unofficial | yes |
| NCAAF | CollegeFootballData | `CFBD_API_KEY` | documented public | not yet, no key |
| NCAAB | ESPN scoreboard | no | unofficial | yes (team list) |
| Odds, all sports | The Odds API | `ODDS_API_KEY` | documented public | not yet, no key |

## Notes per source

**nflverse (`raw.githubusercontent.com/nflverse/nfldata`)** - every NFL game
since 1999 with scores, rest days, roof, surface, temperature, wind, and
**closing spreads, totals and moneylines**. That last part matters: it is the
only free historical line data in the stack, so NFL is the one sport whose
backtest can be measured against the real market from day one. The file is
cached on disk for three hours and streamed row by row rather than decoded into
memory.

**MLB StatsAPI (`statsapi.mlb.com`)** - public and documented, the same feed the
league fronts. Carries probable pitchers, which the MLB model needs.

**NHL (`api-web.nhle.com`)** - public but undocumented. The schedule endpoint
returns a week at a time and tells us the next start date, so a season walk
follows its own pagination. Team names arrive localized, and with accents:
"Montréal Canadiens" normalizes to the same key as "Montreal Canadiens".

**ESPN (`site.api.espn.com`)** - undocumented, keyless, one day per request
(date ranges are rejected). Used for NBA and NCAAB because the NBA-run
endpoints return 403 to datacenter IPs, which is what Laravel Cloud is. Also
the source for the college team lists, via `edge:teams:import`. No odds history.

**CollegeFootballData** - documented and solid, needs a free key. Field names
moved from snake_case to camelCase between major versions, so the adapter reads
either.

**The Odds API** - one request returns every book, market and side for a sport,
which is why a pull costs one request per sport rather than one per game.
Quota headers are recorded on every call in `api_requests`, and the scheduler
refuses to pull once the monthly reserve is reached.

## Historical odds: the honest position

Backtesting against results alone proves very little. The benchmark that
matters is the no-vig closing line, and CLV is the measure of whether a bet was
good at the moment it was placed.

- **NFL**: covered now, free, back to 1999, via nflverse. Imported under a
  separate book called `historical_close` that is excluded from line shopping
  so it can never be mistaken for one of our own snapshots.
- **Everything else**: no free line history exists. ESPN does not return odds
  on completed games, and The Odds API historical endpoints are paid-tier and
  priced per timestamp.

So for NBA, MLB, NHL, NCAAF and NCAAB the market benchmark and CLV start from
the day this app begins taking snapshots. Until enough games have closed, those
markets report `not enough data yet` and produce no picks. That is a deliberate
choice: a model that has not been measured against the market does not get to
recommend a bet.

Buying historical odds would close that gap. It needs a decision, because the
spec says to ask before adding a paid API.

## Team name resolution

Source names are resolved in a strict order: external id for that source, then
a registered alias, then the team name or abbreviation normalized. Nothing is
fuzzy-matched. A name that does not resolve is written to `unmatched_names`
with a hit counter and shown by `edge:doctor`, so the fix is to add one alias
rather than to debug a silently wrong join.

When a name resolves and the source also gave an id, the id is recorded, so the
next lookup is an exact match.

## Live feeds, and what they do not publish

The live engine's first phase is an audit of these, not a model built on
them. `edge:live:probe` records every reading; `edge:live:report` says what
it found.

| Sport | Live sources | Clock | Possession | Stamps its readings |
| --- | --- | --- | --- | --- |
| NFL, NCAAF | ESPN scoreboard | yes | yes, with down and distance | no |
| NBA, NCAAB | ESPN scoreboard | yes | no | no |
| NHL | ESPN scoreboard, `api-web.nhle.com` | yes, both | n/a | neither |
| MLB | ESPN scoreboard, MLB StatsAPI | n/a | batting half-inning | StatsAPI only |

**Only MLB StatsAPI says when its reading was true.** Everything else hands
over a score with no statement of when it was the score, which means its
absolute staleness cannot be measured at all - only its lag relative to
another feed watching the same game. NHL and MLB are the two sports with two
independent feeds, so they are the only ones where even that is possible.
For NBA, NFL and the college sports there is currently one feed and no way
to check it.

**The Odds API is the exception that matters.** Every quote carries the
book's own `last_update`, so the gap between that and when we received it is
a real, directly measured staleness. It is the only number in the live audit
that needs no second opinion - and it needs a key, which is why the audit's
most important half does not run without one.

What nothing here provides: play-by-play, field position, timeouts,
lineups, fouls, or market suspension signals. A live model that needs those
needs a licensed feed, and that is a decision to take on the audit's numbers
rather than ahead of them.
