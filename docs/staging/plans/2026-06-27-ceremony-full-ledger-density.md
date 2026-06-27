# Ceremony Full-Ledger Density Plan

spec: `docs/staging/specs/2026-06-27-ceremony-full-ledger-density.md`

- [x] T1: Add full-ledger research rhythm
  goal: Give `?ledger=full` a distinct command brief, row index, and depth styling while preserving all nominee rows and links.
  files: `templates/hub-page.php`, `assets/css/academy-awards-table.css`, `tests/inner-page-visual-rhythm-contract.php`
  acceptance: `php tests/inner-page-visual-rhythm-contract.php` passes and `/oscars/ceremony/98/?ledger=full` QA confirms no overflow, no broken completed images, and no private/admin leakage.
  spec: `docs/staging/specs/2026-06-27-ceremony-full-ledger-density.md#ceremony-full-ledger-density`
