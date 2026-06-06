<?php
/**
 * Frontend: Awards Tracker V2
 */
if (!defined('ABSPATH')) { exit; }

$aat = Academy_Awards_Table::get_instance();

$ceremony = isset($ceremony) ? intval($ceremony) : 0;
$season_label = isset($season_label) ? (string) $season_label : '';
$year_label = isset($year_label) ? (string) $year_label : '';
$picks = isset($picks) && is_array($picks) ? $picks : array();
$ceremonies = isset($ceremonies) && is_array($ceremonies) ? $ceremonies : array();

$show_selector = isset($show_selector) ? (bool) $show_selector : true;
$show_posters = isset($show_posters) ? (bool) $show_posters : true;
$show_imdb = isset($show_imdb) ? (bool) $show_imdb : true;
$show_review_links = isset($show_review_links) ? (bool) $show_review_links : true;

function aat_tracker_tier_label($tier) {
    switch ($tier) {
        case 'prediction': return 'Predictions';
        case 'lock': return 'Locks';
        case 'watch': return 'Watchlist';
        case 'longshot': return 'Longshots';
        default: return ucfirst($tier);
    }
}

function aat_tracker_tier_kicker($tier) {
    switch ($tier) {
        case 'prediction': return 'Your current #1 pick per category';
        case 'lock': return 'High-confidence calls';
        case 'watch': return 'Contenders we’re tracking';
        case 'longshot': return 'Possible curveballs';
        default: return '';
    }
}

$counts = array();
foreach (array('prediction','lock','watch','longshot') as $t) {
    $counts[$t] = 0;
    if (isset($picks[$t]) && is_array($picks[$t])) {
        foreach ($picks[$t] as $cat => $list) {
            if (is_array($list)) $counts[$t] += count($list);
        }
    }
}
?>
<div class="aat-container aat-tracker-v2">
    <div class="aat-tracker-header">
        <div class="aat-header">
            <img class="aat-oscar-icon" src="<?php echo esc_url(AAT_PLUGIN_URL . 'assets/img/oscar.png'); ?>" alt="Oscar" />
            <h2><?php echo esc_html__('Awards Tracker', 'academy-awards-table'); ?></h2>
            <p class="aat-subtitle">
                <?php echo esc_html($season_label); ?>
                <?php if ($year_label) : ?> — <?php echo esc_html($year_label); ?><?php endif; ?>
            </p>

            <?php if ($show_selector && !empty($ceremonies)) : ?>
                <div class="aat-tracker-season-selector">
                    <label for="aat-tracker-season" class="aat-tracker-season-label"><?php echo esc_html__('Season', 'academy-awards-table'); ?></label>
                    <select id="aat-tracker-season" class="aat-tracker-season-select">
                        <?php foreach ($ceremonies as $c) :
                            $c = intval($c);
                            if ($c <= 0) continue;
                            $lbl = $aat->ordinal($c) . ' Academy Awards';
                            $yr = $aat->get_ceremony_year($c);
                            if ($yr) $lbl .= ' (' . $yr . ')';
                        ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($c, $ceremony); ?>><?php echo esc_html($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="aat-tracker-mini-stats">
                <span class="aat-tracker-mini-stat"><?php echo esc_html(number_format_i18n($counts['prediction'])); ?> <?php echo esc_html__('predictions', 'academy-awards-table'); ?></span>
                <span class="aat-footer-sep">•</span>
                <span class="aat-tracker-mini-stat"><?php echo esc_html(number_format_i18n($counts['lock'])); ?> <?php echo esc_html__('locks', 'academy-awards-table'); ?></span>
                <span class="aat-footer-sep">•</span>
                <span class="aat-tracker-mini-stat"><?php echo esc_html(number_format_i18n($counts['watch'])); ?> <?php echo esc_html__('watchlist', 'academy-awards-table'); ?></span>
            </div>
        </div>
    </div>

    <div class="aat-tracker-tabs" role="tablist" aria-label="Awards Tracker Tabs">
        <button class="aat-tracker-tab active" data-tier="prediction" role="tab"><?php echo esc_html__('Predictions', 'academy-awards-table'); ?></button>
        <button class="aat-tracker-tab" data-tier="lock" role="tab"><?php echo esc_html__('Locks', 'academy-awards-table'); ?></button>
        <button class="aat-tracker-tab" data-tier="watch" role="tab"><?php echo esc_html__('Watchlist', 'academy-awards-table'); ?></button>
        <button class="aat-tracker-tab" data-tier="longshot" role="tab"><?php echo esc_html__('Longshots', 'academy-awards-table'); ?></button>
    </div>

    <?php foreach (array('prediction','lock','watch','longshot') as $tier) :
        $tier_groups = isset($picks[$tier]) && is_array($picks[$tier]) ? $picks[$tier] : array();
        $panel_id = 'aat-tracker-panel-' . $tier;
        $is_active = ($tier === 'prediction');
    ?>
        <div class="aat-tracker-panel<?php echo $is_active ? ' active' : ''; ?>" id="<?php echo esc_attr($panel_id); ?>" data-tier="<?php echo esc_attr($tier); ?>">
            <div class="aat-tracker-panel-head">
                <h3><?php echo esc_html(aat_tracker_tier_label($tier)); ?></h3>
                <p class="aat-tracker-panel-kicker"><?php echo esc_html(aat_tracker_tier_kicker($tier)); ?></p>
            </div>

            <?php if (empty($tier_groups)) : ?>
                <div class="aat-no-results">
                    <div class="aat-no-results-icon">🎬</div>
                    <h3><?php echo esc_html__('No picks yet', 'academy-awards-table'); ?></h3>
                    <p><?php echo esc_html__('Add picks in the WordPress admin: Academy Awards → Awards Tracker.', 'academy-awards-table'); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ($tier_groups as $cat => $list) :
                    if (!is_array($list) || empty($list)) continue;
                    $cat_display = $aat->format_category_display($cat);
                ?>
                    <section class="aat-tracker-category">
                        <h4 class="aat-tracker-category-title"><?php echo esc_html($cat_display); ?></h4>

                        <div class="aat-tracker-picks">
                            <?php foreach ($list as $p) :
                                $etype = (string) ($p['entity_type'] ?? 'title');
                                $eid = (string) ($p['entity_id'] ?? '');
                                $rank = intval($p['rank'] ?? 1);
                                $note = (string) ($p['note'] ?? '');

                                $label = $aat->get_entity_display_name($etype, $eid);
                                if ($label === '') $label = strtoupper($eid);

                                $internal_url = $aat->build_entity_url_from_id($eid);
                                $imdb_url = $show_imdb ? $aat->build_imdb_url($eid) : '';
                                $review_url = '';

                                if ($show_review_links && $etype === 'title' && preg_match('/^tt\\d+$/', $eid)) {
                                    $review_ids = $aat->get_review_ids_for_title_id($eid, 1);
                                    if (!empty($review_ids)) $review_url = get_permalink(intval($review_ids[0]));
                                }

                                $poster_html = '';
                                if ($show_posters && $etype === 'title' && preg_match('/^tt\\d+$/', $eid)) {
                                    $poster_html = $aat->get_poster_img_html_for_title($eid, array(72, 108), array('class' => 'aat-tracker-poster-img'));
                                }
                            ?>
                                <div class="aat-tracker-pick">
                                    <?php if ($poster_html) : ?>
                                        <div class="aat-tracker-poster"><?php echo $poster_html; ?></div>
                                    <?php else : ?>
                                        <div class="aat-tracker-poster aat-tracker-poster-placeholder">
                                            <span><?php echo esc_html($etype === 'name' ? '👤' : '🎞️'); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="aat-tracker-pick-body">
                                        <div class="aat-tracker-pick-top">
                                            <?php if ($internal_url) : ?>
                                                <a class="aat-tracker-pick-title" href="<?php echo esc_url($internal_url); ?>">
                                                    <?php echo esc_html($label); ?>
                                                </a>
                                            <?php else : ?>
                                                <span class="aat-tracker-pick-title"><?php echo esc_html($label); ?></span>
                                            <?php endif; ?>

                                            <?php if ($tier !== 'prediction' && $rank > 1) : ?>
                                                <span class="aat-tracker-rank">#<?php echo esc_html($rank); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="aat-tracker-pick-links">
                                            <?php if ($review_url) : ?>
                                                <a class="aat-review-chip" href="<?php echo esc_url($review_url); ?>"><?php echo esc_html__('Read Review', 'academy-awards-table'); ?></a>
                                            <?php endif; ?>

                                            <?php if ($imdb_url) : ?>
                                                <a class="aat-tracker-imdb" href="<?php echo esc_url($imdb_url); ?>" target="_blank" rel="noopener">
                                                    <?php echo esc_html__('IMDb', 'academy-awards-table'); ?> <span aria-hidden="true">↗</span>
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($note !== '') : ?>
                                            <p class="aat-tracker-note"><?php echo esc_html($note); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="aat-footer">
        <p class="aat-footer-text">
            <?php echo esc_html__('A bespoke Lunara Film feature. Tracker entries are editorial and update continuously throughout the season.', 'academy-awards-table'); ?>
        </p>
        <p class="aat-footer-links">
            <a href="<?php echo esc_url($aat->get_database_url()); ?>"><?php echo esc_html__('Open the full Oscar Ledger', 'academy-awards-table'); ?></a>
        </p>
    </div>
</div>
