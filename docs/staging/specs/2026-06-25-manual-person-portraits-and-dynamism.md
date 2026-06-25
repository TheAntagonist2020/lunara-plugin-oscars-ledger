# Manual Person Portraits And Dynamism

contract: `Academy Awards > Person Portrait Queue` becomes a manual portrait control surface for Dalton-supplied images, not an automated image sourcing tool.

invariant: The plugin must not use Cinemagoer, IMDb scraping, OMDb, TMDb, or any other external source to hunt for person portraits in this pass.

invariant: Public person pages use only verified local WordPress media attachments for portrait state when a portrait is present.

contract: Admin users can attach an existing Media Library image to an IMDb person ID and mark it verified.

contract: Admin users can mark a person row as `Needs Better Image`, `Hold`, or `Verified Local`.

data: Verified portrait attachments use existing plugin-owned metadata where possible:
- `_aat_person_imdb_id`
- `_aat_person_portrait_source`
- `_aat_person_portrait_verified`

data: Manual Media Library portraits use `_aat_person_portrait_source = manual-media-library`; approved batch-imported portraits use `_aat_person_portrait_source = manual-batch-upload`.

failure: If the selected attachment ID is missing, not an image, or the current user lacks permission, the save action fails without changing the person state.

failure: If no verified local portrait exists, public person pages keep the honest no-portrait/fallback presentation.

public surface: No public URL changes.

public surface: No private source notes, attachment metadata keys, admin slugs, or workflow state leaks into public HTML.

visual: Person pages should become more dynamic around the portrait chamber by improving profile rhythm, related nominee context, Oscar route links, and visual density without adding clutter or fake imagery.

visual: The image chamber must respect high-quality portrait rendering, stable aspect ratio, no blur-stretch, no empty broken boxes, and mobile-safe spacing.

test: A source contract test proves there is no Cinemagoer reference, no external portrait import action, manual attachment metadata is written, invalid attachment IDs are rejected, and public rendering still resolves verified local portraits.

deferred: Bulk replacement of already-imported portraits.

deferred: External image search.

deferred: Face matching or image recognition.

deferred: Full redesign of all Oscars person/title/category routes.

## Working notes

Implementation should build on the existing Person Portrait Queue instead of creating a second admin page.

The current queue can keep TMDb diagnostic labels only if they are not used as an import source. If that creates mixed messaging, remove the TMDb refresh/import controls entirely in this pass.
