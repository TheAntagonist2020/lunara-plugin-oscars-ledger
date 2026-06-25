# Existing PEOPLE Manual Review Lane

contract: Private `Academy Awards > Person Portrait Queue` exposes a read-only `Manual review` view for PEOPLE folder images that cannot be safely mapped to one Oscars person ID.

contract: Manual-review rows are inspection-only. They show attachment context, image links, detected IDs, match strategy, and why no safe adoption action exists.

invariant: Manual-review rows never render the one-click adoption form or typed duplicate resolver form.

invariant: Manual-review rows do not fetch, import, rename, move, delete, regenerate, or mutate media, Oscar rows, entities, titles, ceremonies, or person data.

invariant: Manual-review state remains private to `manage_options` users and does not affect public routes.

data: The manual-review lane reuses the existing PEOPLE media audit rows with `state=needs_manual_review`.

test: Contract test proves the `manual` view, manual-review totals, read-only template copy, version/docs alignment, and absence of mutation actions for manual rows.

deferred: Editing/correcting manual-review rows, rejecting images, folder moves, and future source-table reconciliation.

## Working notes

- Live audit after clean bulk adoption shows `344` manual-review PEOPLE rows.
- This lane exists to make the remaining risk visible without pretending it is safe for bulk adoption.
