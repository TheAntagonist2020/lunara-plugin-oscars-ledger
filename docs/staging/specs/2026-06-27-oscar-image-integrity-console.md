# Oscar Image Integrity Console

milestone: M2 (see docs/ROADMAP.md)

## Contract

- Add a private Oscars image-integrity console that lets admins review poster and portrait mapping health from one place before those visuals feed category, ceremony, title, person, carousel, or dossier surfaces.
- The console must reuse existing plugin-owned poster and portrait review metadata wherever possible instead of creating a new source of truth.
- Public rendering must remain strict: only already mapped or explicitly verified visuals may render as image-backed chambers.
- The first pass may add review bucket language and admin filters, but it must not import media, delete media, call external APIs, mutate Oscar result rows, rewrite URLs, or auto-adopt images.
- Public URLs, schema for Oscar result/title/person data, rewrite rules, and existing admin queues must remain unchanged.
- Public markup must not expose private notes, source hashes, local paths, admin statuses, importer state, API keys, or review metadata.

## States

- `needs_review`: candidate needs human review before public use.
- `ready`: candidate appears correctly mapped and may feed verified public modules when the existing renderer already supports it.
- `missing`: no usable visual is available or source lookup failed.
- `wrong_match`: visual appears to show the wrong title/person or an unsafe label match.
- `accepted`: admin accepts the current mapping or existing media relationship.
- `resolved`: previously flagged issue has been corrected or intentionally closed.

These are console buckets. Existing stored state keys may continue to differ internally as long as the public console labels and filters normalize them clearly.

## Admin Surface

- Add an admin-only `Academy Awards > Image Integrity` surface, slug `academy-awards-image-integrity`.
- The surface requires `manage_options`, nonce-protected saves, and inclusion in the admin asset allowlist.
- The surface must show poster and portrait bucket counts, filtered row lists, current note/state previews, and direct paths into existing poster and person portrait queues.
- The default view should prioritize `Needs Review`, `Missing`, and `Wrong Match` rows because those block public credibility.
- Empty states should be editorially clear and operationally useful, not broken-looking.

## Public Surface

- No new public route, shortcode, REST endpoint, or public query parameter.
- No public image chamber should render because a candidate merely exists in a review queue.
- Existing title/person/category/ceremony visual packages may continue to render only from verified attachment mappings and existing renderer guards.
- If a visual cannot be trusted, the public surface must fall back to text-led rhythm rather than fake or guessed imagery.

## Acceptance

- A contract test fails before implementation and passes after the console slug, asset allowlist, state buckets, and public-leakage guards exist.
- `academy-awards-table.php` reports version `2.7.87`.
- `Academy Awards > Image Integrity` renders privately and links to the existing Poster Library and Person Portrait Queue.
- Poster and portrait rows can be filtered by normalized bucket without mutating Oscar result data.
- PHP lint passes for changed PHP files.
- Public smoke routes continue to return `200` and sampled public HTML contains no image-integrity private metadata.

