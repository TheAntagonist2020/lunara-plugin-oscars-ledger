# Company And Studio Credit Resolver Plan

spec: `docs/staging/specs/2026-06-26-company-studio-credit-resolver.md`

contract: Build a private, plugin-owned resolver lane for Sound Mixing source rows whose visible credits are companies, studios, studio departments, or source gaps rather than individual people. Keep the existing `nm...` person resolver untouched.

baseline: Oscars plugin `2.7.52`; person credit full-row resolver is live and verified. Company routes such as `/oscars/company/co0050868/`, `/oscars/company/co0028775/`, and `/oscars/company/co0007143/` already return public company profiles without sampled private marker leakage.

## T1 Schema And State Contract

goal: Add the plugin-owned table and state helpers for company/studio row reviews without touching Oscar result data.

files:

- `academy-awards-table.php`

acceptance:

- `wp_aat_company_credit_row_reviews` is created through activation and `maybe_upgrade_schema`.
- Allowed review states are restricted to `Needs Review`, `Ready To Apply`, `Department Label Only`, `Source Gap`, `Applied`, and `Ignore / Accept`.
- Allowed entity kinds include `company`, `department`, `mixed`, `source_gap`, `person`, and `slot_mismatch` so the admin queue can explicitly route rows out of the company/studio lane when needed.
- Schema creation does not alter `wp_academy_awards`, person credit review tables, reporting tables, attachment metadata, or public posts.
- PHP lint passes.

## T2 Read-Only Classifier

goal: Add a read-only classifier for unresolved Sound Mixing source rows so remaining credits can be sorted into person, company, department, mixed, and source-gap buckets before any mutation path exists.

files:

- `academy-awards-table.php`

acceptance:

- Existing person audit behavior remains intact.
- Read-only command: `wp aat profile-images company-credit-audit --category=sound-mixing --state=all`.
- The classifier reports totals and representative rows for `person`, `company`, `department`, `mixed`, `source_gap`, and `slot_mismatch`.
- The classifier explicitly flags cases where visible label count and proposed company ID count do not match.
- No source mutation occurs in the classifier.

## T3 Private Admin Review Surface

goal: Add a private `Company / Studio Credits` section inside the current Oscar credit administration surface so Dalton can review one source row at a time.

files:

- `academy-awards-table.php`
- `templates/person-portrait-import-admin.php`
- `assets/css/admin.css`

acceptance:

- The existing admin menu slug and person-credit workflow remain unchanged.
- Rows can be filtered by review state and entity kind.
- One row can be saved with status, entity kind, proposed IDs, display label override, and correction note.
- Saved review data persists after refresh.
- Private notes, proposed IDs, reviewer IDs, and review states never render on public routes.

## T4 Preview And Validation Gate

goal: Build the apply preview and validation layer while keeping source mutation disabled until at least one reviewed row passes all safety checks.

files:

- `academy-awards-table.php`

acceptance:

- Preview re-fetches the source row and shows current labels, current `nominee_ids`, proposed IDs, entity kind, and exact final `nominee_ids` string.
- Validation rejects `nm...` IDs, fake department IDs, blank `Ready To Apply` company slots, stale labels, stale category, slot-count mismatch, unchanged rows, and missing typed confirmation.
- Validation preserves the renderer slot-pairing contract: visible label slot `n` links to ID slot `n`.
- No mutation occurs during preview-only mode.

## T5 One-Row Guarded Apply

goal: Enable a guarded one-row company apply path after preview validation proves a row is safe.

files:

- `academy-awards-table.php`

acceptance:

- Apply requires `manage_options`, nonce verification, typed `source_award_id`, and explicit checkbox confirmation.
- Apply updates exactly one source award row's `nominee_ids`.
- Apply marks the company/studio review row `Applied`.
- Reporting rebuild and relevant award cache clearing are invoked after a successful apply.
- Department, mixed, source-gap, and slot-mismatch rows cannot apply.

## T6 Public Route Verification

goal: Confirm the applied row renders as credible public company credit without corrupting person pages or leaking admin metadata.

files:

- No template file changes expected unless verification exposes a renderer defect.

acceptance:

- `/oscars/category/sound-mixing/` returns `200`.
- Applied company chips link to verified `/oscars/company/{co...}/` routes.
- Representative company, title, and person routes return `200`.
- Public HTML contains no review state, correction note, proposed ID, reviewer ID, source path, or admin queue marker.

## T7 Version, Docs, Continuity, And Deployment

goal: Ship the workflow as the next Oscars plugin version with source anchors and continuity updated.

files:

- `academy-awards-table.php`
- `README.md`
- `readme.txt`
- `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
- `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`
- `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
- `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`

acceptance:

- Plugin version is bumped after implementation.
- Control Desk expected version/source anchor is current.
- PHP lint passes for changed PHP files.
- `git diff --check` passes.
- Live deploy only includes changed plugin/theme files.
- Local and remote hashes match after deploy.
- WordPress cache is flushed.
- Evidence is saved under `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\10_VISUAL_EVIDENCE`.

## Deferred

- Bulk apply.
- Automatic IMDb company lookup.
- Department entity routes.
- Image imports.
- Non-Sound-Mixing rollout.
- Public visual redesign.
- Any mutation that cannot be represented as an ordered `nominee_ids` string.
