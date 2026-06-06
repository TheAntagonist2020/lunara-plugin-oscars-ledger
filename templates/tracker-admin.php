<?php
/**
 * Admin: Tracker V2
 */
if (!defined('ABSPATH')) { exit; }

$aat = Academy_Awards_Table::get_instance();

$selected_ceremony = isset($selected_ceremony) ? intval($selected_ceremony) : 0;
$year_label = isset($year_label) ? $year_label : '';
?>
<div class="wrap aat-admin-wrap aat-tracker-admin">
    <div class="aat-admin-header">
        <span class="dashicons dashicons-chart-area"></span>
        <div>
            <h1><?php echo esc_html__('Awards Tracker (V2)', 'academy-awards-table'); ?></h1>
            <p style="margin:6px 0 0; color:#e6eef7; opacity:.9;">
                <?php echo esc_html__('Predictions • Locks • Watchlist • Longshots — keyed to ceremony number so you can plan years ahead.', 'academy-awards-table'); ?>
            </p>
        </div>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Season', 'academy-awards-table'); ?></h2>

        <div class="aat-tracker-season-row">
            <label for="aat-tracker-ceremony" class="aat-field-label"><?php echo esc_html__('Ceremony', 'academy-awards-table'); ?></label>
            <select id="aat-tracker-ceremony" class="aat-field-control">
                <?php if (!empty($ceremonies)) : foreach ($ceremonies as $c) :
                    $c = intval($c);
                    if ($c <= 0) continue;
                    $label = $aat->ordinal($c) . ' Academy Awards';
                    $yr = $aat->get_ceremony_year($c);
                    if ($yr) $label .= ' (' . $yr . ')';
                ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($c, $selected_ceremony); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; endif; ?>
            </select>

            <div class="aat-tracker-season-meta">
                <span class="aat-tracker-season-badge"><?php echo esc_html($aat->ordinal($selected_ceremony)); ?></span>
                <?php if ($year_label) : ?>
                    <span class="aat-tracker-season-year"><?php echo esc_html($year_label); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <p class="description" style="margin-top:10px;">
            <?php echo esc_html__('Tip: create a separate WordPress page for each season (or keep one page and switch the ceremony selector).', 'academy-awards-table'); ?>
        </p>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Add / Update Pick', 'academy-awards-table'); ?></h2>

        <div class="aat-grid-form">
            <div class="aat-field">
                <label class="aat-field-label" for="aat-tracker-category"><?php echo esc_html__('Category', 'academy-awards-table'); ?></label>
                <select id="aat-tracker-category" class="aat-field-control">
                    <option value=""><?php echo esc_html__('Select…', 'academy-awards-table'); ?></option>
                    <?php if (!empty($categories)) : foreach ($categories as $cat) :
                        $cat = (string) $cat;
                        if ($cat === '') continue;
                    ?>
                        <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($aat->format_category_display($cat)); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>

            <div class="aat-field">
                <label class="aat-field-label" for="aat-tracker-tier"><?php echo esc_html__('Tier', 'academy-awards-table'); ?></label>
                <select id="aat-tracker-tier" class="aat-field-control">
                    <option value="prediction"><?php echo esc_html__('Prediction', 'academy-awards-table'); ?></option>
                    <option value="lock"><?php echo esc_html__('Lock', 'academy-awards-table'); ?></option>
                    <option value="watch"><?php echo esc_html__('Watchlist', 'academy-awards-table'); ?></option>
                    <option value="longshot"><?php echo esc_html__('Longshot', 'academy-awards-table'); ?></option>
                </select>
            </div>

            <div class="aat-field">
                <label class="aat-field-label" for="aat-tracker-rank"><?php echo esc_html__('Rank', 'academy-awards-table'); ?></label>
                <input id="aat-tracker-rank" class="aat-field-control" type="number" min="1" value="1" />
                <p class="description"><?php echo esc_html__('Use rank to order watchlist candidates within a category.', 'academy-awards-table'); ?></p>
            </div>

            <div class="aat-field aat-field-wide">
                <label class="aat-field-label" for="aat-tracker-entity-search"><?php echo esc_html__('Film / Person / Company', 'academy-awards-table'); ?></label>
                <div class="aat-entity-search">
                    <input id="aat-tracker-entity-search" class="aat-field-control" type="text" placeholder="Start typing a film title or nominee name…" autocomplete="off" />
                    <input type="hidden" id="aat-tracker-entity-id" value="" />
                    <input type="hidden" id="aat-tracker-entity-type" value="" />
                    <div class="aat-entity-suggestions" id="aat-tracker-entity-suggestions"></div>
                </div>
                <p class="description"><?php echo esc_html__('Matches are searched from your Oscars dataset (fast and consistent). You can also paste a tt/nm/co IMDb ID.', 'academy-awards-table'); ?></p>
            </div>

            <div class="aat-field aat-field-wide">
                <label class="aat-field-label" for="aat-tracker-note"><?php echo esc_html__('Note', 'academy-awards-table'); ?></label>
                <textarea id="aat-tracker-note" class="aat-field-control" rows="3" placeholder="Optional: why it’s a lock, what’s trending, what could upset it…"></textarea>
            </div>

            <div class="aat-field">
                <button class="button button-primary" id="aat-tracker-save"><?php echo esc_html__('Save Pick', 'academy-awards-table'); ?></button>
            </div>
        </div>
    </div>

    <div class="aat-admin-section">
        <h2><?php echo esc_html__('Current Picks', 'academy-awards-table'); ?></h2>

        <?php if (empty($picks)) : ?>
            <p><?php echo esc_html__('No picks yet for this ceremony. Add your first prediction above.', 'academy-awards-table'); ?></p>
        <?php else : ?>
            <div class="aat-admin-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Category', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Tier', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Rank', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Pick', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Note', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Updated', 'academy-awards-table'); ?></th>
                            <th><?php echo esc_html__('Actions', 'academy-awards-table'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($picks as $p) :
                            $id = intval($p['id'] ?? 0);
                            $cat = (string) ($p['canonical_category'] ?? '');
                            $tier = (string) ($p['tier'] ?? 'watch');
                            $rank = intval($p['rank'] ?? 1);
                            $etype = (string) ($p['entity_type'] ?? 'title');
                            $eid = (string) ($p['entity_id'] ?? '');
                            $note = (string) ($p['note'] ?? '');
                            $updated = (string) ($p['updated_at'] ?? '');

                            $label = $aat->get_entity_display_name($etype, $eid);
                            if ($label === '') $label = strtoupper($eid);

                            $url = $aat->build_entity_url_from_id($eid);
                        ?>
                        <tr>
                            <td><?php echo esc_html($aat->format_category_display($cat)); ?></td>
                            <td><span class="aat-tier-pill aat-tier-<?php echo esc_attr($tier); ?>"><?php echo esc_html(strtoupper($tier)); ?></span></td>
                            <td><?php echo esc_html($rank); ?></td>
                            <td>
                                <?php if ($url) : ?>
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($label); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($label); ?>
                                <?php endif; ?>
                                <div class="aat-muted"><?php echo esc_html($eid); ?></div>
                            </td>
                            <td><?php echo esc_html($note); ?></td>
                            <td><?php echo esc_html($updated); ?></td>
                            <td>
                                <button class="button button-secondary aat-tracker-delete" data-id="<?php echo esc_attr($id); ?>">
                                    <?php echo esc_html__('Delete', 'academy-awards-table'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="description" style="margin-top:10px;">
            <?php echo esc_html__('Everything is stored server-side by ceremony number, so when nominations drop you’re just adding entries — no code changes needed.', 'academy-awards-table'); ?>
        </p>
    </div>
</div>
