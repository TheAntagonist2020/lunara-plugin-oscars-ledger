# Existing PEOPLE Duplicate Group Review Plan

spec: `docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md`

- [x] T1: Add grouped duplicate-review contract coverage
goal: Prove the private duplicate-group review surface exists before production code changes.
files: `tests/existing-people-duplicate-group-review-contract.php`
acceptance: `php tests/existing-people-duplicate-group-review-contract.php` fails before implementation and passes after implementation.
spec: `docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md#contract`

- [x] T2: Add grouped duplicate rows and summary counts
goal: Let the existing PEOPLE adoption helper return one grouped review row per duplicate IMDb person ID.
files: `academy-awards-table.php`
acceptance: PHP lint passes and the grouped duplicate-review contract passes.
spec: `docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md#contract`

- [x] T3: Render grouped duplicate cards
goal: Show a compact comparison card with one typed-confirmation resolver form per competing attachment.
files: `templates/person-portrait-import-admin.php`, `assets/css/admin.css`
acceptance: Admin template and CSS contain grouped duplicate-review hooks and no bulk duplicate action markers.
spec: `docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md#contract`

- [x] T4: Version, docs, Control Desk, and continuity
goal: Bump the Oscars plugin version, document grouped duplicate review, align Theme Control Desk, verify, commit, push, and preserve continuity.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
acceptance: Local tests/lint/diff checks pass, live deployment verifies route smoke and no public leakage, and repo commits are pushed.
spec: `docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md#verification`
