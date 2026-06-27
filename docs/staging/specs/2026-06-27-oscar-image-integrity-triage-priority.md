# Oscar Image Integrity Triage Priority

milestone: M2 (see docs/ROADMAP.md)

## Contract

- Extend the private `Academy Awards > Image Integrity` console with a triage priority layer that makes the highest public-credibility risks visible first.
- Add a `Fix First` focus that groups `wrong_match` and `needs_review` rows without changing the existing normalized bucket states.
- Keep the existing poster, portrait, OMDb, and PEOPLE review metadata as the source of truth.
- Each visible row may show admin-only priority and Oscar-impact context, but this context must not render on public routes.
- No image import, media deletion, automatic adoption, external API call, public URL change, rewrite change, or Oscar result mutation.

## Admin Surface

- Add `integrity_focus` as a sanitized private admin filter.
- Add a triage rail with `Fix First`, `Wrong Matches`, `Needs Review`, and `Missing Visuals`.
- Add row-level priority copy that explains why a row should be handled now.
- Add Oscar-impact context from existing entity rows so high-profile title/person issues rise above low-impact rows.

## Public Surface

- No public route, shortcode, REST endpoint, query parameter, or markup hook.
- Public title/person/category/ceremony rendering must remain strict and continue to ignore unverified queue candidates.

## Acceptance

- The image-integrity contract test fails before implementation and passes after `integrity_focus`, `Fix First`, priority row data, triage rail markup, and CSS hooks exist.
- `academy-awards-table.php` reports version `2.7.88`.
- PHP lint passes for changed PHP files.
- Public leakage checks continue to confirm that image-integrity admin metadata is not present in public templates.
