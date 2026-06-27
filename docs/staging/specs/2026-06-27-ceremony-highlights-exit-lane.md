# Ceremony Highlights Exit Lane

## Summary

Upgrade the lower ceremony `Ceremony Highlights` module from a generic poster grid into a ceremony-specific visual exit lane. The page should keep its existing data contract and links while ending with a stronger editorial rhythm: one featured title, a compact verified-visual rail, winner/category context, and intentional text-led states only when imagery is unavailable.

## Contract

- Ceremony pages must keep using `get_ceremony_title_highlights()` as the source for the module.
- Public URLs, schema, rewrite rules, result rows, title links, category links, and person/company links must remain unchanged.
- The ceremony highlight section must expose scoped hooks:
  - `aat-ceremony-exit-lane`
  - `aat-ceremony-exit-feature`
  - `aat-ceremony-exit-feature-media`
  - `aat-ceremony-exit-rail`
  - `aat-ceremony-exit-card`
- The featured card should use existing verified title poster/backdrop packages when available.
- Rail cards must avoid empty media chambers. Cards without usable visual media must render as intentional text-led cards.
- The existing `aat-ceremony-gallery-section` hook must remain present for compatibility.
- The layout must be responsive and viewport-safe at mobile, tablet, and desktop widths.
- Public markup must not expose private admin metadata, source hashes, source notes, or operational review state.

## Non-Goals

- No database schema changes.
- No URL or rewrite-rule changes.
- No public API changes.
- No new media imports, third-party image lookups, or guessed poster/profile matches.
- No replacement of ceremony thesis, major-race, winner-circle, related-review, or ballot-ledger modules.

## Acceptance

- `tests/inner-page-visual-rhythm-contract.php` passes.
- `templates/hub-page.php` renders the ceremony exit lane hooks from existing ceremony title highlights.
- `assets/css/academy-awards-table.css` styles the exit lane, feature card, media chamber, rail, and no-media card state.
- PHP lint passes for changed PHP files.
- Public QA confirms no horizontal overflow, no broken images, and no empty image chambers on `/oscars/ceremony/98/`.
