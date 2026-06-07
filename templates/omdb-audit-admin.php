<?php
/**
 * Admin: OMDb Integrity Audit
 */
if (!defined('ABSPATH')) { exit; }

$aat = Academy_Awards_Table::get_instance();
$counts = is_array($audit['counts'] ?? null) ? $audit['counts'] : array();
$rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
$limit = intval($audit['limit'] ?? 25);
$offset = intval($audit['offset'] ?? 0);
$total = intval($audit['total'] ?? 0);
$next_offset = $offset + $limit;
$prev_offset = max(0, $offset - $limit);
$masked_key = $omdb_key_configured ? '•••••••• configured' : 'Not configured';
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
            <h3><?php echo esc_html__('Audited Slice', 'academy-awards-table'); ?></h3>
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
        <h2><?php echo esc_html__('Audit Queue', 'academy-awards-table'); ?></h2>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: offset, 2: shown count, 3: total count */
                    __('Showing title IDs %1$s-%2$s of %3$s distinct Oscar title IDs.', 'academy-awards-table'),
                    number_format_i18n($offset + 1),
                    number_format_i18n(min($total, $offset + count($rows))),
                    number_format_i18n($total)
                )
            );
            ?>
        </p>

        <p class="aat-admin-actions">
            <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'academy-awards-omdb-audit', 'limit' => $limit, 'offset' => $prev_offset), admin_url('admin.php'))); ?>"><?php echo esc_html__('Previous', 'academy-awards-table'); ?></a>
            <?php if ($next_offset < $total) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'academy-awards-omdb-audit', 'limit' => $limit, 'offset' => $next_offset), admin_url('admin.php'))); ?>"><?php echo esc_html__('Next', 'academy-awards-table'); ?></a>
            <?php endif; ?>
            <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'academy-awards-omdb-audit', 'limit' => $limit, 'offset' => $offset, 'refresh' => 1), admin_url('admin.php'))); ?>"><?php echo esc_html__('Refresh this slice', 'academy-awards-table'); ?></a>
        </p>

        <?php if (empty($rows)) : ?>
            <p><?php echo esc_html__('No IMDb title IDs were found in the Oscars table.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped aat-omdb-audit-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Status', 'academy-awards-table'); ?></th>
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
                            $status = sanitize_html_class((string) ($row['status'] ?? 'unchecked'));
                            $poster = trim((string) ($omdb['poster'] ?? ''));
                            $has_poster = $poster !== '' && strtoupper($poster) !== 'N/A';
                        ?>
                            <tr class="aat-omdb-row is-<?php echo esc_attr($status); ?>">
                                <td>
                                    <span class="aat-omdb-status aat-omdb-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) ($dataset['film'] ?? '')); ?></strong>
                                    <div class="aat-muted">
                                        <code><?php echo esc_html((string) ($dataset['imdb_id'] ?? '')); ?></code>
                                        <?php if (!empty($dataset['year'])) : ?>
                                            · <?php echo esc_html((string) $dataset['year']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($dataset['ceremony'])) : ?>
                                            · <?php echo esc_html(sprintf(__('Ceremony %d', 'academy-awards-table'), intval($dataset['ceremony']))); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="aat-muted">
                                        <a href="<?php echo esc_url((string) ($row['entity_url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Open Lunara title page', 'academy-awards-table'); ?></a>
                                        ·
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
                                                · <?php echo esc_html((string) $omdb['type']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($omdb['runtime'])) : ?>
                                                · <?php echo esc_html((string) $omdb['runtime']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($omdb['director'])) : ?>
                                            <div class="aat-muted"><?php echo esc_html((string) $omdb['director']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="aat-omdb-poster-cell">
                                    <?php if ($has_poster) : ?>
                                        <img class="aat-omdb-poster" src="<?php echo esc_url($poster); ?>" alt="<?php echo esc_attr(((string) ($omdb['title'] ?? $dataset['film'] ?? 'Film')) . ' poster'); ?>" loading="lazy" decoding="async">
                                    <?php else : ?>
                                        <span class="aat-muted"><?php echo esc_html__('No poster', 'academy-awards-table'); ?></span>
                                    <?php endif; ?>
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
