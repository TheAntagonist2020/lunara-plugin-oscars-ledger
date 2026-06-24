# Lunara Oscar Ledger Plugin

Private WordPress plugin source for the Lunara Film Academy Awards Database / Oscar Ledger.

## Role

This plugin owns the server-side Oscars database, public Oscars routes, title/person/category/ceremony views, ballot/tracker utilities, and related data assets used by the Lunara site.

## Source Locations

- Local source: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth`
- Live plugin: `/srv/htdocs/wp-content/plugins/academy-awards-table-optimized`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `2.7.32`.

## Current Public Surface

- Ceremony pages include a data-derived Ceremony Thesis layer with critical-path navigation and compact major-race briefing cards.
- Ceremony pages include a Major Races proof module for Best Picture, Directing, Actor, and Actress before the complete ceremony ledger.
- `Academy Awards > Ceremony Write-Ups` privately previews Dalton-authored DOCX ceremony guides, stages 98 draft rows, reviews one ceremony at a time, and renders only approved write-ups near the top of ceremony dossiers.
- Ceremony Write-Ups includes private status filters, text search across staged copy/notes, and status counts so the 98-row editorial queue can be reviewed efficiently.
- Approved ceremony write-up fields are normalized to valid UTF-8 before public escaping so WordPress DB charset conversions cannot blank smart punctuation in public modules.
- Approved Ceremony Guide modules now include a stronger public guide-file presentation with metadata, ballot/ceremony actions, and refined responsive typography.
- Oscars related-review cards collapse label-only visual fallbacks into intentional text-led cards instead of public empty media chambers.
- Oscars related-review lanes now obey Theme Studio count and visual-treatment controls, including profile image focus support from the active theme.
- Oscars reporting tables now declare route-oriented composite indexes for category, ceremony, winner, and entity lookups.
- The private source validator distinguishes the raw SQL/workbook dimension delta from the header-adjusted data-row delta, reports ID-shape and duplicate-key checks, previews mojibake repair, and documents the safe external-SQL-to-`wp_aat_*` mapping path.

## Verification

- Run PHP lint on `academy-awards-table.php` and template/include PHP files after edits.
- Run `php tests\source-data-validation-contract.php` when validating the repaired workbook and normalized SQL candidates.
- Run `php tests\sql-performance-contract.php` after schema/index changes.
- Confirm representative Oscars routes return `200`.
- Confirm `/oscars/category/best-picture/` and `?history=full` preserve expected Ledger data and links.
- Confirm `/oscars/ceremony/{N}/` and `?ledger=full` preserve the major-races module and full ballot links.
- Confirm public ceremony HTML never exposes write-up source notes, source hashes, statuses, source paths, or reviewer user IDs.
- Flush WordPress cache after deploys and capture visual evidence only under the Desktop `10_VISUAL_EVIDENCE` folder.
