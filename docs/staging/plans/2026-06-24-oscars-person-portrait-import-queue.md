# Oscars Person Portrait Import Queue Plan

spec: `docs/staging/specs/2026-06-24-oscars-person-portrait-import-queue.md`

- [x] T1: Add import queue contract checks.
goal: Prove the queue remains admin-only, profile-source-only, duplicate-safe, and linked to person portrait metadata before implementation.
files: `tests/person-portrait-import-queue-contract.php`, `academy-awards-table.php`
acceptance: `php tests/person-portrait-import-queue-contract.php`
spec: `docs/staging/specs/2026-06-24-oscars-person-portrait-import-queue.md#decisions`

- [x] T2: Build the private portrait import queue.
goal: Add an Oscars admin screen that lists auditable person portrait rows and imports one verified TMDb profile image at a time.
files: `academy-awards-table.php`, `templates/person-portrait-import-admin.php`, `assets/css/admin.css`
acceptance: PHP lint changed files and `php tests/person-portrait-import-queue-contract.php`
spec: `docs/staging/specs/2026-06-24-oscars-person-portrait-import-queue.md#decisions`

- [x] T3: Version and document the workflow.
goal: Bump the plugin version and document the queue as the next step after the dry-run nominee audit.
files: `academy-awards-table.php`, `README.md`, `readme.txt`
acceptance: Plugin headers report the new version and docs mention one-by-one verified portrait imports.
spec: `docs/staging/specs/2026-06-24-oscars-person-portrait-import-queue.md#decisions`

- [x] T4: Verify and prepare deployment notes.
goal: Keep focused contracts green, lint changed files, run `git diff --check`, and update continuity after verification.
files: continuity docs, evidence notes
acceptance: Focused contracts pass, PHP lint passes, no unrelated dirty changes are staged.
spec: `docs/staging/specs/2026-06-24-oscars-person-portrait-import-queue.md#decisions`
