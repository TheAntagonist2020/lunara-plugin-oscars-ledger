# Person Credit Review Queue Plan

spec: `docs/staging/specs/2026-06-25-person-credit-review-queue.md`

- [x] T1: Schema and state contract
goal: Add the plugin-owned person-credit review table, state list, and version/docs contract without touching Oscar result data.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `tests/person-credit-review-queue-contract.php`
acceptance: `php tests\person-credit-review-queue-contract.php` and `php -l academy-awards-table.php`
spec: `docs/staging/specs/2026-06-25-person-credit-review-queue.md#person-credit-review-queue`

- [x] T2: Private admin review lane
goal: Render and save one unresolved person-credit review row at a time inside the private Person Portrait Queue.
files: `academy-awards-table.php`, `templates/person-portrait-import-admin.php`, `tests/person-credit-review-queue-contract.php`
acceptance: Contract verifies nonce-protected save form, filters, review-state merge, proposed ID field, and private note field.
spec: `docs/staging/specs/2026-06-25-person-credit-review-queue.md#person-credit-review-queue`

- [x] T3: Verification and ship
goal: Prove the new lane is private/read-only against Oscar data and source controlled.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `templates/person-portrait-import-admin.php`, `docs/staging/specs/2026-06-25-person-credit-review-queue.md`, `docs/staging/plans/2026-06-25-person-credit-review-queue.md`
acceptance: Full local PHP contract sweep passes, `git diff --check` passes, and docs describe the deferred correction boundary.
spec: `docs/staging/specs/2026-06-25-person-credit-review-queue.md#person-credit-review-queue`
