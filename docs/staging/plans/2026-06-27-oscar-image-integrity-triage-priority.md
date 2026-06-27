# Oscar Image Integrity Triage Priority Plan

- [x] T1: Add failing triage-priority contract.
goal:       Guard `integrity_focus`, `Fix First`, row priority context, admin triage rail markup, version bump, and public leakage constraints before production code changes.
files:      `tests/image-integrity-console-contract.php`
acceptance: `php tests/image-integrity-console-contract.php` fails until the triage-priority console contract exists.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-triage-priority.md#acceptance`

- [x] T2: Build private triage priority data.
goal:       Add sanitized focus filtering, `Fix First` counts, and row-level priority/impact data using existing poster and portrait metadata.
files:      `academy-awards-table.php`
acceptance: Contract passes and rows can be filtered to wrong-match/needs-review fix-first work without mutating Oscar data or media.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-triage-priority.md#admin-surface`

- [x] T3: Render and style the triage rail.
goal:       Add a compact private admin triage rail and row priority presentation that makes the first cleanup batch obvious.
files:      `templates/image-integrity-admin.php`, `assets/css/admin.css`
acceptance: Contract passes and admin CSS contains scoped triage rail/priority hooks.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-triage-priority.md#admin-surface`

- [x] T4: Version, docs, and source alignment.
goal:       Release as Oscars `2.7.88`, document the triage priority layer, and update the Theme Control Desk expected source.
files:      `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
acceptance: Plugin docs/header report `2.7.88`, Theme Control Desk points to the new source, PHP lint passes, and `git diff --check` passes.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-triage-priority.md#acceptance`
