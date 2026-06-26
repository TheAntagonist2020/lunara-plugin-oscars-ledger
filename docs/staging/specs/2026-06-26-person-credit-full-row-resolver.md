# Person Credit Full-Row Resolver

contract: Add a private, plugin-owned workflow for resolving remaining Oscar person-credit hard cases after the single-credit source-correction queue is exhausted.

source state: The safe single-credit Sound Mixing queue is complete on live plugin `2.7.51`: `wp_aat_person_credit_reviews` reports `resolved=50`, the source-correction preview reports `review_rows_seen=0`, and the latest Sound Mixing audit reports `person_credit_linked=215`, `person_credit_unresolved=144`, `missing_source_nominee_ids=135`, and `label_id_mismatch=14`.

problem: The remaining unresolved credits are not safe for one-credit mutation. They require award-row-level handling so the resolver can preserve the order and count of visible credit labels against pipe-delimited source `nominee_ids`.

contract: Add a private full-row review/resolution lane for one source award row at a time. The lane stages every visible person credit for that row, displays the current source labels, current `nominee_ids`, proposed IMDb IDs, row-level validation messages, and the exact final `nominee_ids` string that would be written.

input: One source award row in `wp_academy_awards`.

identity: A full-row review record maps to one `source_award_id`, not to one `review_key`.

storage: Use a plugin-owned table such as `wp_aat_person_credit_row_reviews`, created through activation and `maybe_upgrade_schema`, to store row-level review state, proposed ordered IDs, private note, reviewer user ID, and timestamps.

states: `Needs Review`, `Ready To Apply`, `Source Gap`, `Applied`, `Ignore / Accept`.

admin surface: Add a private row-resolver section to `Academy Awards > Person Portrait Queue` or the existing person-credit review lane, with category filter, state filter, and one-row edit view.

apply gate: The apply action requires `manage_options`, nonce verification, exact typed confirmation of `source_award_id`, and explicit checkbox confirmation.

apply validation: Before writing, re-fetch the source award row, re-tokenize the source labels, re-tokenize the proposed IDs, and require:

- source row still exists.
- category still matches the staged category.
- current source label string still matches the reviewed row.
- proposed ID count exactly equals visible label count.
- every proposed ID is blank or a valid `nm...` ID.
- at least one proposed ID changes the row.
- no unresolved per-label mismatch remains unless the row is saved as `Source Gap`.

mutation: A successful apply updates exactly one source award row's `nominee_ids`, marks the row review `Applied`, rebuilds reporting tables, and clears runtime award caches.

invariant: The resolver never updates title rows, ceremony rows, attachment metadata, media files, review posts, Journal posts, public routes, or unrelated Oscar result rows.

invariant: A row with multiple visible credits cannot be partially applied by the old one-credit source-correction action.

invariant: Public HTML never exposes row-review states, proposed IDs, reviewer IDs, private notes, source CSV paths, or admin-only resolver markers.

test: Contract tests prove schema creation, state labels, row tokenization, validation failures, exact confirmation, single-row mutation boundaries, reporting rebuild invocation, and public-marker non-leakage.

test: Live/admin verification uses one dry-run row first, then one applied row, then public smoke for `/`, `/reviews/`, `/oscars/`, `/oscars/category/sound-mixing/`, and the corrected person routes.

deferred: Bulk row apply, automatic external API lookup, automatic fuzzy matching, image imports, non-Sound-Mixing category rollout, and any source rewrite that cannot be represented as an ordered `nominee_ids` string.

## Working notes

- Keep the old guarded one-credit correction action available but hidden when no eligible one-credit review rows remain.
- The next implementation should start read-only: build the row preview/export first, then add review save, then add apply.
- Remaining live raw source rows with blank `nominee_ids` exceed the audit's unresolved visible-credit count, so the resolver must rely on the audit/row tokenizer rather than treating every blank source row as an actionable person-credit case.
