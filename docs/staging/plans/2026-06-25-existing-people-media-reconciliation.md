- [x] T1: Add read-only existing media audit mode
goal: Add a WP-CLI mode that scans an existing Media Library people folder and reports reusable portrait candidates without mutating media.
files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`
acceptance: `wp aat profile-images existing-media-audit --folder=PEOPLE --sample=25` reports folder strategy, scanned count, state buckets, duplicate count, and sample rows.
spec: `docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md#contract`

- [x] T2: Surface the workflow in private admin/docs
goal: Explain that the existing PEOPLE folder should be audited before new portrait imports.
files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\templates\person-portrait-import-admin.php`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\README.md`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\readme.txt`
acceptance: Private admin copy and plugin docs include the existing media audit command and read-only/adoption-deferred constraint.
spec: `docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md#invariants`

- [x] T3: Add contract and version alignment
goal: Lock the no-mutation audit contract and align version/source expectations.
files: `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\tests\existing-people-media-reconciliation-contract.php`, `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
acceptance: `php tests/existing-people-media-reconciliation-contract.php`, PHP lint, and `git diff --check` pass.
spec: `docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md#test`
