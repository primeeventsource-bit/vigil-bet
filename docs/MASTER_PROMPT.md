# MASTER PROMPT — VIGIL (working name)

Build **Vigil**, a public, subscription sports-betting analytics product. Vigil is the customer-facing brand. The math comes from **Edge Lab** (repo `primeeventsource-bit/edge-lab`, deployed as ParlayQuantum), which stays private and single-user. Vigil adds accounts, subscriptions, an AI explanation layer, a public track record and a marketing site.

The brand name must live in one place (`config('brand.name')`, from `BRAND_NAME` in `.env`) so the product can be renamed without touching templates.

---

## 0. Non-negotiables

1. **Vigil never takes a bet, holds customer funds, or runs a contest with a prize.** It sells information and tools. No deposit, wallet, payout, leaderboard prize or sweepstakes features, ever.
2. **Vigil never invents a judgement.** Every edge, verdict, stake, pick or pass shown to a customer is read from a row Edge Lab wrote. Vigil does not recompute, round up or "improve" them. If Edge Lab says pass, Vigil says pass.
3. **No pick is sold from an unproven market.** A market is "proven" only when Edge Lab's verdict gate says so (the Model Lab verdict). Until then, that market appears as analysis and tools, labelled "not yet proven," never as a pick.
4. **The AI explains numbers; it never produces them.** The LLM receives only stored figures and writes plain-English explanations. It is never asked who will win.
5. **Odds data is displayed, never redistributed.** The Odds API terms allow showing odds inside a paid UI but forbid reselling or repackaging the data. So: no public API, no odds CSV/JSON export, no odds feed, no scrape-friendly bulk endpoints. Customers may export **their own bets**; they may not export odds.
6. **Follow Christian's engineering conventions:** append-only event tables, forward-only migrations, money as integer cents, `writeAudit()` on every admin write, server-side role enforcement only, parameterized queries, atomic transactions with rollback, no silent failures (every action toasts success or failure).
7. **Honest marketing copy only.** No "guaranteed," "lock," "can't lose," "sure thing," or win-rate claims that aren't computed live from the public track record and shown with sample size.

---

## 1. Stack

- Laravel 12, Livewire 3, Tailwind, Vite (match Edge Lab so code and habits transfer).
- PostgreSQL in production, SQLite locally. Deploy on Laravel Cloud.
- Queue + scheduler on Laravel Cloud workers.
- Separate repository: `vigil` (do not fork Edge Lab; consume it).

---

## 2. How Vigil talks to Edge Lab

Edge Lab exposes a **private, read-only internal API**. Vigil is its only client.

**In Edge Lab** (small addition, done first):
- Route group `/internal/v1`, authenticated by a single bearer token (`VIGIL_INTERNAL_TOKEN`) compared with `hash_equals`, plus an IP allowlist from env. Rate limited. Every request logged to `job_runs`-style table.
- Read-only endpoints, paginated by `since` cursor (id or timestamp):
  - `GET /board` — current value scans (`value_bets` rows: event, market, side, model prob, fair prob, best price, book, edge, edge_at_price, verdict, stake fraction, pass reason, created_at).
  - `GET /events` and `GET /events/{id}` — schedule, teams, status, final score, injuries summary.
  - `GET /odds/{event}` — latest snapshot per book, for display only.
  - `GET /predictions/{event}` — the frozen prediction(s) and model version.
  - `GET /models` — model versions and their verdict-gate results per sport and market.
  - `GET /parlay/evaluate` (POST body of legs) — runs Edge Lab's existing parlay judge and returns its stored result.
- No write endpoints. Edge Lab's append-only guarantees are untouched.

**In Vigil:**
- `EdgeLabClient` service with typed DTOs, timeouts, retries with backoff, and a circuit breaker. A failure shows "data delayed" with the age of the last good sync — never stale data presented as live.
- Scheduled sync jobs pull into Vigil's own read-model tables (`vl_events`, `vl_board_entries`, `vl_odds_display`, `vl_models`). These are append-only mirrors keyed by Edge Lab ids.

---

## 3. Odds API usage (plan: 25M credits / month)

Edge Lab does the pulling; Vigil never calls The Odds API directly.

Edge Lab changes to make alongside this build:
- Set `ODDS_API_MONTHLY_QUOTA=25000000`.
- Tighten cadence: under 1 hour to kickoff → every 2 minutes; under 12 hours → every 15 minutes; under 7 days → hourly.
- **Player props** via `/events/{eventId}/odds`, starting with NBA and NFL core markets (points, rebounds, assists, threes; passing yards, rushing yards, receiving yards, anytime TD). Store in the same append-only snapshot tables with `market_key`. Props are display + line shopping only until a props model passes the gate.
- **Historical backfill** command for NBA, MLB, NHL, NCAAF, NCAAB closing lines (historical endpoint, 10 credits per market per region). Budget guard: refuse to start a backfill whose estimated cost exceeds a configurable share of the remaining monthly quota.
- Keep `edge:doctor` reporting credits used vs. quota.

---

## 4. Customers and tiers

Two audiences, one product: casual bettors who want simple daily answers, and serious bettors who want the tools.

| Tier | Price (initial) | For | Includes |
|---|---|---|---|
| **Free** | $0 | Try it | Odds board (moneyline/spread/total) for today; 1 Vigil Read per day (AI explanation of a pick or pass); bet tracker up to 25 bets; parlay grader 3 per day |
| **Vigil Daily** | $19/mo or $179/yr | Casual | Daily card: every pick from a proven market, plus the top "near misses," each with a Vigil Read; unlimited parlay grader; bet tracker with win/loss and ROI; push/email alerts when a pick posts |
| **Vigil Pro** | $59/mo or $549/yr | Serious | Everything in Daily, plus the full value board (every pick and pass with edge, EV and reason); line shopping across all tracked books; player-props board; closing line value on your own bets; arbitrage and middle finders; Model Lab transparency page; custom book list ("books I can bet at"); bankroll + Kelly sizing with loss limits |

- Prices, limits and features are defined in `config/plans.php`, not hard-coded. Feature checks go through one `Entitlements` service (`can($user, 'feature.key')`), enforced server-side in policies and Livewire actions, not only hidden in the UI.
- 7-day free trial on Daily and Pro (configurable). Annual = ~2 months free.
- Downgrade/cancel takes effect at period end. Cancellation is one click in Account; no dark patterns.

**Until a model passes the gate:** the Daily card shows "No proven picks today — here's what we're watching" with near-misses and Vigil Reads. This is a feature, not an empty state. Copy must never imply a pick exists when it doesn't.

---

## 5. Payments

Sports-information subscriptions are often classed as high risk, and Stripe's prohibited list includes sports forecasting with a monetary or material prize. Build so the processor can change without touching app code.

- `PaymentGateway` interface: `createCustomer`, `createSubscription`, `changePlan`, `cancelAtPeriodEnd`, `resume`, `refund`, `handleWebhook`, `customerPortalUrl` (nullable).
- First driver: **NMI** (common with high-risk merchant accounts). Second driver: **Stripe** behind a feature flag. Driver chosen by `PAYMENT_DRIVER`.
- Hosted/tokenized card fields only. Card data never touches Vigil's servers or logs.
- Webhooks: signature-verified, idempotent (store event id), append-only `billing_events` table. Subscription state is derived from billing events.
- Dunning: 3 retries over 7 days, emails at each, then downgrade to Free (data kept).
- Money in integer cents everywhere.

---

## 6. The AI layer — "Vigil Read"

A short, plain-English explanation attached to a pick, a pass, a parlay grade, or a customer's own bet.

- Anthropic Claude API via a `ReadWriter` service. Model from `VIGIL_AI_MODEL` (default `claude-sonnet-5-5`; allow `claude-haiku-5-5` for cheap bulk Reads). Key from `ANTHROPIC_API_KEY`.
- **Input is a JSON fact sheet built only from stored rows:** teams, kickoff, market, side, price and book, fair probability (no-vig), model probability, edge, edge at price, verdict, stake fraction, pass reason, model version and its gate result, line movement summary, availability notes (flagged "not modelled"), parlay hold and shaded edge.
- **System prompt rules for the model:** use only the fact sheet; never predict a winner; never state a number not in the sheet; always say whether the market is proven; if it's a pass, say why in one sentence; 60–120 words; no hype words; end with the responsible-gambling line only on picks.
- **Validation before saving:** every number in the output must appear in the fact sheet (regex extract + compare). Banned-word list check. On failure, regenerate once, then fall back to a templated non-AI explanation. Never show an unvalidated Read.
- Reads are generated once per (board entry id, prompt version), stored append-only in `vigil_reads`, and served from storage. Customer-triggered Reads (own bets, parlays) are rate limited per tier.
- Log tokens and cost per Read to `ai_usage` and surface monthly cost in the admin dashboard.

---

## 7. Public track record

The single most important trust and sales asset.

- Every pick Vigil publishes is written to `published_picks` **before kickoff**, with timestamp, price, book, model probability and stake fraction. Append-only; Postgres trigger rejects updates/deletes; check constraint rejects rows timestamped after kickoff.
- Settled automatically from Edge Lab results. Shows record, units, ROI and **closing line value**, always with sample size. With zero settled picks, show no rate at all.
- Public page `/record` (indexable), filterable by sport, market and month. Each pick has a permanent URL.
- Never mix paper results, backtests and published picks in one number.

---

## 8. Pages

**Marketing (public, indexable):**
- `/` home — hero ("We watch the line so you don't have to"), how it works in three steps, live sample of today's board (blurred beyond the first rows for logged-out users), track-record strip, tier comparison, FAQ, responsible-gambling footer.
- `/pricing`, `/record`, `/how-it-works` (vig, fair price, edge, CLV explained simply), `/blog` (Livewire-free, fast, for SEO), `/legal/terms`, `/legal/privacy`, `/legal/responsible-gambling`, `/legal/disclaimer`.

**App (authenticated):**
- **Today** — the Daily card (casual default view).
- **Board** (Pro) — full value board with filters: sport, market, book, proven only, picks/passes.
- **Game** — prices across books, no-vig probability chart (port Edge Lab's rule: probabilities on the vig-free scale), Vigil Read, injuries (marked "not modelled").
- **Props** (Pro) — props board with line shopping.
- **Parlay** — build a slip from board entries; grade via Edge Lab's parlay judge; Vigil Read on the result.
- **My Bets** — log bets (manual entry, from a board entry in one tap), auto-grading, ROI, CLV (Pro), CSV export of **own bets only**.
- **Bankroll** (Pro) — Kelly sizing, stake caps, daily/weekly loss limits with no override (port Edge Lab's behaviour).
- **Tools** (Pro) — arbitrage and middle finders, read-only from Edge Lab.
- **Model Lab** (Pro) — which markets are proven, the gate each was measured against, calibration chart.
- **Alerts** — email and web push for new picks, line moves on watched games.
- **Account** — plan, billing, invoices, cancel, data export, delete account, 2FA, responsible-gambling settings (self-exclusion timer, deposit-free reminder cadence, hide-the-app period).

**Admin:** users, subscriptions, billing events, refunds, AI cost, sync health from Edge Lab, published-picks ledger, feature flags. Every admin write calls `writeAudit()`.

---

## 9. Compliance and safety

- 21+ age gate at signup (date of birth + attestation). Store only the attestation result and year, not full DOB.
- Responsible-gambling line on every pick and in the footer: "If you or someone you know has a gambling problem, call 1-800-GAMBLER."
- Self-exclusion: user can lock themselves out for 7, 30 or 90 days; cannot be undone early.
- Terms: information and entertainment only, no guarantees, user responsible for legality in their state, no refunds on results.
- No affiliate sportsbook links in the MVP (keep the decision open; when added, disclose them).
- CAN-SPAM compliant email (unsubscribe, physical address). Double opt-in for marketing email.
- `noindex` on all app pages; index marketing pages only.
- Privacy: no selling user data; analytics privacy-friendly (Plausible or similar).

---

## 10. Brand identity

**Name:** Vigil — keeps watch over the line and the vig. Tagline: *We watch the line so you don't have to.*

**Palette** (define as CSS variables and Tailwind tokens; dark mode is the default, light mode supported):

| Token | Hex | Use |
|---|---|---|
| `--vg-night` | `#0B1020` | App background (dark) |
| `--vg-panel` | `#141A2E` | Cards, panels |
| `--vg-line` | `#26304D` | Borders, dividers |
| `--vg-ink` | `#E8ECF6` | Primary text on dark |
| `--vg-mute` | `#8A94B3` | Secondary text |
| `--vg-lantern` | `#F5B83D` | Brand accent, primary buttons, logo |
| `--vg-edge` | `#2DD4A7` | Positive edge, wins, proven badges |
| `--vg-pass` | `#F2726F` | Passes, losses, loss-limit warnings |
| `--vg-signal` | `#6C8CFF` | Links, focus rings, charts' model line |
| `--vg-paper` | `#F7F6F2` | Light-mode background |

Colour is never the only signal: every edge/pass/win/loss also has a label or icon. All text meets WCAG AA contrast.

**Type:** Space Grotesk (headings), Inter (body), JetBrains Mono (all prices, odds and percentages, tabular figures).

**Logo direction:** a minimal lantern/eye mark whose centre is a single rising line, in `--vg-lantern` on `--vg-night`. Wordmark "vigil" lowercase in Space Grotesk. Provide SVG mark, wordmark, app icon (512, 192, 180, favicon), and OG image template.

**Voice:** calm, plain, a little dry. Explains, never hypes. Says "pass" with the same confidence as "pick."

---

## 11. Data model (Vigil side, highlights)

- `users`, `subscriptions` (derived state), `billing_events` (append-only), `entitlement_overrides` (admin, audited)
- `vl_events`, `vl_board_entries`, `vl_odds_display`, `vl_models` (append-only mirrors of Edge Lab)
- `published_picks` (append-only, pre-kickoff constraint), `pick_results`
- `vigil_reads` (append-only), `ai_usage`
- `user_bets` + `user_bet_events` (append-only event log; current state derived), bankroll ledger in cents
- `alerts`, `alert_deliveries`
- `self_exclusions`, `age_attestations`
- `audit_log`

---

## 12. Build order

1. **Edge Lab internal API** + quota/cadence change + props ingestion + historical backfill command (in the Edge Lab repo).
2. Vigil skeleton: auth, 2FA, age gate, brand tokens, marketing layout, legal pages.
3. Edge Lab sync jobs and read models, with sync-health admin page.
4. Today, Board, Game pages (read-only).
5. Entitlements + plans config; Free/Daily/Pro gating enforced server-side.
6. Payment gateway interface, NMI driver, webhooks, dunning, Account billing.
7. Vigil Read (AI) with validation and fallbacks.
8. My Bets, Bankroll, Parlay, Tools, Props, Model Lab.
9. Published picks + public `/record`.
10. Alerts (email, web push).
11. Admin.
12. Hardening: tests for entitlements, append-only guards, pre-kickoff constraint, AI number validation, webhook idempotency, no-odds-export (assert no route returns bulk odds), load test on the board.

Each step ends with passing tests and a short note in `docs/STATUS.md`.

---

## 13. Acceptance checks

- A Free user cannot reach any Pro data through any route, Livewire action or JSON response (tested).
- With no proven market, the Daily card shows zero picks and says so.
- Every number in every Vigil Read exists in its fact sheet (tested across a fixture set).
- A published pick timestamped after kickoff is rejected by the database.
- No endpoint returns odds for more than one event's display, and no export includes odds.
- Changing `BRAND_NAME` renames the product everywhere visible.
- Switching `PAYMENT_DRIVER` between `nmi` and `stripe` requires no code changes.
- Cancelling a subscription takes one click and keeps access until period end.
