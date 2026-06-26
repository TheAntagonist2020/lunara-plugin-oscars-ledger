# Final Category Dossier Completion Spec - 2026-06-26

## Decisions

contract: The current bundled Oscars data has 66 canonical category routes. After this pass, every current canonical category must resolve to the premium category dossier system instead of the generic category wrapper.

contract: Add premium category profiles for the four remaining generic categories:

- `CASTING`
- `DIRECTING (Dramatic Picture)`
- `DIRECTING (Comedy Picture)`
- `UNIQUE AND ARTISTIC PICTURE`

contract: Preserve the existing public route contract:

- `/oscars/category/casting/`
- `/oscars/category/directing-dramatic-picture/`
- `/oscars/category/directing-comedy-picture/`
- `/oscars/category/unique-and-artistic-picture/`

contract: Preserve existing schema, source rows, importer behavior, public APIs, full-history query behavior, and ceremony/title/person/company links.

contract: The four routes should use scoped semantic hooks:

- `aat-casting-dossier`
- `aat-directing-dramatic-picture-dossier`
- `aat-directing-comedy-picture-dossier`
- `aat-unique-artistic-picture-dossier`

contract: Public labels should remain polished and editorial, not raw source labels:

- `Casting`
- `Dramatic Picture Directing`
- `Comedy Picture Directing`
- `Unique and Artistic Picture`

invariant: `Best Picture`, existing craft dossiers, archive-specials dossiers, short-subject dossiers, and legacy-writing dossiers must retain their current hooks and behavior.

invariant: The completion check should be data-derived, not hand-waved. The bundled `data/oscars.csv` canonical categories should all be represented in the premium profile map after this pass.

contract: Category era browsers should use existing verified title visual packages as visual navigation fuel. When multiple trustworthy title visuals exist inside a decade, render a compact poster grid rather than a single static marker; never create fake or empty image chambers.

failure: If a future data import adds a new category without a profile, the category should still render safely, but local contract tests should make the missing profile obvious before deployment.

test: PHP lint changed plugin files.

test: Run the full local plugin PHP contract suite.

test: Add or extend a contract test that compares the bundled canonical category list against premium profile keys and expects zero current missing categories.

test: Add or extend a contract test that confirms the era browser poster-grid hook and bounded visual-density behavior remain present.

test: Public smoke should return `200` for `/`, `/reviews/`, `/oscars/`, the four promoted category routes, `/oscars/category/best-picture/`, and `/oscars/ceremony/98/`.

test: Public HTML checks should confirm each promoted route has its expected dossier hook and no sampled Control Desk/wp-admin asset leakage.

test: Responsive visual QA at `390`, `768`, and `1280` should confirm one H1, no horizontal overflow, no broken images, no awkward long-title wrapping, and no private/admin leakage.

deferred: Do not redesign category-highlight card image sourcing in this pass.

deferred: Do not import, scrape, or bulk-fill new poster art in this pass. The era browser may only use visual packages already verified in the plugin/media data.

deferred: Do not add new data, alter winners, import images, mutate person/company/title rows, or change the Oscars route base.

deferred: Do not solve broader ceremony/title/person page dynamism here; this pass is only the final current category-route coverage pass.

## Working Notes

Current inventory from local bundled data and template profile map:

- All categories in data: 66
- Existing premium profiles before this pass: 62
- Remaining generic categories before this pass: 4

Live probe on 2026-06-26 confirmed the four routes return `200` and have no sampled Control Desk/wp-admin asset leakage, but their wrapper still includes `aat-generic-category-dossier`.

Recommended implementation shape:

- One small plugin version bump after code edits.
- Primary files: `academy-awards-table.php`, `templates/hub-page.php`, `tests/inner-page-visual-rhythm-contract.php`, `README.md`, `readme.txt`.
- Theme Control Desk expected version/source anchor after plugin commit.
- Evidence under `C:\Users\silve_i21do49\OneDrive\Desktop\New folder\10_VISUAL_EVIDENCE`.
