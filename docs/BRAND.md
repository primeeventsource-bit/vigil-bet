# Vigil brand — Dark Terminal

**Selected 2026-10-22.** This supersedes §10 of `MASTER_PROMPT.md` for every
visual decision: website, mobile app, login screen, dashboard.

The master prompt's own rule still holds: the name lives in one place
(`config('brand.name')`, from `BRAND_NAME`), so none of this is hardcoded in
a template.

## Palette

| Token | Hex | Use |
|---|---|---|
| `--vg-night` | `#07131B` | App background, deep navy / near-black |
| `--vg-green` | `#00E676` | Primary accent: success, edge, primary buttons |
| `--vg-navy` | `#0B1320` | Trust, stability; panel base |
| `--vg-blue` | `#3882F6` | Data, AI, links and focus rings |
| `--vg-slate-dark` | `#1E329B` | UI background / raised surfaces |
| `--vg-slate` | `#94A3B8` | Secondary text |
| `--vg-paper` | `#F8FAFC` | Primary text / contrast on dark |

Carried over from the master prompt and **not** negotiable by palette choice:
colour is never the only signal. Every edge, pass, win and loss also carries a
label or an icon, and all text meets WCAG AA contrast. The green/red pair in
the mockups fails that on its own.

## Type

- **Sora** — headings
- **Inter** — UI and body text
- A monospace face with tabular figures for prices, odds and percentages.
  The mockup does not name one; the master prompt said JetBrains Mono and
  nothing here contradicts it, so that stands until told otherwise.

## Logo

Futuristic angular **V** mark in `--vg-green` on `--vg-night`, with the
wordmark "VIGIL" in Sora, letterspaced, uppercase. Deliverables unchanged:
SVG mark, wordmark, app icon (512, 192, 180, favicon), OG image template.

## Tagline

"AI-Driven Edge" (mockup) — see the open questions below, because it collides
with a non-negotiable.

---

## Open questions, to settle before the brand is built

These are not style quibbles. Each one is the new design asking for something
`MASTER_PROMPT.md` §0 rules out, so each needs an answer rather than an
interpretation.

1. **"AI-Driven Edge" / an "AI Picks" nav item.** §0.4: *the AI explains
   numbers, it never produces them.* The edge comes from Edge Lab's models;
   the LLM only writes the explanation. Both the tagline and the nav label
   tell a customer the opposite. Options: keep the words and accept that they
   describe the modelling rather than the LLM; or rename to something true of
   the product ("Model-driven edge", "Today's picks").

2. **"Bet" buttons on each pick.** §0.1: Vigil never takes a bet, and §9
   keeps affiliate sportsbook links out of the MVP. A green *Bet* button is
   either one or the other. Options: relabel to "Log this bet" (writes to My
   Bets); or hold the affiliate decision open and drop the button for now.

3. **"Confidence" scores (78%, 73% avg).** Edge Lab stores model probability,
   fair probability, edge, edge at price and a verdict. It does not produce a
   "confidence", and inventing one from those would be Vigil making up a
   judgement, which §0.2 forbids. Options: relabel the column to "Model
   probability" and show the stored number; or define confidence as an exact
   stored field and say which.

4. **Headline win rate, ROI and units on the dashboard.** §0.7 allows these
   only when computed live from the public track record and shown with sample
   size, and §7 says show no rate at all at zero settled picks. The mockup
   shows 62% / +18.4% / +104.3u with no sample size. These need an "n = …"
   beside them and an honest empty state.

5. **Tagline replacement.** "We watch the line so you don't have to" is gone
   from the mockup. Confirm it is retired rather than kept as a secondary
   line.

6. **"Barlays"** in the mockup nav is a typo for Parlays.
