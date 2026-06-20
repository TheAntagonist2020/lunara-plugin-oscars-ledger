=== Lunara Film — Academy Awards Database ===
Contributors: lunarafilm
Tags: oscars, academy awards, datatable, film, movies
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 2.7.29
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
