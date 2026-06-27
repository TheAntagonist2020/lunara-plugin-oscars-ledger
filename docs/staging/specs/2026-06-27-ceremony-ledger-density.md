# Ceremony Ledger Density

contract: Ceremony dossier pages keep the existing Complete Ceremony Ledger data, URLs, fast/full modes, and category anchors, but render the ledger as a chaptered reader surface instead of a flat list of category groups.

interface:
- Public hooks: `aat-ceremony-ledger-command`, `aat-ceremony-ledger-jump-strip`, `aat-ceremony-ballot-group.is-ledger-chapter`, `aat-ceremony-ballot-group-visuals`, `aat-ceremony-ballot-visual`, `aat-ceremony-ballot-media`.
- Existing hooks stay in place: `aat-ceremony-ballot-ledger`, `aat-ceremony-ballot-group`, `aat-ceremony-ballot-row`, `aat-ceremony-ballot-summary`, and `ceremony-category-*` anchors.

invariant:
- No URL, rewrite, schema, source-row, or ledger-mode behavior changes.
- Public ledger visuals use existing verified title visual data only.
- No empty image chambers render when verified visuals are unavailable.
- Full ledger mode continues to expose nominee rows and deep links.

test:
- `php tests/inner-page-visual-rhythm-contract.php` proves the ceremony ledger chapter hooks, jump-strip hooks, verified visual hooks, and public CSS exist.
- Browser QA on `/oscars/ceremony/98/` at `390`, `768`, and `1280` proves no horizontal overflow, no broken completed images, and no private/admin leakage.

deferred:
- Importing additional posters or correcting missing visual data.
- Reordering categories or changing winner/nominee source data.
- Replacing the complete ledger with an interactive app shell.

## Working notes

The first pass should be markup and CSS only, fed by the existing `$ceremony_ballot_groups`, `$ceremony_review_map`, and title visual helper functions already used above the ledger.
