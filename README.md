# Lunara Oscar Ledger Plugin

Private WordPress plugin source for the Lunara Film Academy Awards Database / Oscar Ledger.

## Role

This plugin owns the server-side Oscars database, public Oscars routes, title/person/category/ceremony views, ballot/tracker utilities, and related data assets used by the Lunara site.

## Source Locations

- Local source: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth`
- Live plugin: `/srv/htdocs/wp-content/plugins/academy-awards-table-optimized`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `2.7.77`.

## Current Public Surface

- Ceremony pages include a data-derived Ceremony Thesis layer with critical-path navigation and compact major-race briefing cards.
- All current bundled Oscar category routes now resolve through the premium dossier system, including Casting, Dramatic Picture Directing, Comedy Picture Directing, and Unique and Artistic Picture.
- The category dossier contract now checks the bundled canonical category set against the premium profile map so future data additions cannot quietly fall back to generic route framing.
- Category pages now scale themselves by route depth: brief, compact, standard, and marathon categories use different spacing/ledger density, while era browsers resize poster grids based on one, two, three, or four verified visuals.
- Premium category era browsers now fill poster chambers from verified nominee title visuals after winner visuals, giving thin historical categories more dynamic image rhythm without inventing or mislabeling art.
- Generic Oscar category pages now use dossier-grade route framing with command-band summaries, denser ledger-card rows, and linked craft/person credit chips where the person entity can be resolved.
- Archive-specials dossier hero titles now use a scoped long-title fit so Honorary, Special, Commendation, and SciTech pages keep premium typography without awkward desktop or mobile word breaks.
- Archive-specials category routes now include a shared premium dossier system for Honorary, Special, Commendation, and SciTech award families, keeping atypical Oscar honors curated instead of generic.
- Assistant Director and Dance Direction now include premium dossier treatment as early-craft curiosity branches, preserving their short-lived historical specificity while keeping URLs and data untouched.
- Early Short Subject branches now include premium dossier treatment for Comedy, Novelty, Color, One-reel, and Two-reel routes, preserving historical category specificity while keeping URLs and data untouched.
- Legacy Writing routes now include premium dossier treatment for Original Story and Title Writing, preserving their early-Oscar specificity while keeping URLs and data untouched.
- Oscar reporting-table rebuilds now recover missing person-link projections from unique, single-person `PERSON_PROFILE` media labels with embedded IMDb `nm` IDs, giving craft/category credits a real route-backed fallback when source rows lack `nominee_ids`.
- Person profile route queries now hydrate joined nominee-projection rows with fully qualified source columns, so recovered person links render real profile pages instead of falling through to 404.
- The private `wp aat profile-images person-credit-audit` command generates a read-only unresolved person credit reconciliation queue, with optional private CSV output, before any source-row correction or portrait adoption work.
- `Academy Awards > Person Portrait Queue` includes a private person-credit review lane for unresolved audit rows, storing one-row states, proposed IMDb person IDs, and notes as deferred correction metadata without mutating Oscar rows or media.
- The private `wp aat profile-images person-credit-stage` command validates Dalton-reviewed Batch CSVs, dry-runs by default, and writes only `wp_aat_person_credit_reviews` annotations when rerun with `--commit`.
- Reviewed single-credit person rows can now be corrected one at a time from the private Person Portrait Queue after an exact IMDb ID confirmation, updating only that award row's `nominee_ids` and rebuilding Oscars reporting tables.
- Multi-credit person rows now have a private full-row resolver inside the Person Portrait Queue, storing ordered source-row review proposals in `wp_aat_person_credit_row_reviews` and applying only after label/count validation plus exact source-row confirmation.
- The company/studio credit resolver now starts with a private `wp_aat_company_credit_row_reviews` storage contract, read-only `wp aat profile-images company-credit-audit` classifier, `Company / Studio Credits` admin review lane, and preview-only validation gate for Sound Mixing company, department, mixed, source-gap, and slot-pairing rows before any source mutation.
- Company/studio preview validation re-fetches the source row, checks stale category/labels, rejects non-`co...` or non-route-backed company IDs, enforces visible-label/proposed-ID slot parity, requires typed source-row confirmation, and still leaves source `nominee_ids` unchanged.
- Public Oscars category/ceremony renderers keep department-style technical credits text-led while true company/studio route actions now read as `Company History`.
- Person profile files now expose honest visual-source states for local portraits, TMDb portraits, and no-portrait cases; contextual title art is barred from the person portrait chamber.
- A dry-run nominee portrait batch audit can use Dalton's nominee CSV roster to review all person image states safely before any import or Media Library mutation.
- `Academy Awards > Person Portrait Queue` imports one verified TMDb person profile image at a time, marks the attachment with plugin-owned person portrait metadata, and keeps title/backdrop art barred from person portraits.
- A private WP-CLI manual batch importer can dry-run and import Dalton-supplied `oscars-profile-images` JPEGs against `tmdb_profile_results.csv` and `profiles_missing.csv`, marking approved portraits as `manual-batch-upload`.
- The private profile-image coverage audit compares `tmdb_profile_results.csv` `Status=OK` IDs against live `people`, `wp_aat_entities`, and imported attachment metadata so route-backed, approved-source/no-people-row, and imported-media/no-route buckets are visible before scaling.
- The private existing media reconciliation audit scans the current `PEOPLE` Media Library folder before new imports, reporting already route-backed portraits, reusable `nm...` filename matches, likely name matches, duplicates, and manual-review rows without mutating attachments.
- `Academy Awards > Person Portrait Queue` includes an Existing PEOPLE adoption lane that lets admins adopt one reusable `nm...` filename portrait at a time as `existing-media-adoption` without fetching, importing, renaming, or moving media.
- Existing PEOPLE ready adoption now requires typed `nm...` confirmation before the admin POST can write portrait metadata, matching the duplicate resolver's defensive rhythm.
- Existing PEOPLE non-duplicate candidates now require a private hold review saved as `Approved To Adopt` before the exact typed IMDb confirmation can unlock adoption.
- Existing PEOPLE hold review now has private state filters for initial review, source-needed rows, and wrong-label rows so suspect portraits can be triaged without bulk adoption.
- The Existing PEOPLE lane now includes a duplicate-review view with competing attachment thumbnails for duplicate `nm...` filename matches, plus a one-by-one typed-confirmation resolver for choosing the correct attachment without enabling bulk duplicate adoption.
- The Existing PEOPLE lane now includes a grouped duplicate-review view so duplicate candidates can be judged one person at a time while keeping typed-confirmation resolver writes per attachment.
- The Existing PEOPLE lane includes a read-only Manual review lane for `needs_manual_review` rows so unresolved PEOPLE images can be inspected without rendering adoption or duplicate resolver actions.
- Ceremony pages include a Major Races proof module for Best Picture, Directing, Actor, and Actress before the complete ceremony ledger.
- `Academy Awards > Ceremony Write-Ups` privately previews Dalton-authored DOCX ceremony guides, stages 98 draft rows, reviews one ceremony at a time, and renders only approved write-ups near the top of ceremony dossiers.
- Ceremony Write-Ups includes private status filters, text search across staged copy/notes, and status counts so the 98-row editorial queue can be reviewed efficiently.
- Approved ceremony write-up fields are normalized to valid UTF-8 before public escaping so WordPress DB charset conversions cannot blank smart punctuation in public modules.
- Approved Ceremony Guide modules now include a stronger public guide-file presentation with metadata, ballot/ceremony actions, and refined responsive typography.
- Oscars related-review cards collapse label-only visual fallbacks into intentional text-led cards instead of public empty media chambers.
- Oscars related-review lanes now obey Theme Studio count and visual-treatment controls, including profile image focus support from the active theme.
- Oscars reporting tables now declare route-oriented composite indexes for category, ceremony, winner, and entity lookups.
- The private source validator distinguishes the raw SQL/workbook dimension delta from the header-adjusted data-row delta, reports ID-shape and duplicate-key checks, previews mojibake repair, and documents the safe external-SQL-to-`wp_aat_*` mapping path.
- Public Oscars hub and ceremony dossier summary reads now use projection-aware helpers before falling back to the legacy awards table.

## Verification

- Run PHP lint on `academy-awards-table.php` and template/include PHP files after edits.
- Run `php tests\source-data-validation-contract.php` when validating the repaired workbook and normalized SQL candidates.
- Run `php tests\sql-performance-contract.php` after schema/index changes.
- Run `php tests\public-query-path-contract.php` after public hub/category/ceremony query-path changes.
- Confirm representative Oscars routes return `200`.
- Confirm `/oscars/category/best-picture/` and `?history=full` preserve expected Ledger data and links.
- Confirm `/oscars/ceremony/{N}/` and `?ledger=full` preserve the major-races module and full ballot links.
- Confirm public ceremony HTML never exposes write-up source notes, source hashes, statuses, source paths, or reviewer user IDs.
- Flush WordPress cache after deploys and capture visual evidence only under the Desktop `10_VISUAL_EVIDENCE` folder.
