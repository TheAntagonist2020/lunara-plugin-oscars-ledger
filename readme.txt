=== Lunara Film — Academy Awards Database ===
Contributors: lunarafilm
Tags: oscars, academy awards, datatable, film, movies
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 2.7.47
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A premium, searchable, filterable table for browsing every Academy Award nominee and winner (1st ceremony through 2024).

== Description ==

This plugin adds a shortcode that renders an interactive, fully searchable table of Academy Awards nominations and winners.

Highlights:

* Instant search across nominees, films, and categories
* Dropdown filters for Category, Class, Year, and Ceremony
* "Winners only" toggle
* Clickable film/person profiles (internal pages) with IMDb reference links
* Cinematic theme tuned to:
  * #0a1520 (deep blue-black background)
  * #c9a961 (primary gold accents)

The plugin includes a bundled `oscars.csv` dataset and a one-click importer (chunked to avoid timeouts).

== Installation ==

1. Upload the plugin ZIP in WordPress:
   * WP Admin -> Plugins -> Add New -> Upload Plugin
2. Activate "Academy Awards Interactive Table".
3. Go to "Academy Awards" in the WP Admin menu.
4. Click "Import Bundled oscars.csv" (recommended). This replaces any existing awards data in the plugin table.
5. Add the table to any page or post using the shortcode:

`[academy_awards]`

== Shortcode ==

Basic:

`[academy_awards]`

Optional filters:

* `category` (canonical category, e.g. `BEST PICTURE`)
* `class` (e.g. `Acting`, `Directing`)
* `year` (e.g. `2024`, `1927/28`)
* `ceremony` (e.g. `97`)
* `winners_only` (`true` or `false`)

Examples:

* `[academy_awards category="BEST PICTURE"]`
* `[academy_awards year="2024"]`
* `[academy_awards category="DIRECTING" winners_only="true"]`

== Notes ==

* This plugin stores imported data in a custom database table named `{prefix}academy_awards`.
* DataTables assets are loaded from the official DataTables CDN.

== Changelog ==

= 2.7.47 =
* Fixed person profile route hydration for nominee-projection rows recovered from existing PEOPLE profile media.
* Recovered craft/person links now resolve to real profile pages even when the original source row still lacks public `nominee_ids`.

= 2.7.46 =
* Recovered missing person-link projections from unique single-person `PERSON_PROFILE` media labels with embedded IMDb `nm` IDs.
* Category and ceremony credit lists can now fall back to the existing label resolver when source rows lack `nominee_ids`, once reporting tables are rebuilt.

= 2.7.45 =
* Fixed the private Duplicate groups view so resolved one-candidate leftovers no longer remain in the grouped chooser.
* The grouped duplicate-review count now reflects only live groups with at least two current candidates.

= 2.7.44 =
* Added a private Duplicate groups view for Existing PEOPLE portrait cleanup.
* Duplicate candidates now group by IMDb person ID so admins can compare competing attachments once per person while keeping typed-confirmation resolver writes per attachment.
* No bulk duplicate adoption, imports, fetches, renames, moves, or public route changes were added.

= 2.7.43 =
* Added a private read-only Manual review lane for unresolved Existing PEOPLE portrait rows.
* Manual-review cards expose image/context fields for `needs_manual_review` rows without rendering adoption or duplicate resolver actions.
* The clean adoption and typed duplicate-resolution paths remain separate from unresolved manual-review images.

= 2.7.42 =
* Added a one-by-one typed-confirmation resolver for duplicate Existing PEOPLE portrait candidates.
* Duplicate rows still stay blocked from the normal adoption button; admins must choose one attachment and type the exact IMDb person ID before metadata can be written.
* The resolver rechecks the live duplicate group and writes only existing-media-adoption metadata for the selected attachment.

= 2.7.41 =
* Added a private duplicate-review view to the Existing PEOPLE adoption lane.
* Duplicate `nm...` filename matches now show competing attachment thumbnails, media links, and duplicate-set counts while remaining blocked from automatic adoption.
* The clean one-click adoption path remains limited to non-duplicate PEOPLE candidates.

= 2.7.40 =
* Added a private Existing PEOPLE adoption lane to `Academy Awards > Person Portrait Queue`.
* Admins can adopt one reusable existing `nm...` filename portrait at a time as `existing-media-adoption` without fetching, importing, renaming, moving media, or mutating Oscar result data.
* Duplicate reusable PEOPLE candidates remain review-only until manually resolved.

= 2.7.39 =
* Added a private read-only `wp aat profile-images existing-media-audit` reconciliation pass for the existing `PEOPLE` Media Library folder.
* Existing media audit reports already route-backed portraits, reusable `nm...` filename matches, likely name matches, duplicate person rows, and manual-review rows before any new imports happen.

= 2.7.38 =
* Added a private read-only `wp aat profile-images coverage` audit for approved `tmdb_profile_results.csv` `Status=OK` portrait IDs.
* Coverage mode reports source `people`, public entity-route, and imported-media buckets, including approved portrait IDs absent from source people and imported-media/no-route samples.

= 2.7.37 =
* Added a private WP-CLI manual batch importer for Dalton-supplied Oscars person portrait JPEGs.
* The importer dry-runs against `tmdb_profile_results.csv` and `profiles_missing.csv`, imports only approved local files, and marks attachments with `manual-batch-upload` metadata.
* Public person profile pages now recognize both manual batch portraits and legacy TMDb profile portraits through the same local portrait resolver.

= 2.7.36 =
* Added `Academy Awards > Person Portrait Queue`, a private Person Portrait Import Queue that imports one verified TMDb person profile image at a time.
* Imported person portraits are marked with plugin-owned person metadata so Oscar profile pages prefer verified local portraits without accepting contextual title or backdrop art.

= 2.7.35 =
* Stopped contextual title/backdrop imagery from rendering as person portrait fallbacks on Oscars person profile pages.
* Added a dry-run nominee portrait batch audit helper that can use Dalton's nominee CSV roster with batch size, offset, and state filters before any image imports exist.

= 2.7.34 =
* Added honest Oscars person/profile visual-source states for local portraits, TMDb portraits, and no-portrait cases.
* Expanded the private Person Profile Audit with visible portrait state/source columns to guide manual cleanup without exposing operational metadata publicly.

= 2.7.33 =
* Upgraded generic Oscar category pages into dossier-grade inner routes with command-band summaries, denser ledger-card rows, and linked craft/person credit chips where name entities can be resolved.

= 2.7.32 =
* Added route-oriented composite indexes to plugin-owned Oscars reporting and legacy tables for faster ceremony/category/entity lookups.
* Added a private source-data validation helper and contract for the repaired workbook and normalized SQL candidates.
* Expanded source validation with header-adjusted row reconciliation, ID-shape checks, duplicate-key reporting, mojibake repair previews, and a documented path from external normalized SQL tables into plugin-owned `wp_aat_*` runtime tables.
* Moved public hub and ceremony dossier summary reads to projection-aware helpers, with legacy awards-table reads retained only inside fallback paths.

= 2.7.31 =
* Added Theme Studio hooks for Oscars related-review count and treatment controls across ceremony, category, and profile routes.

= 2.7.30 =
* Added media guards for Oscars related-review cards so label-only placeholders render as intentional text-led cards instead of empty public media chambers.

= 2.7.29 =
* Added private Ceremony Write-Ups queue filters, search, status counts, and clearer row/status presentation for faster review of staged ceremony guide drafts.

= 2.7.28 =
* Polished public Ceremony Guide modules with a stronger guide-file presentation, metadata rail, reader actions, and responsive editorial typography.

= 2.7.27 =
* Normalized approved Ceremony Write-Up headline, deck, and body text before public escaping so smart punctuation survives WordPress DB charset conversions.

= 2.7.26 =
* Added the private Ceremony Write-Ups workflow: DOCX preview, 98-row draft staging, one-at-a-time review/edit/approval, and approved-only ceremony guide modules on public ceremony dossiers.

= 2.7.25 =
* Added a data-derived Ceremony Thesis layer with critical-path navigation and compact major-race briefing cards for annual ceremony dossiers.

= 2.7.24 =
* Added a ceremony Major Races module for Best Picture, Directing, Actor, and Actress so year dossiers have a premium scan layer before the full ballot ledger.

= 2.7.21 =
* Added private OMDb poster review annotations so accepted imports, source failures, manual poster needs, and replacement notes can be tracked separately from bad-ID correction review states.

= 2.7.20 =
* Added OMDb Audit poster integrity previews and a guarded one-by-one OMDb poster import action that sideloads accepted posters into the Media Library and maps them in the Poster Library without exposing the patron poster URL.

= 2.7.19 =
* Scoped the one-by-one OMDb correction action to the audited title/year context so reused IMDb IDs can be repaired without touching legitimate older rows that share the same ID.

= 2.7.18 =
* Added a guarded one-by-one OMDb correction action for rows marked `Verified Bad ID`, with nonce/capability checks, immediate OMDb candidate revalidation, exact-token film ID replacement, cache invalidation, and automatic `Resolved` review notes after a successful write.

= 2.7.17 =
* Added read-only candidate correction previews for OMDb Audit rows marked `Verified Bad ID`, using private note candidate IDs without mutating Oscar rows or poster records.

= 2.7.16 =
* Added private OMDb Audit review states and correction notes so flagged IMDb title IDs can be marked reviewed before any dataset or poster mutation exists.

= 2.7.15 =
* Added OMDb audit filters, issue classification, and read-only correction queue recommendations for likely bad IMDb IDs, OMDb gaps, poster gaps, and clean matches.

= 2.7.14 =
* Added a read-only OMDb Integrity Audit admin screen for IMDb-title-ID, title/year, and poster identity checks. OMDb keys are read from `AAT_OMDB_API_KEY` or a saved WordPress option, not committed to the plugin repository.

= 2.7.13 =
* Extended the premium category dossier system beyond Best Picture to marquee Oscar categories, adding category-specific dossier headers, shared command-band rhythm, era-browser treatment, visual interruption hooks, and ledger-card history layout while preserving existing category URLs and fast/full history behavior.

= 2.7.12 =
* Introduced the first title/person Oscar Profile File pass with a dossier-style hero, command-band stats, internal section rail, and mobile wrapping guardrails for entity routes.

= 2.7.11 =
* Introduced the ceremony Year Dossier surface with a dedicated hero, command-band summary, compact ballot rhythm, and mobile wrapping guardrails for ceremony routes.

= 2.7.10 =
* Tightened the Best Picture dossier lower-page rhythm by compacting repeated ledger actions and removing duplicate compact ceremony links.

= 2.7.9 =
* Created the first route-family visual-system proof of concept for the Best Picture category page, adding a Best Picture Dossier header, command band, era browser styling, visual era markers, and denser ledger-card rhythm while preserving category URLs, fast view, and `?history=full`.

= 2.7.8 =
* Tightened default category fast-view output by trimming redundant older-history action buttons and reducing default category highlights to a curated 9 cards. Full nominee/history mode remains available with `?history=full`.

= 1.7.3 =
* Added ceremony="latest" and year="latest" shortcode support for auto-updating pages (useful for an Awards Tracker page).

= 1.7.2 =
* Branding + footer copy updates (Lunara Film / Dalton Johnson credit).
* Hub index pages now support custom slugs (e.g. /oscars/categories-page/) by auto-detecting your created pages and adding rewrite aliases.
* Footer + hub navigation links now follow your detected hub page permalinks for consistent menus.


= 1.7.1 =
* Auto-detects the site’s main database page (the page containing the [academy_awards] shortcode) for "Open Full Database" links.
* If you created WordPress pages for /oscars/ceremonies/, /oscars/categories/, and /oscars/about/, the plugin now pulls in their editor content as hub intro copy.

= 1.5.1 =
* Theme alignment: table typography inherits the active theme font (better match with Blocksy/Lunara)
* Footer copy updated to more clearly credit Lunara Film for the structured dataset
* CSS variables now optionally read Lunara theme tokens (with fallbacks), keeping the plugin portable

= 1.5.0 =
* Removed public export buttons (Copy / CSV / Print) to keep the database as an on-site destination
* Mobile refinements: better responsive row control and less column hiding

= 1.4.1 =
* Fixed a PHP fatal error introduced in 1.4.0 (broken nonce block in read-only AJAX handlers)
* Keeps best-effort nonce behavior (public read-only endpoints work even if a cached page embeds an older nonce)

= 1.4.0 =
* Made the front-end AJAX nonce best-effort for public read-only endpoints to avoid cached-page nonce expiry breakage

= 1.3.0 =
* Switched the front-end table to DataTables server-side pagination + search for fast mobile performance
* Reduced server load with search delay + capped page size per request

= 1.2.0 =
* Added IMDb links for films and nominees (uses FilmId / NomineeIds from the dataset)
* Improved mobile UX: tap-to-expand details control column, stacked controls on small screens, smaller default page size

= 1.1.0 =
* Updated cinematic palette to #0a1520 / #c9a961
* Added bundled CSV importer with chunked processing
* Ensured DataTables Print button works by enqueueing the required script
* Smaller front-end JSON payload for faster loads
