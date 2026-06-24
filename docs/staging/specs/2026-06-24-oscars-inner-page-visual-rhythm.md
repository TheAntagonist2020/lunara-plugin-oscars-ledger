# Oscars Inner Page Visual Rhythm

## Summary

Raise the non-premium Oscars inner pages to the same publication-grade standard as the newer premium dossier surfaces. The immediate proof target is `/oscars/category/sound-mixing/`, which currently reads too much like a raw title ledger and does not promote craft people strongly enough.

## Contract

- Generic category pages must render as dossier pages, not plain archive headers.
- Category pages must expose a command band with latest winner, span, ceremony count, winner count, and durable route links.
- Category history rows must use the shared ledger-card grammar across premium and non-premium categories.
- Craft/person credits must render as linked person chips when a name entity can be resolved from nominee IDs, projected entities, or exact label lookup.
- Title links, ceremony links, category links, full-history mode, and existing ledger behavior must remain intact.
- Empty portrait/photo chambers must not be introduced. If no trustworthy person visual exists, render linked text chips only.
- Public markup must remain responsive, viewport-safe, and consistent with the Lunara typography system.

## Non-Goals

- No database schema changes.
- No URL or rewrite-rule changes.
- No public API changes.
- No broad Oscars redesign in one pass.
- No placeholder person photos or guessed image imports.

## Acceptance

- `tests/inner-page-visual-rhythm-contract.php` passes.
- `templates/hub-page.php` contains the shared inner route hooks and linked craft/person strip rendering.
- `academy-awards-table.php` exposes a safe label-to-name-entity resolver for templates.
- `assets/css/academy-awards-table.css` styles generic category dossiers, command bands, ledger cards, and craft/person chips without horizontal overflow.
- PHP lint passes for changed PHP files.
