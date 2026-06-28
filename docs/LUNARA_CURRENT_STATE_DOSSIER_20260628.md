# Lunara Film — Verified Current-State Dossier (2026-06-28)

## 1. Executive read

Lunara Film is a live, editorially-real film publication on WordPress.com Atomic (blog_id 247355955, theme "Lunara Film Living Pulse"), whose genuine asset is its content and voice — 73 published reviews with complete structured meta, 294 published Journal pieces, 41 Oscar facts, and 11–12 carousel-ready Oscar picks, all written in publication-grade POV rather than AI filler [verified-live]. Underneath that, the engineering is a half-migrated WordPress estate: a **classic Blocksy CHILD theme misnamed "blocks"** (no theme.json, no FSE templates) carrying a 680 KB partially-dead `functions.php` monolith alongside a live `inc/` module split, plus five private plugins (a shared content-model core, an AI Journal automation pipeline with a real quality gate, an AI editorial assistant, an IMDb/TMDB guard, and the Oscars Ledger) that are clean on security but carry stale duplicate files, cross-repo coupling, version splits, and a just-landed-but-not-fully-closed TMDB secret remediation [verified-code]. The honest one-line summary: **strong content and brand discipline sitting on legible but heavily accreted, partially-documented-wrong infrastructure**, with measurable data debt (248 undated Journal drafts, 18 orphaned post types, unverified Oscar-fact imagery) and a complete inability — this session — to confirm any performance/CWV number or the live plugin inventory.

---

## 2. Verified design system

Lunara runs **two parallel token systems**. There is **no `theme.json`** in the theme repo (it is a Blocksy child, `Template: blocksy`), so the FSE/block presets are owned by the Blocksy parent + the live Customizer DB, while theme-templated pages are styled by a runtime `:root{--lunara-*}` block emitted by `functions.php` [verified-code]. The hexes agree across both systems; **typography does not** — this is the real "drift."

### 2a. Palette — ACTUAL hex (code-vs-live cross-checked)

| Role | Code token (`style.css :root`, lines 34–58) | Live preset (MCP `theme.presets`) | Hex | Agreement |
|---|---|---|---|---|
| Gold (accent) | `--lunara-gold` | `palette-color-1` "gold" **and** `palette-color-2`* | `#c9a961` | match [verified-code][verified-live] |
| Light gold | `--lunara-gold-light` | `palette-color-10` "lighter oscar" `#D5BA6D` (close, not equal) | `#e0c481` | brand-consistent |
| Background primary/deep | `--lunara-bg-primary` = `--lunara-bg-deep`* | `palette-color-3` | `#0a1520` | match |
| Background secondary/card | `--lunara-bg-secondary` = `--lunara-bg-card`* | `palette-color-4` | `#0f1d2e` | match |
| Body text | `--lunara-text` | `palette-color-7` | `#FAFBFC` | match |
| Muted text | `--lunara-text-muted` | `palette-color-5` | `#A8A8B8` | match |
| Border | `--lunara-border` | — | `rgba(201,169,97,0.28)` gold-tinted (header comment says 0.2 — doc drift) | [verified-code] |
| Glows | `--lunara-glow-gold/-blue/-highlight` | — | `rgba(201,169,97,.16)` / `rgba(168,168,184,.26)` / `rgba(255,255,255,.07)` | [verified-code] |
| Oscar accents | — | `palette-color-9` "darker oscar" `#B69F66`, `palette-color-13` `#997A44` | | [verified-live] |

\* **Token hygiene flags:** `palette-color-1 == palette-color-2 == #c9a961` (duplicate gold, live); `--lunara-bg-primary == --lunara-bg-deep` and `--lunara-bg-secondary == --lunara-bg-card` (duplicate-value pairs, code). Only 4 of 13 live slots are semantically named ("Color 2..13" otherwise); the 12 WP default colors and ~44 stock WP gradients remain enabled (editors can pick off-brand), and **no brand gold/navy gradient** is defined as a preset [verified-live].

Per-journal-type **dispatch accents** (`--lunara-dispatch-accent`, `style.css:5763-5783`): default gold `rgba(201,169,97,.7)`, plus blue/coral/purple/teal/amber/cyan variants — a genuine strength [verified-code].

### 2b. Typography — the headline drift

- **Live presets register exactly ONE font family: "League Spartan"** (empty `fontFace[]`; loaded by Blocksy/Customizer, not child PHP) [verified-live].
- **Code defaults heading AND body to a Georgia SERIF stack:** `Georgia, "Times New Roman", "Iowan Old Style", "Palatino Linotype", serif` (`inc/customizer.php:2695-2696`, `functions.php:1960-1961`), only overridden if a Customizer mod is set [verified-code].
- **Net effect / DRIFT:** FSE/block content renders in League Spartan; theme-templated pages (Home, Reviews, Oscars, single) render in Georgia serif. **The live site has no single typographic identity** [verified-code][verified-live].
- `--lunara-font-display` = `"Cormorant Garamond"` is referenced (`style.css:1748`) but **NOT @import-ed** (no webfont load). The only @import-ed Google fonts are **Bebas Neue + Oswald** (`style.css:14126`), used in a few components only [verified-code].
- Live font-size scale (5-step, fluid): small 13px, medium 20px, large clamp 22→30px, x-large 30→50px, xx-large 45→80px [verified-live]. Code body default 15px (`--lunara-body-size`; Customizer default 17px), line-height 1.65 [verified-code].

> **Survey disagreement to flag:** the synthesis brief assumed a code `theme.json` to diff against live tokens. **No `theme.json` exists** — so a "code-vs-live theme.json drift" comparison is impossible. The real, verified divergence is *internal*: Blocksy FSE presets (League Spartan) vs the child's runtime `--lunara-*` tokens (Georgia serif). Two surveys (Theme, Live-Design) agree on this.

### 2c. Spacing / layout / motion

- **Layout (code):** `--lunara-shell-max` 1360px, `--lunara-shell-pad` 28px, `--lunara-section-gap` 72px (mobile 46px), `--lunara-surface-radius` 12px, `--lunara-header-pad` 20px, `--lunara-logo-max` 50px; ~30 more generated at runtime by Customizer [verified-code]. **Live:** root `blockGap = var(--theme-content-spacing)`, root padding 0; `content_width = var(--theme-block-max-width)` [verified-live].
- **Motion (code, `style.css:11414`):** `--lunara-ease cubic-bezier(.2,0,.2,1)`; durations fast `.22s` / med `.35s` / slow `.6s`; `prefers-reduced-motion` respected. 33 distinct `--lunara-*` tokens total [verified-code].
- **No live/staging drift:** production (247355955) and staging clone (250866514) returned byte-identical presets [verified-live].

---

## 3. Architecture map

**Theme + 5 plugins, all on branch `claude/tmdb-key-rotation-9hsb3b`** (NOT the handoff's `claude/new-session-wrs0x5`, which is specific to the Oscars-ledger repo where the handoff lives) [verified-code].

### 3a. Theme — `lunara-theme-blocks` ("Lunara Film Living Pulse")
- **Classic Blocksy CHILD theme, NOT FSE.** No `theme.json`/`templates/`/`parts/`/`patterns/`; classic PHP hierarchy (`front-page.php`, `single-review.php`, `single-journal.php`, `single.php` 45 KB, `page-oscars.php` 52 KB, archives) [verified-code].
- **Loader vs monolith (core finding):** `functions.php:27` `require_once functions-loader.php`, which loads ~18–21 `inc/` modules; `functions.php` (15,917 lines / 680 KB) then re-declares behind `function_exists` guards. **Of 282 functions: 170 are dead duplicates (inc/ wins), but 112 are UNIQUE & LIVE** — including the `lunara_oscar_pick` (`functions.php:12391`) and `oscar_fact` (`:13324`) CPTs, all 4 homepage blocks (`lunara_register_homepage_blocks`, `:14952`), and the hero/picks/facts carousel renderers. **The monolith cannot be deleted without breaking Oscar picks/facts and the homepage** [verified-code]. *Strongest evidence: function-name diff (170 dup / 112 unique); `functions.php:12391,13324,14952`.*
- **`inc/blocks.php`** registers a separate, complementary block family (`lunara/home`, `/reviews`, `/debrief`, `/pair-it-with`, `/where-to-watch`) — both families live [verified-code].
- **`ARCHITECTURE.md` is INVERTED** (`:37`, `:380` say `inc/` is dead/unloaded — the opposite is true). Half-inverted: its "blocks live in functions.php" claim is actually TRUE for the 4 homepage blocks. Doc is `.deployignore`'d (never ships) but is the on-repo maintainer guide; it also calls `header.php` "~6 KB" (actual 238 KB) [verified-code].
- **`header.php` is 5,489 lines / 238 KB** (a Blocksy child header should be ~150 lines) with inline `<style>/<script>`; ships on every page [verified-code].
- **`style.css`** = 16,423 lines / 454 KB, **794 `!important`**, `.lunara-btn` in 17 separate blocks (append-only convention fighting Blocksy specificity) [verified-code].
- **Dead code in live templates:** `single-review.php:136-152` runs ~12 lines of filtering/regex against `$tmdb_providers = ''` (always empty — "Where to Watch" TMDB path is regressed dead code) [verified-code].
- **UTF-8 mojibake:** 169 occurrences in `functions.php` (+ `inc/debrief.php`), user-visible in block-inserter descriptions; `style.css` clean [verified-code].

### 3b. Oscars plugin coupling (two-way, tight)
The theme reads the academy-awards-table plugin via **(1) `Academy_Awards_Table::get_instance()`** (~12 `class_exists`-guarded sites) **AND (2) direct `$wpdb` queries against `{prefix}aat_awards`** via a `SHOW TABLES LIKE` candidate resolver (`functions.php:2886-2907`, DUPLICATED in `inc/debrief.php:471-490` and `inc/frontend.php:1241`). No plugin-provided API contract; prepared + 12h transient-cached, so safe but high coupling/fragility [verified-code]. *Strongest evidence: the `aat_awards` SQL at `functions.php:2886-2907`.*

### 3c. The five plugins

| Plugin | Version | Shape | Role | Notable debt |
|---|---|---|---|---|
| **lunara-plugin-core** | 0.1.3 | single file, 725 lines | Owns canonical `review` CPT, Debrief/Details meta, director/year + `lunara_slide_set` taxonomies, Carousel Manager | One cross-repo unescaped `echo lunara_render_pair_it_with_admin_preview()` (`:300-309`); renderer lives in theme `inc/debrief.php` (theme DOES escape — no live XSS) [verified-code] |
| **lunara-plugin-dispatch** | 3.0.13 | multi-file `includes/` | RSS→AI Journal pipeline with real 2-stage quality gate | **4 stale duplicate root class files** (`class-admin/-image-handler/-post-builder/-prompts`) the loader never includes; would fatal-redeclare if loaded; **they DO deploy** (not in `.deployignore`) [verified-code] |
| **lunara-plugin-ai-assistant-classic** | 0.4.0 | single file, 1302 lines | Classic-editor AI packaging meta box (OpenAI/Anthropic/Gemini) | **Default OpenAI model `gpt-5.5` is invalid** → fresh-install `/generate` fails (`:49`). **~470 lines of `/suggest`+`/suggestions` Control Desk code is unreachable** (only `/generate` has a JS caller) [verified-code] |
| **lunara-plugin-imdb-guard** | 0.2.0 | single file, 1314 lines | Validates review IMDb IDs (OMDb) + syncs TMDB art | **Broken `//u` empty regex** (`:931`) makes review-header lookup dead + emits warnings. **Synchronous OMDb+TMDB HTTP on every save** (~44s worst case); bulk audit loops 150 with no cron → timeouts [verified-code] |
| **lunara-plugin-oscars-ledger** | 2.7.58 (working) / 2.7.89 (origin/new-session) | multi-file | Oscars hub/ceremony/category/entity; `wp_academy_awards`/`wp_aat_*` tables | **Version split** — security-fix branch built on OLDER 2.7.58 base, not deployed 2.7.89; shipping as-is regresses ~31 changelog versions. `readme.txt` "Tested up to: 6.4" stale [verified-code] |

### 3d. Data model & ownership contracts (a genuine strength)
- **Single-owner contract via constant:** both theme and core register `review` + Debrief meta box, but every theme copy is guarded `if (!defined('LUNARA_CORE_VERSION'))` (theme `inc/reviews-cpt.php:13`, `functions.php:2103`, `inc/debrief.php:66`). Core defines the constant, so the theme self-disables cleanly when the plugin is active — **no live double-registration** [verified-code].
- **`journal` CPT + `journal_type` taxonomy owned by the THEME** (`inc/journal-cpt.php:73,114`), consumed by dispatch with `post_type_exists()`/`taxonomy_exists()` guards (falls back to `post`) — one-directional, defensively coded [verified-code].
- **`lunara_oscar_pick` + `oscar_fact` CPTs registered ONLY in `functions.php`** (the load-bearing monolith) [verified-code].

### 3e. Key debt summary
Version drift (theme: style.css 3.0.7 / loader 3.0.0 / functions.php 2.2.0; Oscars 2.7.58-vs-2.7.89) [verified-code] · 680 KB monolith parsed every request · 238 KB header.php · 794 `!important` · cross-plugin DB reads · stale dispatch duplicates that deploy live · no runtime tests / no CI (37 Windows-only PowerShell regex-on-source `.ps1` files; `.deployignore` contract test on Oscars only) [verified-code].

---

## 4. Live content & data

Authoritative counts from `isonwp-diagnose` [verified-live]:

| CPT | publish | draft | pending | trash | other |
|---|---|---|---|---|---|
| review | 73 | 32 | 5 | 7 | — |
| journal | 294 | **248** | — | 1 | — |
| oscar_fact | 41 | — | — | 1 | — |
| lunara_oscar_pick | **11** | 1 | — | — | (12 total) |
| attachment | 11,789 inherit | — | — | — | 3 private (**11,792 total**) |

**Quality & hygiene (verified-live):**
- **Reviews are the richest, healthiest model:** every sampled published review carries score/year/director/runtime/studio/where + the "Pair It With" trio (theme_echo/counter_program/career_context) + an IMDb-guard `verified` block. `lunara_director`/`lunara_review_year` taxonomies are populated (working archives).
- **Oscar-fact imagery is the biggest content risk:** **fact 31346** (Streisand/Hepburn 1969 tie) uses an **Anthony-Fauci photo** with no verify flag — exact, severe defect. In a 22/41 sample: ~27% imageless, only ~25% of imaged facts carry `_lunara_fact_visual_verified=1`. Many imaged facts use bare TMDB-hash filenames never human-checked.
- **248 undated Journal drafts** (all date `0000-00-00`, empty slug) = a staging backlog larger than publish throughput. Contains raw automation artifacts (**draft 33614** has three stacked copies of the same "Digger" article, `needs_visual`) and **duplicate published-vs-draft coverage** (Supergirl 33372 pub / 33687 draft; Glen Powell 33599 pub / 33613 draft). Nuance: the Supergirl *draft* is the stronger/later take.
- **Live title artifacts:** 29392 ("The Bride!" + long space run), 33224 ("Toy Story 5" double-space, missing em-dash) render as live `<title>/H1`.
- **Score mismatch:** review 33224 meta `_lunara_score='3'` vs in-body DEBRIEF "Score: 3.5 / 5".
- **Empty featured_image** on published 33224 & 33089 (rely on `_lunara_review_card_image`; can break OG/social fallbacks).
- **`journal_type`/`post_tag`/`hf_cat_journal` empty across all sampled Journal posts** — taxonomy exists but unused; no working Journal filters/archives.
- **18 orphaned post types** + junk `movie` stub (id 28895: empty title, content "saywhat", date 0000-00-00, ACF placeholder meta) — DELETE, do not migrate.
- **Dispatch quality gate is real:** weak/imageless output is held as DRAFT, not auto-published — the 248 pile is a gated staging queue, not live junk.

> **Survey disagreements flagged (handoff ratios refined by live check):** `lunara_oscar_pick` is **12 not 11** (handoff counted publish only; draft 31468 is complete/publish-ready). Oscar-fact imageless is **~27% not 40%**; verified is **~25% not 1/12** — directional handoff point holds, magnitudes off.

---

## 5. Infrastructure & performance

**VERIFIED [verified-code / verified-live]:**
- **Deploy model:** WordPress.com Atomic git-to-deploy, six GitHub repos (owner theantagonist2020, commits "RAYLAN"), gated per-repo by `.deployignore` (the "WordPress.com deploy filter" — confirmed identical commits adding *only* `.deployignore`) [verified-code].
- **Oscars `LIVE_DEPLOY_CHECKLIST.md`:** real 10-step ritual (`php -l`, repair tables/rewrite rules, data-count SQL, hub/ceremony/category/entity smoke tests, cache flush, rollback note) + `tests/deployignore-contract.php` enforcing the filter [verified-code].
- **`.deployignore` inconsistency:** only the Oscars repo excludes `.git` itself / has the contract test; the other 5 exclude `.gitattributes/.github/.gitignore` only — unmanaged divergence (low risk; Atomic generally doesn't ship `.git`) [verified-code].
- **TMDB secret remediation (HIGH, partially closed):** the literal key `b17bcb1a...` is **removed from both** `academy-awards-table.php` (constant→env→option resolution, commits b3cd8a5/d23a843) **and** `lunara-imdb-guard.php` (febb779/d86b6b9, settings field now `type=password`). **Code-default risk CLOSED.** STILL OPEN/UNVERIFIABLE: TMDB-side key rotation and git-history scrub — the key remains recoverable from history [verified-code].
- **Data hygiene** (counts, 18 orphaned types, movie stub, 248 drafts, 11,792 media): all CONFIRMED live (see §4) [verified-live].
- **imdb-guard synchronous on-save HTTP** (OMDb 12s + TMDB 10s, no cron/Action Scheduler): CONFIRMED in code as a perf/timeout risk [verified-code].

> ## Unverified — needs a live Lighthouse pass [needs-eyes-on]
> **Every Core Web Vitals / performance / plugin-inventory number below is UNCONFIRMED this session.** No Lighthouse/PageSpeed/CrUX MCP was available; `lunarafilm.com` egress is 403-blocked; the lunara MCP exposes post-types only, not the active-plugin list, plugin versions, or editor settings.
> - **CWV/perf (handoff):** mobile **39/100**, mobile **LCP 7.3s**, desktop **CLS 0.349**, **~4.9 MB** page weight, **18 scripts / 16 stylesheets / 28 critical-request chains**, `uses-responsive-images` 0.02 (~13.9s savings). — UNVERIFIED.
> - **Plugin estate (handoff):** **61 installed / 38 active**; overlaps (3 table, 3 media, 4 AI, 3 MCP, 2 security); **Cimo Image Optimizer INSTALLED but INACTIVE** over 11,792 media; images served **non-responsively**; **Classic Editor active atop a block theme** (+ Stackable, Elementor inactive); **two "Lunara Core"** + **two "MCP Adapter"** installs; **Jetpack on alpha 16.0-a.5**; both Rank Math inactive; empty `blogdescription`. — UNVERIFIED (live orphaned-type breadcrumbs corroborate that Elementor/Pods/TablePress/RankMath were installed, but headline counts/states are unconfirmed).
>
> **All of the above require a live Lighthouse/PageSpeed run and a wp-admin plugin-list pass.**

---

## 6. Handoff verification ledger

Aggregating every `handoff_check` across the 6 surveys. ✅ CONFIRMED · ❌ REFUTED · ❓ UNVERIFIABLE.

| Handoff claim | Verdict | Evidence / refinement |
|---|---|---|
| ~680 KB dead-duplicated `functions.php` re-declares behind `function_exists` | ✅ (refined) | 680 KB / 15,917 lines, but 112 funcs UNIQUE & LIVE — NOT just-delete-able [verified-code] |
| `ARCHITECTURE.md` inverted (says `inc/` dead) | ✅ | `:37,380` vs `functions.php:27` loading all `inc/`; half-inverted (blocks-in-functions claim is true) [verified-code] |
| Theme version drift 3.0.7 / 3.0.0 / 2.2.0 | ✅ | Exact match across style.css / loader / functions.php [verified-code] |
| Theme reads Oscars plugin DB + class directly | ✅ | `aat_awards` `$wpdb` + `Academy_Awards_Table::get_instance()` ×12; no API [verified-code] |
| No runtime tests; 38 PowerShell tests, no CI | ✅ (refined) | **37** `.ps1` (not 38); Windows-only regex-on-source; no `.github/workflows` [verified-code] |
| Theme is a block/FSE theme with `theme.json` carrying tokens | ❌ | Classic Blocksy CHILD; **no `theme.json`**; tokens in `style.css` + Customizer [verified-code] |
| Theme is a "Blocksy block/FSE theme" (infra framing) | ✅ (nuanced) | `Template: blocksy` + Blocksy `palette-color-N` convention; but CHILD with no own `theme.json` [verified-live] |
| `lunara-header-duplicate-guard.php` mu-plugin guards doubled-header bug | ❓ | Lives in live `wp-content/mu-plugins`, not in theme repo |
| Dispatch: 4 stale duplicate root class files | ✅ | All 4 differ from `includes/`, older (root post-builder lacks entire quality gate); deploy live [verified-code] |
| Core: `echo lunara_render_pair_it_with_admin_preview()` unescaped, cross-repo | ✅ (downgraded) | Echo real at `:301`; theme fn DOES escape → no live XSS; fragile split, not exploitable [verified-code] |
| Dispatch quality gates hold weak output as draft | ✅ | `publishable_section_failure` + 0.62 dup matcher + default-draft + `LUNARA_SKIP` prompt [verified-code] |
| Core: clean shared content model, one cross-repo echo | ✅ | Accurate; only escaping concern [verified-code] |
| AI Assistant invalid default OpenAI model `gpt-5.5` breaks fresh `/generate` | ✅ | `:49` sent verbatim; fresh install fails [verified-code] |
| AI Assistant ~700 lines unreachable Control Desk `/suggest` code | ✅ (refined) | ~470 lines, zero in-repo caller; JS hits only `/generate` [verified-code] |
| AI Assistant good auth/secrets handling | ✅ | constant>env>write-only-option; password fields never echoed; blank-preserves [verified-code] |
| AI Assistant Anthropic default also "invalid" | ❌ | `claude-sonnet-4-20250514` is REAL (active-but-deprecated Sonnet 4); only OpenAI default invalid [verified-code] |
| Hardcoded TMDB key in `academy-awards-table.php` + `lunara-imdb-guard.php` | ❌ (current) / ✅ (historical) | Removed from both current files (constant/env/option); recoverable in git history; rotation+scrub unverified [verified-code] |
| imdb-guard broken `//u` empty regex → dead review-header lookup | ✅ | `:931`; empty `//u` returns 1 with `$matches[0]=''`; falls back to post title; warnings [verified-code] |
| imdb-guard synchronous OMDb(12s)+TMDB(10s) on every save; bulk loops 150, no cron | ✅ | `:64`/`:788`/`:803`/`:981`; ~44s worst case; no `wp_schedule`/Action Scheduler [verified-code] |
| imdb-guard thin OMDb rate-limit handling (no status check; cached not_found) | ✅ | `:980-998`; "Request limit reached" → `not_found` cached 12h [verified-code] |
| Dev branch is `claude/new-session-wrs0x5` across all repos | ❌ | core/dispatch/ai-assistant/imdb-guard/theme on `claude/tmdb-key-rotation-9hsb3b`; wrs0x5 is Oscars-ledger only [verified-code] |
| reviews 73 published | ✅ | diagnose `review{publish:73,draft:32,pending:5,trash:7}` [verified-live] |
| journal 294 published / 248 undated drafts | ✅ | diagnose `journal{publish:294,draft:248,trash:1}`; all date 0000-00-00 [verified-live] |
| oscar_fact count 41 | ✅ | diagnose `oscar_fact{publish:41,trash:1}` [verified-live] |
| lunara_oscar_pick is 11 | ❌ | 11 publish + **1 draft = 12**; draft 31468 complete [verified-live] |
| oscar_fact ~40% imageless | ❌ | ~27% in 22/41 sample; directional point holds, magnitude lower [verified-live] |
| oscar_fact only 1/12 verified | ❌ | ~25% of imaged carry verified flag; gist (most unverified) holds [verified-live] |
| fact 31346 uses wrong/Fauci image | ✅ | filename literally contains `Anthony_Fauci`; no verify flag [verified-live] |
| Journal duplicate pub-vs-draft pairs 33599/33613 & 33372/33687 | ✅ | both subjects double-covered; Supergirl draft is the stronger take [verified-live] |
| 33614 stacked copies, no image, needs_visual | ✅ | 3 passes + `lunara-ai-inserted` div; `_lunara_dispatch_visual_status:needs_visual` [verified-live] |
| Live title whitespace artifacts 29392 & 33224 | ✅ | long space run / double-space missing em-dash [verified-live] |
| 33224 score mismatch (meta 3 vs body 3.5) | ✅ | meta `_lunara_score:'3'`; DEBRIEF "3.5 / 5" [verified-live] |
| featured_image empty on published 33224 & 33089 | ✅ | both `''`; rely on card image [verified-live] |
| 18 orphaned post types incl. movie | ✅ | exactly 18 listed [verified-live] |
| movie stub id 28895 junk (saywhat, no title, 0000-00-00) | ✅ | inspect confirms placeholder meta [verified-live] |
| 11,792 media / 11,789 attachments | ✅ | `inherit 11,789 + private 3`; both figures correct [verified-live] |
| journal_type empty; author split id 1 / 264250038 | ✅ | all sampled empty taxonomy; two authors [verified-live] |
| Reviews "Pair It With" + core meta complete; IMDb guard verified | ✅ | 33224/33089/33241/29392 all complete + `verified` [verified-live] |
| publish pick 31468 (zero-date draft, complete) | ✅ | draft, 0000-00-00, complete + entity-linked [verified-live] |
| Live theme `lunara-theme-blocks-20260513-2300` | ✅ | MCP `theme.active` exact match [verified-live] |
| Dormant "coming soon" sister site (lunarafilm-oabld, no paid plan) | ✅ | blog_id 249148181, `is_coming_soon true`, `wpcom_paid_plan_required` [verified-live] |
| Google Fonts (googleapis/gstatic) needed by theme | ✅ | "League Spartan" registered live via Blocksy/Customizer; child default is system serif [verified-live] |
| theme.json code-vs-live token drift exists | ❌ | No `theme.json` to diff; real divergence is internal (League Spartan vs Georgia) [verified-code] |
| `.deployignore` is the "WordPress.com deploy filter" | ✅ | commits add only `.deployignore`; not a PHP `add_filter` [verified-code] |
| 2.7.89 = prepared/deployed Oscars state | ✅ (nuanced) | True for origin/new-session-wrs0x5; working tree + main are 2.7.58 [verified-code] |
| Failing CWV (mobile 39 / LCP 7.3s / CLS 0.349 / 4.9MB / 18 scripts) | ❓ | No perf MCP; egress 403; **needs live Lighthouse** [needs-eyes-on] |
| Images non-responsive; Cimo optimizer INSTALLED-but-INACTIVE | ❓ | Plugin state not exposed by available MCP [needs-eyes-on] |
| 61 installed / 38 active plugins; category overlaps | ❓ | Active-plugin list not accessible this session [needs-eyes-on] |
| Classic Editor active atop block theme + Stackable + Elementor | ❓ | Editor setting not queryable; only stub-meta breadcrumb [needs-eyes-on] |
| Duplicate Core/MCP-Adapter installs; Jetpack alpha 16.0-a.5 | ❓ | Live plugin inventory not accessible [needs-eyes-on] |
| `gpt-5.5` defect in dispatch plugin | ❌ (scope) | Different plugin; dispatch OpenAI default is valid `gpt-4o` [verified-code] |
| WebFetch/curl to lunarafilm.com blocked (403) | ❓ | Consistent with handoff; not re-tested; lunara MCP is the only live bridge [needs-eyes-on] |

---

## 7. Genuine strengths to preserve

- **Editorial voice + content depth** — the site's biggest asset: real publication POV across reviews, Journal, and picks, not AI filler [verified-live].
- **Disciplined, centralized design-token system** — coherent gold/navy cinema palette defined once in `:root` + re-emitted by Customizer; brand hexes agree across code and live [verified-code][verified-live].
- **Considered motion design** — single shared easing token + 3 duration tiers + explicit `prefers-reduced-motion` [verified-code].
- **Clean single-owner cross-repo contract** — theme cedes `review` CPT + Debrief meta to core via `if(!defined('LUNARA_CORE_VERSION'))` guards; no live double-registration [verified-code].
- **Dispatch quality gate is real and layered** — editorial-failure gate + 0.62 topic-duplicate matcher, both logging reasons, default-draft, `LUNARA_SKIP` prompt: weak AI output never auto-publishes [verified-code].
- **Strong, consistent security hygiene across all plugins** — nonces + capability + autosave guards on every write path, masked password API-key fields, full output escaping, ABSPATH guards, no raw SQL, no committed secrets [verified-code].
- **Safe cross-plugin DB access** — `aat_awards` discovered via `SHOW TABLES LIKE` + `$wpdb->prepare` + 12h transient cache (survives prefix/rename) [verified-code].
- **Path-B homepage architecture** — block-driven Home via `front-page.php → the_content`; layout reorders are content edits [verified-code].
- **Dispatch image pipeline** — 6-layer source extraction, 6h scrape cache, hero upscale w/ GD fallback, provenance meta, World-of-Reel image blocking, human visual-assignment brief [verified-code].
- **A real visual-verification system already exists for facts** (`_lunara_fact_visual_verified` + treatment/focus) — the gating mechanism is built; just needs applying to the unverified majority [verified-live].
- **Operational hygiene** — `.deployignore` deploy filter (enforced by a contract test on the flagship repo), `LIVE_DEPLOY_CHECKLIST.md`, `.gitattributes` EOL discipline, README forbids committing keys, deep real Customizer (10 panels), AI-Assistant secret chain (constant>env>write-only-option) [verified-code].
- **No live/staging design drift** — staging is a faithful design-QA mirror [verified-live].

---

## 8. The one blind spot — rendered pixels [needs-eyes-on]

**Everything above is structural and data-level. Nobody has actually LOOKED at the rendered site this session.** `lunarafilm.com` egress is 403-blocked, no Lighthouse/PageSpeed/CrUX MCP is available, and the lunara MCP returns data, not pixels. Two consequences cannot be closed from here:

1. **No verified performance signal** — every CWV/perf number in §5 stays under the Unverified banner.
2. **No verified visual rendering** — the typography drift (League Spartan vs Georgia serif), the live application of palette tokens, layout integrity, the title-whitespace artifacts (29392, 33224), the Fauci-image fact (31346), and whether the dual token systems visually clash are all **inferred, not seen**.

**What opening egress (Network access → Full, new session) would let me capture** — Playwright screenshots at **390 / 768 / 1280** widths across:
- **Home** — verify hero/picks/facts carousels render, which font system wins above the fold, palette application.
- **Reviews** (archive + a single review) — card vs hero image fallback behavior, the empty-featured_image OG risk, score-pill rendering, title whitespace on 33224.
- **Oscars hub** — Blocksy-vs-`--lunara` token boundary, plugin coupling output.
- **Oscars category** — list/grid rendering.
- **Oscars ceremony** — table-driven `aat_awards` output.
- **Oscars title** — entity page (e.g. `/oscars/title/tt14905854/`).
- **Oscars person** — entity page (e.g. `/oscars/name/nm0475070`).

Paired with a live Lighthouse/PageSpeed pass on Home + a Review + an Oscars page, this would convert every `[needs-eyes-on]` claim in this dossier into `[verified-live]` and definitively resolve whether the two design systems produce a visible split or are reconciled at render time.