# Oscars Person Profile Visual Integrity

## Decisions

contract: Person entity pages must expose a truthful visual state: verified local portrait, TMDb portrait, contextual film/title visual, or no portrait.

contract: Public person pages must not imply a portrait is verified when the source is a fallback, inferred image, or text-only state.

contract: Existing title, category, ceremony, and person URLs remain unchanged.

contract: The existing Poster Library person audit remains the private operating surface for portrait integrity unless a separate admin surface becomes necessary later.

invariant: No fake person-photo chamber is introduced. If no trustworthy portrait exists, the profile renders an intentional text-led/ledger-led visual state.

invariant: Local Media Library matches beat external API portraits. TMDb portraits remain useful but must be identifiable by source in code and admin audit.

invariant: Profile pages stay visually dynamic through command bands, timeline rhythm, filmography cards, and related-review modules, not by showing unverified headshots.

test: A focused contract proves person visual packages include source/state metadata and the entity template uses that state for classing/rendering.

test: Existing inner-route, source-validation, public-query-path, SQL-performance, related-review, and ceremony-writeup contracts remain green.

deferred: Bulk importing person portraits.

deferred: A full person portrait approval workflow with one-by-one accept/reject actions.

deferred: Replacing TMDb/person API matching logic wholesale.

## Working Notes

- Initial proof target should use `/oscars/name/nm0424163/` or another Sound Mixing person link sampled from the deployed page.
- The first implementation should be metadata and rendering honesty, not visual-data mutation.
- The later admin improvement can add filterable states: local verified, TMDb fallback, no portrait, ambiguous local match.
