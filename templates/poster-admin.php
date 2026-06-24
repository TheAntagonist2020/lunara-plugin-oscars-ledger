<?php
/**
 * Admin: Poster Library
 */
if (!defined('ABSPATH')) { exit; }

$aat = Academy_Awards_Table::get_instance();
?>
<div class="wrap aat-admin-wrap aat-posters-admin">
    <div class="aat-admin-header">
        <span class="dashicons dashicons-format-image"></span>
        <div>
            <h1><?php echo esc_html__('Poster Library', 'academy-awards-table'); ?></h1>
            <p style="margin:6px 0 0; color:#e6eef7; opacity:.9;">
                <?php echo esc_html__('A single, canonical poster source for the Oscars database, hubs, and Awards Tracker.', 'academy-awards-table'); ?>
            </p>
        </div>
    </div>

    <?php if (!empty($message)) : ?>
        <div class="aat-message aat-message-<?php echo esc_attr($message_type ?? 'success'); ?>">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>

    <div class="aat-admin-stats">
        <div class="aat-admin-stat-card">
            <h3><?php echo esc_html__('Mapped Posters', 'academy-awards-table'); ?></h3>
            <div class="stat-value"><?php echo esc_html(number_format_i18n($total_posters ?? 0)); ?></div>
        </div>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('API Settings', 'academy-awards-table'); ?></h2>
        <p><?php echo esc_html__('Save your OMDb and TMDB keys here so the poster workflow can import missing images automatically.', 'academy-awards-table'); ?></p>
        <p>
            <strong><?php echo esc_html__('OMDb Status:', 'academy-awards-table'); ?></strong>
            <?php echo esc_html(!empty($omdb_key_configured) ? __('Configured', 'academy-awards-table') : __('Not configured', 'academy-awards-table')); ?>
            &nbsp;|&nbsp;
            <strong><?php echo esc_html__('TMDB Status:', 'academy-awards-table'); ?></strong>
            <?php echo esc_html(!empty($tmdb_key_configured) ? __('Configured', 'academy-awards-table') : __('Not configured', 'academy-awards-table')); ?>
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('aat_poster_api_settings', 'aat_poster_api_settings_nonce'); ?>
            <div class="aat-grid-form">
                <div class="aat-field aat-field-wide">
                    <label class="aat-field-label" for="aat_omdb_api_key"><?php echo esc_html__('OMDb API Key', 'academy-awards-table'); ?></label>
                    <input class="aat-field-control" type="password" id="aat_omdb_api_key" name="aat_omdb_api_key" value="" autocomplete="off" placeholder="<?php echo esc_attr__('Paste key to save or leave blank', 'academy-awards-table'); ?>">
                    <p class="description"><?php echo esc_html__('Used for patron poster import and title identity checks.', 'academy-awards-table'); ?></p>
                </div>

                <div class="aat-field aat-field-wide">
                    <label class="aat-field-label" for="aat_tmdb_api_key"><?php echo esc_html__('TMDB API Key', 'academy-awards-table'); ?></label>
                    <input class="aat-field-control" type="password" id="aat_tmdb_api_key" name="aat_tmdb_api_key" value="" autocomplete="off" placeholder="<?php echo esc_attr__('Paste key to save or leave blank', 'academy-awards-table'); ?>">
                    <p class="description"><?php echo esc_html__('Used for dynamic metadata, backdrops, and poster fallback when OMDb poster import is unavailable.', 'academy-awards-table'); ?></p>
                </div>

                <div class="aat-field">
                    <label>
                        <input type="checkbox" name="aat_omdb_clear_key" value="1">
                        <?php echo esc_html__('Clear saved OMDb key', 'academy-awards-table'); ?>
                    </label>
                </div>

                <div class="aat-field">
                    <label>
                        <input type="checkbox" name="aat_tmdb_clear_key" value="1">
                        <?php echo esc_html__('Clear saved TMDB key', 'academy-awards-table'); ?>
                    </label>
                </div>

                <div class="aat-field">
                    <button class="button button-primary" type="submit"><?php echo esc_html__('Save API Settings', 'academy-awards-table'); ?></button>
                </div>
            </div>
        </form>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Quick Sync From Reviews', 'academy-awards-table'); ?></h2>
        <p><?php echo esc_html__('If your reviews have an IMDb Title ID + featured image, we can automatically map those images as posters.', 'academy-awards-table'); ?></p>
        <button class="button button-primary" id="aat-posters-sync">
            <?php echo esc_html__('Sync posters from published reviews', 'academy-awards-table'); ?>
        </button>
        <p class="description" id="aat-posters-sync-status" style="margin-top:10px;"></p>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Auto Fill Missing Posters (OMDb + TMDB)', 'academy-awards-table'); ?></h2>
        <p>
            <?php echo esc_html__('This imports missing title posters into the Media Library using OMDb patron API first, then TMDB fallback. Existing mapped/review posters are left untouched.', 'academy-awards-table'); ?>
        </p>
        <p>
            <strong><?php echo esc_html__('OMDb Key:', 'academy-awards-table'); ?></strong>
            <?php echo esc_html($aat->get_omdb_api_key() !== '' ? __('Configured', 'academy-awards-table') : __('Not configured', 'academy-awards-table')); ?>
            &nbsp;|&nbsp;
            <strong><?php echo esc_html__('TMDB Key:', 'academy-awards-table'); ?></strong>
            <?php echo esc_html($aat->get_tmdb_api_key() !== '' ? __('Configured', 'academy-awards-table') : __('Not configured', 'academy-awards-table')); ?>
        </p>
        <button class="button button-secondary" id="aat-posters-sync-apis">
            <?php echo esc_html__('Import missing posters from APIs', 'academy-awards-table'); ?>
        </button>
        <p class="description" id="aat-posters-sync-apis-status" style="margin-top:10px;"></p>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Add / Update Poster Mapping', 'academy-awards-table'); ?></h2>

        <div class="aat-grid-form">
            <div class="aat-field aat-field-wide">
                <label class="aat-field-label" for="aat-poster-imdb-search"><?php echo esc_html__('Film (IMDb Title ID)', 'academy-awards-table'); ?></label>
                <div class="aat-entity-search">
                    <input id="aat-poster-imdb-search" class="aat-field-control" type="text" placeholder="Type a film title or paste tt1234567…" autocomplete="off" />
                    <input type="hidden" id="aat-poster-imdb-id" value="" />
                    <div class="aat-entity-suggestions" id="aat-poster-entity-suggestions"></div>
                </div>
                <p class="description"><?php echo esc_html__('This mapping is keyed by tt-id so it can be used everywhere (database rows, hub pages, tracker, film pages).', 'academy-awards-table'); ?></p>
            </div>

            <div class="aat-field aat-field-wide">
                <label class="aat-field-label"><?php echo esc_html__('Poster Image (Media Library)', 'academy-awards-table'); ?></label>
                <div class="aat-poster-picker">
                    <input type="hidden" id="aat-poster-attachment-id" value="" />
                    <button class="button" id="aat-poster-pick"><?php echo esc_html__('Choose / Upload Image', 'academy-awards-table'); ?></button>
                    <button class="button button-secondary" id="aat-poster-clear"><?php echo esc_html__('Clear', 'academy-awards-table'); ?></button>
                    <div class="aat-poster-preview" id="aat-poster-preview"></div>
                </div>
            </div>

            <div class="aat-field">
                <button class="button button-primary" id="aat-poster-save"><?php echo esc_html__('Save Poster', 'academy-awards-table'); ?></button>
            </div>
        </div>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Person Profile Audit', 'academy-awards-table'); ?></h2>
        <p><?php echo esc_html__('Checks Oscar person pages against the existing Media Library and shows how each portrait was resolved.', 'academy-awards-table'); ?></p>

        <?php if (empty($person_profile_audit)) : ?>
            <p><?php echo esc_html__('No Oscar person records were detected for the portrait audit.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Portrait', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Visual State', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Person ID', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Resolved Label', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Oscar Rows', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Source', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Match Strategy', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('File', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Status', 'academy-awards-table'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($person_profile_audit as $audit_row) : ?>
                            <tr>
                                <td style="width:72px;">
                                    <?php if (!empty($audit_row['thumb_url'])) : ?>
                                        <img src="<?php echo esc_url($audit_row['thumb_url']); ?>" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:8px;" />
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html((string) ($audit_row['portrait_state_label'] ?? '')); ?></strong>
                                    <?php if (!empty($audit_row['visual_state'])) : ?>
                                        <div class="aat-muted"><code><?php echo esc_html((string) $audit_row['visual_state']); ?></code></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo esc_html((string) ($audit_row['person_id'] ?? '')); ?></code>
                                    <?php if (!empty($audit_row['profile_url'])) : ?>
                                        <div class="aat-muted"><a href="<?php echo esc_url((string) $audit_row['profile_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Open profile page', 'academy-awards-table'); ?></a></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) ($audit_row['label'] ?? '')); ?></td>
                                <td><?php echo esc_html(number_format_i18n(intval($audit_row['nomination_count'] ?? 0))); ?></td>
                                <td><code><?php echo esc_html((string) ($audit_row['visual_source'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ($audit_row['match_strategy'] ?? '')); ?></td>
                                <td>
                                    <?php if (!empty($audit_row['attached_file'])) : ?>
                                        <code><?php echo esc_html((string) ($audit_row['attached_file'] ?? '')); ?></code>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($audit_row['matched'])) : ?>
                                        <strong><?php echo esc_html__('Connected', 'academy-awards-table'); ?></strong>
                                    <?php else : ?>
                                        <strong><?php echo esc_html__('No portrait match', 'academy-awards-table'); ?></strong>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Current Poster Mappings', 'academy-awards-table'); ?></h2>

        <?php if (empty($rows)) : ?>
            <p><?php echo esc_html__('No posters mapped yet. Sync from reviews or add a mapping above.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('IMDb ID', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Poster', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Attachment ID', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Source', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Updated', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Actions', 'academy-awards-table'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r) :
                            $tt = strtolower((string) ($r['imdb_id'] ?? ''));
                            $aid = intval($r['attachment_id'] ?? 0);
                            $src = (string) ($r['source'] ?? '');
                            $updated = (string) ($r['updated_at'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <code><?php echo esc_html($tt); ?></code>
                                <div class="aat-muted">
                                    <a href="<?php echo esc_url($aat->build_entity_url_from_id($tt)); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html__('Open film page', 'academy-awards-table'); ?>
                                    </a>
                                </div>
                            </td>
                            <td style="width:90px;">
                                <?php if ($aid) : ?>
                                    <?php echo wp_get_attachment_image($aid, array(60, 90), false, array('style' => 'border-radius:6px;')); ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($aid); ?></td>
                            <td><?php echo esc_html($src); ?></td>
                            <td><?php echo esc_html($updated); ?></td>
                            <td>
                                <button class="button button-secondary aat-poster-delete" data-imdb="<?php echo esc_attr($tt); ?>">
                                    <?php echo esc_html__('Remove', 'academy-awards-table'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
