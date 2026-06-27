# Oscar Image Integrity Console Plan

- [x] T1: Add failing image-integrity contract.
goal:       Guard the new private console slug, state buckets, admin asset allowlist, version bump, and public leakage constraints before production code changes.
files:      `tests/image-integrity-console-contract.php`
acceptance: `php tests/image-integrity-console-contract.php` fails until the image-integrity console contract exists.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#contract`

- [x] T2: Build normalized image-integrity data buckets.
goal:       Reuse existing poster and portrait review metadata to produce unified `needs_review`, `ready`, `missing`, `wrong_match`, `accepted`, and `resolved` buckets.
files:      `academy-awards-table.php`
acceptance: The private data builder returns poster and portrait counts/rows without importing media, deleting media, calling APIs, or mutating Oscar result/title/person rows.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#states`

- [x] T3: Render the private Image Integrity console.
goal:       Add `Academy Awards > Image Integrity` with filters, bucket counts, note/state previews, and direct links into existing Poster Library and Person Portrait Queue workflows.
files:      `academy-awards-table.php`, `templates/image-integrity-admin.php`, `assets/css/admin.css`
acceptance: Admin page renders behind `manage_options`, nonce-protected actions are available where saves exist, and the page is included in the admin asset allowlist.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#admin-surface`

- [x] T4: Preserve strict public visual guards.
goal:       Ensure public title/person/category/ceremony renderers only use trusted mapped visuals and fall back cleanly when a candidate is not verified.
files:      `academy-awards-table.php`, `templates/entity-page.php`, `templates/hub-page.php`, `assets/css/academy-awards-table.css`
acceptance: Contract and sampled public HTML confirm no private image-integrity metadata leaks and no unverified candidate creates an image-backed public chamber.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#public-surface`

- [x] T5: Version, docs, and Control Desk alignment.
goal:       Release the pass as Oscars `2.7.87`, document the workflow, and align the theme Control Desk expected version/source anchor.
files:      `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
acceptance: Plugin docs/header report `2.7.87`, Theme Control Desk points to the new verified source, PHP lint passes, and `git diff --check` passes.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#acceptance`

- [ ] T6: Deploy, verify, and preserve continuity.
goal:       Deploy only changed runtime files, verify admin/public behavior, flush cache, capture evidence, update continuity docs, and prepare repo commit/push when approved.
files:      `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`, active session log, evidence folder under `10_VISUAL_EVIDENCE`
acceptance: Local/remote lint and SHA256 checks pass, public smoke routes return `200`, no private metadata appears in public HTML, and evidence is saved under `10_VISUAL_EVIDENCE`.
spec:       `docs/staging/specs/2026-06-27-oscar-image-integrity-console.md#acceptance`
