# Oscars Profile Destination Files

milestone: M1 (see docs/ROADMAP.md)

## Contract

- Title and person profile routes must gain a scoped reader-path module that makes the destination page feel intentional after a ceremony, category, review, or search click.
- The module must use existing Oscar rows, existing title/person URLs, existing review lookups, and existing verified visual packages only.
- Public hooks must include `aat-profile-reader-path`, `aat-profile-reader-path-grid`, `aat-profile-reader-path-card`, and scoped title/person variants.
- Current URLs, schema, rewrite rules, Oscar result rows, profile dossier strip behavior, review modules, and ledger modes must remain unchanged.
- Public markup must not expose private admin metadata, source hashes, source notes, review-state internals, API keys, or import paths.

## Acceptance

- `tests/inner-page-visual-rhythm-contract.php` fails before implementation and passes after implementation.
- Title routes expose the reader-path hooks and at least one title-scoped card path from existing page data.
- Person routes expose the reader-path hooks and at least one person-scoped card path from existing page data.
- CSS styles the reader-path module, cards, media strip, and text-led fallback without horizontal overflow.
- PHP lint passes for changed PHP files.
