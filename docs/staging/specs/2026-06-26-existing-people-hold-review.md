# Existing PEOPLE Hold Review

milestone: M5 (see docs/ROADMAP.md)

## Decisions

contract: `Academy Awards > Person Portrait Queue` gets a private Existing PEOPLE hold-review lane for non-duplicate reusable `nm...-profile` candidates that still need a human judgment before adoption.

contract: normal Existing PEOPLE adoption becomes a two-step private workflow for non-duplicate candidates: review approval first, exact typed IMDb `nm...` confirmation second.

contract: duplicate-person rows remain in the existing duplicate-specific visual review path and are not handled by this lane.

contract: public routes, public APIs, Oscar source rows, nominee/result rows, media files, attachment files, and person/title/ceremony URLs do not change.

data: add a plugin-owned table, `wp_aat_person_portrait_existing_reviews`, keyed by attachment ID plus candidate IMDb person ID.

data: one review record stores attachment ID, candidate person ID, review state, issue type, private note, reviewer user ID, created timestamp, and updated timestamp.

states: `Needs Review`, `Approved To Adopt`, `Wrong Person Or Label`, `Not A Person`, `Needs Better Source`, `Reject / Ignore`, `Resolved`.

issue types: `Missing Expected Name`, `Expected Label Mismatch`, `Expected Source Gap`, `Suspicious Label`, `Manual Note`, `None`.

invariant: a non-duplicate existing portrait candidate cannot be adopted unless its current review state is `Approved To Adopt` and the submitted confirmation exactly matches the candidate IMDb person ID.

invariant: the adoption method must re-fetch the current PEOPLE audit row before adopting and must reject stale attachment/person pairs.

invariant: review notes and review states remain admin-only and must not appear in public HTML.

failure: saving a review with an invalid attachment, invalid candidate IMDb ID, unsupported state, unsupported issue type, stale candidate pair, or missing capability returns an admin error without changing adoption metadata.

failure: adoption of an unreviewed or non-approved candidate returns a specific private `WP_Error`, `aat_existing_portrait_review_required`, and leaves attachment metadata unchanged.

test: local PHP lint changed plugin/theme files.

test: plugin contract test proves table/schema registration, allowed states, admin strings, adoption approval guard, and no import/network code in this workflow.

test: live WP-CLI verifier proves an unapproved ready candidate is blocked, metadata unchanged; then a review-approved proof verifies the candidate remains blocked without matching typed confirmation.

test: public smoke for `/`, `/reviews/`, `/reviews/sinners-2025/`, `/oscars/`, `/oscars/category/best-picture/`, `/oscars/category/sound-mixing/`, and one sampled person route returns `200` with no private review/adoption leakage.

deferred: no bulk adoption, no automatic image sourcing, no OMDb/TMDb calls, no duplicate group resolver redesign, no public visual changes.

deferred: resolving the duplicate-person rows stays in the existing duplicate-group workflow.

## Implementation Notes

Primary plugin files:

- `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\academy-awards-table.php`
- `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\templates\person-portrait-import-admin.php`
- `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\assets\css\admin.css`
- `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\README.md`
- `G:\lunara-backups\work\academy-awards-table-optimized-ceremony-depth\readme.txt`

Theme alignment:

- `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`

Expected version bump: Oscars plugin `2.7.55 -> 2.7.56`.

Evidence target:

- `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\10_VISUAL_EVIDENCE\lunara-existing-people-hold-review-20260626`

## Working Notes

Current strict hold evidence after the 98-row bulk adoption pass:

- `90` reusable rows remain.
- `69` are duplicate-person rows and stay outside this lane.
- `21` are non-duplicate rows: `18` missing expected name, `2` expected label mismatch, and `1` expected source gap.
- Examples requiring review include `Hong Kong`, `The Netherlands`, `Puerto Rico`, `Steven A. Morrow` vs `Steve A. Morrow`, `Ray Parker` vs `Ray Parker Jr.`, and `Guillaume Rocheron` with `NO_PHOTO`.
