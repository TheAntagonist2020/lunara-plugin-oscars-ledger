# Person Credit Full-Row Resolver Plan

spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md`

- [x] T1: Add row-review storage and state contract
goal: Create the plugin-owned row-review table and state helpers without mutating Oscar result data.
files: `academy-awards-table.php`, `tests/person-credit-full-row-resolver-contract.php`
acceptance: PHP lint passes and focused contract checks prove schema SQL, allowed states, and no writes outside row-review storage.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`

- [x] T2: Build full-row preview and validation
goal: Produce a private preview for one source award row that tokenizes visible credit labels, proposed IDs, validation messages, and final `nominee_ids`.
files: `academy-awards-table.php`, `tests/person-credit-full-row-resolver-contract.php`
acceptance: Focused contract checks cover label/ID count matching, valid/invalid `nm...` IDs, blank gaps, stale source rows, and exact ordered output.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`

- [x] T3: Add private admin review lane
goal: Add a private row-level review surface inside the existing Person Portrait Queue without public route changes.
files: `academy-awards-table.php`, `templates/person-portrait-import-admin.php`, `assets/css/admin.css`
acceptance: Admin template contract proves category/state filters, one-row edit fields, nonce/capability forms, and no public marker output.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`

- [x] T4: Add guarded one-row apply action
goal: Apply one validated full-row correction with exact confirmation, reporting rebuild, cache clearing, and strict mutation boundaries.
files: `academy-awards-table.php`, `tests/person-credit-full-row-resolver-contract.php`
acceptance: Focused contract checks prove exact typed confirmation, single source-row update, review state `Applied`, rebuild invocation, and rejection of partial/mismatched rows.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`

- [x] T5: Version, docs, and source-map alignment
goal: Document the workflow, bump the plugin version, and update the private theme source anchor.
files: `academy-awards-table.php`, `README.md`, `readme.txt`, `G:\lunara-backups\work\lunara-theme-blocks-20260513-2300\inc\control-desk.php`
acceptance: Version/docs/source map reference the new resolver release and PHP lint passes for changed PHP files.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`

- [x] T6: Local verification and deployment handoff
goal: Run local checks, preserve continuity notes, and leave a deployment-ready handoff without touching live until approved.
files: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\LUNARA_WORLD_CHANGELOG.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\SESSION-LOG-2026-06-25.md`, `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\09_DOCS_AND_NOTES\LUNARA_WEBSITE_HANDOFF.md`
acceptance: PHP lint, focused contracts, `git diff --check`, and continuity updates are complete; live deploy remains a separate approval step.
spec: `docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md#person-credit-full-row-resolver`
