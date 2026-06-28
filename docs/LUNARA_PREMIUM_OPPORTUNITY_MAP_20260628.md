# Lunara Film — Premium Opportunity Map (first draft)

> A menu for discussion, not a committed plan. Everything here is grounded in the verified dossier (2026-06-28). Recommendations that **cannot be designed responsibly without the rendered-pixel / egress pass** are marked **[NEEDS-EYES-ON]** — these require Playwright screenshots at 390/768/1280 plus a live Lighthouse/PageSpeed run before any pixel-level commitment.

---

## 0. The premise, honestly stated

Lunara already has the two things money can't retrofit: a **real editorial voice** (73 reviews, 294 Journal pieces, 41 Oscar facts, 12 carousel-ready picks, all publication-grade POV) and a **disciplined gold/navy cinema palette** whose hexes agree across code and live. The premium opportunity is therefore *not* "invent an identity" — it's **resolve the drift, ship the bespoke Oscars modules a real Academy database makes possible, and make it all feel fast and trustworthy.**

The single hardest constraint the dossier exposes: there is **no verified rendered view of the site.** We are working from structure and data, not pixels. So this map is built to be *correct in direction* while flagging exactly where direction must meet the screen before it becomes design.

---

## 1. Design-system direction — elevate navy+gold into a true bespoke system

### 1.1 The drift we must close first (the design-system blocker)

The dossier's verified core problem isn't a missing identity — it's **two parallel, unreconciled token systems**:

- **FSE/block content** renders in **League Spartan** (the one live-registered family, loaded by Blocksy/Customizer).
- **Theme-templated pages** (Home, Reviews, Oscars, single) render in a **Georgia serif stack** (code default, `inc/customizer.php:2695-2696`).
- `Cormorant Garamond` is *referenced* but never `@import`-ed. The only loaded Google fonts are **Bebas Neue + Oswald**, used in a few components.

**There is no single typographic identity on the live site.** No premium system can be claimed until this resolves. **[NEEDS-EYES-ON]** — we cannot responsibly choose the winner until we *see which font system dominates above the fold* on Home/Reviews/Oscars at each breakpoint.

### 1.2 Type pairing — concrete options (to be chosen post-pixel-pass)

A premium film-criticism publication wants an editorial-serif/condensed-display contrast. Three grounded directions:

| Option | Display / headline | Body / reading | Accent / labels | Rationale tied to dossier |
|---|---|---|---|---|
| **A — "Editorial Cinema" (recommended starting point)** | **Cormorant Garamond** (already referenced in code — finish what was started by actually loading it) | A clean humanist sans for long-form legibility (e.g. **Source Serif / Newsreader** for serif reading, or a quiet sans) | **Bebas Neue / Oswald** retained for tickers, scores, ceremony labels (already loaded — reuse, don't add) | Lowest net-new font weight; honors existing intent; serif display reads "criticism," not "blog." |
| **B — "Marquee" (condensed-led)** | **League Spartan** promoted to the single system-wide display face (it already wins in FSE) | A warm editorial serif for body to keep reviews readable | Oswald for data/labels | Consolidates onto the one already-live family; least migration risk for FSE content. |
| **C — "Award Plate"** | A bespoke/licensed high-contrast display (Didone-adjacent) for ceremony dossiers only | Source Serif body | Bebas for numerals | Highest "premium" ceiling, highest cost/perf risk; reserve for Oscars hub hero only. |

**Non-negotiable regardless of option:** **one** display family and **one** body family applied to *both* token systems, so FSE and theme-templated pages stop disagreeing. Loading a webfont is itself a perf decision — see §3. **[NEEDS-EYES-ON]** for final pairing because line-fit, x-height at the live clamp scale, and serif-vs-condensed feel above the fold cannot be judged from CSS.

### 1.3 Type scale

Reconcile the two scales the dossier records:

- Live fluid 5-step: small 13 / medium 20 / large clamp 22→30 / x-large 30→50 / xx-large 45→80.
- Code body default 15px (Customizer 17px), line-height 1.65.

Premium direction: **adopt one fluid `clamp()` modular scale** spanning both systems, anchored on a comfortable **18–19px** reading body for long-form criticism (current 15px code default is too small for premium reading), 1.6–1.7 line-height retained. Keep the existing 45→80 display ceiling for ceremony/hero — it's already ambitious and correct.

### 1.4 Color roles — promote a coherent palette into named semantic tokens

The hexes are *right and agree*; the **naming and hygiene are the debt.** Verified flags to fix:

- Duplicate gold: `palette-color-1 == palette-color-2 == #c9a961` (live).
- Duplicate-value pairs in code: `--lunara-bg-primary == --lunara-bg-deep`, `--lunara-bg-secondary == --lunara-bg-card`.
- Only 4 of 13 live slots are semantically named; **12 WP default colors + ~44 stock gradients remain editor-pickable** (off-brand risk); **no brand gold/navy gradient preset exists**.
- Border doc drift (header says 0.2, value is 0.28).

**Premium color-role system (proposed canonical set):**

| Role | Value (verified) | Notes |
|---|---|---|
| `accent/gold` | `#c9a961` | Collapse the duplicate to one canonical slot |
| `accent/gold-light` | `#e0c481` (code) / `#D5BA6D` (live) | Pick one; reconcile the near-miss |
| `accent/gold-deep` | `#B69F66`, `#997A44` | "darker oscar" tier for pressed/hover/ceremony |
| `surface/deep` | `#0a1520` | Page ground |
| `surface/card` | `#0f1d2e` | Elevated card |
| `text/primary` | `#FAFBFC` | |
| `text/muted` | `#A8A8B8` | |
| `border/gold` | `rgba(201,169,97,0.28)` | Fix the doc to match |
| `glow/gold·blue·highlight` | as verified | Reserve for premium hover/focus states |

Plus: **define the missing brand gold→navy gradient as a preset**, and **prune the stock WP color + gradient presets** so editors physically cannot pick off-brand. **Preserve and extend the per-journal-type dispatch accents** (gold/blue/coral/purple/teal/amber/cyan, `style.css:5763-5783`) — a genuine, bespoke strength; formalize them as a documented "dispatch spectrum."

### 1.5 Motion

The dossier shows a real, considered motion foundation: one shared easing token `cubic-bezier(.2,0,.2,1)`, three duration tiers (.22/.35/.6s), and **`prefers-reduced-motion` already respected.** This is a premium-grade base most sites lack. **Direction: keep the system, raise the ceiling selectively** — carousel transitions, ceremony-dossier reveals, score-pill micro-interactions, gold glow on focus. Motion budget stays disciplined; no motion that fights perceived performance (§3). **[NEEDS-EYES-ON]** to tune actual easing/timing against rendered carousels.

### 1.6 Dark / light

The identity is **natively dark** (`#0a1520` ground) and that *is* the premium cinema feel — lean into it as the canonical mode. A light mode is **optional, Phase 2+**, and only worth it if the role-token system above is fully semantic first (otherwise light mode multiplies the `!important` problem). Recommendation: **ship a polished single dark system before entertaining light mode.**

### 1.7 The specificity tax

`style.css` is 16,423 lines with **794 `!important`** and `.lunara-btn` defined in 17 separate blocks — an append-only convention fighting Blocksy. A true bespoke system means **a real component layer** (buttons, cards, score pills, carousels defined once). This is design-system work *and* maintainability work; it's the bridge from "themed" to "bespoke."

---

## 2. Signature Oscars modules — what only a real Academy database can ship

Lunara's moat is the **Oscars Ledger** (`wp_academy_awards` / `wp_aat_*`) plus 12 verified picks and 41 facts. These modules are *defensible* because they're backed by a real structured awards table, not scraped trivia. All of them depend on the verified-imagery non-negotiable (§3.2).

1. **Ceremony Dossiers** — a bespoke long-form page per ceremony, driven by `aat_awards` (the table-backed ceremony smoke-tested in the deploy checklist). Winner/nominee grids, category breakdowns, "the night's story." This is the flagship premium artifact. **[NEEDS-EYES-ON]** — ceremony pages are table-driven; we must *see* current `aat_awards` render output and the Blocksy-vs-`--lunara` token boundary before designing.

2. **Winner / Nominee Carousels from verified picks** — the 12 carousel-ready `lunara_oscar_pick` entries (incl. publish-ready draft 31468) become a signature, motion-tuned carousel. **Gate strictly to picks with verified imagery.** **[NEEDS-EYES-ON]** to confirm the hero/picks/facts carousels actually render and which font wins above the fold.

3. **Fact Cards with verified imagery** — the 41 `oscar_fact` entries as premium, shareable cards. **Blocked on §3.2**: ~27% are imageless and only ~25% of imaged facts carry `_lunara_fact_visual_verified=1`. The verification *mechanism already exists* — this is an application gap, not a build.

4. **Person pages** — entity pages like `/oscars/name/nm0475070` — career arcs, nomination/win history from the ledger. **[NEEDS-EYES-ON]** — entity page rendering is unverified.

5. **Title pages** — entity pages like `/oscars/title/tt14905854/` — a film's full awards record, linked to the matching review where one exists (reviews already carry IMDb-guard `verified` blocks — a clean join key). **[NEEDS-EYES-ON]**.

6. **"Review ↔ Awards" bridge** — connect the healthy review model (score/director/year/"Pair It With" trio, all complete) to the ledger title pages. Lowest-risk high-value link; reviews are the richest, healthiest data in the estate.

> **Architectural caution carried into design:** the theme reads the Oscars plugin two ways — `Academy_Awards_Table::get_instance()` (~12 sites) **and direct `$wpdb` queries** against `aat_awards` (duplicated in 3 files). It's prepared + 12h-cached (safe) but **high-coupling/fragile with no API contract.** Any new Oscars module should consume a *single* read path, not add a fourth `$wpdb` call site.

---

## 3. The non-negotiables the dossier exposes

### 3.1 Perceived performance — the gate on everything visual

The honest position: **every CWV/perf number is UNVERIFIED this session** (egress 403-blocked, no Lighthouse MCP). The handoff *claims* mobile 39/100, LCP 7.3s, CLS 0.349, ~4.9 MB, 18 scripts / 16 stylesheets — **all [NEEDS-EYES-ON].** But the *code-verified* perf liabilities are real and independent of any Lighthouse run:

- **680 KB monolith `functions.php`** parsed every request (cannot be deleted — 112 unique live functions including Oscar picks/facts CPTs and homepage blocks).
- **238 KB `header.php`** (5,489 lines) shipped on every page with inline style/script.
- **454 KB `style.css`** with 794 `!important`.
- **imdb-guard synchronous OMDb(12s)+TMDB(10s) HTTP on every save** (~44s worst case), bulk audit loops 150 with no cron — an editorial-experience perf wound.
- **11,792 media items**, images served non-responsively (handoff: Cimo optimizer installed-but-inactive — **[NEEDS-EYES-ON]**).

**Non-negotiable rule for this engagement:** *no premium visual feature ships if it regresses perceived performance.* Webfonts, carousels, glows, ceremony hero imagery all carry budget. **A live Lighthouse pass on Home + a Review + an Oscars page is a prerequisite, not a nice-to-have**, and must run before Phase 1 design work is finalized.

### 3.2 Verified imagery — never a confident wrong image

This is the dossier's most severe defect and the one that most threatens "premium":

- **Fact 31346** (Streisand/Hepburn 1969 tie) displays an **Anthony Fauci photo** — filename literally contains `Anthony_Fauci`, no verify flag. For an Oscars publication this is brand-fatal.
- ~27% of facts imageless; only ~25% of imaged facts carry `_lunara_fact_visual_verified=1`; many use bare TMDB-hash filenames never human-checked.
- Published reviews 33224 & 33089 have **empty `featured_image`** (rely on card image) — OG/social fallback risk.

**Non-negotiable rule:** **a fact/pick/title with unverified imagery renders text-only or with a tasteful placeholder — never a guessed image.** The good news: the **verification system already exists** (`_lunara_fact_visual_verified` + treatment/focus). The work is **enforcement + backfill**, not invention:

1. Make every image-bearing module **hard-gate on the verified flag** (text/placeholder fallback otherwise).
2. **Immediately correct fact 31346** (and audit for siblings) — this is a Phase 0 item, not a roadmap item.
3. Human-verify the imaged-but-unflagged majority and the ~27% imageless.

**[NEEDS-EYES-ON]** for the *visual* design of the placeholder/fallback state and to confirm how the Fauci image currently renders live.

### 3.3 Content trust artifacts (premium = no visible cracks)

Verified live and must be clean before "premium" is claimed: title whitespace artifacts (29392 long space run; 33224 double-space/missing em-dash render as live `<title>`/H1); score mismatch (33224 meta `3` vs body `3.5/5`); 248 undated Journal drafts; `journal_type` taxonomy unused (no working Journal filters/archives); 18 orphaned post types + the `movie` stub (28895, "saywhat") to **delete, not migrate**.

---

## 4. Phased path — honest, because the site is live

> Guardrails that apply to every phase: the site is **live**; production and staging (250866514) are **byte-identical** (faithful design-QA mirror — use it); deploy is **git-to-deploy gated by `.deployignore`** with a real `LIVE_DEPLOY_CHECKLIST.md` ritual on the Oscars repo. **The 680 KB monolith cannot be deleted** (load-bearing). Work *with* these facts.

### Phase 0 — Stabilize / security / perf (no visible redesign)
*Goal: earn the right to design by making the foundation safe and honest.*

- **Run the egress / rendered-pixel pass + live Lighthouse** (the precondition for all of Phase 1 design). Capture Home, Reviews archive+single, Oscars hub/category/ceremony/title/person at 390/768/1280.
- **Verified-imagery emergency fix:** correct fact 31346 (Fauci) and audit siblings; enforce the verified-flag gate.
- **Close TMDB secret remediation:** code-default risk is already closed; **still open** = TMDB-side key rotation + git-history scrub (key recoverable from history). **[NEEDS-EYES-ON / ops]** for rotation confirmation.
- **imdb-guard:** fix the broken `//u` empty regex (`:931`); move synchronous OMDb+TMDB HTTP off the save path to cron/Action Scheduler.
- **Dispatch:** remove the 4 stale duplicate root class files that *deploy live* and would fatal-redeclare if loaded.
- **AI-assistant:** fix invalid default OpenAI model `gpt-5.5` (`:49`); decide fate of ~470 lines unreachable Control Desk code.
- **Oscars version split:** resolve 2.7.58-working vs 2.7.89-origin **before** any deploy (shipping as-is regresses ~31 changelog versions).
- **Data hygiene:** delete the 18 orphaned types + `movie` stub; fix title whitespace (29392, 33224) and the 33224 score mismatch; triage the 248 drafts (incl. dedup 33599/33613, 33372/33687 — keep the stronger Supergirl *draft*; collapse 33614's 3 stacked copies).
- **Fix the inverted `ARCHITECTURE.md`** (says `inc/` is dead — opposite is true; header "~6 KB" is actually 238 KB). Premium teams don't run on documentation that lies.

### Phase 1 — Design-system elevation on the current Blocksy child
*Goal: make it feel bespoke without a theme rebuild. Lowest-risk premium.*
*Precondition: Phase 0 pixel-pass + Lighthouse complete.*

- **Resolve the typographic drift** — choose one display + one body family (§1.2), actually `@import`/load it, apply across *both* token systems. **[NEEDS-EYES-ON]** for final pairing.
- **Promote the color roles** to semantic tokens (§1.4), collapse duplicates, **prune stock WP colors/gradients**, define the brand gold→navy gradient, formalize the dispatch spectrum.
- **Adopt one fluid type scale**, raise body to ~18–19px reading size.
- **Build a real component layer** for buttons/cards/score-pills/carousels — start paying down the 794 `!important` / 17-button-block debt.
- **Ship the lower-risk Oscars modules** that don't need new templates: verified-pick carousel, fact cards (gated), review↔awards bridge. **[NEEDS-EYES-ON]** for render confirmation.
- **Perf budget enforced** against the Phase 0 Lighthouse baseline.

### Phase 2 — Optional bespoke block theme (FSE with a real `theme.json`)
*Goal: the full premium ceiling. Highest reward, highest risk — explicitly optional.*

- **Introduce a real `theme.json`** carrying the now-canonical tokens (today there is none — design presets live in Blocksy + Customizer DB).
- **Full FSE templates/parts/patterns** for ceremony dossiers, person/title pages — the modules that most want bespoke layout.
- **Honest risk notes:** this is a **migration of a live, half-migrated estate.** The monolith stays load-bearing (Oscar picks/facts CPTs + homepage blocks live in `functions.php`); the Oscars coupling is tight and contract-less; there is **no CI and no runtime tests** (only 37 Windows-only PowerShell regex-on-source scripts). A block-theme migration without first adding tests and a single Oscars read-path API is a high-risk bet on a live site. **Recommend Phase 2 only after Phase 1 proves the token system and after a real test/CI safety net exists.** Optional, possibly never — Phase 1 may deliver enough "premium."

---

## 5. Recommendations requiring the rendered-pixel / egress pass — explicit list

These **cannot be designed responsibly** until egress is opened (Network → Full, new session) and Playwright + Lighthouse run:

| # | Recommendation | Why it's blocked |
|---|---|---|
| 1 | Final type-pairing choice (§1.2) | Must see which font wins above the fold; judge x-height/line-fit at the live clamp scale |
| 2 | Motion tuning of carousels/reveals (§1.5) | Easing/timing can't be judged from CSS alone |
| 3 | All Oscars modules — ceremony dossier, pick carousel, fact cards, person/title pages (§2) | Render output and Blocksy↔`--lunara` token boundary unverified |
| 4 | Verified-imagery placeholder/fallback visual design + confirming how 31346 renders (§3.2) | No verified visual rendering this session |
| 5 | Any perf-budgeted feature (webfonts, glows, hero imagery) | Every CWV/perf number is unverified; needs live Lighthouse baseline |
| 6 | Cimo/optimizer + non-responsive-image remediation scope (§3.1) | Plugin active-state not exposed by available MCP |
| 7 | Confirming whether the two token systems visibly clash or reconcile at render (§1.1) | The entire premise of the design-system fix |
| 8 | Plugin-estate decisions (overlaps, Classic-Editor-on-block-theme, duplicate Core/MCP installs) | 61/38 counts and editor settings are handoff-only, unconfirmed |

---

## 6. One-paragraph pitch (for the discussion)

Lunara doesn't need to *become* premium — it needs to **stop fighting itself.** Resolve one typographic identity across two token systems, promote an already-coherent gold/navy palette into a true semantic role system, enforce the verified-imagery gate the site already half-built, and let the real Academy database power a handful of signature modules — ceremony dossiers, verified-pick carousels, person/title pages — that no generic film blog could ship. Do Phase 0 to earn the right to design, Phase 1 to feel bespoke on the current theme, and treat the full block-theme rebuild as optional, not assumed. **But almost none of the visual decisions can be locked until someone finally looks at the rendered site** — the egress + Lighthouse pass is the unlock for this entire map.