---
title: Lunara Session Handoff + Full Site Review
date: 2026-06-28
status: resume-anchor
audience: the next Claude Code (web) session
---

# Lunara Session Handoff — 2026-06-28

**Purpose:** This file is the durable resume point for a Claude Code *web* session
that reviewed lunarafilm.com. The container is ephemeral and the chat does not carry
into a new session, so everything needed to pick up is captured here. It lives in
`docs/` of `lunara-plugin-oscars-ledger`, which `.deployignore` excludes — it will
**never** deploy to the live plugin.

To resume, a new session need only be told: *"Read
`docs/LUNARA_SESSION_HANDOFF_20260628.md` on branch `claude/new-session-wrs0x5` in
lunara-plugin-oscars-ledger and continue."*

---

## 1. Why this session exists

Dalton (daltino1@gmail.com) asked for "a small full review" of the Lunara Film site
after time away. Three context docs were provided at the start (SESSIONLOG 2026-06-27,
LUNARA_CANONICAL_SITE_STATE 2026-06-27, LUNARA_WEBSITE_HANDOFF). Those describe a local
Windows workflow (`G:\lunara-backups\...`, plugin branch `feat/ceremony-depth-thesis`,
theme branch `main`). GitHub state was confirmed to match: nothing was out of sync.

No code was changed this session. It was a **read-only review**. The only write is this
handoff file.

## 2. Access map (what works from a web session, what doesn't)

- **GitHub MCP (`mcp__github__*`)** — full read on the 6 scoped repos. Works.
- **`lunara` MCP (`mcp__lunara__isonwp-*`)** — live DB bridge to lunarafilm.com
  (diagnose / list-recent-posts / search-posts / inspect-post). Works; it is an
  Anthropic-routed connector so it bypasses the egress proxy.
- **WordPress.com MCP (`mcp__WordPress_com__*`)** — Atomic site control
  (wpcom-mcp-site, wpcom-mcp-site-editor-context, content-authoring). Works.
- **WebFetch / curl to lunarafilm.com** — **BLOCKED (403)**. The environment's egress
  policy (default "Trusted") does not allowlist lunarafilm.com. Confirmed via
  `curl $HTTPS_PROXY/__agentproxy/status` and a CONNECT-403 test. This is *by design*,
  not the site's WAF. README at `/root/.ccr/README.md`: "403 from the proxy = host not
  allowed; report it, don't route around it."
- **`wsp-mcp` bridge** (`https://lunarafilm.com/wp-json/wsp-mcp/v1/mcp`, native WP MCP
  plugin) — Dalton supplied a config + bearer token. It could NOT be used here: not
  connected, no way to hot-register an MCP server mid web-session, and its endpoint is
  on the egress-blocked host anyway. It is meant for a *local* Claude Desktop/Cursor.
  **Security note:** the bearer token was pasted in chat — recommend hitting "Regenerate"
  on the WSP MCP settings screen.

### To unblock live visual review in the next session
Dalton edits the environment (cloud icon → gear → **Network access**) to **Full**, or
**Custom** with allowed domains:
```
lunarafilm.com
*.lunarafilm.com
image.tmdb.org
api.themoviedb.org
*.wp.com
fonts.googleapis.com
fonts.gstatic.com
```
(Check "Also include default list of common package managers" under Custom.)
**This only takes effect in a NEW session** — the running container's egress is fixed at
startup. Once open, WebFetch works AND the pre-installed Chromium+Playwright
(`/opt/pw-browsers`, `PLAYWRIGHT_BROWSERS_PATH` set) can screenshot public routes at
390/768/1280 for real visual QA — the one thing this review could not see.

## 3. Repo + deploy facts (confirmed)

- Owner: `theantagonist2020` (GitHub login `TheAntagonist2020`, commits authored as
  "RAYLAN" <TemporalPincerMove@pm.me>).
- Site: `lunarafilm.com`, WordPress.com **Atomic**, blog_id **247355955**, theme
  `lunara-theme-blocks-20260513-2300`, ~2,133 monthly views, 11,792 media, health
  reported "healthy". A dormant "coming soon" sister site exists
  (`lunarafilm-oabld.wordpress.com`, "Silver Screen Dispatch", no paid plan).
- `lunara-plugin-oscars-ledger`: branch `feat/ceremony-depth-thesis` @ `7d5dd1c`
  ("Add image integrity review pack") = the **2.7.89** prepared/deployed state.
  `main` is older (`2b811b4`). This handoff's branch `claude/new-session-wrs0x5` was cut
  from `7d5dd1c`.
- `lunara-theme-blocks`: `main` @ `6897811`. Also has
  `feature/acf-pro-field-bridge-20260624`.
- Dev-branch mandate for this session family: **`claude/new-session-wrs0x5`** across all
  repos (create per-repo as needed).

## 4. THE REVIEW — full findings (11 reviewers: 6 code, 4 content, 1 infra)

Overall read: **the code discipline and editorial product are stronger than the prior
handoff docs imply**, but two things were under-tracked — a concrete committed secret,
and an at-risk infrastructure/performance layer. The documented #1 risk (Oscar image
accuracy) is real and visible in the data.

### Scorecard
| Surface | Verdict | One-liner |
|---|---|---|
| Oscars plugin (code) | Solid | Mature, 22 security contract-tests; 739 KB God-class monolith; **hardcoded TMDB key default (line 25)** |
| Theme (code) | Mixed | Strong security; ~680 KB dead-duplicated functions.php; ARCHITECTURE.md is inverted from reality |
| Core plugin (code) | Solid | Clean shared content model; one cross-repo unescaped echo |
| Dispatch plugin (code) | Solid | Real quality gates (holds weak output as draft); 4 stale dup files |
| AI Assistant (code) | Solid | Good auth/secrets; invalid default model id; ~700 lines unreachable |
| IMDb Guard (code) | Mixed | **Hardcoded TMDB key**; broken `//u` regex; sync HTTP on save |
| Reviews (content) | Solid | Excellent prose, core meta complete; weak pull-quotes; live artifacts |
| Journal (content) | Solid | Real trade-desk voice; duplicate coverage; 248 rotting drafts |
| Oscars data | Mixed | Picks carousel-ready; Facts ~40% imageless & unverified |
| Data hygiene | Mixed | 18 orphaned post types; junk `movie` stub; undated draft piles |
| Site & infrastructure | **At-risk** | 38 active plugins; failing CWV (mobile 39); image optimizer OFF |

### HIGH severity
1. **Hardcoded TMDB API key in TWO repos** — the same live key
   (`b17bcb1a2b1a44a50898eaf079bcdede`) is committed in BOTH `lunara-imdb-guard.php`
   (`fetch_tmdb_images()`) AND `academy-awards-table.php` **line 24-25** as the
   `AAT_TMDB_API_KEY` default (`define('AAT_TMDB_API_KEY', '…')`). Verified directly; the
   Oscars-monolith copy was initially missed (the 739 KB file wasn't read whole) and was
   caught by gemini-code-assist on PR #1. OMDb is clean in both (constant/option, no
   committed default). → rotate the key on TMDB's side, remove the hardcoded defaults from
   BOTH files (keep the constant/option indirection), then scrub git history.
2. **IMDb Guard — broken regex**: `preg_match('//u', $content, $matches)` (empty pattern) in
   `extract_review_header_context()` makes the "review_header" lookup source dead code;
   validation always falls back to post title. Restore the intended title/year regex + guard.
3. **Infra — plugin bloat**: 61 installed / **38 active** on a 3-post-type site. Overlaps:
   3 table plugins (Ninja Tables + Pro + wpDataTables), 3 media organizers (WP Media Folder
   + HappyFiles + Sigma), 4 AI plugins, 3 MCP servers, 2 security stacks.
4. **Infra — failing Core Web Vitals**: mobile **39/100**, mobile LCP **7.3s**, desktop CLS
   **0.349**, ~4.9 MB page weight, 18 scripts / 16 stylesheets / 28 critical-request chains.
5. **Infra — images served non-responsively**: `uses-responsive-images` 0.02 mobile (~13.9s
   est. savings); **Cimo Image Optimizer is INSTALLED but INACTIVE** over 11,792 media.
   Activating it is the single biggest perf win.
6. **Infra — authoring-model conflict**: Classic Editor ACTIVE on top of a Blocksy block/FSE
   theme + Stackable blocks, with Elementor + Pro installed (inactive). Pick one model.
7. **Theme — ~680 KB dead duplicate**: `functions.php` (15,917 lines) require_once's
   `functions-loader.php` (loads all 21 `inc/` modules) then re-declares everything behind
   `function_exists` guards — parsed every request. A prior unguarded copy caused fatal
   redeclaration. Either finish the "Path B" migration (delete the monolith) or revert.
8. **Theme — ARCHITECTURE.md lies**: says "inc/ — DEAD CODE, NOT loaded" and "blocks live in
   functions.php"; the opposite is true on `main`. A maintainer following it edits dead code.
9. **Oscars data — `oscar_fact` imagery**: ~40% of 41 facts have no image; of those that do,
   only 1 of 12 sampled is verified (`_lunara_fact_visual_verified`). Concrete mismatch: fact
   31346 (1969 Streisand/Hepburn tie) uses a modern Fauci/Collins photo.
10. **Journal — duplicate coverage**: strong PUBLISHED vs weak DRAFT of the same story both
    live (33599 vs 33613 "Homewreckers"; 33372 vs 33687 Supergirl). Dedupe before bulk publish.
11. **Hygiene — 248 undated journal drafts** (all date 0000-00-00): time-pegged news rotting
    in a queue larger than publish throughput. Top hygiene priority.

### MEDIUM severity (selected)
- IMDb Guard: synchronous OMDb (12s) + TMDB (10s) HTTP on every `save_post_review` (worst
  case ~44s); bulk audit loops 150 reviews with no cron/batch → timeouts. Thin OMDb
  rate-limit handling (no response-code check; "Request limit reached" cached as not_found).
- AI Assistant: default OpenAI model `gpt-5.5` is not a real id → fresh installs fail on
  `/generate`. ~700 lines of "Control Desk" suggestion code (`/suggest`,`/suggestions`) have
  no caller in-repo — confirm a live consumer or remove.
- Oscars plugin: 739 KB single-class `academy-awards-table.php`; oversized templates
  (`hub-page.php` 306 KB, `entity-page.php` 107 KB) with a contract guarding raw SQL out of
  views. Decompose into `includes/` units.
- Dispatch: 4 stale duplicate root class files (`class-admin.php` etc.) shadow `includes/`
  (loader only uses `includes/`); they're materially out of date. Delete them.
- Core plugin: `echo lunara_render_pair_it_with_admin_preview(...)` unescaped — the renderer
  lives in the theme, so escaping is delegated cross-repo (potential stored-XSS in wp-admin).
- Infra: duplicate live installs — two "Lunara Core" (lunara-core ACTIVE vs lunara-plugin-core)
  and two "MCP Adapter" (mcp-adapter ACTIVE vs mcp-adapter-trunk). Fatal-activation risk.
- Infra: Jetpack on alpha `16.0-a.5` (owns backups/Boost/Social/VideoPress). Pin to stable.
  Both Rank Math SEO copies inactive → no active SEO suite; site tagline (blogdescription) empty.
- Reviews: pull-quote card field systematically weak (only 1/5 published "Ready" ≥38 words);
  trailers almost never filled; `featured_image` empty on some published (33224, 33089) relying
  on TMDb card image (breaks OG/social). Live title whitespace artifacts (29392 "The Bride!",
  33224 "Toy Story 5"); score mismatch on 33224 (meta `3` vs body DEBRIEF `3.5`). Pending
  backlog (7599, 7563) carries legacy dual-schema cruft + Google-Docs paste markup; 7599 has a
  stale `_lunara_imdb_id` (tt18257524) vs guard-verified tt26581740.
- Journal: raw automation artifacts in some drafts (33614 has 3 stacked copies of the same
  article, no image, `_lunara_dispatch_visual_status: needs_visual`). `journal_type` taxonomy
  (File/Dispatch/News/Trailer) left empty on most posts; post_tag inconsistent; author split
  between id 1 and 264250038.
- Theme: no runtime tests (38 PowerShell regex-on-source "tests", Windows-only, no CI); version
  drift (style.css 3.0.7 vs loader 3.0.0 vs functions.php 2.2.0); reads Oscars plugin DB tables
  + class directly (should be behind a plugin API).
- Hygiene: 18 orphaned post types from deactivated builders (Elementor/Pods/TablePress/AMP/
  RankMath/Jetpack-sitemaps/WP-Social-Reviews); the lone `movie` CPT (id 28895) is a junk test
  stub ("saywhat", no title, 0000-00-00) — DELETE, do not migrate. 11,789 attachments at ~289 MB
  (~25 KB avg) = thumbnail/orphan bloat, not storage cost.

### What's genuinely strong (preserve)
- Editorial voice (reviews + journal) — real publication POV, not AI filler. Biggest asset.
- Oscars plugin security is *tested* (22 contract tests assert manage_options, nonces,
  `$wpdb->prepare`, no private Image-Integrity leakage to public templates). Image Integrity
  console is correctly private + read-only.
- Dispatch quality gates hold weak output as draft (the 248 pile is a staging queue, with a
  real reserve of publish-ready pieces: 33687, 33585, 33586, 33582).
- Reviews "Pair It With" trio + core meta (score/year/director/runtime/studio/where) reliably
  complete; IMDb guard "verified" across published.
- `lunara_oscar_pick` (11) is carousel-ready: every pick verified image + structured meta +
  real critical writing. Two link to canonical entities via `_lunara_pick_oscar_entity_url`.
- Backups healthy (Jetpack rewind, zero errors); security baseline reasonable; secrets handled
  correctly everywhere EXCEPT the TMDB key, which is hardcoded in two files (see HIGH #1).

## 5. Prioritized punch list (where to go next)
1. Rotate + de-hardcode the TMDB key — committed in BOTH `lunara-imdb-guard.php` and
   `academy-awards-table.php` (line 25, `AAT_TMDB_API_KEY`). Remove both hardcoded defaults,
   rotate on TMDB, scrub history. Security, first.
2. Infra triage: activate image optimizer; cut active plugins to <20 (one per category); pin
   Jetpack stable; delete duplicate Core/MCP-Adapter installs; resolve Classic-vs-block editor;
   tackle desktop CLS (explicit dims on hero/carousel). Biggest reader-facing win.
3. Fix the imdb-guard `//u` regex; offload its on-save HTTP to WP-Cron/Action Scheduler.
4. Oscars images: verify/backfill `oscar_fact` art (gate unverified out of any public carousel);
   ship the homepage carousel from PICKS, not facts. Publish pick 31468 (zero-date draft).
5. Content polish: fix live title whitespace (29392, 33224) + 33224 score mismatch; dedupe the
   Journal published-vs-draft pairs; backfill ≥38-word purpose-written pull-quotes.
6. Architecture debt (lower urgency): decompose the 739 KB Oscars monolith; delete the theme's
   dead functions.php + rewrite ARCHITECTURE.md; remove dispatch's stale dup class files.
7. Hygiene: delete `movie` stub (id 28895); drain/triage draft backlogs; purge orphaned plugin
   rows + trash; audit media for parent=0 orphans (protect the review/oscar image subset).
8. NEW capability once egress is open: Playwright screenshot pass at 390/768/1280 across Home,
   Reviews, Oscars hub/category/ceremony/title/person — verify rendering, image display,
   overflow, CLS, and confirm no private/admin leakage.

## 6. Operating reminders
- Develop on `claude/new-session-wrs0x5`; push with `git push -u origin <branch>`; open DRAFT
  PRs; never push to another branch without explicit permission.
- Do NOT put the model id in commits/PRs/code. Keep CRLF discipline (the repos warn CRLF only).
- The Oscars plugin runs 22 PHP contract tests + PHP lint before release; the documented deploy
  ritual is local tests → commit → push → WordPress.com deploy → confirm live version → flush
  cache → smoke routes → check no admin leakage → update changelog/handoff.
- Verified image beats filler image. Never show a confident wrong image to avoid a blank.

## 7. Open PR for this handoff
A draft PR was opened from `claude/new-session-wrs0x5` into `feat/ceremony-depth-thesis`
(oscars-ledger) carrying only this docs file. It exists to anchor the resume, not to ship code.
