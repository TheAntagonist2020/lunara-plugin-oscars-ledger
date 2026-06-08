<?php
/**
 * Admin: OMDb Integrity Audit
 */
if (!defined('ABSPATH')) { exit; }

$aat = Academy_Awards_Table::get_instance();
$counts = is_array($audit['counts'] ?? null) ? $audit['counts'] : array();
$issue_counts = is_array($audit['issue_counts'] ?? null) ? $audit['issue_counts'] : array();
$rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
$limit = intval($audit['limit'] ?? 25);
$offset = intval($audit['offset'] ?? 0);
$total = intval($audit['total'] ?? 0);
$total_titles = intval($audit['total_titles'] ?? $total);
$issue_filter = sanitize_key((string) ($audit['issue_filter'] ?? 'all'));
$review_state_filter = sanitize_key((string) ($audit['review_state_filter'] ?? 'all'));
$scan_limit = intval($audit['scan_limit'] ?? 250);
$scanned = intval($audit['scanned'] ?? count($rows));
$next_offset = $offset + $limit;
$prev_offset = max(0, $offset - $limit);
$masked_key = $omdb_key_configured ? __('Key configured', 'academy-awards-table') : __('Not configured', 'academy-awards-table');
$omdb_review_states = is_array($omdb_review_states ?? null) ? $omdb_review_states : array();
$omdb_review_filter_labels = is_array($omdb_review_filter_labels ?? null) ? $omdb_review_filter_labels : array();
$omdb_poster_review_states = is_array($omdb_poster_review_states ?? null) ? $omdb_poster_review_states : array();

$issue_labels = array(
    'all' => __('All IDs', 'academy-awards-table'),
    'actionable' => __('Actionable', 'academy-awards-table'),
    'mismatch' => __('Likely Bad IDs', 'academy-awards-table'),
    'omdb_missing' => __('OMDb Missing', 'academy-awards-table'),
    'poster_missing' => __('Poster Missing', 'academy-awards-table'),
    'match' => __('Clean Matches', 'academy-awards-table'),
);

$issue_descriptions = array(
    'mismatch' => __('Title or year disagrees with OMDb. Verify before touching the Oscar row.', 'academy-awards-table'),
    'omdb_missing' => __('OMDb did not return a usable record. Usually a source gap, not a Lunara error.', 'academy-awards-table'),
    'poster_missing' => __('Identity reads cleanly enough, but OMDb does not provide a poster URL.', 'academy-awards-table'),
    'match' => __('No correction needed in the current read-only check.', 'academy-awards-table'),
);

$filter_url_args = array(
    'page' => 'academy-awards-omdb-audit',
    'limit' => $limit,
    'scan' => $scan_limit,
    'review_state' => $review_state_filter,
);
$review_filter_url_args = array(
    'page' => 'academy-awards-omdb-audit',
    'limit' => $limit,
    'scan' => $scan_limit,
    'issue' => $issue_filter,
);
?>
<div class="wrap aat-admin-wrap aat-omdb-audit-admin">
    <div class="aat-admin-header">
        <span class="dashicons dashicons-search"></span>
        <div>
            <h1><?php echo esc_html__('OMDb Integrity Audit', 'academy-awards-table'); ?></h1>
            <p style="margin:6px 0 0; color:#e6eef7; opacity:.9;">
                <?php echo esc_html__('A read-only second-source check for Oscar title IDs, names, years, and poster identity.', 'academy-awards-table'); ?>
            </p>
        </div>
    </div>

    <?php if ($message !== '') : ?>
        <div class="aat-message aat-message-<?php echo esc_attr($message_type); ?>">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>

    <div class="aat-admin-stats">
        <div class="aat-admin-stat-card">
            <h3><?php echo esc_html__('Rows Shown', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format_i18n(count($rows))); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php echo esc_html__('Matches', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format_i18n(intval($counts['match'] ?? 0))); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php echo esc_html__('Warnings', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format_i18n(intval($counts['warning'] ?? 0))); ?></div>
        </div>
        <div class="aat-admin-stat-card">
            <h3><?php echo esc_html__('Errors', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format_i18n(intval($counts['error'] ?? 0))); ?></div>
        </div>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('API Connection', 'academy-awards-table'); ?></h2>
        <p>
            <?php echo esc_html__('Use either the AAT_OMDB_API_KEY constant or save the key here. Saved keys live in WordPress options and are not committed to GitHub.', 'academy-awards-table'); ?>
        </p>
        <p><strong><?php echo esc_html__('Status:', 'academy-awards-table'); ?></strong> <?php echo esc_html($masked_key); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('aat_omdb_settings', 'aat_omdb_settings_nonce'); ?>
            <div class="aat-grid-form">
                <div class="aat-field aat-field-wide">
                    <label class="aat-field-label" for="aat_omdb_api_key"><?php echo esc_html__('OMDb API Key', 'academy-awards-table'); ?></label>
                    <input class="aat-field-control" type="password" id="aat_omdb_api_key" name="aat_omdb_api_key" value="" autocomplete="off" placeholder="<?php echo esc_attr__('Paste key to save or leave blank', 'academy-awards-table'); ?>">
                    <p class="description"><?php echo esc_html__('The screen uses i=tt... JSON lookups for identity checks. Patron Poster API usage should be added as a server-side import step after this audit proves what needs repair.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-field">
                    <label>
                        <input type="checkbox" name="aat_omdb_clear_key" value="1">
                        <?php echo esc_html__('Clear saved key', 'academy-awards-table'); ?>
                    </label>
                </div>
                <div class="aat-field">
                    <button class="button button-primary" type="submit"><?php echo esc_html__('Save OMDb Settings', 'academy-awards-table'); ?></button>
                </div>
            </div>
        </form>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Correction Queue', 'academy-awards-table'); ?></h2>
        <p>
            <?php echo esc_html__('This is still a read-only audit. Use these queues to decide what needs a human correction, what is just an OMDb source gap, and what can be ignored.', 'academy-awards-table'); ?>
        </p>

        <div class="aat-omdb-filter-bar" aria-label="<?php echo esc_attr__('OMDb audit filters', 'academy-awards-table'); ?>">
            <?php foreach ($issue_labels as $issue_key => $issue_label) :
                $filter_args = array_merge($filter_url_args, array('issue' => $issue_key, 'offset' => 0));
                $filter_classes = 'aat-omdb-filter-link';
                if ($issue_key === $issue_filter) {
                    $filter_classes .= ' is-active';
                }
            ?>
                <a class="<?php echo esc_attr($filter_classes); ?>" href="<?php echo esc_url(add_query_arg($filter_args, admin_url('admin.php'))); ?>">
                    <?php echo esc_html($issue_label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="aat-omdb-filter-bar aat-omdb-review-filter-bar" aria-label="<?php echo esc_attr__('OMDb review-state filters', 'academy-awards-table'); ?>">
            <?php foreach ($omdb_review_filter_labels as $review_filter_key => $review_filter_label) :
                $review_filter_args = array_merge($review_filter_url_args, array('review_state' => $review_filter_key, 'offset' => 0));
                $review_filter_classes = 'aat-omdb-filter-link aat-omdb-review-filter-link';
                if ($review_filter_key === $review_state_filter) {
                    $review_filter_classes .= ' is-active';
                }
            ?>
                <a class="<?php echo esc_attr($review_filter_classes); ?>" href="<?php echo esc_url(add_query_arg($review_filter_args, admin_url('admin.php'))); ?>">
                    <?php echo esc_html($review_filter_label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form class="aat-omdb-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="academy-awards-omdb-audit">
            <label>
                <span><?php echo esc_html__('Filter', 'academy-awards-table'); ?></span>
                <select name="issue">
                    <?php foreach ($issue_labels as $issue_key => $issue_label) : ?>
                        <option value="<?php echo esc_attr($issue_key); ?>" <?php selected($issue_filter, $issue_key); ?>><?php echo esc_html($issue_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Review state', 'academy-awards-table'); ?></span>
                <select name="review_state">
                    <?php foreach ($omdb_review_filter_labels as $review_filter_key => $review_filter_label) : ?>
                        <option value="<?php echo esc_attr($review_filter_key); ?>" <?php selected($review_state_filter, $review_filter_key); ?>><?php echo esc_html($review_filter_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Rows per page', 'academy-awards-table'); ?></span>
                <input type="number" name="limit" value="<?php echo esc_attr($limit); ?>" min="1" max="100">
            </label>
            <label>
                <span><?php echo esc_html__('Scan depth', 'academy-awards-table'); ?></span>
                <input type="number" name="scan" value="<?php echo esc_attr($scan_limit); ?>" min="25" max="1000" step="25">
            </label>
            <button class="button button-secondary" type="submit"><?php echo esc_html__('Apply Audit Filter', 'academy-awards-table'); ?></button>
        </form>

        <div class="aat-omdb-queue">
            <div class="aat-omdb-queue-card is-mismatch">
                <span><?php echo esc_html__('Likely bad IDs', 'academy-awards-table'); ?></span>
                <strong><?php echo esc_html(number_format_i18n(intval($issue_counts['mismatch'] ?? 0))); ?></strong>
                <p><?php echo esc_html($issue_descriptions['mismatch']); ?></p>
            </div>
            <div class="aat-omdb-queue-card is-omdb-missing">
                <span><?php echo esc_html__('OMDb gaps', 'academy-awards-table'); ?></span>
                <strong><?php echo esc_html(number_format_i18n(intval($issue_counts['omdb_missing'] ?? 0))); ?></strong>
                <p><?php echo esc_html($issue_descriptions['omdb_missing']); ?></p>
            </div>
            <div class="aat-omdb-queue-card is-poster-missing">
                <span><?php echo esc_html__('Poster gaps', 'academy-awards-table'); ?></span>
                <strong><?php echo esc_html(number_format_i18n(intval($issue_counts['poster_missing'] ?? 0))); ?></strong>
                <p><?php echo esc_html($issue_descriptions['poster_missing']); ?></p>
            </div>
            <div class="aat-omdb-queue-card is-match">
                <span><?php echo esc_html__('Clean reads', 'academy-awards-table'); ?></span>
                <strong><?php echo esc_html(number_format_i18n(intval($issue_counts['match'] ?? 0))); ?></strong>
                <p><?php echo esc_html($issue_descriptions['match']); ?></p>
            </div>
        </div>

        <p>
            <?php
            if ($issue_filter === 'all' && $review_state_filter === 'all') {
                echo esc_html(
                    sprintf(
                        /* translators: 1: offset, 2: shown count, 3: total count */
                        __('Showing title IDs %1$s-%2$s of %3$s distinct Oscar title IDs.', 'academy-awards-table'),
                        number_format_i18n($total > 0 ? $offset + 1 : 0),
                        number_format_i18n(min($total, $offset + count($rows))),
                        number_format_i18n($total)
                    )
                );
            } else {
                echo esc_html(
                    sprintf(
                        /* translators: 1: issue filter label, 2: review filter label, 3: offset, 4: shown count, 5: total filtered count, 6: scanned count, 7: total count */
                        __('Showing %1$s / %2$s rows %3$s-%4$s from %5$s matches inside the current scan of %6$s title IDs (%7$s total known IDs).', 'academy-awards-table'),
                        strtolower((string) ($issue_labels[$issue_filter] ?? $issue_filter)),
                        strtolower((string) ($omdb_review_filter_labels[$review_state_filter] ?? $review_state_filter)),
                        number_format_i18n($total > 0 ? $offset + 1 : 0),
                        number_format_i18n(min($total, $offset + count($rows))),
                        number_format_i18n($total),
                        number_format_i18n($scanned),
                        number_format_i18n($total_titles)
                    )
                );
            }
            ?>
        </p>

        <p class="aat-admin-actions">
            <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'offset' => $prev_offset)), admin_url('admin.php'))); ?>"><?php echo esc_html__('Previous', 'academy-awards-table'); ?></a>
            <?php if ($next_offset < $total) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'offset' => $next_offset)), admin_url('admin.php'))); ?>"><?php echo esc_html__('Next', 'academy-awards-table'); ?></a>
            <?php endif; ?>
            <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'offset' => $offset, 'refresh' => 1)), admin_url('admin.php'))); ?>"><?php echo esc_html__('Refresh this read', 'academy-awards-table'); ?></a>
        </p>

        <?php if (empty($rows)) : ?>
            <p><?php echo esc_html__('No rows match this audit filter in the current read.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped aat-omdb-audit-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Status', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Queue', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Review State', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Oscar Dataset', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('OMDb Result', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Poster', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Warnings', 'academy-awards-table'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) :
                            $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
                            $omdb = is_array($row['omdb'] ?? null) ? $row['omdb'] : array();
                            $warnings = is_array($row['warnings'] ?? null) ? $row['warnings'] : array();
                            $issue_types = is_array($row['issue_types'] ?? null) ? $row['issue_types'] : array();
                            $review = is_array($row['review'] ?? null) ? $row['review'] : array();
                            $status = sanitize_html_class((string) ($row['status'] ?? 'unchecked'));
                            $issue_type = sanitize_key((string) ($row['issue_type'] ?? $status));
                            $poster = trim((string) ($omdb['poster'] ?? ''));
                            $has_poster = $poster !== '' && strtoupper($poster) !== 'N/A';
                            $local_poster = is_array($row['local_poster'] ?? null) ? $row['local_poster'] : array();
                            $has_local_poster = !empty($local_poster['has_local_poster']);
                            $local_thumb_url = (string) ($local_poster['thumb_url'] ?? '');
                            $poster_review = is_array($row['poster_review'] ?? null) ? $row['poster_review'] : array();
                            $poster_state = sanitize_key((string) ($poster_review['poster_state'] ?? 'needs_review'));
                            $poster_note = (string) ($poster_review['poster_note'] ?? '');
                            $poster_is_reviewed = !empty($poster_review['is_reviewed']);
                            $poster_reviewed_at = (string) ($poster_review['reviewed_at'] ?? '');
                            $review_state = sanitize_key((string) ($review['review_state'] ?? 'needs_review'));
                            $review_note = (string) ($review['correction_note'] ?? '');
                            $is_reviewed = !empty($review['is_reviewed']);
                            $reviewed_at = (string) ($review['reviewed_at'] ?? '');
                            $correction_preview = is_array($row['correction_preview'] ?? null) ? $row['correction_preview'] : array();
                        ?>
                            <tr class="aat-omdb-row is-<?php echo esc_attr($status); ?> has-issue-<?php echo esc_attr($issue_type); ?>">
                                <td>
                                    <span class="aat-omdb-status aat-omdb-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                </td>
                                <td>
                                    <span class="aat-omdb-issue-chip is-<?php echo esc_attr($issue_type); ?>">
                                        <?php echo esc_html($issue_labels[$issue_type] ?? ucfirst(str_replace('_', ' ', $issue_type))); ?>
                                    </span>
                                    <?php if (count($issue_types) > 1) : ?>
                                        <div class="aat-omdb-issue-stack">
                                            <?php foreach ($issue_types as $row_issue_type) :
                                                $row_issue_type = sanitize_key((string) $row_issue_type);
                                                if ($row_issue_type === $issue_type) {
                                                    continue;
                                                }
                                            ?>
                                                <span class="aat-omdb-mini-chip is-<?php echo esc_attr($row_issue_type); ?>"><?php echo esc_html($issue_labels[$row_issue_type] ?? ucfirst(str_replace('_', ' ', $row_issue_type))); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <p class="aat-omdb-action-note"><?php echo esc_html((string) ($row['recommended_action'] ?? '')); ?></p>
                                </td>
                                <td class="aat-omdb-review-cell">
                                    <span class="aat-omdb-review-state is-<?php echo esc_attr($review_state); ?>">
                                        <?php echo esc_html((string) ($review['review_state_label'] ?? $omdb_review_states[$review_state] ?? __('Needs Review', 'academy-awards-table'))); ?>
                                    </span>
                                    <p class="aat-omdb-review-meta">
                                        <?php
                                        if ($is_reviewed && $reviewed_at !== '') {
                                            echo esc_html(sprintf(__('Last reviewed %s', 'academy-awards-table'), $reviewed_at));
                                        } else {
                                            echo esc_html__('No private review note yet.', 'academy-awards-table');
                                        }
                                        ?>
                                    </p>
                                    <?php if ($review_note !== '') : ?>
                                        <p class="aat-omdb-review-note-preview"><?php echo esc_html($review_note); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($correction_preview)) :
                                        $preview_state = sanitize_html_class((string) ($correction_preview['state'] ?? ''));
                                        $candidate = is_array($correction_preview['candidate'] ?? null) ? $correction_preview['candidate'] : array();
                                        $preview_warnings = is_array($correction_preview['warnings'] ?? null) ? $correction_preview['warnings'] : array();
                                        $candidate_imdb_id = (string) ($correction_preview['candidate_imdb_id'] ?? '');
                                    ?>
                                        <div class="aat-omdb-correction-preview is-<?php echo esc_attr($preview_state); ?>">
                                            <div class="aat-omdb-correction-preview-head">
                                                <span><?php echo esc_html__('Candidate Preview', 'academy-awards-table'); ?></span>
                                                <strong><?php echo esc_html__('Preview only', 'academy-awards-table'); ?></strong>
                                            </div>
                                            <?php if ($candidate_imdb_id !== '' && !empty($candidate)) : ?>
                                                <dl class="aat-omdb-correction-preview-grid">
                                                    <div>
                                                        <dt><?php echo esc_html__('Current ID', 'academy-awards-table'); ?></dt>
                                                        <dd><code><?php echo esc_html((string) ($dataset['imdb_id'] ?? '')); ?></code></dd>
                                                    </div>
                                                    <div>
                                                        <dt><?php echo esc_html__('Candidate ID', 'academy-awards-table'); ?></dt>
                                                        <dd>
                                                            <code><?php echo esc_html($candidate_imdb_id); ?></code>
                                                            <?php if (!empty($correction_preview['candidate_imdb_url'])) : ?>
                                                                <a href="<?php echo esc_url((string) $correction_preview['candidate_imdb_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('IMDb', 'academy-awards-table'); ?></a>
                                                            <?php endif; ?>
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt><?php echo esc_html__('Candidate title', 'academy-awards-table'); ?></dt>
                                                        <dd><?php echo esc_html((string) ($candidate['title'] ?? '')); ?></dd>
                                                    </div>
                                                    <div>
                                                        <dt><?php echo esc_html__('Candidate year/type', 'academy-awards-table'); ?></dt>
                                                        <dd>
                                                            <?php echo esc_html(trim((string) ($candidate['year'] ?? ''))); ?>
                                                            <?php if (!empty($candidate['type'])) : ?>
                                                                <?php echo esc_html(' / ' . (string) $candidate['type']); ?>
                                                            <?php endif; ?>
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt><?php echo esc_html__('Poster', 'academy-awards-table'); ?></dt>
                                                        <dd><?php echo !empty($candidate['poster_present']) ? esc_html__('Present', 'academy-awards-table') : esc_html__('Missing', 'academy-awards-table'); ?></dd>
                                                    </div>
                                                </dl>
                                            <?php else : ?>
                                                <p><?php echo esc_html((string) ($correction_preview['message'] ?? __('No candidate preview is available yet.', 'academy-awards-table'))); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($preview_warnings)) : ?>
                                                <ul class="aat-omdb-correction-preview-warnings">
                                                    <?php foreach ($preview_warnings as $preview_warning) : ?>
                                                        <li><?php echo esc_html((string) $preview_warning); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else : ?>
                                                <p class="aat-omdb-correction-preview-ready"><?php echo esc_html__('Candidate title/year/type/poster checks are clean. Still no database write has happened.', 'academy-awards-table'); ?></p>
                                            <?php endif; ?>
                                            <?php if ((string) ($correction_preview['state'] ?? '') === 'ready_preview' && $candidate_imdb_id !== '') : ?>
                                                <form class="aat-omdb-correction-form" method="post" action="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'review_state' => $review_state_filter, 'offset' => $offset)), admin_url('admin.php'))); ?>">
                                                    <?php wp_nonce_field('aat_omdb_correction', 'aat_omdb_correction_nonce'); ?>
                                                    <input type="hidden" name="aat_omdb_correction_current_id" value="<?php echo esc_attr((string) ($dataset['imdb_id'] ?? '')); ?>">
                                                    <input type="hidden" name="aat_omdb_correction_candidate_id" value="<?php echo esc_attr($candidate_imdb_id); ?>">
                                                    <input type="hidden" name="aat_omdb_correction_dataset_title" value="<?php echo esc_attr((string) ($dataset['film'] ?? '')); ?>">
                                                    <input type="hidden" name="aat_omdb_correction_dataset_year" value="<?php echo esc_attr((string) ($dataset['year'] ?? '')); ?>">
                                                    <label>
                                                        <input type="checkbox" name="aat_omdb_correction_confirm" value="1">
                                                        <?php echo esc_html__('I reviewed this preview and want to replace this bad IMDb ID in Oscar rows.', 'academy-awards-table'); ?>
                                                    </label>
                                                    <button class="button button-small aat-omdb-correction-button" type="submit"><?php echo esc_html__('Apply This Candidate', 'academy-awards-table'); ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form class="aat-omdb-review-form" method="post" action="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'review_state' => $review_state_filter, 'offset' => $offset)), admin_url('admin.php'))); ?>">
                                        <?php wp_nonce_field('aat_omdb_review', 'aat_omdb_review_nonce'); ?>
                                        <input type="hidden" name="aat_omdb_review_imdb_id" value="<?php echo esc_attr((string) ($dataset['imdb_id'] ?? '')); ?>">
                                        <input type="hidden" name="aat_omdb_review_issue_type" value="<?php echo esc_attr($issue_type); ?>">
                                        <label>
                                            <span><?php echo esc_html__('State', 'academy-awards-table'); ?></span>
                                            <select name="aat_omdb_review_state">
                                                <?php foreach ($omdb_review_states as $state_key => $state_label) : ?>
                                                    <option value="<?php echo esc_attr($state_key); ?>" <?php selected($review_state, $state_key); ?>><?php echo esc_html($state_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span><?php echo esc_html__('Private correction note', 'academy-awards-table'); ?></span>
                                            <textarea name="aat_omdb_review_note" rows="3" placeholder="<?php echo esc_attr__('What did you verify, and what should happen next?', 'academy-awards-table'); ?>"><?php echo esc_textarea($review_note); ?></textarea>
                                        </label>
                                        <button class="button button-small" type="submit"><?php echo esc_html__('Save Review State', 'academy-awards-table'); ?></button>
                                    </form>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) ($dataset['film'] ?? '')); ?></strong>
                                    <div class="aat-muted">
                                        <code><?php echo esc_html((string) ($dataset['imdb_id'] ?? '')); ?></code>
                                        <?php if (!empty($dataset['year'])) : ?>
                                            <?php echo esc_html(' / ' . (string) $dataset['year']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($dataset['ceremony'])) : ?>
                                            <?php echo esc_html(' / ' . sprintf(__('Ceremony %d', 'academy-awards-table'), intval($dataset['ceremony']))); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="aat-muted">
                                        <a href="<?php echo esc_url((string) ($row['entity_url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Open Lunara title page', 'academy-awards-table'); ?></a>
                                        <?php echo esc_html(' / '); ?>
                                        <a href="<?php echo esc_url((string) ($row['imdb_url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('IMDb', 'academy-awards-table'); ?></a>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($omdb['error'])) : ?>
                                        <span class="aat-muted"><?php echo esc_html((string) $omdb['error']); ?></span>
                                    <?php else : ?>
                                        <strong><?php echo esc_html((string) ($omdb['title'] ?? '')); ?></strong>
                                        <div class="aat-muted">
                                            <?php echo esc_html((string) ($omdb['year'] ?? '')); ?>
                                            <?php if (!empty($omdb['type'])) : ?>
                                                <?php echo esc_html(' / ' . (string) $omdb['type']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($omdb['runtime'])) : ?>
                                                <?php echo esc_html(' / ' . (string) $omdb['runtime']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($omdb['director'])) : ?>
                                            <div class="aat-muted"><?php echo esc_html((string) $omdb['director']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="aat-omdb-poster-cell">
                                    <div class="aat-omdb-poster-stack">
                                        <div class="aat-omdb-poster-preview-block">
                                            <span><?php echo esc_html__('OMDb', 'academy-awards-table'); ?></span>
                                            <?php if ($has_poster) : ?>
                                                <img class="aat-omdb-poster" src="<?php echo esc_url($poster); ?>" alt="<?php echo esc_attr(((string) ($omdb['title'] ?? $dataset['film'] ?? 'Film')) . ' poster'); ?>" loading="lazy" decoding="async">
                                            <?php else : ?>
                                                <em><?php echo esc_html__('No poster', 'academy-awards-table'); ?></em>
                                            <?php endif; ?>
                                        </div>
                                        <div class="aat-omdb-poster-preview-block">
                                            <span><?php echo esc_html__('Lunara', 'academy-awards-table'); ?></span>
                                            <?php if ($has_local_poster && $local_thumb_url !== '') : ?>
                                                <img class="aat-omdb-poster" src="<?php echo esc_url($local_thumb_url); ?>" alt="<?php echo esc_attr__('Current Lunara poster', 'academy-awards-table'); ?>" loading="lazy" decoding="async">
                                                <em><?php echo esc_html((string) ($local_poster['source'] ?? __('Mapped', 'academy-awards-table'))); ?></em>
                                            <?php elseif ($has_local_poster) : ?>
                                                <em><?php echo esc_html__('Mapped locally', 'academy-awards-table'); ?></em>
                                            <?php else : ?>
                                                <em><?php echo esc_html__('Not mapped', 'academy-awards-table'); ?></em>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($has_poster && !$has_local_poster && !empty($dataset['imdb_id'])) : ?>
                                            <form class="aat-omdb-poster-import-form" method="post" action="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'review_state' => $review_state_filter, 'offset' => $offset)), admin_url('admin.php'))); ?>">
                                                <?php wp_nonce_field('aat_omdb_poster_import', 'aat_omdb_poster_import_nonce'); ?>
                                                <input type="hidden" name="aat_omdb_poster_import_imdb_id" value="<?php echo esc_attr((string) ($dataset['imdb_id'] ?? '')); ?>">
                                                <label>
                                                    <input type="checkbox" name="aat_omdb_poster_import_confirm" value="1">
                                                    <?php echo esc_html__('Accept this OMDb poster into Lunara.', 'academy-awards-table'); ?>
                                                </label>
                                                <button class="button button-small aat-omdb-poster-import-button" type="submit"><?php echo esc_html__('Import Poster', 'academy-awards-table'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <div class="aat-omdb-poster-review-box">
                                            <span class="aat-omdb-poster-review-state is-<?php echo esc_attr($poster_state); ?>">
                                                <?php echo esc_html((string) ($poster_review['poster_state_label'] ?? $omdb_poster_review_states[$poster_state] ?? __('Needs Poster Review', 'academy-awards-table'))); ?>
                                            </span>
                                            <p class="aat-omdb-poster-review-meta">
                                                <?php
                                                if ($poster_is_reviewed && $poster_reviewed_at !== '') {
                                                    echo esc_html(sprintf(__('Poster reviewed %s', 'academy-awards-table'), $poster_reviewed_at));
                                                } else {
                                                    echo esc_html__('No private poster note yet.', 'academy-awards-table');
                                                }
                                                ?>
                                            </p>
                                            <?php if ($poster_note !== '') : ?>
                                                <p class="aat-omdb-poster-note-preview"><?php echo esc_html($poster_note); ?></p>
                                            <?php endif; ?>
                                            <form class="aat-omdb-poster-review-form" method="post" action="<?php echo esc_url(add_query_arg(array_merge($filter_url_args, array('issue' => $issue_filter, 'review_state' => $review_state_filter, 'offset' => $offset)), admin_url('admin.php'))); ?>">
                                                <?php wp_nonce_field('aat_omdb_poster_review', 'aat_omdb_poster_review_nonce'); ?>
                                                <input type="hidden" name="aat_omdb_poster_review_imdb_id" value="<?php echo esc_attr((string) ($dataset['imdb_id'] ?? '')); ?>">
                                                <label>
                                                    <span><?php echo esc_html__('Poster state', 'academy-awards-table'); ?></span>
                                                    <select name="aat_omdb_poster_review_state">
                                                        <?php foreach ($omdb_poster_review_states as $state_key => $state_label) : ?>
                                                            <option value="<?php echo esc_attr($state_key); ?>" <?php selected($poster_state, $state_key); ?>><?php echo esc_html($state_label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label>
                                                    <span><?php echo esc_html__('Private poster note', 'academy-awards-table'); ?></span>
                                                    <textarea name="aat_omdb_poster_review_note" rows="2" placeholder="<?php echo esc_attr__('Accept, source failure, manual replacement, or next poster action.', 'academy-awards-table'); ?>"><?php echo esc_textarea($poster_note); ?></textarea>
                                                </label>
                                                <button class="button button-small" type="submit"><?php echo esc_html__('Save Poster State', 'academy-awards-table'); ?></button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (empty($warnings)) : ?>
                                        <span class="aat-muted"><?php echo esc_html__('No issues in this read-only check.', 'academy-awards-table'); ?></span>
                                    <?php else : ?>
                                        <ul class="aat-omdb-warnings">
                                            <?php foreach ($warnings as $warning) : ?>
                                                <li><?php echo esc_html((string) $warning); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
