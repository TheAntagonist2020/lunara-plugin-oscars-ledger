<?php
/**
 * Academy Awards Table - Frontend Display Template
 * This template renders the interactive awards table or, on the main database page,
 * a lightweight landing view that defers the heavy explorer until requested.
 */

if (!defined('ABSPATH')) {
    exit;
}

$layout = isset($atts['layout']) ? (string) $atts['layout'] : 'full';
$layout = in_array($layout, array('full', 'embedded'), true) ? $layout : 'full';

$autoload_attr = isset($atts['autoload']) ? strtolower(trim((string) $atts['autoload'])) : '';
$table_view_requested = isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'table';
$autoload_table = ($layout === 'embedded') || $table_view_requested || in_array($autoload_attr, array('true', '1', 'yes'), true);

$aat_instance = Academy_Awards_Table::get_instance();
global $wpdb;
$aat_table = $wpdb->prefix . 'academy_awards';
$aat_ceremony_count = intval($wpdb->get_var("SELECT COUNT(DISTINCT ceremony) FROM $aat_table"));
$aat_min_ceremony = intval($wpdb->get_var("SELECT MIN(ceremony) FROM $aat_table"));
$aat_max_ceremony = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $aat_table"));
$aat_record_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $aat_table"));
$aat_winner_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $aat_table WHERE winner = 1"));
$aat_category_count = intval($wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $aat_table WHERE canonical_category != ''"));
$aat_span = '';
if ($aat_min_ceremony > 0 && $aat_max_ceremony > 0) {
    $first_year = $aat_instance->get_ceremony_year($aat_min_ceremony);
    $last_year = $aat_instance->get_ceremony_year($aat_max_ceremony);
    if ($first_year && $last_year) {
        $aat_span = $first_year . '&ndash;' . $last_year;
    }
}
$aat_get_visual_package = function($title_id, $size = 'medium_large') use ($aat_instance) {
    $title_id = trim((string) $title_id);
    if ($title_id === '' || !method_exists($aat_instance, 'get_title_visual_package')) {
        return array();
    }

    $visual = $aat_instance->get_title_visual_package($title_id, $size);

    return is_array($visual) ? $visual : array();
};

$aat_get_curated_winner_photo_map = function() {
    $map = array();

    if (function_exists('lunara_get_oscars_winner_photo_map')) {
        $map = (array) lunara_get_oscars_winner_photo_map();
    }

    if (empty($map)) {
        $map = array(
            'BEST PICTURE'                  => 30253,
            'DIRECTING'                     => 30249,
            'ACTOR IN A LEADING ROLE'       => 30247,
            'ACTRESS IN A LEADING ROLE'     => 30251,
            'ACTOR IN A SUPPORTING ROLE'    => 30255,
            'ACTRESS IN A SUPPORTING ROLE'  => 30256,
            'WRITING (Original Screenplay)' => 30252,
            'WRITING (Adapted Screenplay)'  => 28520,
            'CINEMATOGRAPHY'                => 30248,
            'FILM EDITING'                  => 30250,
            'MUSIC (Original Score)'        => 30254,
        );
    }

    $normalized = array();
    foreach ($map as $category => $attachment_id) {
        $normalized[strtoupper(trim((string) $category))] = absint($attachment_id);
    }

    return $normalized;
};

$aat_get_curated_winner_visual = function($entry, $size = 'large') use ($aat_get_curated_winner_photo_map) {
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    if ($category === '') {
        return array();
    }

    $map = $aat_get_curated_winner_photo_map();
    $attachment_id = isset($map[$category]) ? absint($map[$category]) : 0;
    if ($attachment_id <= 0) {
        return array();
    }

    $photo_url = wp_get_attachment_image_url($attachment_id, $size);
    if (!$photo_url) {
        return array();
    }

    $photo_alt = '';
    if ($category === 'BEST PICTURE') {
        $photo_alt = trim((string) ($entry['film'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['name'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['film'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['category_label'] ?? $category));
    }

    return array(
        'poster_url'   => $photo_url,
        'backdrop_url' => $photo_url,
        'poster_html'  => sprintf(
            '<img class="aat-winner-circle-photo" src="%s" alt="%s" loading="lazy" decoding="async" />',
            esc_url($photo_url),
            esc_attr($photo_alt)
        ),
    );
};

$aat_get_card_backdrop_style = function($poster_url = '', $backdrop_url = '') {
    $poster_url = trim((string) $poster_url);
    $backdrop_url = trim((string) $backdrop_url);
    $image_url = $poster_url !== '' ? $poster_url : $backdrop_url;

    if ($image_url === '') {
        return '';
    }

    return "background-image: linear-gradient(145deg, rgba(3,10,22,.54), rgba(3,10,22,.86) 40%, rgba(3,10,22,.96)), url('" . esc_url($image_url) . "'); background-size: cover; background-position: center;";
};

$aat_render_text_link = function($label, $url = '', $class = '') {
    $label = trim((string) $label);
    $url = trim((string) $url);
    $class_attr = trim((string) $class);

    if ($label === '') {
        return '';
    }

    if ($url !== '') {
        return '<a class="' . esc_attr($class_attr) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    return '<span class="' . esc_attr($class_attr) . '">' . esc_html($label) . '</span>';
};

$aat_build_winner_actions = function($entry, $category_url = '', $ceremony_url = '') {
    $entry = is_array($entry) ? $entry : array();
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    $film_url = trim((string) ($entry['film_url'] ?? ''));
    $person_url = trim((string) ($entry['person_url'] ?? ''));
    $person_label = trim((string) ($entry['person_label'] ?? ''));
    $primary_url = trim((string) ($entry['primary_url'] ?? ''));
    $secondary_url = trim((string) ($entry['secondary_url'] ?? ''));
    $primary_label = trim((string) ($entry['primary_label'] ?? ''));
    $secondary_label = trim((string) ($entry['secondary_label'] ?? ''));
    $category_url = trim((string) $category_url);
    $ceremony_url = trim((string) $ceremony_url);

    $visible_urls = array_values(array_unique(array_filter(array_map('trim', array(
        $category_url,
        $primary_url,
        $secondary_url,
    )))));

    $resolve_label = function($url, $value_label = '') use ($category, $category_url, $ceremony_url, $film_url, $person_url, $person_label, $primary_url, $secondary_url) {
        $url = trim((string) $url);
        $value_label = trim((string) $value_label);

        if ($url === '') {
            return array('label' => '', 'kind' => '');
        }

        if ($category_url !== '' && $url === $category_url) {
            return array('label' => __('Category', 'academy-awards-table'), 'kind' => 'category');
        }

        if ($ceremony_url !== '' && $url === $ceremony_url) {
            return array('label' => __('Ceremony', 'academy-awards-table'), 'kind' => 'ceremony');
        }

        if ($film_url !== '' && $url === $film_url) {
            return array('label' => __('Film', 'academy-awards-table'), 'kind' => 'film');
        }

        if ($person_url !== '' && $url === $person_url) {
            if ($category === 'BEST PICTURE') {
                return array('label' => __('Producer', 'academy-awards-table'), 'kind' => 'producer');
            }

            if ($primary_url === $film_url && $secondary_url === $person_url) {
                return array('label' => __('Winner', 'academy-awards-table'), 'kind' => 'winner');
            }

            if ($value_label !== '' && $person_label !== '' && $value_label === $person_label) {
                return array('label' => __('Person', 'academy-awards-table'), 'kind' => 'person');
            }

            return array('label' => __('Winner', 'academy-awards-table'), 'kind' => 'winner');
        }

        return array('label' => __('Profile', 'academy-awards-table'), 'kind' => 'profile');
    };

    $actions = array();
    $add_action = function($label, $url, $kind = '') use (&$actions, $visible_urls) {
        $label = trim((string) $label);
        $url = trim((string) $url);
        $kind = trim((string) $kind);
        if ($label === '' || $url === '') {
            return;
        }

        if (in_array($url, $visible_urls, true)) {
            return;
        }

        foreach ($actions as $existing_action) {
            if ((string) $existing_action['url'] === $url) {
                return;
            }
        }

        $actions[] = array(
            'label' => $label,
            'url'   => $url,
            'kind'  => $kind,
        );
    };

    $primary_action = $resolve_label($primary_url, $primary_label);
    $secondary_action = $resolve_label($secondary_url, $secondary_label);
    $ceremony_action = $resolve_label($ceremony_url);
    $category_action = $resolve_label($category_url);

    $add_action($primary_action['label'], $primary_url, $primary_action['kind']);
    $add_action($secondary_action['label'], $secondary_url, $secondary_action['kind']);
    $add_action($category_action['label'], $category_url, $category_action['kind']);
    $add_action($ceremony_action['label'], $ceremony_url, $ceremony_action['kind']);

    return $actions;
};

$aat_rollup = method_exists($aat_instance, 'get_ceremony_rollup') ? $aat_instance->get_ceremony_rollup($aat_max_ceremony) : array();
$aat_best_picture = !empty($aat_rollup['best_picture']) ? $aat_rollup['best_picture'] : array();
$aat_top_titles = method_exists($aat_instance, 'get_ceremony_title_highlights') ? $aat_instance->get_ceremony_title_highlights($aat_max_ceremony, 6) : array();
$aat_winner_rows = !empty($aat_rollup['winner_rows']) && is_array($aat_rollup['winner_rows']) ? array_slice($aat_rollup['winner_rows'], 0, 6) : array();
$aat_table_view_url = add_query_arg('view', 'table');
$aat_latest_ceremony_url = $aat_max_ceremony > 0 ? $aat_instance->get_ceremony_url($aat_max_ceremony) : '';
$aat_best_picture_visual = !empty($aat_best_picture['film_id']) ? $aat_get_visual_package((string) $aat_best_picture['film_id'], 'large') : array();
$aat_best_picture_backdrop_style = $aat_get_card_backdrop_style($aat_best_picture_visual['poster_url'] ?? '', $aat_best_picture_visual['backdrop_url'] ?? '');
$aat_best_picture_url = !empty($aat_best_picture['film_id']) ? $aat_instance->get_entity_url((string) $aat_best_picture['film_id']) : '';
$aat_table_latest_rows = array();
if (!empty($aat_winner_rows)) {
    foreach ($aat_winner_rows as $winner_entry) {
        if (method_exists($aat_instance, 'enrich_winner_entry_links')) {
            $winner_entry = $aat_instance->enrich_winner_entry_links($winner_entry);
        }
        if (method_exists($aat_instance, 'format_category_display') && empty($winner_entry['category_label']) && !empty($winner_entry['canonical_category'])) {
            $winner_entry['category_label'] = $aat_instance->format_category_display((string) $winner_entry['canonical_category']);
        }
        if (function_exists('lunara_home_winner_primary_label')) {
            $winner_entry['primary_label'] = lunara_home_winner_primary_label($winner_entry);
        }
        if (function_exists('lunara_home_winner_secondary_label')) {
            $winner_entry['secondary_label'] = lunara_home_winner_secondary_label($winner_entry);
        }
        if (function_exists('lunara_enrich_oscars_entry_links')) {
            $winner_entry = lunara_enrich_oscars_entry_links($winner_entry, $aat_instance);
        }
        $aat_table_latest_rows[] = $winner_entry;
    }
}
?>

<div
    class="aat-container<?php echo ($layout === 'embedded') ? ' aat-embedded' : ''; ?><?php echo (!$autoload_table && $layout === 'full') ? ' aat-database-landing-shell' : ''; ?>"
    data-initial-category="<?php echo esc_attr($atts['category'] ?? ''); ?>"
    data-initial-class="<?php echo esc_attr($atts['class'] ?? ''); ?>"
    data-initial-year="<?php echo esc_attr($atts['year'] ?? ''); ?>"
    data-initial-ceremony="<?php echo esc_attr($atts['ceremony'] ?? ''); ?>"
    data-initial-winners-only="<?php echo esc_attr($atts['winners_only'] ?? 'false'); ?>"
>
    <?php if ($layout === 'full' && !$autoload_table) : ?>
        <div class="aat-header aat-database-landing-header aat-ledger-command">
            <div class="aat-ledger-command-symbol">
                <img
                    class="aat-oscar-icon"
                    src="<?php echo esc_url(AAT_PLUGIN_URL . 'assets/img/oscar.png'); ?>"
                    alt="<?php echo esc_attr__('Oscar statuette', 'academy-awards-table'); ?>"
                    loading="lazy"
                />
            </div>
            <div class="aat-ledger-command-copy">
                <p class="aat-hub-kicker"><?php esc_html_e('The Living Archive', 'academy-awards-table'); ?></p>
                <h2><?php esc_html_e('The Lunara Oscar Ledger', 'academy-awards-table'); ?></h2>
                <p class="aat-subtitle"><?php echo esc_html(sprintf(__('A fast gateway into %1$s records across %2$s ceremonies, built for browsing before the heavy table ever loads.', 'academy-awards-table'), number_format_i18n($aat_record_count), number_format_i18n($aat_ceremony_count))); ?></p>
            </div>
            <nav class="aat-ledger-header-actions" aria-label="<?php echo esc_attr__('Oscar Ledger shortcuts', 'academy-awards-table'); ?>">
                <a class="aat-ledger-header-action is-primary" href="<?php echo esc_url($aat_table_view_url); ?>">
                    <strong><?php esc_html_e('Data Explorer', 'academy-awards-table'); ?></strong>
                    <span><?php esc_html_e('Search every row', 'academy-awards-table'); ?></span>
                </a>
                <a class="aat-ledger-header-action" href="<?php echo esc_url($aat_instance->get_ceremonies_index_url()); ?>">
                    <strong><?php esc_html_e('Ceremonies', 'academy-awards-table'); ?></strong>
                    <span><?php esc_html_e('Move by year', 'academy-awards-table'); ?></span>
                </a>
                <a class="aat-ledger-header-action" href="<?php echo esc_url($aat_instance->get_categories_index_url()); ?>">
                    <strong><?php esc_html_e('Categories', 'academy-awards-table'); ?></strong>
                    <span><?php esc_html_e('Move by race', 'academy-awards-table'); ?></span>
                </a>
            </nav>
        </div>

        <div class="aat-hub-metric-grid aat-database-landing-metrics">
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Records', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value"><?php echo esc_html(number_format_i18n($aat_record_count)); ?></strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('Nominee and winner rows structured for browsing, linking, and search.', 'academy-awards-table'); ?></p>
            </article>
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Winners', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value"><?php echo esc_html(number_format_i18n($aat_winner_count)); ?></strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('Every winner row preserved as part of the living ledger.', 'academy-awards-table'); ?></p>
            </article>
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Categories', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value"><?php echo esc_html(number_format_i18n($aat_category_count)); ?></strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('Normalized categories that connect films, people, companies, and ceremonies.', 'academy-awards-table'); ?></p>
            </article>
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Span', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value"><?php echo $aat_span ? wp_kses_post($aat_span) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('A lightweight front door into the full archive.', 'academy-awards-table'); ?></p>
            </article>
        </div>

        <?php if (!empty($aat_rollup)) : ?>
            <section class="aat-hub-section aat-ceremony-marquee">
                <div class="aat-ceremony-marquee-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Latest Ceremony', 'academy-awards-table'); ?></p>
                    <h2>
                        <?php
                            echo esc_html(
                                sprintf(
                                    __('%1$s Academy Awards', 'academy-awards-table'),
                                    $aat_instance->ordinal($aat_max_ceremony)
                                )
                            );
                        ?>
                    </h2>
                    <p class="aat-hub-copy">
                        <?php if (!empty($aat_best_picture['film'])) : ?>
                            <?php echo wp_kses_post(sprintf(__('Best Picture: %s.', 'academy-awards-table'), $aat_render_text_link((string) $aat_best_picture['film'], $aat_best_picture_url, 'aat-hub-inline-link'))); ?>
                        <?php endif; ?>
                        <?php if (!empty($aat_rollup['most_wins']['film']) && !empty($aat_rollup['most_wins']['wins'])) : ?>
                            <?php
                            $aat_most_wins_url = !empty($aat_rollup['most_wins']['film_id']) ? $aat_instance->get_entity_url((string) $aat_rollup['most_wins']['film_id']) : '';
                            echo wp_kses_post(sprintf(__('The biggest winner was %1$s with %2$s win%3$s.', 'academy-awards-table'), $aat_render_text_link((string) $aat_rollup['most_wins']['film'], $aat_most_wins_url, 'aat-hub-inline-link'), number_format_i18n(intval($aat_rollup['most_wins']['wins'])), intval($aat_rollup['most_wins']['wins']) === 1 ? '' : 's'));
                            ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="aat-ceremony-marquee-stack">
                    <a class="aat-hub-chip aat-hub-chip-rich<?php echo $aat_best_picture_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>" href="<?php echo esc_url($aat_latest_ceremony_url); ?>"<?php if ($aat_best_picture_backdrop_style !== '') : ?> style="<?php echo esc_attr($aat_best_picture_backdrop_style); ?>"<?php endif; ?>>
                        <strong><?php esc_html_e('Open Ceremony Page', 'academy-awards-table'); ?></strong>
                        <span><?php echo esc_html($aat_instance->get_ceremony_year($aat_max_ceremony)); ?></span>
                    </a>
                    <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($aat_table_view_url); ?>">
                        <strong><?php esc_html_e('Launch Data Explorer', 'academy-awards-table'); ?></strong>
                        <span><?php esc_html_e('Sort, search, and filter the raw ledger', 'academy-awards-table'); ?></span>
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($aat_top_titles)) : ?>
            <div class="aat-hub-section aat-ceremony-gallery-section">
                <h2><?php esc_html_e('Poster Highlights', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php esc_html_e('Start with the films people actually recognize, then move into the deeper record from there.', 'academy-awards-table'); ?></p>
                <div class="aat-filmography-grid aat-hub-film-grid">
                    <?php foreach ($aat_top_titles as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        if (!$fid) { continue; }
                        $visual = $aat_get_visual_package($fid, 'medium_large');
                        $film_label = !empty($entry['film']) ? (string) $entry['film'] : $aat_instance->lookup_title_label($fid);
                        $film_url = $aat_instance->get_entity_url($fid);
                        $aat_title_backdrop_style = $aat_get_card_backdrop_style($visual['poster_url'] ?? '', $visual['backdrop_url'] ?? '');
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?><?php echo $aat_title_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($aat_title_backdrop_style !== '') : ?> style="<?php echo esc_attr($aat_title_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $aat_table_view_url); ?>">
                                <div class="aat-filmography-poster-wrap">
                                    <?php if (!empty($visual['poster_html'])) : ?>
                                        <?php echo $visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($visual['poster_url'])) : ?>
                                        <img class="aat-filmography-poster" src="<?php echo esc_url($visual['poster_url']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($visual['card_fallback_html'])) : ?>
                                        <?php echo $visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge"><?php esc_html_e('Winner', 'academy-awards-table'); ?></span><?php endif; ?>
                                </div>
                                <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                                <?php if (!empty($entry['year'])) : ?><p class="aat-filmography-meta"><?php echo esc_html($entry['year']); ?></p><?php endif; ?>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($aat_table_latest_rows)) : ?>
            <div class="aat-hub-section aat-winner-circle-section is-hero-latest is-marquee-latest">
                <h2><?php esc_html_e('Latest Winner Circle', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php esc_html_e('A quick mobile-friendly read of the latest top-line winners, without booting the full explorer.', 'academy-awards-table'); ?></p>
                <div class="aat-winner-circle-grid is-hero-latest is-marquee-latest">
                    <?php foreach ($aat_table_latest_rows as $winner_entry) :
                        $primary_label = trim((string) ($winner_entry['primary_label'] ?? ($winner_entry['name'] ?? '')));
                        if ($primary_label === '') {
                            $primary_label = trim((string) ($winner_entry['film'] ?? ''));
                        }
                        $secondary_bits = array();
                        if (!empty($winner_entry['film']) && $winner_entry['film'] !== $primary_label) {
                            $secondary_bits[] = $winner_entry['film'];
                        }
                        if (!empty($winner_entry['detail']) && $winner_entry['detail'] !== $primary_label) {
                            $secondary_bits[] = $winner_entry['detail'];
                        }
                        $secondary_label = implode(' · ', array_slice($secondary_bits, 0, 2));
                        $category_url = $winner_entry['category_url'] ?? $aat_instance->get_category_url($winner_entry['canonical_category'] ?? '');
                        $winner_visual = $aat_get_curated_winner_visual($winner_entry, 'large');
                        if (empty($winner_visual) && !empty($winner_entry['film_id'])) {
                            $winner_visual = $aat_get_visual_package((string) $winner_entry['film_id'], 'medium_large');
                        }
                        $winner_media_url = '';
                        if (!empty($winner_entry['primary_url'])) {
                            $winner_media_url = (string) $winner_entry['primary_url'];
                        } elseif (!empty($winner_entry['film_url'])) {
                            $winner_media_url = (string) $winner_entry['film_url'];
                        } elseif (!empty($winner_entry['secondary_url'])) {
                            $winner_media_url = (string) $winner_entry['secondary_url'];
                        } elseif (!empty($category_url)) {
                            $winner_media_url = (string) $category_url;
                        }
                        $winner_actions = $aat_build_winner_actions($winner_entry, (string) $category_url, (string) $aat_latest_ceremony_url);
                    ?>
                        <article class="aat-winner-circle-card is-hero-latest is-marquee-latest<?php echo !empty($winner_visual['poster_url']) ? ' has-hero-media' : ''; ?>">
                            <div class="aat-winner-circle-top">
                                <?php if ($category_url) : ?>
                                    <a class="aat-winner-circle-category" href="<?php echo esc_url($category_url); ?>"><?php echo esc_html($winner_entry['category_label']); ?></a>
                                <?php else : ?>
                                    <span class="aat-winner-circle-category"><?php echo esc_html($winner_entry['category_label']); ?></span>
                                <?php endif; ?>
                                <span class="aat-winner-badge"><?php esc_html_e('Winner', 'academy-awards-table'); ?></span>
                            </div>
                            <?php if (!empty($winner_visual['poster_url'])) : ?>
                                <?php if ($winner_media_url !== '') : ?>
                                    <a class="aat-winner-circle-media" href="<?php echo esc_url($winner_media_url); ?>">
                                        <?php if (!empty($winner_visual['poster_html'])) : ?>
                                            <?php echo $winner_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php else : ?>
                                            <img class="aat-winner-circle-photo" src="<?php echo esc_url($winner_visual['poster_url']); ?>" alt="<?php echo esc_attr($primary_label); ?>" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </a>
                                <?php else : ?>
                                    <div class="aat-winner-circle-media">
                                        <?php if (!empty($winner_visual['poster_html'])) : ?>
                                            <?php echo $winner_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php else : ?>
                                            <img class="aat-winner-circle-photo" src="<?php echo esc_url($winner_visual['poster_url']); ?>" alt="<?php echo esc_attr($primary_label); ?>" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <h3 class="aat-winner-circle-title">
                                <?php if (!empty($winner_entry['primary_url'])) : ?>
                                    <a href="<?php echo esc_url($winner_entry['primary_url']); ?>"><?php echo esc_html($primary_label); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($primary_label); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($secondary_label !== '') : ?>
                                <p class="aat-winner-circle-meta">
                                    <?php if (!empty($winner_entry['secondary_url'])) : ?>
                                        <a href="<?php echo esc_url($winner_entry['secondary_url']); ?>"><?php echo esc_html($secondary_label); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($secondary_label); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($winner_actions)) : ?>
                                <div class="aat-winner-circle-actions">
                                    <?php foreach ($winner_actions as $winner_action) : ?>
                                        <a class="aat-hub-card-action aat-winner-circle-action<?php echo !empty($winner_action['kind']) ? ' is-kind-' . sanitize_html_class((string) $winner_action['kind']) : ''; ?>" href="<?php echo esc_url($winner_action['url']); ?>"><?php echo esc_html($winner_action['label']); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="aat-footer aat-database-landing-footer">
            <p class="aat-footer-line">
                <?php esc_html_e('Want the full sortable table?', 'academy-awards-table'); ?>
                <a href="<?php echo esc_url($aat_table_view_url); ?>"><?php esc_html_e('Open Data Explorer', 'academy-awards-table'); ?></a>
                <span class="aat-footer-sep">&bull;</span>
                <?php esc_html_e('On phones, the poster-first view is now the default for speed and readability.', 'academy-awards-table'); ?>
            </p>
        </div>
    <?php else : ?>
        <?php if ($layout === 'full') : ?>
            <?php $poster_view_url = remove_query_arg('view'); ?>
            <div class="aat-explorer-shell aat-explorer-callout<?php echo $aat_best_picture_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($aat_best_picture_backdrop_style !== '') : ?> style="<?php echo esc_attr($aat_best_picture_backdrop_style); ?>"<?php endif; ?>>
                <div class="aat-explorer-copy">
                    <p class="aat-hub-kicker"><?php esc_html_e('Research Mode', 'academy-awards-table'); ?></p>
                    <h2><?php esc_html_e('Data Explorer', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy"><?php esc_html_e('Use the full table when you want raw row-level research. For faster browsing and better mobile reading, switch back to the poster-first ledger view.', 'academy-awards-table'); ?></p>
                    <?php if (!empty($aat_best_picture['film'])) : ?>
                        <p class="aat-explorer-context"><?php echo wp_kses_post(sprintf(__('Current Best Picture anchor: %1$s from the %2$s ceremony.', 'academy-awards-table'), $aat_render_text_link((string) $aat_best_picture['film'], $aat_best_picture_url, 'aat-hub-inline-link'), $aat_render_text_link((string) $aat_instance->ordinal($aat_max_ceremony), $aat_latest_ceremony_url, 'aat-hub-inline-link'))); ?></p>
                    <?php endif; ?>
                </div>
                <div class="aat-hub-actions aat-view-toggle">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($poster_view_url); ?>"><?php esc_html_e('Poster View', 'academy-awards-table'); ?></a>
                    <?php if ($aat_latest_ceremony_url) : ?>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat_latest_ceremony_url); ?>"><?php esc_html_e('Latest Ceremony', 'academy-awards-table'); ?></a>
                    <?php endif; ?>
                    <span class="aat-btn aat-btn-primary is-active"><?php esc_html_e('Data Explorer', 'academy-awards-table'); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($layout === 'full') : ?>
        <?php
            $aat_header_categories_url = method_exists($aat_instance, 'get_categories_index_url') ? $aat_instance->get_categories_index_url() : '';
        ?>
        <div class="aat-header aat-ledger-command">
            <img
                class="aat-oscar-icon"
                src="<?php echo esc_url(AAT_PLUGIN_URL . 'assets/img/oscar.png'); ?>"
                alt="<?php echo esc_attr__('Oscar statuette', 'academy-awards-table'); ?>"
                loading="lazy"
            />
            <div class="aat-ledger-command-copy">
                <p class="aat-hub-kicker"><?php esc_html_e('Full Archive', 'academy-awards-table'); ?></p>
                <h2><?php esc_html_e('The Lunara Oscar Ledger', 'academy-awards-table'); ?></h2>
                <p class="aat-subtitle"><?php echo wp_kses_post(sprintf('Every nominee & winner in our dataset (%s)', $aat_span ? $aat_span : 'through 2024')); ?></p>
            </div>
            <nav class="aat-ledger-header-actions" aria-label="<?php echo esc_attr__('Oscar Ledger shortcuts', 'academy-awards-table'); ?>">
                <a class="aat-ledger-header-action" href="<?php echo esc_url($aat_table_view_url); ?>">
                    <strong><?php esc_html_e('Open Data Explorer', 'academy-awards-table'); ?></strong>
                    <span><?php esc_html_e('Search the raw ledger', 'academy-awards-table'); ?></span>
                </a>
                <?php if ($aat_latest_ceremony_url) : ?>
                    <a class="aat-ledger-header-action" href="<?php echo esc_url($aat_latest_ceremony_url); ?>">
                        <strong><?php esc_html_e('Latest Ceremony', 'academy-awards-table'); ?></strong>
                        <span><?php echo esc_html($aat_instance->ordinal($aat_max_ceremony)); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($aat_header_categories_url) : ?>
                    <a class="aat-ledger-header-action" href="<?php echo esc_url($aat_header_categories_url); ?>">
                        <strong><?php esc_html_e('Browse Categories', 'academy-awards-table'); ?></strong>
                        <span><?php esc_html_e('Move by race', 'academy-awards-table'); ?></span>
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <div class="aat-stats-bar">
            <div class="aat-stat">
                <span class="aat-stat-number" id="aat-stat-total">&mdash;</span>
                <span class="aat-stat-label"><?php esc_html_e('Total Nominations', 'academy-awards-table'); ?></span>
            </div>
            <div class="aat-stat">
                <span class="aat-stat-number" id="aat-stat-winners">&mdash;</span>
                <span class="aat-stat-label"><?php esc_html_e('Winners', 'academy-awards-table'); ?></span>
            </div>
            <div class="aat-stat">
                <span class="aat-stat-number" id="aat-stat-categories">&mdash;</span>
                <span class="aat-stat-label"><?php esc_html_e('Categories', 'academy-awards-table'); ?></span>
            </div>
            <div class="aat-stat">
                <span class="aat-stat-number" id="aat-stat-ceremonies">&mdash;</span>
                <span class="aat-stat-label"><?php esc_html_e('Ceremonies', 'academy-awards-table'); ?></span>
            </div>
        </div>

        <div class="aat-quick-filters">
            <!-- Quick filters populated by JavaScript -->
        </div>

        <div class="aat-hub-metric-grid aat-explorer-signal-grid">
            <article class="aat-hub-metric-card<?php echo $aat_best_picture_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($aat_best_picture_backdrop_style !== '') : ?> style="<?php echo esc_attr($aat_best_picture_backdrop_style); ?>"<?php endif; ?>>
                <span class="aat-hub-metric-label"><?php esc_html_e('Latest Best Picture', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value">
                    <?php if (!empty($aat_best_picture['film'])) : ?>
                        <?php echo wp_kses_post($aat_render_text_link((string) $aat_best_picture['film'], $aat_best_picture_url, 'aat-hub-inline-link')); ?>
                    <?php else : ?>
                        <?php echo esc_html__('Updating', 'academy-awards-table'); ?>
                    <?php endif; ?>
                </strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('Jump from the raw ledger back into the film profile that currently anchors the Oscar conversation.', 'academy-awards-table'); ?></p>
            </article>
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Latest Ceremony', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value">
                    <?php if ($aat_latest_ceremony_url) : ?>
                        <a class="aat-hub-inline-link" href="<?php echo esc_url($aat_latest_ceremony_url); ?>"><?php echo esc_html($aat_instance->ordinal($aat_max_ceremony)); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($aat_instance->ordinal($aat_max_ceremony)); ?>
                    <?php endif; ?>
                </strong>
                <p class="aat-hub-metric-copy"><?php echo esc_html(sprintf(__('Browse the %s ceremony page if you want the richer card system instead of the raw explorer.', 'academy-awards-table'), $aat_instance->get_ceremony_year($aat_max_ceremony))); ?></p>
            </article>
            <article class="aat-hub-metric-card">
                <span class="aat-hub-metric-label"><?php esc_html_e('Linked Winner Circle', 'academy-awards-table'); ?></span>
                <strong class="aat-hub-metric-value"><?php echo esc_html(number_format_i18n(count($aat_table_latest_rows))); ?></strong>
                <p class="aat-hub-metric-copy"><?php esc_html_e('The latest winner surfaces now connect category, person, and film routes before you ever touch the table.', 'academy-awards-table'); ?></p>
            </article>
        </div>
        <?php endif; ?>

        <details class="aat-filters-disclosure" open>
            <summary><?php esc_html_e('Filters', 'academy-awards-table'); ?></summary>
            <div class="aat-filters">
                <div class="aat-filter-group">
                    <label for="aat-filter-category"><?php esc_html_e('Category', 'academy-awards-table'); ?></label>
                    <select id="aat-filter-category">
                        <option value=""><?php esc_html_e('All Categories', 'academy-awards-table'); ?></option>
                    </select>
                </div>

                <div class="aat-filter-group">
                    <label for="aat-filter-class"><?php esc_html_e('Type', 'academy-awards-table'); ?></label>
                    <select id="aat-filter-class">
                        <option value=""><?php esc_html_e('All Types', 'academy-awards-table'); ?></option>
                    </select>
                </div>

                <div class="aat-filter-group">
                    <label for="aat-filter-year"><?php esc_html_e('Year', 'academy-awards-table'); ?></label>
                    <select id="aat-filter-year">
                        <option value=""><?php esc_html_e('All Years', 'academy-awards-table'); ?></option>
                    </select>
                </div>

                <div class="aat-filter-group">
                    <label for="aat-filter-ceremony"><?php esc_html_e('Ceremony', 'academy-awards-table'); ?></label>
                    <select id="aat-filter-ceremony">
                        <option value=""><?php esc_html_e('All Ceremonies', 'academy-awards-table'); ?></option>
                    </select>
                </div>

                <div class="aat-filter-group aat-checkbox-group">
                    <input type="checkbox" id="aat-filter-winners">
                    <label for="aat-filter-winners"><?php esc_html_e('Winners Only', 'academy-awards-table'); ?></label>
                </div>

                <div class="aat-filter-group aat-filter-actions">
                    <button type="button" class="aat-btn aat-btn-secondary aat-btn-reset">
                        <?php esc_html_e('Reset', 'academy-awards-table'); ?>
                    </button>
                </div>
            </div>
        </details>

        <div class="aat-table-wrapper">
            <div class="aat-loading">
                <div class="aat-loading-spinner"></div>
                <span class="aat-loading-text"><?php esc_html_e('Loading Academy Awards data...', 'academy-awards-table'); ?></span>
            </div>
        </div>

        <div class="aat-footer">
            <p class="aat-footer-line">
                <?php esc_html_e('Data sourced from the Academy of Motion Picture Arts and Sciences.', 'academy-awards-table'); ?>
                <span class="aat-footer-sep">&bull;</span>
                <?php esc_html_e('Structured, normalized, and maintained by Lunara Film (Dalton Johnson).', 'academy-awards-table'); ?>
            </p>
            <p class="aat-footer-line">
                <span class="aat-footer-sep"><?php echo esc_html(number_format_i18n($aat_ceremony_count)); ?> ceremonies<?php if ($aat_span) : ?> (<?php echo wp_kses_post($aat_span); ?>)<?php endif; ?></span>
                <span class="aat-footer-sep">&bull;</span>
                <?php esc_html_e('Click nominees and films to open Lunara profiles; IMDb links are provided for verification.', 'academy-awards-table'); ?>
                <span class="aat-footer-mobile-hint"><?php esc_html_e('On mobile, tap the + icon to view full details.', 'academy-awards-table'); ?></span>
            </p>
            <p class="aat-footer-links">
                <a href="<?php echo esc_url(Academy_Awards_Table::get_instance()->get_ceremonies_index_url()); ?>"><?php esc_html_e('Ceremonies', 'academy-awards-table'); ?></a>
                <span class="aat-footer-sep">&bull;</span>
                <a href="<?php echo esc_url(Academy_Awards_Table::get_instance()->get_categories_index_url()); ?>"><?php esc_html_e('Categories', 'academy-awards-table'); ?></a>
                <span class="aat-footer-sep">&bull;</span>
                <a href="<?php echo esc_url(Academy_Awards_Table::get_instance()->get_about_url()); ?>"><?php esc_html_e('About the ledger', 'academy-awards-table'); ?></a>
            </p>
        </div>
    <?php endif; ?>
</div>
