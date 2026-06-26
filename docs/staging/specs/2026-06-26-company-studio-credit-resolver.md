# Company And Studio Credit Resolver

contract: Add a private, plugin-owned workflow for Oscar source rows whose visible Sound Mixing credits are companies, studios, studio departments, or production sound departments rather than individual people.

source state: The person-safe Sound Mixing batches reached the point where the remaining actionable rows are no longer trustworthy `nm...` person-credit rows. The sampled rows now include labels such as Seven Arts, Columbia, Shepperton, 20th Century-Fox, Metro-Goldwyn-Mayer, Universal City, and studio sound department labels.

problem: The current full-row resolver is deliberately person-only. It validates every proposed slot as an IMDb name ID and then rebuilds person-aware reporting. That is correct for individual nominees, but it must not be used for `co...` companies or unmodeled department labels. Forcing studio credits through the person resolver would create false person pages and corrupt the Oscars Ledger identity layer.

goal: Preserve source truth while giving Dalton a guarded review lane for non-person historical Sound Mixing credits. The lane should let the audit say "this is a company/studio/department credit, here is the reviewed decision, and here is whether it can safely become source `nominee_ids`."

input: One source award row in `wp_academy_awards`.

identity: A company/studio review record maps to one `source_award_id`, not to one person review key.

storage: Use a new plugin-owned table such as `wp_aat_company_credit_row_reviews`, created through activation and `maybe_upgrade_schema`, to keep the company/studio lane separate from `wp_aat_person_credit_row_reviews`.

stored fields:

- `source_award_id`
- `category_slug`
- `credit_labels`
- `review_state`
- `entity_kind`
- `proposed_nominee_ids`
- `display_label_override`
- `correction_note`
- `reviewer_user_id`
- `reviewed_at`
- `updated_at`

states: `Needs Review`, `Ready To Apply`, `Department Label Only`, `Source Gap`, `Applied`, `Ignore / Accept`.

entity kinds:

- `company`: every proposed ID slot must be a valid IMDb company ID such as `co0000000`.
- `department`: the row remains a text-led credit unless a later approved pass adds a stable department entity model.
- `mixed`: the row has both company-like and department-like labels and requires manual handling.
- `source_gap`: the row is known to need better evidence before source mutation.
- `person`: the row belongs back in the person-credit lane, not the company/studio lane.
- `slot_mismatch`: the visible label count and current ID count do not preserve the renderer's slot-pairing contract.

admin surface: Preserve the existing menu slug and add a private `Company / Studio Credits` section inside the current Oscar credit administration surface. The first shipped pass includes a read-only classifier, review storage, filters, and one-row review editing. Source mutation remains disabled in T3; preview and apply are separate follow-up gates.

preview contract: The preview must re-fetch the source award row, tokenize visible labels, show current `nominee_ids`, show proposed IDs, show the entity kind, show route-backed company links for each proposed `co...` slot, and show the exact final `nominee_ids` string before any apply action is available.

apply gate: The apply action requires `manage_options`, nonce verification, exact typed confirmation of `source_award_id`, and explicit checkbox confirmation.

apply validation: Before writing, re-fetch the source award row, re-tokenize source labels, re-tokenize proposed IDs, and require:

- source row still exists.
- category still matches the staged category.
- current source labels still match the reviewed labels.
- proposed ID count exactly equals visible label count when entity kind is `company`.
- every company slot is blank or a valid route-backed `co...` ID.
- `Ready To Apply` has no blank company slots.
- department-only rows cannot write fake IDs.
- one visible label with multiple proposed `co...` IDs is blocked as `mixed` or `source_gap` unless the reviewed display labels are split to the same slot count as the proposed IDs.
- at least one proposed ID changes the row.
- no `nm...` person ID is accepted in this lane.

mutation: A successful company apply updates exactly one source award row's `nominee_ids`, marks the row review `Applied`, rebuilds reporting tables, and clears runtime award caches for the proposed company IDs.

public behavior: Company IDs render as linked company/entity chips through the verified `/oscars/company/{co...}/` route. Department rows remain credible text credits and do not link to fake company/person pages.

invariant: The resolver never creates person pages, never writes `nm...`, never updates attachment metadata, never imports images, never changes review or Journal posts, and never mutates unrelated Oscar result rows.

invariant: Department labels are preserved as historical text until a separate department-entity model exists.

invariant: Company source mutation must preserve the renderer's slot-pairing contract: label slot `n` links to ID slot `n`. The resolver must never write more IDs than reviewed labels.

invariant: Public HTML never exposes company/studio review states, proposed IDs, reviewer IDs, private notes, source CSV paths, admin-only queue markers, or source metadata.

test: Contract tests prove schema creation, allowed states, entity-kind validation, preview-only validation, rejection of `nm...` IDs, rejection of fake department IDs, exact confirmation, single-row mutation boundaries, reporting rebuild invocation, and public-marker non-leakage.

test: Live verification uses a read-only audit sample first, then one dry-run reviewed company row. One applied company row is allowed only after public route behavior is verified.

public smoke: `/`, `/reviews/`, `/oscars/`, `/oscars/category/sound-mixing/`, representative `/oscars/company/{co...}/` routes, and representative title/person routes must return `200` with no admin leakage.

deferred: Bulk apply, automatic company lookup, department entity routes, image imports, non-Sound-Mixing rollout, public visual redesign, and any mutation that cannot be represented as an ordered `nominee_ids` string.

## Working Notes

- The existing person full-row resolver stays `nm...` only.
- The next implementation begins read-only: produce an audit classifier for remaining Sound Mixing rows and prove which rows are person, company, department, mixed, or source-gap.
- Company and department work does not resume in bulk until the classifier and preview validator give us a trustworthy count and sample list.
- Live route proof already confirms `/oscars/company/co0050868/`, `/oscars/company/co0028775/`, and `/oscars/company/co0007143/` return `200` with company-profile titles and no sampled private marker leakage.
- `templates/hub-page.php` pairs pipe labels and pipe IDs by numeric slot, so labels and proposed IDs must have the same count before any company row writes source `nominee_ids`.
