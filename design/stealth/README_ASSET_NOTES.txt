VIGIL STEALTH BLACK — CLAUDE IMPLEMENTATION PACKAGE

SOURCE: User-supplied flattened 1536 x 1024 PNG, preserved as 00_ORIGINAL_MASTER_REFERENCE.png.

CRITICAL ACCURACY NOTES:
- Crops 01–10 are pixel-exact crops of the source image, NOT independent design layers.
- 11 and 12 are approximate alpha reconstructions from the white logo pixels, NOT original vectors.
- The athlete/background in the reference is partially obscured by UI and cannot be recovered as a complete standalone background from this file.
- Do not pretend the screenshot contains working code, a clean full-background image, SVG vectors, original font files, or editable Figma layers.
- To recreate editable UI, implement HTML/CSS components using the original as visual reference. Do not use the screenshot as the entire site background.

DESIGN TOKENS (sampled/estimated from reference; not original source design variables):
Canvas: #0A0A0A
Surface: #111315
Elevated surface: #1E1F22
Border: #2D2F32
Primary text: #FFFFFF
Secondary text: #A7A7AB
Muted text: #74777C
Success: subtle green only for directional data when necessary
Warning/loss: restrained red/green arrows for odds movement
Font suggestions: Sora headings, Inter body (obtain via normal licensed/web font distribution)

LAYOUT:
- Black-and-white angular eagle/V brand mark, VIGIL wordmark, spaced AI-DRIVEN EDGE subtitle.
- Left vertical navigation: Dashboard, Live Odds, AI Picks, Parlays, Bankroll, Analytics, Models, Results, Settings.
- Top bar: logo, search, live state, notifications, profile, settings.
- Hero: SMARTER BETS. SHARPER EDGES. with monochrome angular texture.
- Stat tiles: active picks, average confidence, weekly and lifetime performance.
- Table: Top AI Picks; charts: Bankroll, Live Odds Movement, Model Confidence; Parlay Builder.
- Mobile: dark app shell, bottom navigation, daily pick detail.

PRODUCT POSITIONING:
VIGIL publishes its own daily sports picks based on an independently evaluated prediction engine, not merely aggregated sportsbook recommendations. Confidence scores must be model-estimated probabilities, with clear timestamps and honest historical performance. All screenshot statistics, games and odds are placeholder visual data; do not present as real live results.

IMPLEMENTATION SAFETY:
- Do not change production DNS, hosting, databases, env vars or payment integrations without approval.
- Use feature branch and staging preview, preserve existing app functionality and data.
- Make UI accessible, responsive, and fast; use original crops as visual references and logo assets as appropriate.
