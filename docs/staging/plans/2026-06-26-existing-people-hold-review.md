# Existing PEOPLE Hold Review Plan

spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md`

- [x] T1: Add review storage and state contract
  goal: Create the plugin-owned review table, allowed states, allowed issue types, and fetch/save helpers.
  files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`
  acceptance: `php tests\existing-people-hold-review-contract.php`
  spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md#decisions`

- [x] T2: Add private hold-review admin lane
  goal: Let one non-duplicate Existing PEOPLE candidate be reviewed, noted, and approved before adoption.
  files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\templates\person-portrait-import-admin.php`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\assets\css\admin.css`
  acceptance: `php tests\existing-people-hold-review-contract.php`
  spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md#decisions`

- [x] T3: Enforce approved-state adoption guard
  goal: Reject non-duplicate Existing PEOPLE adoption unless the candidate is approved and the typed IMDb confirmation matches.
  files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\tests\existing-people-hold-review-contract.php`
  acceptance: `php tests\existing-people-hold-review-contract.php`
  spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md#decisions`

- [x] T4: Version, docs, and Control Desk alignment
  goal: Bump Oscars to `2.7.56`, document the private hold-review workflow, and update the Theme Control Desk expected source.
  files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\README.md`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
  acceptance: `php -l academy-awards-table.php`; `php -l inc\control-desk.php`
  spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md#implementation-notes`

- [ ] T5: Verify, deploy, and preserve continuity
  goal: Deploy only changed files, prove the live guard, flush cache, smoke public routes, and update continuity evidence.
  files: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
  acceptance: live WP-CLI verifier returns blocked-unapproved and metadata-unchanged; public smoke returns `200` with no private leakage.
  spec: `docs/staging/specs/2026-06-26-existing-people-hold-review.md#decisions`
