# Profile Image Batch Importer Plan

spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md`

- [x] T1: Add batch importer contract test
goal: Lock the CLI command, dry-run behavior, metadata contract, and no-external-fetch invariant before production code.
files: `tests/manual-profile-image-batch-import-contract.php`
acceptance: `php tests/manual-profile-image-batch-import-contract.php`
spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md#profile-image-batch-importer`

- [x] T2: Implement private WP-CLI importer
goal: Add a bounded dry-run/import command for Dalton-supplied `nmXXXXXXX.jpg` profile images and approved CSV rows.
files: `academy-awards-table.php`
acceptance: `php tests/manual-profile-image-batch-import-contract.php` and `php -l academy-awards-table.php`
spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md#profile-image-batch-importer`

- [x] T3: Update admin language and docs
goal: Keep the Person Portrait Queue aligned with Dalton-supplied/manual imagery rather than external portrait hunting.
files: `templates/person-portrait-import-admin.php`, `README.md`, `readme.txt`
acceptance: `php -l templates/person-portrait-import-admin.php` and source check confirms no Cinemagoer/external-sourcing language.
spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md#profile-image-batch-importer`

- [x] T4: Run local dry-run against staged inputs
goal: Verify the zip and CSVs produce a clean importable set before anything touches WordPress media.
files: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\10_VISUAL_EVIDENCE\lunara-profile-image-batch-importer-20260625\dry-run-source-package-report.txt`
acceptance: Dry-run reports `4,158` importable zip images and `0` zip/missing conflicts.
spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md#profile-image-batch-importer`

- [x] T5: Version, verify, and prepare deploy
goal: Bump plugin version, run all local contracts/lint/diff checks, and update continuity docs with the exact importer workflow.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, continuity docs under `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`
acceptance: Full PHP contract suite passes, changed PHP files lint, `git diff --check`, continuity docs updated.
spec: `docs/staging/specs/2026-06-25-profile-image-batch-importer.md#profile-image-batch-importer`
