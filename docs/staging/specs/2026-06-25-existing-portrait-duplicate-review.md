# Existing PEOPLE Duplicate Review

## Summary

Add a private duplicate-review surface to `Academy Awards > Person Portrait Queue` after the clean existing-portrait adoption lane has been exhausted.

## Contract

- Keep existing one-click adoption limited to non-duplicate `reusable_nm_filename` rows.
- Keep duplicate rows blocked from automatic adoption.
- Add an admin-only `Duplicate review` filter for the Existing PEOPLE lane.
- For duplicate rows, show the competing PEOPLE attachments for the same IMDb person ID with thumbnails, attachment IDs, Media Library image links, and public person-file links.
- Add a one-by-one typed-confirmation resolver for duplicate groups:
  - admin chooses one attachment row
  - admin types the exact IMDb person ID
  - the resolver rechecks that the attachment still belongs to the current duplicate group
  - only the selected attachment receives `existing-media-adoption` metadata
- Do not fetch, import, rename, move, delete, or regenerate media.
- Do not mutate Oscar result, entity, ceremony, title, or person data.
- Do not expose duplicate-review metadata publicly.

## Verification

- PHP lint changed plugin/theme PHP files.
- Existing adoption queue contract passes.
- Admin duplicate-review markup is present in the source.
- Duplicate resolver markup, nonce, typed confirmation, and group mismatch guard are present in the source.
- Public person profile rendering still resolves only verified local portrait metadata.
