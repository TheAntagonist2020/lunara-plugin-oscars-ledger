<?php
if (!defined('ABSPATH')) {
    exit;
}

$message = isset($message) ? (string) $message : '';
$message_type = isset($message_type) ? (string) $message_type : 'success';
$queue_rows = isset($queue_rows) && is_array($queue_rows) ? $queue_rows : array();
$queue_summary = isset($queue_summary) && is_array($queue_summary) ? $queue_summary : array();
$selected_state = isset($selected_state) ? (string) $selected_state : 'candidate_external';
$selected_limit = isset($selected_limit) ? intval($selected_limit) : 50;
$selected_offset = isset($selected_offset) ? intval($selected_offset) : 0;
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
