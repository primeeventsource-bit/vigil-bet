# VIGIL — Claude Master Implementation Prompt

You are working on the EXISTING VIGIL Laravel Cloud project. Your assignment is to implement a functional, production-quality FRONTEND and BACKEND based on the reference images in `references/`. Do not deliver a static mockup. Inspect the repository and report the actual stack, routes, models, migrations, queues, services, deployment configuration, and existing data-provider integrations before changing anything. Do not assume Vue/Inertia or any specific existing frontend framework.

## Source-of-truth design files
- `01_fullstack_9_screen_mockup.png`: PRIMARY reference for actual layouts of homepage, Today's Games, Parlay Builder, AI Picks, My Picks, Results & Analytics, Admin Dashboard, AI Engine Control, User Management.
- `02_premium_stealth_mobile_mockup.png`: SECONDARY reference for cinematic homepage and mobile screens.
- `03_original_brand_logo_reference.png`: MASTER brand reference for VIGIL's white eagle/V logo and Theme #4 Stealth Black.
- `05_original_logo_symbol_crop.png`, `06_original_wordmark_crop.png`: original pixel crops, NOT editable source vectors; if tracing to SVG, label as reconstructed and obtain visual approval.
- `07_hero_athlete_reference.png`: cropped background reference, not a clean isolated background.
- `04_current_board_issue.png`: existing Board showing 24,610 judged, 0 sellable picks, 18,422 no price, 4,400 market unproven, 1,788 stale quote.

These are flattened, AI-generated images, not exact source assets. Recreate responsive layouts with real components, not an image used as the whole page. Some sample scores, picks, user records, performance claims and odds in mockups are fictitious. NEVER present these as real live data. For missing data use clearly labeled empty states.

## Brand and UI
Name: VIGIL. Expansion: Validated Intelligence for Gambling Insights & Learning. Tagline: The Data Speaks. VIGIL Makes the Call.
Stealth Black design: background #0A0A0A; main surfaces #111315; raised panels #1E1F22; white #FFFFFF; secondary gray #A7A7AB; subtle borders #303237. Sora headings, Inter body (with fallbacks). Primary controls white-on-black or black-on-white. Green/red only for meaningful verified statuses, profit/loss or directional odds. Responsive accessible navigation, high-contrast focus states, visible selected-state outlines, touch targets >=44px, keyboard support. Preserve existing VIGIL identity. Make desktop and mobile responsive.

## Frontend routes and workflows
1. Public landing page: VIGIL logo, cinematic hero, CTA Today's Picks and Explore Engine, accurate descriptions of analytics and tracking.
2. Today's Games: filters sport/date/book/market; actual available event and market prices, timestamps, selectable moneyline/spread/total and supported props. Clicking an available outcome immediately highlights it and adds it to a persistent bet slip. Clicking again removes it. Never render an inert betting control. Provide clear no-odds and suspended-market states.
3. VIGIL AI Picks: engine's own dated recommendations, model version, predicted probability, estimated edge and reasoning; can show 'No qualifying picks' without inventing picks.
4. AI Decision Board: preserve the engine's candidate audit/rejection reasons. This is NOT the same as Today's Games and must not block manual personal picks.
5. Parlay Builder: 2+ eligible legs, add/remove, duplicate/conflict prevention, current price freshness, decimal/American odds conversion, stake, estimated profit and return, draft save and separately 'record as placed' with sportsbook accepted odds. Same-game correlated parlays require an actual supported quote; don't simply multiply correlated odds. Single selections can be saved as straight bets.
6. My Picks / My Parlays: private, persistent records, pending/settled states, ticket detail, manual vs AI-recommended labels, external sportsbook and optional reference number.
7. Results & Analytics: official event outcomes, individual leg and ticket settlements, historical stake, ROI, win rate, realized P/L and drawdown; segregate paper bets, real externally placed bets and unsaved drafts.
8. Bankroll: optional bankroll journal, exposure limits, stake sizing controls, deposit/withdrawal records as manual accounting entries only (no movement of money).
9. Account, login, registration, plan access, preferences and notification controls.

## Backend — separate secure admin interface
- Master admin login with MFA where supported, RBAC and scoped access; never hardcode credentials.
- Dashboard: system health, real provider feed status, recent picks, active users, job failures, data freshness.
- User & subscription management: create/invite users, manage roles/plans, suspend accounts, audit actions; integrate billing only if a legitimate existing provider is configured.
- Odds and sports data: providers, licenses, quota/rate limits, feed diagnostics, market mapping, stale quote detection, ingestion retries and reconciliation.
- AI engine: predictions, training data quality, model registry/versioning, calibration, Brier/log loss, out-of-sample backtesting, candidate model evaluation and controlled promotion/rollback. Python inference service only if appropriate after inspecting the stack and deployment constraints.
- Publication gate: keep statistically justified thresholds. Do NOT relax validation just to make AI picks appear.
- Settlement: official final scores, market-specific sportsbook settlement rules, pushes/voids/cancellations, idempotent settlement jobs, correction audit trail.
- Reports, logs, permissions, configuration, alerts.

## Investigate the current blocker
The supplied screenshot shows 24,610 candidates and 0 sellable picks, with reasons 18,422 'no price', 4,400 'market unproven', 1,788 'stale quote'. Inspect API credentials, licensed market coverage, feed jobs, mapping, timestamps, quote expiration, job queues and frontend interactivity. Distinguish genuine provider limitations from bugs. Report root causes with evidence. Create a separate clickable Today's Games page for supported markets, regardless of AI pick publication. When no live quote exists, optionally permit manual entry of accepted sportsbook odds clearly marked as manual, for tracking only.

## Backend data model (reuse existing models when possible)
Users, roles/permissions, subscriptions, sports/leagues/teams/players, events, markets/outcomes, bookmaker odds snapshots, AI model versions, immutable AI predictions, candidate decisions, personal selections, parlay tickets, parlay legs, bet settlement records, official results, bankroll ledger, provider health logs and admin audit logs. Use migrations, indexes, FK constraints, authorization policies and idempotency keys. Snapshot accepted price, line, provider and timestamp; never rewrite old predictions after outcomes.

## API behavior
Provide authenticated endpoints to list games/markets, add/remove picks in a persisted or session bet slip, validate parlay legs, calculate price estimates, save drafts, record placed external bets, list tickets, and view settlements. Validation and authorization on the server. Frontend optimistic state should reconcile errors and display useful messages. Protect against duplicate saves, stale odds and cross-user access.

## Tests and deployment
First audit architecture, back up database, plan changes and identify rollback. Implement on a branch/staging; do not change DNS, hosting providers, production environment, secrets or database destructively without explicit approval. Add unit, integration and real-browser tests for desktop and mobile: click/highlight/deselect moneyline, spread and total; bet slip persistence; two-leg parlay; combined odds; stake changes; draft save; external bet record; refresh persistence; user isolation; result ingestion; settlement including pushes/voids; AI board independence; admin RBAC; provider outage and stale quote cases. Report exact commands, changed files, screenshots and test results. Do not claim tests passed unless actually executed.

## Execution order
PHASE 0: Inspect repo, infrastructure and actual blockers. Return a concise findings report and file-level plan.
PHASE 1: Implement working Today's Games selection -> bet slip -> save straight/parlay -> My Bets, including database and tests.
PHASE 2: Official results and reliable automatic settlement; bankroll and analytics.
PHASE 3: AI recommendations, model monitoring and Admin AI Control, preserving honest no-pick states.
PHASE 4: User/subscription admin, full visual polish, mobile QA and staged release.

## Definition of done
A logged-in user can click a real available market, see an obvious selected state, add multiple compatible selections, enter a stake, save a parlay, reload and see it in My Parlays, and later see each leg and ticket settled from verified results. Admin can monitor data feeds, users and model decisions. All pages visually follow the references. All claims and figures reflect real records or are clearly labeled demos.
