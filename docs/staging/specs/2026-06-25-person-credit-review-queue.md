# Person Credit Review Queue

contract: Add a private `Academy Awards > Person Portrait Queue` lane for unresolved Oscar person credits produced by `wp aat profile-images person-credit-audit`.

storage: Create plugin-owned table `wp_aat_person_credit_reviews` through activation and `maybe_upgrade_schema`.

identity: One review row maps to one visible source credit by deterministic `review_key = source_award_id:label_index`.

fields: Store `review_key`, `source_award_id`, `label_index`, `category_slug`, `credit_label`, `review_state`, `proposed_person_id`, `correction_note`, `reviewer_user_id`, `reviewed_at`, and `updated_at`.

states: `Needs Review`, `Candidate Found`, `Ready To Correct`, `Source Gap`, `Resolved`, `Ignore / Accept`.

admin surface: Add a private person-credit review section to `templates/person-portrait-import-admin.php` with filters for category, review state, limit, and offset. Each row shows source award ID, ceremony, film, category, visible credit, source nominee IDs, current review state, proposed person ID, private note, and a one-row nonce-protected save form.

invariant: Saving a person-credit review record never mutates Oscar result rows, nominee projections, attachments, post meta, public routes, or media.

invariant: Public HTML never exposes person-credit review states, notes, reviewer IDs, source nominee IDs, private CSV paths, or admin-only queue markers.

test: Local PHP lint and contract tests prove current plugin version, schema creation, admin-only save helpers, review-state labels, review-key merging, docs, and no mutation calls inside the review queue builder/saver beyond the plugin-owned review table.

deferred: Actual Oscar source-row correction, nominee projection rebuild, bulk actions, external API lookup, and automatic IMDb ID repair.

This release is a deferred correction contract: the queue can hold Dalton's private judgment and proposed person IDs, but it cannot apply the correction to Oscar source rows.
