# Design source material

Saved here so it survives a Downloads folder. None of it is wired to the
application yet.

```
layout/     two candidate front-end layouts, as standalone HTML
stealth/    Theme 4 "Stealth Black" asset package + brief
fullstack/  nine-screen mockup package + brief
showcase/   rendered design showcases
```

## The two layouts

Both are static Tailwind-CDN pages. Neither is Blade, neither reads a
database, and the numbers in both are invented.

**`layout/vigil_sports_betting_intelligence.html`** is the stronger of the
two and matches the nine-screen package: homepage, Today's Games, parlay
drawer, AI Picks, My Picks, Results & Analytics, Admin Dashboard, AI
Engine Control, User Management.

It is also the one that already understands the product. It carries a
section headed *"Nothing here is sellable, and here is why"* — 24,610
candidates judged, each market marked "Loses to the market", and the
declination reasons broken out (18,422 no price, 4,400 market unproven,
1,788 stale quote). That is the board being honest about itself, and it is
the single most important thing on the page. Keep it.

**`layout/pickvigil_homepage.html`** is a marketing page and needs more
care. It advertises a "Verified Tipster & AI Leaderboard", a blog, and
three trademarked engines — SteamVigil™, EV-Hunter™, Kelly-Guardian™ —
none of which exist. Its tiers are Free Sentinel / Pro VIP Access / Whale
Syndicate at $0 / $49 / $499, which is not the pricing that was settled
on. Taken literally it would describe a different business.

## What has to change before either one ships

Every figure in both files is a placeholder. These are the ones that
would be false the moment the page went live:

| Shown | Actually |
|---|---|
| Win rate 62%, ROI +18.4%, net profit $1,245 | no market has beaten the closing line; `/record` returns 404 |
| Per-sport 68% / 61% / 58% / 63% | NCAAB, NCAAF and NHL h2h all read `negative` — they lose to the market |
| Three AI picks at 78% / 72% / 69% confidence | 0 published opinions live; the engine recommended **nothing** today |
| Model accuracy 62.8%, "Engine v3.2 Active" | the four active models are `calibrated`, not `proven`; there is no v3.2 |
| 2,548 users, 1,843 active subs, 18,722 settled | 8 published picks, 24 opinions, 6 settled results all time |
| Christian Banks, Sarah Miller | invented people in a user table |
| Bills vs Chiefs, Lakers vs Celtics, Yankees vs Astros | hardcoded fixtures |

The engine's qualifying count has gone 209 → 88 → 4 → 0 over four days as
calibration tightened. An empty picks table is the correct output right
now, and `components/app/daily-picks.blade.php` already says so: *"We
would rather show you none than invent one."*

Both design briefs in this folder agree. `stealth/CLAUDE_IMPLEMENTATION_PROMPT.md`:

> No static mock stats in production; show labeled demo/empty states
> until actual backend data is connected.

and `fullstack/CLAUDE_MASTER_PROMPT.md`:

> Do not deliver a static mockup.

## Porting, when it happens

The work is to take the visual design and keep the behaviour, not to
replace one with the other. Specifically, these must survive:

- **The verdict gate.** `/record` and `/record/{pick}` 404 until a market
  is `proven`, and the home page drops its track-record strip with it.
  See `tests/Feature/Record/GateClosedTest.php`.
- **The OpinionBar gate**, which is a different and weaker claim. The
  engine's own opinions publish once a market's probabilities have been
  measured, and `/opinions` stays public and possibly empty rather than
  hiding.
- **Empty states as real states.** "No picks right now" is an answer, not
  a failure to load.
- **Canonical URLs pinned to `APP_URL`**, not the request host.

Anything hardcoded in these files that corresponds to a real figure needs
to come from the database instead — and where there is no figure yet, the
page should say so, which is what the dash in the accuracy strip is for.
