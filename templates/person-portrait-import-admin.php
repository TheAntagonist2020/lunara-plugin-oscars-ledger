<?php
if (!defined('ABSPATH')) {
    exit;
}

$message = isset($message) ? (string) $message : '';
$message_type = isset($message_type) ? (string) $message_type : 'success';
$queue_rows = isset($queue_rows) && is_array($queue_rows) ? $queue_rows : array();
$queue_summary = isset($queue_summary) && is_array($queue_summary) ? $queue_summary : array();
$adoption_rows = isset($adoption_rows) && is_array($adoption_rows) ? $adoption_rows : array();
$adoption_summary = isset($adoption_summary) && is_array($adoption_summary) ? $adoption_summary : array();
$person_credit_rows = isset($person_credit_rows) && is_array($person_credit_rows) ? $person_credit_rows : array();
$person_credit_summary = isset($person_credit_summary) && is_array($person_credit_summary) ? $person_credit_summary : array();
$person_credit_review_states = isset($person_credit_review_states) && is_array($person_credit_review_states) ? $person_credit_review_states : array();
$person_credit_row_review_states = isset($person_credit_row_review_states) && is_array($person_credit_row_review_states) ? $person_credit_row_review_states : array();
$person_credit_review_filter_labels = isset($person_credit_review_filter_labels) && is_array($person_credit_review_filter_labels) ? $person_credit_review_filter_labels : array();
$company_credit_rows = isset($company_credit_rows) && is_array($company_credit_rows) ? $company_credit_rows : array();
$company_credit_summary = isset($company_credit_summary) && is_array($company_credit_summary) ? $company_credit_summary : array();
$company_credit_preview_result = isset($company_credit_preview_result) && is_array($company_credit_preview_result) ? $company_credit_preview_result : array();
$company_credit_review_states = isset($company_credit_review_states) && is_array($company_credit_review_states) ? $company_credit_review_states : array();
$company_credit_review_filter_labels = isset($company_credit_review_filter_labels) && is_array($company_credit_review_filter_labels) ? $company_credit_review_filter_labels : array();
$company_credit_entity_kinds = isset($company_credit_entity_kinds) && is_array($company_credit_entity_kinds) ? $company_credit_entity_kinds : array();
$company_credit_entity_filter_labels = isset($company_credit_entity_filter_labels) && is_array($company_credit_entity_filter_labels) ? $company_credit_entity_filter_labels : array();
$selected_state = isset($selected_state) ? (string) $selected_state : 'candidate_external';
$selected_limit = isset($selected_limit) ? intval($selected_limit) : 50;
$selected_offset = isset($selected_offset) ? intval($selected_offset) : 0;
$adoption_view = isset($adoption_view) ? (string) $adoption_view : 'all';
$adoption_limit = isset($adoption_limit) ? intval($adoption_limit) : 24;
$adoption_offset = isset($adoption_offset) ? intval($adoption_offset) : 0;
$person_credit_category = isset($person_credit_category) ? (string) $person_credit_category : 'sound-mixing';
$person_credit_review_state = isset($person_credit_review_state) ? (string) $person_credit_review_state : 'all';
$person_credit_limit = isset($person_credit_limit) ? intval($person_credit_limit) : 25;
$person_credit_offset = isset($person_credit_offset) ? intval($person_credit_offset) : 0;
$company_credit_category = isset($company_credit_category) ? (string) $company_credit_category : 'sound-mixing';
$company_credit_review_state = isset($company_credit_review_state) ? (string) $company_credit_review_state : 'all';
$company_credit_entity_kind = isset($company_credit_entity_kind) ? (string) $company_credit_entity_kind : 'all';
$company_credit_limit = isset($company_credit_limit) ? intval($company_credit_limit) : 25;
$company_credit_offset = isset($company_credit_offset) ? intval($company_credit_offset) : 0;
$ids_raw = isset($ids_raw) ? (string) $ids_raw : '';
$refresh_tmdb = !empty($refresh_tmdb);
$tmdb_key_configured = !empty($tmdb_key_configured);
?>

<div class="wrap aat-admin-wrap aat-person-portrait-import">
    <div class="aat-admin-header">
        <span class="dashicons dashicons-format-image"></span>
        <div>
            <h1><?php esc_html_e('Person Portrait Import Queue', 'academy-awards-table'); ?></h1>
            <p><?php esc_html_e('Import one verified TMDb person profile image at a time. Contextual title art stays blocked.', 'academy-awards-table'); ?></p>
        </div>
    </div>

    <?php if ($message !== '') : ?>
        <div class="notice notice-<?php echo esc_attr($message_type === 'error' ? 'error' : 'success'); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$tmdb_key_configured) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('TMDb is not configured. Add the key in Poster Library before refreshing candidates or importing portraits.', 'academy-awards-table'); ?></p>
        </div>
    <?php endif; ?>

    <section class="aat-admin-section">
        <h2><?php esc_html_e('Manual batch upload', 'academy-awards-table'); ?></h2>
        <p><?php esc_html_e('Before importing another portrait package, reconcile the existing PEOPLE media folder so already-uploaded person images can be reviewed without creating duplicates.', 'academy-awards-table'); ?></p>
        <div class="aat-admin-note">
            <code>wp aat profile-images existing-media-audit --folder=PEOPLE --sample=25 --output-csv=/private/people-media-reconciliation.csv</code>
        </div>
        <p><?php esc_html_e('Existing media audit is read-only. It reports route-backed portraits, reusable filename/metadata matches, likely name matches, duplicates, and manual-review rows; adoption metadata comes later after review.', 'academy-awards-table'); ?></p>
        <p><?php esc_html_e('For large Dalton-supplied portrait batches, use the private WP-CLI importer after uploading the reviewed image package and CSV manifests outside the public web root.', 'academy-awards-table'); ?></p>
        <div class="aat-admin-note">
            <code>wp aat profile-images dry-run --source=/private/oscars-profile-images --results-csv=/private/tmdb_profile_results.csv --missing-csv=/private/profiles_missing.csv</code>
        </div>
        <div class="aat-admin-note">
            <code>wp aat profile-images import --source=/private/oscars-profile-images --results-csv=/private/tmdb_profile_results.csv --missing-csv=/private/profiles_missing.csv --limit=100 --offset=0 --batch=manual-batch-upload</code>
        </div>
        <div class="aat-admin-note">
            <code>wp aat profile-images coverage --results-csv=/private/tmdb_profile_results.csv --batch=manual-batch-upload --sample=25</code>
        </div>
        <p><?php esc_html_e('The batch path never searches for images. It only imports approved JPEG files whose IMDb IDs match the verified CSV, then marks them as manual-batch-upload portraits.', 'academy-awards-table'); ?></p>
        <p><?php esc_html_e('Use coverage mode after imports to separate route-backed portraits, approved portrait IDs absent from source people, and imported-media/no-route cleanup rows. It reads tmdb_profile_results.csv Status=OK rows and does not import media.', 'academy-awards-table'); ?></p>
    </section>

    <section class="aat-admin-section aat-person-credit-review">
        <h2><?php esc_html_e('Person credit review', 'academy-awards-table'); ?></h2>
        <p><?php esc_html_e('Review unresolved visible Oscar person credits one row at a time before any source-row correction happens. This saves private judgment metadata only; it does not edit award results, nominees, media, or public routes.', 'academy-awards-table'); ?></p>
        <div class="aat-admin-note">
            <code>wp aat profile-images person-credit-audit --category=sound-mixing --state=unresolved --sample=50 --output-csv=/private/person-credit-reconciliation.csv</code>
        </div>

        <?php if (!empty($person_credit_summary['error'])) : ?>
            <div class="notice notice-error inline">
                <p><?php echo esc_html((string) $person_credit_summary['error']); ?></p>
            </div>
        <?php else : ?>
            <div class="aat-person-portrait-summary">
                <span><?php echo esc_html(sprintf(__('Source rows: %d', 'academy-awards-table'), intval($person_credit_summary['source_rows'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Visible credits: %d', 'academy-awards-table'), intval($person_credit_summary['credit_labels'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Unresolved: %d', 'academy-awards-table'), intval($person_credit_summary['person_credit_unresolved'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Missing source IDs: %d', 'academy-awards-table'), intval($person_credit_summary['missing_source_nominee_ids'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Label/ID mismatches: %d', 'academy-awards-table'), intval($person_credit_summary['label_id_mismatch'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Saved reviews in window: %d', 'academy-awards-table'), intval($person_credit_summary['reviewed_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Full-row reviews in window: %d', 'academy-awards-table'), intval($person_credit_summary['row_reviewed_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Showing: %d', 'academy-awards-table'), intval($person_credit_summary['returned'] ?? count($person_credit_rows)))); ?></span>
            </div>
        <?php endif; ?>

        <form method="get" class="aat-person-portrait-filters aat-person-credit-review-controls">
            <input type="hidden" name="page" value="academy-awards-person-portraits" />
            <input type="hidden" name="state" value="<?php echo esc_attr($selected_state); ?>" />
            <input type="hidden" name="limit" value="<?php echo esc_attr((string) $selected_limit); ?>" />
            <input type="hidden" name="offset" value="<?php echo esc_attr((string) $selected_offset); ?>" />
            <label>
                <span><?php esc_html_e('Category slug', 'academy-awards-table'); ?></span>
                <input type="text" name="person_credit_category" value="<?php echo esc_attr($person_credit_category); ?>" />
            </label>
            <label>
                <span><?php esc_html_e('Review state', 'academy-awards-table'); ?></span>
                <select name="person_credit_review_state">
                    <?php foreach ($person_credit_review_filter_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($person_credit_review_state, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Limit', 'academy-awards-table'); ?></span>
                <input type="number" name="person_credit_limit" value="<?php echo esc_attr((string) $person_credit_limit); ?>" min="1" max="100" />
            </label>
            <label>
                <span><?php esc_html_e('Offset', 'academy-awards-table'); ?></span>
                <input type="number" name="person_credit_offset" value="<?php echo esc_attr((string) $person_credit_offset); ?>" min="0" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Refresh person-credit review', 'academy-awards-table'); ?></button>
        </form>

        <?php if (empty($person_credit_rows)) : ?>
            <p class="aat-person-portrait-muted"><?php esc_html_e('No unresolved person credits matched the current review window.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-person-portrait-adoption-grid aat-person-credit-review-grid">
                <?php foreach ($person_credit_rows as $row) : ?>
                    <?php
                    $review_key = (string) ($row['review_key'] ?? '');
                    $review_state = (string) ($row['review_state'] ?? 'needs_review');
                    $proposed_person_id = (string) ($row['proposed_person_id'] ?? '');
                    $correction_note = (string) ($row['correction_note'] ?? '');
                    $source_nominee_ids = (string) ($row['source_nominee_ids'] ?? '');
                    $source_correction_preview = isset($row['source_correction_preview']) && is_array($row['source_correction_preview']) ? $row['source_correction_preview'] : array();
                    $source_correction_ready = !empty($source_correction_preview['ready']);
                    $full_row_review = isset($row['full_row_review']) && is_array($row['full_row_review']) ? $row['full_row_review'] : array();
                    $full_row_preview = isset($row['full_row_preview']) && is_array($row['full_row_preview']) ? $row['full_row_preview'] : array();
                    $full_row_ready = !empty($full_row_preview['ready']);
                    $full_row_labels = isset($full_row_preview['labels']) && is_array($full_row_preview['labels']) ? $full_row_preview['labels'] : array();
                    $full_row_current_ids = array_values(array_filter(array_map('trim', explode('|', (string) ($full_row_preview['current_nominee_ids'] ?? ''))), 'strlen'));
                    $full_row_show = count($full_row_labels) > 1 || !empty($full_row_review['is_reviewed']);
                    ?>
                    <article class="aat-person-portrait-adoption-card aat-person-credit-review-card">
                        <div class="aat-person-portrait-adoption-body">
                            <span class="aat-person-portrait-state aat-person-portrait-state-needs_attention">
                                <?php echo esc_html((string) ($row['review_state_label'] ?? __('Needs Review', 'academy-awards-table'))); ?>
                            </span>
                            <h3><?php echo esc_html((string) ($row['credit_label'] ?? '')); ?></h3>
                            <p>
                                <code><?php echo esc_html($review_key); ?></code>
                                <span><?php echo esc_html(sprintf(__('Award row #%d', 'academy-awards-table'), intval($row['source_award_id'] ?? 0))); ?></span>
                            </p>
                            <dl>
                                <div>
                                    <dt><?php esc_html_e('Ceremony', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['ceremony'] ?? '')); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('Film', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['film'] ?? '')); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('Category', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['category'] ?? '')); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('source_nominee_ids', 'academy-awards-table'); ?></dt>
                                    <dd><code><?php echo esc_html($source_nominee_ids); ?></code></dd>
                                </div>
                            </dl>
                            <form method="post" class="aat-person-credit-review-form">
                                <?php wp_nonce_field('aat_person_credit_review', 'aat_person_credit_review_nonce'); ?>
                                <input type="hidden" name="aat_person_credit_review_key" value="<?php echo esc_attr($review_key); ?>" />
                                <input type="hidden" name="aat_person_credit_category_slug" value="<?php echo esc_attr((string) ($row['category_slug'] ?? '')); ?>" />
                                <input type="hidden" name="aat_person_credit_label" value="<?php echo esc_attr((string) ($row['credit_label'] ?? '')); ?>" />
                                <label>
                                    <span><?php esc_html_e('Review state', 'academy-awards-table'); ?></span>
                                    <select name="aat_person_credit_review_state">
                                        <?php foreach ($person_credit_review_states as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($review_state, $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?php esc_html_e('Proposed IMDb person ID', 'academy-awards-table'); ?></span>
                                    <input type="text" name="aat_person_credit_proposed_person_id" value="<?php echo esc_attr($proposed_person_id); ?>" placeholder="nm0000000" autocomplete="off" />
                                </label>
                                <label>
                                    <span><?php esc_html_e('Private note', 'academy-awards-table'); ?></span>
                                    <textarea name="aat_person_credit_review_note" rows="3"><?php echo esc_textarea($correction_note); ?></textarea>
                                </label>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Save person-credit review', 'academy-awards-table'); ?></button>
                            </form>
                            <?php if (!empty($source_correction_preview)) : ?>
                                <div class="aat-person-credit-source-correction <?php echo $source_correction_ready ? 'is-ready' : 'is-blocked'; ?>">
                                    <strong><?php esc_html_e('One-row source correction', 'academy-awards-table'); ?></strong>
                                    <p><?php echo esc_html((string) ($source_correction_preview['message'] ?? '')); ?></p>
                                    <?php if ($source_correction_ready) : ?>
                                        <dl>
                                            <div>
                                                <dt><?php esc_html_e('Current nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($source_correction_preview['current_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                            <div>
                                                <dt><?php esc_html_e('New nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($source_correction_preview['new_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                        </dl>
                                        <form method="post" class="aat-person-credit-source-correction-form">
                                            <?php wp_nonce_field('aat_person_credit_source_correction', 'aat_person_credit_source_correction_nonce'); ?>
                                            <input type="hidden" name="aat_person_credit_source_review_key" value="<?php echo esc_attr($review_key); ?>" />
                                            <input type="hidden" name="aat_person_credit_source_proposed_person_id" value="<?php echo esc_attr((string) ($source_correction_preview['proposed_person_id'] ?? '')); ?>" />
                                            <label class="aat-person-credit-source-correction-confirm">
                                                <input type="checkbox" name="aat_person_credit_source_confirm" value="1" />
                                                <span><?php esc_html_e('I confirm this updates only this award row source nominee_ids.', 'academy-awards-table'); ?></span>
                                            </label>
                                            <label>
                                                <span><?php esc_html_e('Type exact IMDb person ID', 'academy-awards-table'); ?></span>
                                                <input type="text" name="aat_person_credit_source_confirm_person_id" value="" placeholder="<?php echo esc_attr((string) ($source_correction_preview['proposed_person_id'] ?? 'nm0000000')); ?>" autocomplete="off" />
                                            </label>
                                            <button type="submit" class="button button-secondary"><?php esc_html_e('Apply one-row source correction', 'academy-awards-table'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($full_row_show) : ?>
                                <div class="aat-person-credit-full-row-resolver <?php echo $full_row_ready ? 'is-ready' : 'is-blocked'; ?>">
                                    <strong><?php esc_html_e('Full-row resolver', 'academy-awards-table'); ?></strong>
                                    <p><?php echo esc_html((string) ($full_row_preview['message'] ?? __('Save a full-row review before applying any row-level correction.', 'academy-awards-table'))); ?></p>
                                    <?php if (!empty($full_row_labels)) : ?>
                                        <ol class="aat-person-credit-full-row-slots">
                                            <?php foreach ($full_row_labels as $slot_index => $slot_label) : ?>
                                                <li>
                                                    <span><?php echo esc_html((string) $slot_label); ?></span>
                                                    <code><?php echo esc_html((string) ($full_row_current_ids[$slot_index] ?? 'blank')); ?></code>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php endif; ?>
                                    <form method="post" class="aat-person-credit-full-row-form">
                                        <?php wp_nonce_field('aat_person_credit_row_review', 'aat_person_credit_row_review_nonce'); ?>
                                        <input type="hidden" name="aat_person_credit_row_source_award_id" value="<?php echo esc_attr((string) ($row['source_award_id'] ?? 0)); ?>" />
                                        <label>
                                            <span><?php esc_html_e('Full-row state', 'academy-awards-table'); ?></span>
                                            <select name="aat_person_credit_row_review_state">
                                                <?php foreach ($person_credit_row_review_states as $value => $label) : ?>
                                                    <option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($full_row_review['review_state'] ?? 'needs_review'), $value); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span><?php esc_html_e('Ordered nominee_ids', 'academy-awards-table'); ?></span>
                                            <textarea name="aat_person_credit_row_proposed_nominee_ids" rows="<?php echo esc_attr((string) max(3, min(8, count($full_row_labels)))); ?>" placeholder="nm0000000|nm0000001"><?php echo esc_textarea((string) ($full_row_review['proposed_nominee_ids'] ?? '')); ?></textarea>
                                        </label>
                                        <label>
                                            <span><?php esc_html_e('Private full-row note', 'academy-awards-table'); ?></span>
                                            <textarea name="aat_person_credit_row_review_note" rows="3"><?php echo esc_textarea((string) ($full_row_review['correction_note'] ?? '')); ?></textarea>
                                        </label>
                                        <button type="submit" class="button button-primary"><?php esc_html_e('Save full-row review', 'academy-awards-table'); ?></button>
                                    </form>
                                    <?php if ($full_row_ready) : ?>
                                        <dl>
                                            <div>
                                                <dt><?php esc_html_e('Current nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($full_row_preview['current_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                            <div>
                                                <dt><?php esc_html_e('New nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($full_row_preview['new_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                        </dl>
                                        <form method="post" class="aat-person-credit-full-row-apply-form">
                                            <?php wp_nonce_field('aat_person_credit_row_apply', 'aat_person_credit_row_apply_nonce'); ?>
                                            <input type="hidden" name="aat_person_credit_row_apply_source_award_id" value="<?php echo esc_attr((string) ($row['source_award_id'] ?? 0)); ?>" />
                                            <label class="aat-person-credit-source-correction-confirm">
                                                <input type="checkbox" name="aat_person_credit_row_apply_confirm" value="1" />
                                                <span><?php esc_html_e('I confirm this updates only this award row source nominee_ids.', 'academy-awards-table'); ?></span>
                                            </label>
                                            <label>
                                                <span><?php esc_html_e('Type exact award row number', 'academy-awards-table'); ?></span>
                                                <input type="text" name="aat_person_credit_row_apply_confirm_source_award_id" value="" placeholder="<?php echo esc_attr((string) ($row['source_award_id'] ?? 0)); ?>" autocomplete="off" />
                                            </label>
                                            <button type="submit" class="button button-secondary"><?php esc_html_e('Apply full-row source correction', 'academy-awards-table'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="aat-admin-section aat-company-credit-review">
        <h2><?php esc_html_e('Company / Studio Credits', 'academy-awards-table'); ?></h2>
        <p><?php esc_html_e('Review studio, company, and department-style Oscar credit rows as private annotations before any future source-row repair exists. This queue accepts only IMDb co IDs in proposed slots and does not edit award results, nominees, media, or public routes.', 'academy-awards-table'); ?></p>
        <div class="aat-admin-note">
            <code>wp aat profile-images company-credit-audit --category=sound-mixing --state=all --sample=80 --output-csv=/private/company-credit-reconciliation.csv</code>
        </div>

        <?php if (!empty($company_credit_summary['error'])) : ?>
            <div class="notice notice-error inline">
                <p><?php echo esc_html((string) $company_credit_summary['error']); ?></p>
            </div>
        <?php else : ?>
            <div class="aat-person-portrait-summary aat-company-credit-summary">
                <span><?php echo esc_html(sprintf(__('Source rows: %d', 'academy-awards-table'), intval($company_credit_summary['source_rows'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Candidate rows: %d', 'academy-awards-table'), intval($company_credit_summary['candidate_rows'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Companies: %d', 'academy-awards-table'), intval($company_credit_summary['company'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Departments: %d', 'academy-awards-table'), intval($company_credit_summary['department'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Mixed: %d', 'academy-awards-table'), intval($company_credit_summary['mixed'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Slot mismatches: %d', 'academy-awards-table'), intval($company_credit_summary['slot_mismatch'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Saved reviews in window: %d', 'academy-awards-table'), intval($company_credit_summary['reviewed_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Showing: %d', 'academy-awards-table'), intval($company_credit_summary['returned'] ?? count($company_credit_rows)))); ?></span>
            </div>
        <?php endif; ?>

        <form method="get" class="aat-person-portrait-filters aat-company-credit-review-controls">
            <input type="hidden" name="page" value="academy-awards-person-portraits" />
            <input type="hidden" name="state" value="<?php echo esc_attr($selected_state); ?>" />
            <input type="hidden" name="limit" value="<?php echo esc_attr((string) $selected_limit); ?>" />
            <input type="hidden" name="offset" value="<?php echo esc_attr((string) $selected_offset); ?>" />
            <label>
                <span><?php esc_html_e('Category slug', 'academy-awards-table'); ?></span>
                <input type="text" name="company_credit_category" value="<?php echo esc_attr($company_credit_category); ?>" />
            </label>
            <label>
                <span><?php esc_html_e('Review state', 'academy-awards-table'); ?></span>
                <select name="company_credit_review_state">
                    <?php foreach ($company_credit_review_filter_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($company_credit_review_state, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Entity kind', 'academy-awards-table'); ?></span>
                <select name="company_credit_entity_kind">
                    <?php foreach ($company_credit_entity_filter_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($company_credit_entity_kind, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Limit', 'academy-awards-table'); ?></span>
                <input type="number" name="company_credit_limit" value="<?php echo esc_attr((string) $company_credit_limit); ?>" min="1" max="100" />
            </label>
            <label>
                <span><?php esc_html_e('Offset', 'academy-awards-table'); ?></span>
                <input type="number" name="company_credit_offset" value="<?php echo esc_attr((string) $company_credit_offset); ?>" min="0" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Refresh company/studio review', 'academy-awards-table'); ?></button>
        </form>

        <?php if (empty($company_credit_rows)) : ?>
            <p class="aat-person-portrait-muted"><?php esc_html_e('No company or studio credit rows matched the current review window.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-person-portrait-adoption-grid aat-company-credit-review-grid">
                <?php foreach ($company_credit_rows as $row) : ?>
                    <?php
                    $review = isset($row['review']) && is_array($row['review']) ? $row['review'] : array();
                    $label_list = isset($row['credit_label_list']) && is_array($row['credit_label_list']) ? $row['credit_label_list'] : array();
                    $source_ids = array_values(array_filter(array_map('trim', explode('|', (string) ($row['source_nominee_ids'] ?? ''))), 'strlen'));
                    $stored_entity_kind = (string) ($row['stored_entity_kind'] ?? ($row['entity_kind'] ?? 'source_gap'));
                    $review_state = (string) ($row['review_state'] ?? 'needs_review');
                    $row_state_class = 'aat-company-credit-state-' . sanitize_html_class($review_state);
                    $row_preview = array();
                    if (
                        !empty($company_credit_preview_result) &&
                        intval($company_credit_preview_result['source_award_id'] ?? 0) === intval($row['source_award_id'] ?? 0)
                    ) {
                        $row_preview = $company_credit_preview_result;
                    }
                    ?>
                    <article class="aat-person-portrait-adoption-card aat-company-credit-review-card">
                        <div class="aat-person-portrait-adoption-body">
                            <span class="aat-person-portrait-state <?php echo esc_attr($row_state_class); ?>">
                                <?php echo esc_html((string) ($row['review_state_label'] ?? __('Needs Review', 'academy-awards-table'))); ?>
                            </span>
                            <h3><?php echo esc_html((string) ($row['film'] ?? __('Unknown film', 'academy-awards-table'))); ?></h3>
                            <p>
                                <code><?php echo esc_html(sprintf('#%d', intval($row['source_award_id'] ?? 0))); ?></code>
                                <span><?php echo esc_html((string) ($row['entity_kind_label'] ?? '')); ?></span>
                                <span><?php echo esc_html((string) ($row['category'] ?? '')); ?></span>
                            </p>
                            <dl>
                                <div>
                                    <dt><?php esc_html_e('Ceremony', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['ceremony'] ?? '')); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('Year', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html((string) ($row['year'] ?? '')); ?></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('Current nominee_ids', 'academy-awards-table'); ?></dt>
                                    <dd><code><?php echo esc_html((string) ($row['source_nominee_ids'] ?? '')); ?></code></dd>
                                </div>
                                <div>
                                    <dt><?php esc_html_e('Classifier', 'academy-awards-table'); ?></dt>
                                    <dd><?php echo esc_html(sprintf(__('labels=%1$d ids=%2$d mismatch=%3$d', 'academy-awards-table'), intval($row['label_count'] ?? 0), intval($row['source_id_count'] ?? 0), intval($row['slot_mismatch'] ?? 0))); ?></dd>
                                </div>
                            </dl>
                            <?php if (!empty($label_list)) : ?>
                                <ol class="aat-company-credit-slots">
                                    <?php foreach ($label_list as $slot_index => $slot_label) : ?>
                                        <li>
                                            <span><?php echo esc_html((string) $slot_label); ?></span>
                                            <code><?php echo esc_html((string) ($source_ids[$slot_index] ?? 'blank')); ?></code>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                            <form method="post" class="aat-company-credit-review-form">
                                <?php wp_nonce_field('aat_company_credit_row_review', 'aat_company_credit_row_review_nonce'); ?>
                                <input type="hidden" name="aat_company_credit_row_source_award_id" value="<?php echo esc_attr((string) ($row['source_award_id'] ?? 0)); ?>" />
                                <label>
                                    <span><?php esc_html_e('Review state', 'academy-awards-table'); ?></span>
                                    <select name="aat_company_credit_row_review_state">
                                        <?php foreach ($company_credit_review_states as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($review_state, $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?php esc_html_e('Entity kind', 'academy-awards-table'); ?></span>
                                    <select name="aat_company_credit_row_entity_kind">
                                        <?php foreach ($company_credit_entity_kinds as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($stored_entity_kind, $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span><?php esc_html_e('Proposed ordered co IDs', 'academy-awards-table'); ?></span>
                                    <textarea name="aat_company_credit_row_proposed_nominee_ids" rows="<?php echo esc_attr((string) max(3, min(8, count($label_list)))); ?>" placeholder="co0000000|co0000001"><?php echo esc_textarea((string) ($review['proposed_nominee_ids'] ?? '')); ?></textarea>
                                </label>
                                <label>
                                    <span><?php esc_html_e('Display label override', 'academy-awards-table'); ?></span>
                                    <textarea name="aat_company_credit_row_display_label_override" rows="2"><?php echo esc_textarea((string) ($review['display_label_override'] ?? '')); ?></textarea>
                                </label>
                                <label>
                                    <span><?php esc_html_e('Private company/studio note', 'academy-awards-table'); ?></span>
                                    <textarea name="aat_company_credit_row_review_note" rows="3"><?php echo esc_textarea((string) ($review['correction_note'] ?? '')); ?></textarea>
                                </label>
                                <?php if (!empty($review['updated_at'])) : ?>
                                    <p class="aat-company-credit-reviewed-at"><?php echo esc_html(sprintf(__('Last reviewed: %s', 'academy-awards-table'), (string) $review['updated_at'])); ?></p>
                                <?php endif; ?>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Save company/studio review', 'academy-awards-table'); ?></button>
                            </form>
                            <div class="aat-company-credit-preview-gate">
                                <form method="post" class="aat-company-credit-preview-form">
                                    <?php wp_nonce_field('aat_company_credit_row_preview', 'aat_company_credit_row_preview_nonce'); ?>
                                    <input type="hidden" name="aat_company_credit_row_preview_source_award_id" value="<?php echo esc_attr((string) ($row['source_award_id'] ?? 0)); ?>" />
                                    <label>
                                        <span><?php esc_html_e('Preview confirmation', 'academy-awards-table'); ?></span>
                                        <input type="text" name="aat_company_credit_row_preview_confirm_source_id" value="" placeholder="<?php echo esc_attr((string) ($row['source_award_id'] ?? '')); ?>" />
                                    </label>
                                    <button type="submit" class="button"><?php esc_html_e('Preview validation only', 'academy-awards-table'); ?></button>
                                </form>
                                <?php if (!empty($row_preview)) : ?>
                                    <?php
                                    $preview_ready = !empty($row_preview['ready']);
                                    $preview_state_class = $preview_ready ? 'is-ready' : 'is-blocked';
                                    $slot_previews = isset($row_preview['slot_previews']) && is_array($row_preview['slot_previews']) ? $row_preview['slot_previews'] : array();
                                    ?>
                                    <div class="aat-company-credit-preview <?php echo esc_attr($preview_state_class); ?>">
                                        <span><?php echo esc_html((string) ($row_preview['state'] ?? 'preview')); ?></span>
                                        <p><?php echo esc_html((string) ($row_preview['message'] ?? '')); ?></p>
                                        <dl>
                                            <div>
                                                <dt><?php esc_html_e('Current nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($row_preview['current_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                            <div>
                                                <dt><?php esc_html_e('Preview nominee_ids', 'academy-awards-table'); ?></dt>
                                                <dd><code><?php echo esc_html((string) ($row_preview['new_nominee_ids'] ?? '')); ?></code></dd>
                                            </div>
                                        </dl>
                                        <?php if (!empty($slot_previews)) : ?>
                                            <ol class="aat-company-credit-preview-slots">
                                                <?php foreach ($slot_previews as $slot_preview) : ?>
                                                    <li>
                                                        <span><?php echo esc_html((string) ($slot_preview['label'] ?? '')); ?></span>
                                                        <code><?php echo esc_html((string) ($slot_preview['proposed_id'] ?? '')); ?></code>
                                                        <?php if (!empty($slot_preview['company_url'])) : ?>
                                                            <a href="<?php echo esc_url((string) $slot_preview['company_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($slot_preview['company_label'] ?? $slot_preview['proposed_id'])); ?></a>
                                                        <?php else : ?>
                                                            <span><?php echo esc_html((string) ($slot_preview['company_label'] ?? '')); ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ol>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="aat-admin-section">
        <h2><?php esc_html_e('Existing PEOPLE adoption', 'academy-awards-table'); ?></h2>
        <p><?php esc_html_e('Review already-uploaded PEOPLE folder images and connect one verified attachment to one route-backed Oscars person file. This writes existing-media-adoption metadata only; it does not import, fetch, rename, or move media.', 'academy-awards-table'); ?></p>
        <?php if (!empty($adoption_summary['error'])) : ?>
            <div class="notice notice-error inline">
                <p><?php echo esc_html((string) $adoption_summary['error']); ?></p>
            </div>
        <?php else : ?>
            <div class="aat-person-portrait-summary">
                <span><?php echo esc_html(sprintf(__('PEOPLE attachments: %d', 'academy-awards-table'), intval($adoption_summary['folder_attachments'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Adoption candidates: %d', 'academy-awards-table'), intval($adoption_summary['adoption_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Ready: %d', 'academy-awards-table'), intval($adoption_summary['ready_adoption_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Duplicate review: %d', 'academy-awards-table'), intval($adoption_summary['duplicate_adoption_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Duplicate people: %d', 'academy-awards-table'), intval($adoption_summary['duplicate_person_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Duplicate groups: %d', 'academy-awards-table'), intval($adoption_summary['duplicate_group_review_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Duplicate candidate rows: %d', 'academy-awards-table'), intval($adoption_summary['duplicate_person_id_rows'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Manual review rows: %d', 'academy-awards-table'), intval($adoption_summary['manual_review_total'] ?? 0))); ?></span>
                <span><?php echo esc_html(sprintf(__('Showing: %d', 'academy-awards-table'), intval($adoption_summary['returned'] ?? count($adoption_rows)))); ?></span>
            </div>
        <?php endif; ?>

        <form method="get" class="aat-person-portrait-filters aat-person-portrait-adoption-controls">
            <input type="hidden" name="page" value="academy-awards-person-portraits" />
            <input type="hidden" name="state" value="<?php echo esc_attr($selected_state); ?>" />
            <input type="hidden" name="limit" value="<?php echo esc_attr((string) $selected_limit); ?>" />
            <input type="hidden" name="offset" value="<?php echo esc_attr((string) $selected_offset); ?>" />
            <label>
                <span><?php esc_html_e('Adoption view', 'academy-awards-table'); ?></span>
                <select name="adoption_view">
                    <?php foreach (array(
                        'all' => __('All candidates', 'academy-awards-table'),
                        'ready' => __('Ready only', 'academy-awards-table'),
                        'duplicates' => __('Duplicate review', 'academy-awards-table'),
                        'duplicate_groups' => __('Duplicate groups', 'academy-awards-table'),
                        'manual' => __('Manual review', 'academy-awards-table'),
                    ) as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($adoption_view, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Adoption limit', 'academy-awards-table'); ?></span>
                <input type="number" name="adoption_limit" value="<?php echo esc_attr((string) $adoption_limit); ?>" min="1" max="60" />
            </label>
            <label>
                <span><?php esc_html_e('Adoption offset', 'academy-awards-table'); ?></span>
                <input type="number" name="adoption_offset" value="<?php echo esc_attr((string) $adoption_offset); ?>" min="0" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Refresh adoption lane', 'academy-awards-table'); ?></button>
        </form>

        <?php if (empty($adoption_rows)) : ?>
            <p class="aat-person-portrait-muted"><?php esc_html_e('No existing PEOPLE adoption candidates matched the current window.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-person-portrait-adoption-grid">
                <?php foreach ($adoption_rows as $row) : ?>
                    <?php
                    $attachment_id = intval($row['attachment_id'] ?? 0);
                    $person_id = (string) ($row['person_id'] ?? '');
                    $label = (string) ($row['label'] ?? $person_id);
                    $thumb_url = (string) ($row['thumb_url'] ?? '');
                    $full_url = (string) ($row['full_url'] ?? '');
                    $profile_url = (string) ($row['profile_url'] ?? '');
                    $is_manual = !empty($row['manual_review']);
                    $is_duplicate_group = !empty($row['duplicate_group_review']);
                    $is_duplicate = !empty($row['duplicate_person_id']);
                    $duplicate_group = isset($row['duplicate_group']) && is_array($row['duplicate_group']) ? $row['duplicate_group'] : array();
                    $duplicate_group_candidates = isset($row['duplicate_group_candidates']) && is_array($row['duplicate_group_candidates']) ? $row['duplicate_group_candidates'] : $duplicate_group;
                    $duplicate_count = intval($row['duplicate_count'] ?? count($duplicate_group));
                    ?>
                    <article class="aat-person-portrait-adoption-card <?php echo $is_manual ? 'is-manual' : ($is_duplicate_group ? 'is-duplicate-group' : ($is_duplicate ? 'is-duplicate' : 'is-ready')); ?>">
                        <div class="aat-person-portrait-adoption-media">
                            <?php if ($thumb_url !== '') : ?>
                                <img src="<?php echo esc_url($thumb_url); ?>" alt="" loading="lazy" />
                            <?php else : ?>
                                <span><?php esc_html_e('No image', 'academy-awards-table'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="aat-person-portrait-adoption-body">
                            <span class="aat-person-portrait-state <?php echo ($is_duplicate || $is_manual) ? 'aat-person-portrait-state-needs_attention' : 'aat-person-portrait-state-ready'; ?>">
                                <?php echo esc_html($is_manual ? __('Manual review needed', 'academy-awards-table') : ($is_duplicate_group ? __('Duplicate group review', 'academy-awards-table') : ($is_duplicate ? __('Duplicate candidate', 'academy-awards-table') : __('Ready to adopt', 'academy-awards-table')))); ?>
                            </span>
                            <h3><?php echo esc_html($label); ?></h3>
                            <p>
                                <?php if ($person_id !== '') : ?>
                                    <code><?php echo esc_html($person_id); ?></code>
                                <?php endif; ?>
                                <span><?php echo esc_html(sprintf(__('Attachment #%d', 'academy-awards-table'), $attachment_id)); ?></span>
                            </p>
                            <?php if ($is_duplicate && $duplicate_count > 1) : ?>
                                <p><strong><?php echo esc_html(sprintf(__('Duplicate set: %d PEOPLE images', 'academy-awards-table'), $duplicate_count)); ?></strong></p>
                            <?php endif; ?>
                            <p class="aat-person-portrait-muted"><?php echo esc_html((string) ($row['post_title'] ?? '')); ?></p>
                            <div class="aat-person-portrait-adoption-links">
                                <?php if ($profile_url !== '') : ?>
                                    <a href="<?php echo esc_url($profile_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Public file', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                                <?php if ($full_url !== '') : ?>
                                    <a href="<?php echo esc_url($full_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Media image', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_duplicate_group) : ?>
                                <div class="aat-person-portrait-duplicate-group-card">
                                    <strong><?php esc_html_e('Choose from this duplicate group', 'academy-awards-table'); ?></strong>
                                    <p><?php esc_html_e('Each candidate still requires the exact IMDb ID typed below. The server rechecks the live PEOPLE duplicate group before writing portrait metadata.', 'academy-awards-table'); ?></p>
                                    <div class="aat-person-portrait-duplicate-group-candidates">
                                        <?php foreach ($duplicate_group_candidates as $duplicate_item) : ?>
                                            <?php
                                            $duplicate_attachment_id = intval($duplicate_item['attachment_id'] ?? 0);
                                            $duplicate_thumb_url = (string) ($duplicate_item['thumb_url'] ?? '');
                                            $duplicate_full_url = (string) ($duplicate_item['full_url'] ?? '');
                                            $duplicate_title = trim((string) ($duplicate_item['post_title'] ?? ''));
                                            if ($duplicate_title === '') {
                                                $duplicate_title = trim(basename((string) ($duplicate_item['attached_file'] ?? '')));
                                            }
                                            ?>
                                            <div class="aat-person-portrait-duplicate-group-option">
                                                <div class="aat-person-portrait-duplicate-group-media">
                                                    <?php if ($duplicate_thumb_url !== '') : ?>
                                                        <img src="<?php echo esc_url($duplicate_thumb_url); ?>" alt="" loading="lazy" />
                                                    <?php else : ?>
                                                        <span><?php esc_html_e('No image', 'academy-awards-table'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="aat-person-portrait-duplicate-group-meta">
                                                    <span><?php echo esc_html(sprintf(__('Attachment #%d', 'academy-awards-table'), $duplicate_attachment_id)); ?></span>
                                                    <?php if ($duplicate_title !== '') : ?>
                                                        <small><?php echo esc_html($duplicate_title); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($duplicate_full_url !== '') : ?>
                                                        <a href="<?php echo esc_url($duplicate_full_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Media image', 'academy-awards-table'); ?></a>
                                                    <?php endif; ?>
                                                </div>
                                                <form method="post" class="aat-person-portrait-duplicate-group-resolver">
                                                    <?php wp_nonce_field('aat_existing_person_portrait_duplicate_resolve', 'aat_existing_person_portrait_duplicate_resolve_nonce'); ?>
                                                    <input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $duplicate_attachment_id); ?>" />
                                                    <input type="hidden" name="person_id" value="<?php echo esc_attr($person_id); ?>" />
                                                    <label>
                                                        <span><?php echo esc_html(sprintf(__('Type %s to confirm this duplicate resolution', 'academy-awards-table'), $person_id)); ?></span>
                                                        <input type="text" name="duplicate_confirm_person_id" value="" placeholder="<?php echo esc_attr($person_id); ?>" autocomplete="off" />
                                                    </label>
                                                    <label>
                                                        <span><?php esc_html_e('Private resolver note', 'academy-awards-table'); ?></span>
                                                        <textarea name="adoption_note" rows="2" placeholder="<?php esc_attr_e('Confirmed best PEOPLE portrait from duplicate group.', 'academy-awards-table'); ?>"></textarea>
                                                    </label>
                                                    <button type="submit" class="button button-primary"><?php esc_html_e('Resolve duplicate with this attachment', 'academy-awards-table'); ?></button>
                                                    <code>typed-confirmation duplicate resolver</code>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php elseif ($is_manual) : ?>
                                <div class="aat-person-portrait-manual-review">
                                    <strong><?php esc_html_e('Read-only manual review', 'academy-awards-table'); ?></strong>
                                    <p><?php esc_html_e('No safe IMDb person ID was detected for this PEOPLE image. Open the media image, inspect the filename/title/alt text, and keep it out of adoption until the person can be verified.', 'academy-awards-table'); ?></p>
                                    <dl>
                                        <div>
                                            <dt><?php esc_html_e('Match strategy', 'academy-awards-table'); ?></dt>
                                            <dd><?php echo esc_html((string) ($row['match_strategy'] ?? 'none')); ?></dd>
                                        </div>
                                        <div>
                                            <dt><?php esc_html_e('Detected ID', 'academy-awards-table'); ?></dt>
                                            <dd><?php echo esc_html((string) ($row['detected_person_id'] ?? '')); ?></dd>
                                        </div>
                                        <div>
                                            <dt><?php esc_html_e('Explicit ID', 'academy-awards-table'); ?></dt>
                                            <dd><?php echo esc_html((string) ($row['explicit_person_id'] ?? '')); ?></dd>
                                        </div>
                                    </dl>
                                </div>
                            <?php elseif ($is_duplicate) : ?>
                                <p class="aat-person-portrait-muted"><?php esc_html_e('Manual review required before adoption because more than one PEOPLE image maps to this person ID.', 'academy-awards-table'); ?></p>
                                <?php if (!empty($duplicate_group)) : ?>
                                    <div class="aat-person-portrait-duplicate-review">
                                        <strong><?php esc_html_e('Competing PEOPLE images', 'academy-awards-table'); ?></strong>
                                        <div class="aat-person-portrait-duplicate-strip">
                                            <?php foreach ($duplicate_group as $duplicate_item) : ?>
                                                <?php
                                                $duplicate_attachment_id = intval($duplicate_item['attachment_id'] ?? 0);
                                                $duplicate_thumb_url = (string) ($duplicate_item['thumb_url'] ?? '');
                                                $duplicate_full_url = (string) ($duplicate_item['full_url'] ?? '');
                                                $duplicate_title = (string) ($duplicate_item['post_title'] ?? '');
                                                $duplicate_is_current = $duplicate_attachment_id === $attachment_id;
                                                ?>
                                                <a class="aat-person-portrait-duplicate-option <?php echo $duplicate_is_current ? 'is-current' : ''; ?>" href="<?php echo esc_url($duplicate_full_url !== '' ? $duplicate_full_url : '#'); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php if ($duplicate_thumb_url !== '') : ?>
                                                        <img src="<?php echo esc_url($duplicate_thumb_url); ?>" alt="" loading="lazy" />
                                                    <?php else : ?>
                                                        <span><?php esc_html_e('No image', 'academy-awards-table'); ?></span>
                                                    <?php endif; ?>
                                                    <span><?php echo esc_html(sprintf(__('Attachment #%d', 'academy-awards-table'), $duplicate_attachment_id)); ?></span>
                                                    <?php if ($duplicate_title !== '') : ?>
                                                        <small><?php echo esc_html($duplicate_title); ?></small>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <form method="post" class="aat-person-portrait-duplicate-resolver">
                                    <?php wp_nonce_field('aat_existing_person_portrait_duplicate_resolve', 'aat_existing_person_portrait_duplicate_resolve_nonce'); ?>
                                    <input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $attachment_id); ?>" />
                                    <input type="hidden" name="person_id" value="<?php echo esc_attr($person_id); ?>" />
                                    <label>
                                        <span><?php echo esc_html(sprintf(__('Type %s to confirm this duplicate resolution', 'academy-awards-table'), $person_id)); ?></span>
                                        <input type="text" name="duplicate_confirm_person_id" value="" placeholder="<?php echo esc_attr($person_id); ?>" autocomplete="off" />
                                    </label>
                                    <label>
                                        <span><?php esc_html_e('Private resolver note', 'academy-awards-table'); ?></span>
                                        <textarea name="adoption_note" rows="2" placeholder="<?php esc_attr_e('Confirmed best PEOPLE portrait from duplicate set.', 'academy-awards-table'); ?>"></textarea>
                                    </label>
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Resolve duplicate with this attachment', 'academy-awards-table'); ?></button>
                                    <code>typed-confirmation duplicate resolver</code>
                                </form>
                            <?php else : ?>
                                <form method="post">
                                    <?php wp_nonce_field('aat_existing_person_portrait_adopt', 'aat_existing_person_portrait_adopt_nonce'); ?>
                                    <input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $attachment_id); ?>" />
                                    <input type="hidden" name="person_id" value="<?php echo esc_attr($person_id); ?>" />
                                    <label>
                                        <span><?php esc_html_e('Private adoption note', 'academy-awards-table'); ?></span>
                                        <textarea name="adoption_note" rows="2" placeholder="<?php esc_attr_e('Confirmed existing PEOPLE portrait.', 'academy-awards-table'); ?>"></textarea>
                                    </label>
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Adopt existing portrait', 'academy-awards-table'); ?></button>
                                    <code>existing-media-adoption</code>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="aat-admin-section">
        <h2><?php esc_html_e('Queue Controls', 'academy-awards-table'); ?></h2>
        <form method="get" class="aat-person-portrait-filters">
            <input type="hidden" name="page" value="academy-awards-person-portraits" />
            <label>
                <span><?php esc_html_e('State', 'academy-awards-table'); ?></span>
                <select name="state">
                    <?php foreach (array(
                        'candidate_external' => __('Candidate external', 'academy-awards-table'),
                        'needs_attention' => __('Needs attention', 'academy-awards-table'),
                        'ready' => __('Ready', 'academy-awards-table'),
                        'all' => __('All', 'academy-awards-table'),
                    ) as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_state, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Limit', 'academy-awards-table'); ?></span>
                <input type="number" name="limit" value="<?php echo esc_attr((string) $selected_limit); ?>" min="1" max="200" />
            </label>
            <label>
                <span><?php esc_html_e('Offset', 'academy-awards-table'); ?></span>
                <input type="number" name="offset" value="<?php echo esc_attr((string) $selected_offset); ?>" min="0" />
            </label>
            <label class="aat-person-portrait-checkbox">
                <input type="checkbox" name="refresh_tmdb" value="1" <?php checked($refresh_tmdb); ?> />
                <span><?php esc_html_e('Refresh TMDb for visible rows', 'academy-awards-table'); ?></span>
            </label>
            <label class="aat-person-portrait-id-paste">
                <span><?php esc_html_e('Manual IMDb person IDs', 'academy-awards-table'); ?></span>
                <textarea name="person_ids" rows="3" placeholder="nm0000122 nm0946705"><?php echo esc_textarea($ids_raw); ?></textarea>
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Review Queue', 'academy-awards-table'); ?></button>
        </form>

        <div class="aat-person-portrait-summary">
            <span><?php echo esc_html(sprintf(__('Source: %s', 'academy-awards-table'), (string) ($queue_summary['source'] ?? 'wordpress'))); ?></span>
            <span><?php echo esc_html(sprintf(__('Scanned: %d', 'academy-awards-table'), intval($queue_summary['scanned'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Returned: %d', 'academy-awards-table'), intval($queue_summary['returned'] ?? 0))); ?></span>
            <span><?php echo esc_html(sprintf(__('Total roster: %d', 'academy-awards-table'), intval($queue_summary['total_roster'] ?? 0))); ?></span>
        </div>
    </section>

    <section class="aat-admin-section">
        <h2><?php esc_html_e('Verified Profile Candidates', 'academy-awards-table'); ?></h2>
        <div class="aat-admin-table-wrap">
            <table class="widefat striped aat-person-portrait-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Image', 'academy-awards-table'); ?></th>
                        <th><?php esc_html_e('Nominee', 'academy-awards-table'); ?></th>
                        <th><?php esc_html_e('State', 'academy-awards-table'); ?></th>
                        <th><?php esc_html_e('Source', 'academy-awards-table'); ?></th>
                        <th><?php esc_html_e('Notes', 'academy-awards-table'); ?></th>
                        <th><?php esc_html_e('Action', 'academy-awards-table'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queue_rows)) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No rows matched the current filters.', 'academy-awards-table'); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($queue_rows as $row) : ?>
                        <?php
                        $state = (string) ($row['state'] ?? 'needs_attention');
                        $person_id = (string) ($row['person_id'] ?? '');
                        $thumb_url = (string) ($row['thumb_url'] ?? '');
                        $profile_url = (string) ($row['profile_url'] ?? '');
                        $tmdb_profile_url = (string) ($row['tmdb_profile_url'] ?? '');
                        ?>
                        <tr class="aat-person-portrait-row is-<?php echo esc_attr($state); ?>">
                            <td>
                                <div class="aat-person-portrait-thumb">
                                    <?php if ($thumb_url !== '') : ?>
                                        <img src="<?php echo esc_url($thumb_url); ?>" alt="" loading="lazy" />
                                    <?php else : ?>
                                        <span><?php esc_html_e('No image', 'academy-awards-table'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo esc_html((string) ($row['label'] ?? $person_id)); ?></strong>
                                <code><?php echo esc_html($person_id); ?></code>
                                <?php if ($profile_url !== '') : ?>
                                    <a href="<?php echo esc_url($profile_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Public file', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="aat-person-portrait-state aat-person-portrait-state-<?php echo esc_attr($state); ?>"><?php echo esc_html((string) ($row['state_label'] ?? $state)); ?></span>
                            </td>
                            <td>
                                <code><?php echo esc_html((string) ($row['visual_source'] ?? 'none')); ?></code>
                                <?php if ($tmdb_profile_url !== '') : ?>
                                    <div><a href="<?php echo esc_url($tmdb_profile_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('TMDb profile image', 'academy-awards-table'); ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ((array) ($row['notes'] ?? array()) as $note) : ?>
                                    <div><?php echo esc_html((string) $note); ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($state === 'candidate_external' && $person_id !== '') : ?>
                                    <form method="post">
                                        <?php wp_nonce_field('aat_person_portrait_import', 'aat_person_portrait_import_nonce'); ?>
                                        <input type="hidden" name="person_id" value="<?php echo esc_attr($person_id); ?>" />
                                        <button type="submit" class="button button-primary"><?php esc_html_e('Import verified portrait', 'academy-awards-table'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <span class="aat-person-portrait-muted"><?php esc_html_e('No profile import available', 'academy-awards-table'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
