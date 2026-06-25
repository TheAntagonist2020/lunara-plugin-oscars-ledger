# Existing PEOPLE Manual Review Lane Plan

spec: `docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md`

- [x] T1: Add manual-review contract coverage
goal: Prove the remaining PEOPLE manual-review rows are visible in a private read-only lane before production code changes.
files: `tests/existing-people-media-manual-review-lane-contract.php`
acceptance: `php tests/existing-people-media-manual-review-lane-contract.php` fails before implementation and passes after implementation.
spec: `docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md#existing-people-manual-review-lane`

- [x] T2: Add manual-review rows and summary counts
goal: Let the existing PEOPLE audit return paged `needs_manual_review` rows through a `manual` view without adding any mutation path.
files: `academy-awards-table.php`
acceptance: PHP lint passes and the manual-review contract test passes.
spec: `docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md#existing-people-manual-review-lane`

- [x] T3: Render read-only manual-review cards
goal: Show manual-review cards with attachment imagery and context, but no adoption or duplicate resolver form.
files: `templates/person-portrait-import-admin.php`, `assets/css/admin.css`
acceptance: Admin template contains the manual-review lane, read-only copy, media links, and no manual-row submit controls.
spec: `docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md#existing-people-manual-review-lane`

- [x] T4: Version, docs, Control Desk, and continuity
goal: Bump the Oscars plugin version, document the manual-review lane, align Theme Control Desk, verify, commit, push, and preserve continuity.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
acceptance: Local tests/lint/diff checks pass, live deployment verifies route smoke and no public leakage, and repo commits are pushed.
spec: `docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md#existing-people-manual-review-lane`
