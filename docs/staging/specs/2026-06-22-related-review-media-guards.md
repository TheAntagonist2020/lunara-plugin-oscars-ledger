# Related Review Media Guards

Date: 2026-06-22
Milestone: Oscars Route-Family Visual Systems
Primary owner: Academy Awards plugin
Primary target: Oscars `On Lunara` related-review cards

## Contract

- Oscars related-review cards must not print a large media chamber when the card has no real visual.
- Real visual means a Review featured image, a title poster image, or a title backdrop-backed fallback.
- A label-only title placeholder is not a real visual for related-review cards.
- Cards without real media render as intentional text-led cards using a `has-no-media` state.
- Cards with real media render with a `has-media` state and keep the existing linked media treatment.

## Interfaces

- Public URLs remain unchanged.
- No database schema changes.
- No Review post data changes.
- No Oscar result data changes.
- No Theme Studio setting changes in this pass.
- Existing `aat-related-review-card`, `aat-related-review-media`, and `aat-related-review-body` hooks remain available.

## Invariants

- No public Oscars route should show an empty or label-only related-review media chamber.
- Review featured images remain first priority.
- Title posters/backdrops can still support visual fallback cards.
- Text-led cards retain Review, title profile, and metadata links.
- Public HTML must not expose admin/private metadata.

## Test

- A focused contract checks that ceremony, category, and entity related-review render blocks:
  - assign `has-media` / `has-no-media` card states;
  - conditionally render `aat-related-review-media` only when media exists;
  - do not render the old `aat-filmography-poster-placeholder` fallback inside related-review media blocks.
- PHP lint changed templates.
- Browser QA samples Best Picture, Ceremony 98, one title route, and one person route.
