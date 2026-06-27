# Ceremony Full-Ledger Density

contract: Ceremony full-ledger mode (`?ledger=full`) keeps every winner, nominee, film, person, review link, and category anchor public, but adds a distinct research-view rhythm so the deep ballot reads as a premium dossier instead of an expanded wall of rows.

interface:
- Public full-mode hooks: `is-full-ledger`, `aat-ceremony-full-ledger-brief`, `aat-ceremony-full-ledger-stat`, `aat-ceremony-ballot-row-index`, and `aat-ceremony-ballot-depth`.
- Existing hooks stay in place: `aat-ceremony-ballot-ledger`, `aat-ceremony-ledger-command`, `aat-ceremony-ledger-jump-strip`, `aat-ceremony-ballot-group.is-ledger-chapter`, `aat-ceremony-ballot-row`, `aat-ceremony-ballot-actions`, and `ceremony-category-*` anchors.

invariant:
- No URL, rewrite, schema, source-row, source-query, or import behavior changes.
- Full mode exposes all nominee rows that were already exposed by `?ledger=full`.
- Fast mode remains winner-first with nominee summaries.
- Public visuals use existing verified title visual data only.
- No empty image chambers render when verified visuals are unavailable.

test:
- `php tests/inner-page-visual-rhythm-contract.php` proves the full-mode hooks, full-mode branch, row index, and CSS exist.
- Browser QA on `/oscars/ceremony/98/?ledger=full` at `390`, `768`, and `1280` proves one H1, no horizontal overflow, no broken completed images, and no private/admin leakage.

deferred:
- Additional poster import or image correction.
- Category reordering.
- Ajax filtering, search, or collapsing.
- Changing the source of nominee/person/title links.

## Working notes

The full-ledger pass should reuse the existing `$ceremony_ballot_full_requested`, `$ceremony_ballot_groups`, `$noms`, `$wins`, and visual helpers. The goal is to make research depth easier to scan, not to hide data.
