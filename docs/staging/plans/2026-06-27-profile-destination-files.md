# Oscars Profile Destination Files Plan

- [x] T1: Add failing profile reader-path contract.
goal:       Guard the new title/person destination module hooks before production code changes.
files:      `tests/inner-page-visual-rhythm-contract.php`
acceptance: `php tests/inner-page-visual-rhythm-contract.php` fails for missing profile reader-path hooks.
spec:       `docs/staging/specs/2026-06-27-profile-destination-files.md#contract`

- [x] T2: Render profile reader-path module from existing entity data.
goal:       Add title/person reader-path cards using existing rows, links, review lookups, and verified visuals only.
files:      `templates/entity-page.php`
acceptance: `php tests/inner-page-visual-rhythm-contract.php` passes template hook assertions.
spec:       `docs/staging/specs/2026-06-27-profile-destination-files.md#contract`

- [x] T3: Style profile reader-path module for desktop, tablet, and mobile.
goal:       Make the new module visually dense, responsive, and text-led when media is unavailable.
files:      `assets/css/academy-awards-table.css`
acceptance: `php tests/inner-page-visual-rhythm-contract.php` passes CSS hook assertions.
spec:       `docs/staging/specs/2026-06-27-profile-destination-files.md#acceptance`

- [ ] T4: Version, verify, deploy, and preserve continuity.
goal:       Release the phase as Oscars `2.7.86`, align Control Desk, verify live routes, and capture evidence.
files:      plugin docs/version files, Theme Control Desk, continuity docs
acceptance: Local/remote lint and SHA256 checks pass, public smoke routes return `200`, and visual evidence is saved under `10_VISUAL_EVIDENCE`.
spec:       `docs/staging/specs/2026-06-27-profile-destination-files.md#acceptance`
