# Existing Portrait Adoption Queue

contract: Private `Academy Awards > Person Portrait Queue` gains an existing-media adoption lane for already-uploaded PEOPLE folder images.

contract: Adoption source is WordPress attachment media only; no external image lookup, no source workbook mutation, no Oscar result mutation, and no public route changes.

contract: Only users with `manage_options` can view the adoption lane or submit adoption actions.

contract: One submitted row adopts exactly one attachment for exactly one IMDb person ID.

data: Adopted attachment metadata:
- `_aat_person_imdb_id`
- `_aat_person_portrait_source=existing-media-adoption`
- `_aat_person_portrait_verified=1`
- `_aat_person_portrait_adopted_at`
- `_aat_person_portrait_adopted_by`
- `_aat_person_portrait_adoption_note`

invariant: Candidate person ID must be an IMDb name ID and must resolve to a public Oscars name entity before adoption.

invariant: Attachment must exist, be an image attachment, and be an adoption candidate from the existing-media audit row builder.

invariant: Duplicate candidate rows are reviewable but cannot be adopted from an automatic one-click button until a future duplicate-resolution workflow exists.

invariant: Adoption clears `aat_person_profile_attachment_v2_<person_id>` so public person pages can pick up the reviewed attachment.

failure: Invalid nonce/capability blocks the action.

failure: Non-image attachment, missing attachment, invalid person ID, no public entity route, or duplicate candidate returns a private admin error and writes no metadata.

test: Contract test proves the mode, metadata keys, source value, duplicate guard, no external fetch, and no media import behavior.

deferred: Bulk adoption, duplicate-resolution UI, folder-image visual comparison, and source-table reconciliation for the 806 no-route approved IDs.

## Working notes

- Live audit found `3370` reusable filename candidates and `344` manual-review rows in `PEOPLE`.
- First release should favor safe one-by-one adoption over speed.
