# Oscar Image Integrity Review Pack Plan

spec: `docs/staging/specs/2026-06-27-oscar-image-integrity-review-pack.md`

- [x] T1: Add private review-pack data contract
  goal: Expose a capped 25-row `review_pack_rows` array from existing Image Integrity console data without changing public/data mutation behavior.
  files: `academy-awards-table.php`, `tests/image-integrity-console-contract.php`
  acceptance: `php tests/image-integrity-console-contract.php`
  spec: `docs/staging/specs/2026-06-27-oscar-image-integrity-review-pack.md#data`

- [x] T2: Render the Review Pack in wp-admin
  goal: Add a compact private Review Pack module above the full Image Integrity table with direct row workflow links.
  files: `templates/image-integrity-admin.php`, `assets/css/admin.css`, `tests/image-integrity-console-contract.php`
  acceptance: `php tests/image-integrity-console-contract.php`
  spec: `docs/staging/specs/2026-06-27-oscar-image-integrity-review-pack.md#contract`

- [x] T3: Version, docs, and source anchors
  goal: Bump Oscars to `2.7.89`, document the review-pack workflow, and align Theme Control Desk.
  files: `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
  acceptance: `php tests/image-integrity-console-contract.php`, PHP lint changed files, `git diff --check`
  spec: `docs/staging/specs/2026-06-27-oscar-image-integrity-review-pack.md#test`
