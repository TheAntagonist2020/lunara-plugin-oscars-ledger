contract: `wp aat profile-images existing-media-audit --folder=PEOPLE --sample=25 [--output-csv=/private/people-media.csv]`

data:
- Source attachments come from the existing WordPress Media Library folder named by `--folder`.
- Folder lookup supports common folder plugins through taxonomy-backed folders, FileBird tables, and Real Media Library tables.
- `--all-media` is an explicit fallback for scanning all image attachments when the folder plugin storage cannot be detected.
- Each audited attachment reports attachment ID, file path, title, alt text, explicit `_aat_person_imdb_id`, detected `nm...` ID, likely route-backed person, match strategy, duplicate flag, and review state.

states:
- `already_route_backed`: explicit person metadata exists and the person route exists.
- `mapped_no_route`: explicit person metadata exists but no Oscar person route exists.
- `reusable_nm_filename`: an `nm...` ID appears in filename/title/alt and that person route exists, but plugin portrait metadata is not yet attached.
- `likely_name_match`: cleaned attachment title/alt uniquely matches one Oscar person label.
- `ambiguous_name_match`: cleaned attachment title/alt matches more than one Oscar person label.
- `needs_manual_review`: no safe ID or unique route-backed name match exists.

invariants:
- The audit is read-only against WordPress content and plugin tables.
- The audit never imports images, downloads images, calls external APIs, mutates attachments, writes person metadata, or clears portrait transients.
- Existing import and coverage modes stay unchanged.
- Public routes and public HTML stay unchanged.

test:
- `php -l academy-awards-table.php`
- `php tests/existing-people-media-reconciliation-contract.php`
- `wp aat profile-images existing-media-audit --folder=PEOPLE --sample=25` reports folder strategy, scanned count, state buckets, duplicate count, and samples without mutation.

deferred:
- An adoption action that writes `_aat_person_imdb_id` to selected existing attachments.
- A browser/admin review queue for one-by-one adoption.
- Deleting duplicate Media Library items.

## Working notes

The user already has a large `PEOPLE` folder in Media Library, so adding more images before reconciling existing assets would create avoidable duplication. This pass turns that folder into a private inventory and preserves the import pipeline only for genuinely missing portraits.
