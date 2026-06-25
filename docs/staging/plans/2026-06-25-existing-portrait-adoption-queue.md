# Existing Portrait Adoption Queue Plan

spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md`

- [x] T1: Add adoption contract coverage
goal: Prove existing portrait adoption is private, one-by-one, and metadata-only before production code changes.
files: `tests/existing-people-media-adoption-queue-contract.php`
acceptance: `php tests/existing-people-media-adoption-queue-contract.php` fails before implementation and passes after implementation.
spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md#existing-portrait-adoption-queue`

- [x] T2: Add adoption helpers and POST handling
goal: Let one reviewed existing attachment be adopted for one route-backed IMDb person ID.
files: `academy-awards-table.php`
acceptance: PHP lint passes and the adoption contract test passes.
spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md#existing-portrait-adoption-queue`

- [x] T3: Render the private adoption lane
goal: Show reusable existing PEOPLE media candidates and a guarded one-row adoption action in the existing Person Portrait Queue admin page.
files: `templates/person-portrait-import-admin.php`, `assets/css/admin.css`
acceptance: Admin template contains the adoption lane, nonce, duplicate guard copy, and no public route code changes.
spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md#existing-portrait-adoption-queue`

- [x] T4: Version, docs, and source anchor
goal: Bump plugin version, document the workflow, and align the Theme Control Desk expected version.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
acceptance: Plugin reports the new version, docs mention existing-media adoption, and Theme Control Desk expects the same version.
spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md#existing-portrait-adoption-queue`

- [x] T5: Verify and preserve continuity
goal: Run local checks, update continuity docs, commit, and push.
files: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
acceptance: PHP lint, contract tests, `git diff --check`, continuity updates, and pushed commits complete.
spec: `docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md#existing-portrait-adoption-queue`
