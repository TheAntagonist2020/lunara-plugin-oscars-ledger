# Existing PEOPLE Duplicate Group Review

## Contract

- Add a private `Duplicate groups` duplicate-group review view to `Academy Awards > Person Portrait Queue`.
- Keep the existing `Duplicate review` row view available.
- Group duplicate PEOPLE portrait candidates by IMDb person ID so each duplicate person appears once in the grouped view.
- Each grouped card shows:
  - public person-file link
  - IMDb person ID
  - duplicate candidate count
  - each competing attachment thumbnail
  - attachment ID
  - Media Library image link
  - attachment title or filename
  - one typed-confirmation resolver form per attachment
- Resolver behavior stays unchanged:
  - admin chooses one attachment
  - admin types the exact IMDb person ID
  - the server rechecks the attachment still belongs to the current duplicate group
  - only the selected attachment receives `existing-media-adoption` metadata
- Do not add bulk duplicate adoption.
- Do not fetch, import, rename, move, delete, or regenerate media.
- Do not mutate Oscar result, entity, ceremony, title, person, or visual data.
- Do not expose duplicate group review metadata publicly.

## Verification

- Contract test proves version `2.7.44`, duplicate-group review markers, grouping summary, grouped template, typed-confirmation resolver forms, and CSS hooks.
- Existing adoption, duplicate resolver, and manual-review contracts remain green.
- PHP lint passes for changed PHP files.
- Public smoke confirms no private grouped-review markers leak onto sampled public routes.
