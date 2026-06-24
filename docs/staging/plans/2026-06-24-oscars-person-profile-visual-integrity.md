# Oscars Person Profile Visual Integrity Plan

spec: `docs/staging/specs/2026-06-24-oscars-person-profile-visual-integrity.md`

- [x] T1: Add person visual-source metadata contract.
goal: Person visual packages expose source/state metadata without changing public URLs or importing images.
files: `academy-awards-table.php`, `tests/person-profile-visual-integrity-contract.php`
acceptance: `php tests/person-profile-visual-integrity-contract.php`
spec: `docs/staging/specs/2026-06-24-oscars-person-profile-visual-integrity.md#decisions`

- [x] T2: Render honest person profile visual states.
goal: Person pages class and render local portrait, TMDb portrait, contextual fallback, and no-portrait states distinctly.
files: `templates/entity-page.php`, `assets/css/academy-awards-table.css`, `tests/person-profile-visual-integrity-contract.php`
acceptance: `php tests/person-profile-visual-integrity-contract.php`
spec: `docs/staging/specs/2026-06-24-oscars-person-profile-visual-integrity.md#decisions`

- [x] T3: Upgrade private portrait audit readability.
goal: Poster Library person audit surfaces portrait source/state clearly enough to guide manual cleanup.
files: `academy-awards-table.php`, `templates/poster-admin.php`, `tests/person-profile-visual-integrity-contract.php`
acceptance: `php tests/person-profile-visual-integrity-contract.php`
spec: `docs/staging/specs/2026-06-24-oscars-person-profile-visual-integrity.md#decisions`

- [x] T4: Verify and document.
goal: Keep the repo green, preserve continuity, and prepare WordPress.com deployment notes.
files: `README.md`, `readme.txt`, `docs/staging/plans/2026-06-24-oscars-person-profile-visual-integrity.md`, continuity docs
acceptance: PHP lint changed PHP files, all focused contracts pass, `git diff --check`, continuity docs updated.
spec: `docs/staging/specs/2026-06-24-oscars-person-profile-visual-integrity.md#decisions`
