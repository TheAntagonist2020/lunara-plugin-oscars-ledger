# Related Review Media Guards Implementation Plan

Date: 2026-06-22
Spec: `docs/staging/specs/2026-06-22-related-review-media-guards.md`

## Tasks

- [x] T1: Add failing media-guard contract
  - goal: Make the related-review empty-chamber rule executable before production changes.
  - files: `tests/related-review-media-guards.php`
  - acceptance: `php tests/related-review-media-guards.php` fails against the current related-review card markup.
  - spec: `docs/staging/specs/2026-06-22-related-review-media-guards.md#test`

- [x] T2: Add card media states in templates
  - goal: Render related-review cards as media-backed or text-led instead of always printing a media chamber.
  - files: `templates/hub-page.php`, `templates/entity-page.php`
  - acceptance: `php tests/related-review-media-guards.php` passes and changed templates lint.
  - spec: `docs/staging/specs/2026-06-22-related-review-media-guards.md#contract`

- [x] T3: Add scoped text-led card styling
  - goal: Make no-media related-review cards look intentional and premium.
  - files: `assets/css/academy-awards-table.css`
  - acceptance: no `aat-related-review-card.has-no-media` visual rule is missing and `git diff --check` passes.
  - spec: `docs/staging/specs/2026-06-22-related-review-media-guards.md#invariants`

- [ ] T4: Verify, deploy, and preserve continuity
  - goal: Deploy only changed plugin files, verify public routes, update continuity, and push the plugin repo.
  - files: `LUNARA_WORLD_CHANGELOG.md`, active session log, `LUNARA_WEBSITE_HANDOFF.md`
  - acceptance: local/remote lint and hashes pass, cache flush succeeds, public smoke and visual QA pass, evidence is under `10_VISUAL_EVIDENCE`.
  - spec: `docs/staging/specs/2026-06-22-related-review-media-guards.md#test`
