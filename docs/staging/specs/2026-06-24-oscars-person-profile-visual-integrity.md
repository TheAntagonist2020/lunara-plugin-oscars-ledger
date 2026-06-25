# Oscars Person Profile Visual Integrity

## Decisions

contract: Person entity pages must expose a truthful visual state: verified local portrait, TMDb portrait, or no portrait.

contract: Public person pages must not imply a portrait is verified when the source is a fallback, inferred image, or text-only state.

contract: Contextual title or backdrop imagery may support surrounding route atmosphere, but must never occupy the person portrait chamber or be labeled as a person visual source.

contract: Batch nominee portrait cleanup starts as a dry-run audit using Dalton's nominee CSV roster before any Media Library import or public visual mutation exists.

contract: Existing title, category, ceremony, and person URLs remain unchanged.

contract: The existing Poster Library person audit remains the private operating surface for portrait integrity unless a separate admin surface becomes necessary later.

invariant: No fake person-photo chamber is introduced. If no trustworthy portrait exists, the profile renders an intentional text-led/ledger-led visual state.

invariant: Local Media Library matches beat external API portraits. TMDb portraits remain useful but must be identifiable by source in code and admin audit.

invariant: Profile pages stay visually dynamic through command bands, timeline rhythm, filmography cards, and related-review modules, not by showing unverified headshots or title-character art as if it were a person image.

test: A focused contract proves person visual packages include source/state metadata and the entity template uses that state for classing/rendering.

test: A batch contract proves the nominee portrait helper is dry-run, batchable, CSV-aware, and does not mutate Media Library or post metadata.

test: Existing inner-route, source-validation, public-query-path, SQL-performance, related-review, and ceremony-writeup contracts remain green.

deferred: Bulk importing person portraits after the dry-run audit produces trustworthy candidates.

deferred: A full person portrait approval workflow with one-by-one accept/reject actions.

deferred: Replacing TMDb/person API matching logic wholesale.

## Working Notes

- Initial regression target includes `/oscars/name/nm0946705/`, where title-context art previously rendered Peanuts imagery inside the person visual chamber.
- The first implementation should be metadata and rendering honesty, not visual-data mutation.
- Dalton's `E:\nominees and their ids - Sheet1.csv` can be used as the batch roster source for all nominees and their IMDb person IDs.
- The later admin improvement can add filterable states: local verified, external portrait candidate, no portrait, ambiguous local match.
