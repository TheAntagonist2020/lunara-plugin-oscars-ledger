<?php
/**
 * Admin: Image Integrity Console
 */
if (!defined('ABSPATH')) { exit; }

$image_integrity = is_array($image_integrity ?? null) ? $image_integrity : array();
$bucket = (string) ($image_integrity['bucket'] ?? 'needs_review');
$section = (string) ($image_integrity['section'] ?? 'all');
$focus = (string) ($image_integrity['focus'] ?? 'all');
$limit = (int) ($image_integrity['limit'] ?? 80);
$rows = is_array($image_integrity['rows'] ?? null) ? $image_integrity['rows'] : array();
$review_pack_rows = is_array($image_integrity['review_pack_rows'] ?? null) ? $image_integrity['review_pack_rows'] : array();
$review_pack_limit = (int) ($image_integrity['review_pack_limit'] ?? 25);
$counts = is_array($image_integrity['counts'] ?? null) ? $image_integrity['counts'] : array();
$focus_counts = is_array($image_integrity['focus_counts'] ?? null) ? $image_integrity['focus_counts'] : array();
$bucket_labels = is_array($image_integrity['bucket_labels'] ?? null) ? $image_integrity['bucket_labels'] : array();
$section_labels = is_array($image_integrity['section_labels'] ?? null) ? $image_integrity['section_labels'] : array();
$focus_labels = is_array($image_integrity['focus_labels'] ?? null) ? $image_integrity['focus_labels'] : array();
$total_filtered = (int) ($image_integrity['total_filtered'] ?? count($rows));
$table_scope = $focus !== 'all' ? (string) ($focus_labels[$focus] ?? __('Selected focus', 'academy-awards-table')) : (string) ($bucket_labels[$bucket] ?? __('Selected bucket', 'academy-awards-table'));
$page_url = admin_url('admin.php?page=academy-awards-image-integrity');
?>
<div class="wrap aat-admin-wrap aat-image-integrity-admin">
    <div class="aat-admin-header">
        <span class="dashicons dashicons-visibility"></span>
        <div>
            <h1><?php echo esc_html__('Image Integrity Console', 'academy-awards-table'); ?></h1>
            <p>
                <?php echo esc_html__('A private credibility desk for poster and portrait mapping before those visuals feed public Oscar dossiers.', 'academy-awards-table'); ?>
            </p>
        </div>
    </div>

    <div class="aat-image-integrity-brief">
        <div>
            <strong><?php echo esc_html__('Verified media beats volume.', 'academy-awards-table'); ?></strong>
            <span><?php echo esc_html__('This console normalizes existing Poster Library and Person Portrait Queue metadata into public-safety buckets.', 'academy-awards-table'); ?></span>
        </div>
        <div>
            <strong><?php echo esc_html__('No private review metadata renders on public routes.', 'academy-awards-table'); ?></strong>
            <span><?php echo esc_html__('Rows here are admin-only annotations and workflow links, not automatic public image approvals.', 'academy-awards-table'); ?></span>
        </div>
    </div>

    <div class="aat-image-integrity-actions">
        <a class="button button-primary" href="<?php echo esc_url((string) ($image_integrity['poster_library_url'] ?? admin_url('admin.php?page=academy-awards-posters'))); ?>">
            <?php echo esc_html__('Poster Library', 'academy-awards-table'); ?>
        </a>
        <a class="button button-secondary" href="<?php echo esc_url((string) ($image_integrity['portrait_queue_url'] ?? admin_url('admin.php?page=academy-awards-person-portraits'))); ?>">
            <?php echo esc_html__('Person Portrait Queue', 'academy-awards-table'); ?>
        </a>
        <a class="button button-secondary" href="<?php echo esc_url((string) ($image_integrity['omdb_audit_url'] ?? admin_url('admin.php?page=academy-awards-omdb-audit'))); ?>">
            <?php echo esc_html__('OMDb Audit', 'academy-awards-table'); ?>
        </a>
    </div>

    <div class="aat-image-integrity-section-tabs" aria-label="<?php echo esc_attr__('Image integrity sections', 'academy-awards-table'); ?>">
        <?php foreach ($section_labels as $section_key => $section_label) : ?>
            <?php
            $section_url = add_query_arg(array(
                'page' => 'academy-awards-image-integrity',
                'integrity_section' => $section_key,
                'integrity_bucket' => $bucket,
                'integrity_focus' => $focus,
                'limit' => $limit,
            ), admin_url('admin.php'));
            ?>
            <a class="<?php echo esc_attr('aat-image-integrity-section-tab' . ($section_key === $section ? ' is-active' : '')); ?>" href="<?php echo esc_url($section_url); ?>">
                <?php echo esc_html((string) $section_label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="aat-image-integrity-bucket-grid">
        <?php foreach ($bucket_labels as $bucket_key => $bucket_label) : ?>
            <?php
            $count = (int) ($counts[$bucket_key] ?? 0);
            $bucket_url = add_query_arg(array(
                'page' => 'academy-awards-image-integrity',
                'integrity_section' => $section,
                'integrity_bucket' => $bucket_key,
                'integrity_focus' => 'all',
                'limit' => $limit,
            ), admin_url('admin.php'));
            ?>
            <a class="<?php echo esc_attr('aat-image-integrity-bucket-card is-' . $bucket_key . ($bucket_key === $bucket ? ' is-active' : '')); ?>" href="<?php echo esc_url($bucket_url); ?>">
                <span><?php echo esc_html((string) $bucket_label); ?></span>
                <strong><?php echo esc_html(number_format_i18n($count)); ?></strong>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="aat-image-integrity-triage-rail" aria-label="<?php echo esc_attr__('Image integrity triage priorities', 'academy-awards-table'); ?>">
        <?php foreach ($focus_labels as $focus_key => $focus_label) : ?>
            <?php
            $rendered_focus_label = $focus_key === 'fix_first' ? __('Fix First', 'academy-awards-table') : (string) $focus_label;
            $focus_url = add_query_arg(array(
                'page' => 'academy-awards-image-integrity',
                'integrity_section' => $section,
                'integrity_bucket' => $focus_key === 'all' ? $bucket : 'all',
                'integrity_focus' => $focus_key,
                'limit' => $limit,
            ), admin_url('admin.php'));
            ?>
            <a class="<?php echo esc_attr('aat-image-integrity-triage-card is-' . $focus_key . ($focus_key === $focus ? ' is-active' : '')); ?>" href="<?php echo esc_url($focus_url); ?>">
                <span><?php echo esc_html($rendered_focus_label); ?></span>
                <strong><?php echo esc_html(number_format_i18n((int) ($focus_counts[$focus_key] ?? 0))); ?></strong>
                <?php if ($focus_key === 'fix_first') : ?>
                    <em><?php echo esc_html__('Wrong matches and unreviewed visuals first.', 'academy-awards-table'); ?></em>
                <?php elseif ($focus_key === 'missing') : ?>
                    <em><?php echo esc_html__('Image gaps that limit public dynamism.', 'academy-awards-table'); ?></em>
                <?php else : ?>
                    <em><?php echo esc_html__('Scoped private review queue.', 'academy-awards-table'); ?></em>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="aat-image-integrity-review-pack">
        <div class="aat-image-integrity-review-pack-heading">
            <div>
                <span><?php echo esc_html__('Top 25', 'academy-awards-table'); ?></span>
                <h2><?php echo esc_html__('Fix First Review Pack', 'academy-awards-table'); ?></h2>
                <p><?php echo esc_html__('A read-only sprint list from the current visual section, sorted by urgency and Oscar impact before the full table below.', 'academy-awards-table'); ?></p>
            </div>
            <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array(
                'page' => 'academy-awards-image-integrity',
                'integrity_section' => $section,
                'integrity_bucket' => 'all',
                'integrity_focus' => 'fix_first',
                'limit' => max($limit, $review_pack_limit),
            ), admin_url('admin.php'))); ?>">
                <?php echo esc_html__('Open Fix First Table', 'academy-awards-table'); ?>
            </a>
        </div>

        <?php if (empty($review_pack_rows)) : ?>
            <div class="aat-image-integrity-review-pack-empty">
                <?php echo esc_html__('No Fix First rows in this section.', 'academy-awards-table'); ?>
            </div>
        <?php else : ?>
            <div class="aat-image-integrity-review-pack-grid">
                <?php foreach ($review_pack_rows as $pack_index => $pack_row) : ?>
                    <?php
                    $pack_workflow_url = (string) ($pack_row['workflow_url'] ?? $page_url);
                    $pack_bucket = (string) ($pack_row['bucket'] ?? 'needs_review');
                    ?>
                    <a class="<?php echo esc_attr('aat-image-integrity-review-pack-card is-' . $pack_bucket); ?>" href="<?php echo esc_url($pack_workflow_url); ?>">
                        <span class="aat-image-integrity-review-pack-index"><?php echo esc_html(number_format_i18n($pack_index + 1)); ?></span>
                        <span class="aat-image-integrity-review-pack-kind"><?php echo esc_html((string) ($pack_row['kind_label'] ?? '')); ?></span>
                        <strong><?php echo esc_html((string) ($pack_row['label'] ?? '')); ?></strong>
                        <code><?php echo esc_html((string) ($pack_row['entity_id'] ?? '')); ?></code>
                        <em><?php echo esc_html((string) ($pack_row['impact_label'] ?? __('No Oscar rows', 'academy-awards-table'))); ?></em>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="aat-admin-section">
        <div class="aat-image-integrity-table-heading">
            <div>
                <h2><?php echo esc_html($table_scope); ?></h2>
                <p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: visible row count, 2: total filtered rows */
                        __('Showing %1$s of %2$s private integrity rows in %3$s.', 'academy-awards-table'),
                        number_format_i18n(count($rows)),
                        number_format_i18n($total_filtered),
                        $table_scope
                    ));
                    ?>
                </p>
            </div>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="aat-image-integrity-limit">
                <input type="hidden" name="page" value="academy-awards-image-integrity">
                <input type="hidden" name="integrity_section" value="<?php echo esc_attr($section); ?>">
                <input type="hidden" name="integrity_bucket" value="<?php echo esc_attr($bucket); ?>">
                <input type="hidden" name="integrity_focus" value="<?php echo esc_attr($focus); ?>">
                <label for="aat-image-integrity-limit"><?php echo esc_html__('Rows', 'academy-awards-table'); ?></label>
                <input id="aat-image-integrity-limit" type="number" name="limit" min="1" max="200" value="<?php echo esc_attr((string) $limit); ?>">
                <button class="button" type="submit"><?php echo esc_html__('Apply', 'academy-awards-table'); ?></button>
            </form>
        </div>

        <?php if (empty($rows)) : ?>
            <div class="aat-image-integrity-empty">
                <strong><?php echo esc_html__('No rows in this bucket.', 'academy-awards-table'); ?></strong>
                <span><?php echo esc_html__('That is good when it means issues are cleared; switch buckets if you are looking for ready or resolved visuals.', 'academy-awards-table'); ?></span>
            </div>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped aat-image-integrity-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Visual', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Entity', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Priority', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Bucket', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('State', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Source', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Private Note Preview', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Workflow', 'academy-awards-table'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $row_bucket = (string) ($row['bucket'] ?? 'needs_review');
                            $workflow_url = (string) ($row['workflow_url'] ?? $page_url);
                            $entity_url = (string) ($row['entity_url'] ?? '');
                            $note = trim((string) ($row['note'] ?? ''));
                            $priority_key = (string) ($row['priority_key'] ?? 'steady');
                            ?>
                            <tr class="<?php echo esc_attr('aat-image-integrity-row is-' . $row_bucket); ?>">
                                <td class="aat-image-integrity-visual-cell">
                                    <?php if (!empty($row['thumb_url'])) : ?>
                                        <img class="aat-image-integrity-thumb" src="<?php echo esc_url((string) $row['thumb_url']); ?>" alt="">
                                    <?php else : ?>
                                        <span class="aat-image-integrity-thumb is-empty"><?php echo esc_html__('No image', 'academy-awards-table'); ?></span>
                                    <?php endif; ?>
                                    <span><?php echo esc_html((string) ($row['kind_label'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) ($row['label'] ?? '')); ?></strong>
                                    <div class="aat-muted"><code><?php echo esc_html((string) ($row['entity_id'] ?? '')); ?></code></div>
                                    <?php if ($entity_url !== '') : ?>
                                        <div><a href="<?php echo esc_url($entity_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Open public file', 'academy-awards-table'); ?></a></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?php echo esc_attr('aat-image-integrity-priority is-' . $priority_key); ?>">
                                        <?php echo esc_html((string) ($row['priority_label'] ?? __('Stable', 'academy-awards-table'))); ?>
                                    </span>
                                    <div class="aat-muted"><?php echo esc_html((string) ($row['impact_label'] ?? __('No Oscar rows', 'academy-awards-table'))); ?></div>
                                    <div class="aat-image-integrity-reason"><?php echo esc_html((string) ($row['triage_reason'] ?? '')); ?></div>
                                </td>
                                <td>
                                    <span class="<?php echo esc_attr('aat-image-integrity-state is-' . $row_bucket); ?>">
                                        <?php echo esc_html((string) ($bucket_labels[$row_bucket] ?? $row_bucket)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) ($row['state_label'] ?? '')); ?></strong>
                                    <?php if (!empty($row['state'])) : ?>
                                        <div class="aat-muted"><code><?php echo esc_html((string) $row['state']); ?></code></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['attachment_id'])) : ?>
                                        <div><code><?php echo esc_html('#' . (int) $row['attachment_id']); ?></code></div>
                                    <?php endif; ?>
                                    <span><?php echo esc_html((string) ($row['source'] ?? '')); ?></span>
                                </td>
                                <td>
                                    <?php if ($note !== '') : ?>
                                        <?php echo esc_html(wp_trim_words($note, 18, '...')); ?>
                                    <?php else : ?>
                                        <span class="aat-muted"><?php echo esc_html__('No private note.', 'academy-awards-table'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($workflow_url); ?>">
                                        <?php echo esc_html__('Open workflow', 'academy-awards-table'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
