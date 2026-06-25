# Profile Image Coverage Audit Plan

spec: `docs/staging/specs/2026-06-25-profile-image-coverage-audit.md`

- [x] T1: Add coverage contract test
goal: Prove the manual profile-image workflow exposes a read-only coverage mode before production code changes.
files: `tests/manual-profile-image-coverage-audit-contract.php`
acceptance: `php tests/manual-profile-image-coverage-audit-contract.php` fails before implementation and passes after implementation.
spec: `docs/staging/specs/2026-06-25-profile-image-coverage-audit.md#profile-image-coverage-audit`

- [x] T2: Implement coverage mode
goal: Add `wp aat profile-images coverage` with source people/entity/imported-media counts and samples.
files: `academy-awards-table.php`
acceptance: `php tests/manual-profile-image-coverage-audit-contract.php` and `php tests/manual-profile-image-batch-import-contract.php` pass.
spec: `docs/staging/specs/2026-06-25-profile-image-coverage-audit.md#profile-image-coverage-audit`

- [x] T3: Update private admin/docs copy
goal: Document the coverage command in the Person Portrait Queue and plugin docs without exposing anything publicly.
files: `templates/person-portrait-import-admin.php`, `README.md`, `readme.txt`
acceptance: Coverage contract confirms admin/docs markers and `php -l templates/person-portrait-import-admin.php` passes.
spec: `docs/staging/specs/2026-06-25-profile-image-coverage-audit.md#profile-image-coverage-audit`

- [x] T4: Verify and mark complete
goal: Run focused contracts, PHP lint, and whitespace checks, then mark this plan complete.
files: `docs/staging/plans/2026-06-25-profile-image-coverage-audit.md`
acceptance: `php tests/manual-profile-image-coverage-audit-contract.php`, `php tests/manual-profile-image-batch-import-contract.php`, changed-file PHP lint, and `git diff --check` pass.
spec: `docs/staging/specs/2026-06-25-profile-image-coverage-audit.md#profile-image-coverage-audit`
