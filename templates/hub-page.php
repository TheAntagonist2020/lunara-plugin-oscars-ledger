<?php
/**
 * Academy Awards Table - Hub Page Template
 *
 * Routes:
 *   /{base}/ceremonies/
 *   /{base}/categories/
 *   /{base}/about/
 *   /{base}/ceremony/{N}/
 *   /{base}/category/{slug}/
 */

if (!defined('ABSPATH')) {
    exit;
}

$aat = Academy_Awards_Table::get_instance();
$hub = sanitize_text_field(get_query_var('aat_hub'));
$hub_id = sanitize_text_field(get_query_var('aat_hub_id'));

global $wpdb;
$table_name = $wpdb->prefix . 'academy_awards';

// Common dynamic scope values are supplied by projection-aware helpers.
$hub_stats = $aat->get_hub_page_stats();
$total_records    = intval($hub_stats['total_records'] ?? 0);
$total_winners    = intval($hub_stats['total_winners'] ?? 0);
$total_categories = intval($hub_stats['total_categories'] ?? 0);
$total_ceremonies = intval($hub_stats['total_ceremonies'] ?? 0);
$min_ceremony     = intval($hub_stats['min_ceremony'] ?? 0);
$max_ceremony     = intval($hub_stats['max_ceremony'] ?? 0);
$span = '';
if ($min_ceremony > 0 && $max_ceremony > 0) {
    $first_year = $aat->get_ceremony_year($min_ceremony);
    $last_year = $aat->get_ceremony_year($max_ceremony);
    if ($first_year && $last_year) {
        $span = $first_year . '-' . $last_year;
    }
}

// Helper: mark 404 and show friendly page
$mark_404 = function() {
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
};

$db_url = $aat->get_database_url();
$table_view_requested = ( isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'table' );

$aat_pipe_display = function($value) {
    $parts = array_values(array_filter(array_map('trim', explode('|', (string) $value)), 'strlen'));
    return implode(' | ', $parts);
};

$aat_clean_nominee_label = function($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $patterns = array(
        '/^Written by\s+/i',
        '/^Music and Lyric by\s+/i',
        '/^Music by\s+/i',
        '/^Lyric by\s+/i',
        '/^Produced by\s+/i',
        '/^Directed by\s+/i',
    );

    return trim((string) preg_replace($patterns, '', $value));
};

$aat_join_meta = function($parts) {
    $parts = array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));
    if (empty($parts)) {
        return '';
    }

    $out = array();
    foreach ($parts as $part) {
        $out[] = '<span>' . esc_html($part) . '</span>';
    }

    return implode('<span class="aat-meta-sep" aria-hidden="true">&middot;</span>', $out);
};

$aat_winner_primary = function($entry) use ($aat_pipe_display, $aat_clean_nominee_label) {
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    $film = trim((string) ($entry['film'] ?? ''));
    $name = $aat_clean_nominee_label($entry['name'] ?? '');
    $nominees = $aat_clean_nominee_label($aat_pipe_display($entry['nominees'] ?? ''));

    if (in_array($category, array('BEST PICTURE', 'ANIMATED FEATURE FILM', 'DOCUMENTARY (Feature)', 'INTERNATIONAL FEATURE FILM', 'SHORT FILM (Animated)', 'SHORT FILM (Live Action)'), true) && $film !== '') {
        return $film;
    }

    if ($name !== '') {
        return $name;
    }

    if ($nominees !== '') {
        return $nominees;
    }

    return $film;
};

$aat_winner_secondary = function($entry) use ($aat_pipe_display, $aat_winner_primary, $aat_clean_nominee_label) {
    $primary = $aat_winner_primary($entry);
    $film = trim((string) ($entry['film'] ?? ''));
    $detail = trim((string) ($entry['detail'] ?? ''));
    $nominees = $aat_clean_nominee_label($aat_pipe_display($entry['nominees'] ?? ''));

    if ($film !== '' && $film !== $primary) {
        return $film;
    }

    if ($detail !== '' && $detail !== $primary) {
        return $detail;
    }

    if ($nominees !== '' && $nominees !== $primary) {
        return $nominees;
    }

    return '';
};

$aat_build_entity_url = function($id) use ($aat) {
    $id = strtolower(trim((string) $id));
    return $id !== '' ? $aat->get_entity_url($id) : '';
};

$aat_entity_section_url = function($url, $section = 'oscar-history') {
    $url = trim((string) $url);
    $section = sanitize_title(trim((string) $section, "# \t\n\r\0\x0B"));

    if ($url === '') {
        return '';
    }

    if ($section === '') {
        return $url;
    }

    return preg_replace('/#.*/', '', $url) . '#' . $section;
};

$aat_normalize_comparable_name = function($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }

    $value = strtolower($value);
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);

    return trim((string) $value);
};

$aat_is_department_credit_label = function($value) use ($aat_normalize_comparable_name) {
    $normalized = $aat_normalize_comparable_name($value);
    if ($normalized === '') {
        return false;
    }

    return (bool) preg_match('/\b(?:studio sound department|sound department|sound dept|sound recording department)\b/', $normalized);
};

$aat_entity_url_kind = function($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if (preg_match('~/oscars/company/~i', $path)) {
        return 'company';
    }
    if (preg_match('~/oscars/name/~i', $path)) {
        return 'person';
    }
    if (preg_match('~/oscars/title/~i', $path)) {
        return 'film';
    }

    return '';
};

$aat_person_history_action_meta = function($history_url, $category) use ($aat_entity_url_kind) {
    $category = strtoupper(trim((string) $category));

    if ($aat_entity_url_kind($history_url) === 'company') {
        return array(
            'label' => __('Company History', 'academy-awards-table'),
            'kind'  => 'company-history',
        );
    }

    return array(
        'label' => ($category === 'BEST PICTURE') ? __('Producer History', 'academy-awards-table') : __('Person History', 'academy-awards-table'),
        'kind'  => ($category === 'BEST PICTURE') ? 'producer-history' : 'person-history',
    );
};

$aat_resolve_entry_name_link = function($entry) use ($aat_build_entity_url, $aat_clean_nominee_label, $aat_normalize_comparable_name, $aat_is_department_credit_label) {
    $explicit_name = $aat_clean_nominee_label($entry['name'] ?? '');
    $nominee_value = trim((string) ($entry['nominees'] ?? ''));
    $nominee_ids = trim((string) ($entry['nominee_ids'] ?? ''));
    $nominee_parts = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
        return $aat_clean_nominee_label($part);
    }, explode('|', $nominee_value)), 'strlen'));
    $id_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_ids)), 'strlen'));

    if ($aat_is_department_credit_label($explicit_name) || (count($nominee_parts) === 1 && $aat_is_department_credit_label($nominee_parts[0]))) {
        return array(
            'label' => $explicit_name !== '' ? $explicit_name : (string) ($nominee_parts[0] ?? ''),
            'url' => '',
        );
    }

    if ($explicit_name === '' && count($nominee_parts) === 1 && count($id_parts) === 1) {
        return array(
            'label' => (string) $nominee_parts[0],
            'url' => $aat_build_entity_url($id_parts[0]),
        );
    }

    if ($explicit_name !== '' && count($nominee_parts) === count($id_parts) && !empty($id_parts)) {
        $target = $aat_normalize_comparable_name($explicit_name);
        $matches = array();

        foreach ($nominee_parts as $index => $nominee_part) {
            if ($aat_normalize_comparable_name($nominee_part) === $target && isset($id_parts[$index])) {
                $matches[] = $id_parts[$index];
            }
        }

        if (count($matches) === 1) {
            return array(
                'label' => $explicit_name,
                'url' => $aat_build_entity_url($matches[0]),
            );
        }
    }

    if ($explicit_name !== '' && count($id_parts) === 1) {
        return array(
            'label' => $explicit_name,
            'url' => $aat_build_entity_url($id_parts[0]),
        );
    }

    return array(
        'label' => $explicit_name,
        'url' => '',
    );
};

$aat_enrich_winner_entry_links = function($entry) use ($aat, $aat_winner_primary, $aat_winner_secondary, $aat_resolve_entry_name_link, $aat_build_entity_url, $aat_entity_section_url) {
    $entry = is_array($entry) ? $entry : array();
    $category = trim((string) ($entry['canonical_category'] ?? ''));
    $film = trim((string) ($entry['film'] ?? ''));
    $film_id = strtolower(trim((string) ($entry['film_id'] ?? '')));
    $film_url = '';

    if ($film_id !== '') {
        $film_url = $aat_build_entity_url($film_id);
    }
    if ($film_url === '' && !empty($entry['film_url'])) {
        $film_url = (string) $entry['film_url'];
    }

    $name_link = $aat_resolve_entry_name_link($entry);
    $primary_label = $aat_winner_primary($entry);
    $secondary_label = $aat_winner_secondary($entry);
    $primary_url = '';
    $secondary_url = '';

    if ($film !== '' && $primary_label === $film && $film_url !== '') {
        $primary_url = $film_url;
    } elseif (!empty($name_link['label']) && $primary_label === $name_link['label'] && !empty($name_link['url'])) {
        $primary_url = (string) $name_link['url'];
    }

    if ($film !== '' && $secondary_label === $film && $film_url !== '') {
        $secondary_url = $film_url;
    } elseif (!empty($name_link['label']) && $secondary_label === $name_link['label'] && !empty($name_link['url'])) {
        $secondary_url = (string) $name_link['url'];
    }

    $person_url = !empty($name_link['url']) ? (string) $name_link['url'] : '';

    $entry['category_url'] = $category !== '' ? $aat->get_category_url($category) : '';
    $entry['film_url'] = $film_url;
    $entry['film_history_url'] = $film_url !== '' ? $aat_entity_section_url($film_url, 'oscar-history') : '';
    $entry['person_url'] = $person_url;
    $entry['person_history_url'] = $person_url !== '' ? $aat_entity_section_url($person_url, 'oscar-history') : '';
    $entry['person_label'] = !empty($name_link['label']) ? (string) $name_link['label'] : '';
    $entry['primary_label'] = $primary_label;
    $entry['primary_url'] = $primary_url;
    $entry['secondary_label'] = $secondary_label;
    $entry['secondary_url'] = $secondary_url;

    return $entry;
};

$aat_build_person_link_items = function($entry) use ($aat, $aat_clean_nominee_label, $aat_build_entity_url, $aat_is_department_credit_label) {
    $entry = is_array($entry) ? $entry : array();
    $split_credit_labels = function($value) use ($aat_clean_nominee_label) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $parts = strpos($value, '|') !== false
            ? explode('|', $value)
            : preg_split('/\s*(?:,|\s+and\s+)\s*/i', $value);

        return array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
            return $aat_clean_nominee_label($part);
        }, (array) $parts), 'strlen'));
    };

    $labels = $split_credit_labels($entry['nominees'] ?? '');
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) ($entry['nominee_ids'] ?? ''))), 'strlen'));

    if (empty($labels) && !empty($entry['person_label'])) {
        $labels[] = trim((string) $entry['person_label']);
    } elseif (empty($labels) && !empty($entry['name'])) {
        $labels = $split_credit_labels($entry['name']);
    }

    $items = array();
    $seen = array();

    foreach ($labels as $index => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }

        if ($aat_is_department_credit_label($label)) {
            continue;
        }

        $entity_id = isset($ids[$index]) ? strtolower(trim((string) $ids[$index])) : '';
        $url = '';

        if ($entity_id !== '' && preg_match('/^(nm\d{7,9}|lnm-[a-z0-9-]+)$/i', $entity_id)) {
            $url = $aat_build_entity_url($entity_id);
        }

        if ($url === '' && method_exists($aat, 'get_name_entity_link_by_label')) {
            $resolved = $aat->get_name_entity_link_by_label($label);
            if (!empty($resolved['url'])) {
                $url = (string) $resolved['url'];
                if (!empty($resolved['label'])) {
                    $label = (string) $resolved['label'];
                }
            }
        }

        if ($url === '') {
            continue;
        }

        $fingerprint = strtolower($url . '|' . $label);
        if (isset($seen[$fingerprint])) {
            continue;
        }

        $seen[$fingerprint] = true;
        $items[] = array(
            'label' => $label,
            'url'   => $url,
        );
    }

    return $items;
};

$aat_render_hub_text_link = function($label, $url = '', $class = '') use ($aat_is_department_credit_label) {
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }

    $class_attr = trim((string) $class);
    if ($url !== '') {
        return '<a class="' . esc_attr($class_attr) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    if ($aat_is_department_credit_label($label)) {
        $class_attr = trim($class_attr . ' aat-department-credit-label');
    }

    return '<span class="' . esc_attr($class_attr) . '">' . esc_html($label) . '</span>';
};

$aat_render_pipe_links = function($value_list, $id_list = '', $class = 'aat-hub-inline-link') use ($aat, $aat_build_entity_url, $aat_clean_nominee_label, $aat_is_department_credit_label) {
    $raw_value_list = (string) $value_list;
    $values = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
        return $aat_clean_nominee_label($part);
    }, explode('|', $raw_value_list)), 'strlen'));
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) $id_list)), 'strlen'));

    if (empty($ids) && strpos($raw_value_list, '|') === false && strpos((string) $class, 'aat-ballot-main-link') === false) {
        $split_values = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
            return $aat_clean_nominee_label($part);
        }, (array) preg_split('/\s*(?:,|\s+and\s+)\s*/i', $raw_value_list)), 'strlen'));
        if (count($split_values) > 1) {
            $values = $split_values;
        }
    }

    if (empty($values)) {
        return '';
    }

    $out = array();
    foreach ($values as $index => $value) {
        $is_department_credit = $aat_is_department_credit_label($value);
        $url = (!$is_department_credit && isset($ids[$index])) ? $aat_build_entity_url($ids[$index]) : '';
        if (!$is_department_credit && $url === '' && method_exists($aat, 'get_name_entity_link_by_label')) {
            $resolved = $aat->get_name_entity_link_by_label($value);
            if (!empty($resolved['url'])) {
                $url = (string) $resolved['url'];
                if (!empty($resolved['label'])) {
                    $value = (string) $resolved['label'];
                }
            }
        }
        if ($url !== '') {
            $out[] = '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($value) . '</a>';
        } else {
            $span_class = trim((string) $class . ($is_department_credit ? ' aat-department-credit-label' : ''));
            $out[] = '<span class="' . esc_attr($span_class) . '">' . esc_html($value) . '</span>';
        }
    }

    return implode('<span class="aat-meta-sep" aria-hidden="true">&middot;</span>', $out);
};

$aat_build_winner_actions = function($entry, $category_url = '', $ceremony_url = '') use ($aat_person_history_action_meta) {
    $entry = is_array($entry) ? $entry : array();
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    $film_url = trim((string) ($entry['film_url'] ?? ''));
    $person_url = trim((string) ($entry['person_url'] ?? ''));
    $person_label = trim((string) ($entry['person_label'] ?? ''));
    $primary_url = trim((string) ($entry['primary_url'] ?? ''));
    $secondary_url = trim((string) ($entry['secondary_url'] ?? ''));
    $film_history_url = trim((string) ($entry['film_history_url'] ?? ''));
    $person_history_url = trim((string) ($entry['person_history_url'] ?? ''));
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
    $category_action = $resolve_label($category_url);
    $ceremony_action = $resolve_label($ceremony_url);

    $add_action($primary_action['label'], $primary_url, $primary_action['kind']);
    $add_action($secondary_action['label'], $secondary_url, $secondary_action['kind']);
    $add_action($category_action['label'], $category_url, $category_action['kind']);
    $add_action($ceremony_action['label'], $ceremony_url, $ceremony_action['kind']);

    if ($film_history_url !== '' && $film_history_url !== $film_url) {
        $add_action(__('Film History', 'academy-awards-table'), $film_history_url, 'film-history');
    }

    if ($person_history_url !== '' && $person_history_url !== $person_url) {
        $person_history_meta = $aat_person_history_action_meta($person_history_url, $category);
        $add_action($person_history_meta['label'], $person_history_url, $person_history_meta['kind']);
    }

    return $actions;
};

$aat_get_visual_package = function($film_id, $size = 'medium_large') use ($aat) {
    static $visual_cache = array();

    $film_id = strtolower(trim((string) $film_id));
    $size = trim((string) $size);
    if ($film_id === '' || !preg_match('/^tt\d+$/', $film_id)) {
        return array();
    }

    $cache_key = $film_id . '|' . $size;
    if (isset($visual_cache[$cache_key])) {
        return $visual_cache[$cache_key];
    }

    $visual_cache[$cache_key] = method_exists($aat, 'get_title_visual_package') ? (array) $aat->get_title_visual_package($film_id, $size) : array();

    return $visual_cache[$cache_key];
};

$aat_extract_title_ids = function($entry) {
    $title_ids = array();

    foreach (explode('|', (string) ($entry['film_id'] ?? '')) as $candidate_title_id) {
        $candidate_title_id = strtolower(trim((string) $candidate_title_id));
        if ($candidate_title_id === '' || !preg_match('/^tt\d+$/', $candidate_title_id)) {
            continue;
        }

        $title_ids[$candidate_title_id] = true;
    }

    return array_keys($title_ids);
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

$aat_get_curated_winner_visual = function($entry, $size = 'medium_large') use ($aat_get_curated_winner_photo_map) {
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

$aat_get_related_review_limit = function() {
    $limit = function_exists('get_theme_mod') ? get_theme_mod('lunara_oscars_related_reviews_count', 6) : 6;
    return max(2, min(8, absint($limit)));
};

$aat_get_related_review_treatment = function() {
    $allowed = array('standard-grid', 'compact-rail', 'feature-strip');
    $treatment = function_exists('get_theme_mod') ? sanitize_key((string) get_theme_mod('lunara_oscars_related_reviews_treatment', 'standard-grid')) : 'standard-grid';
    return in_array($treatment, $allowed, true) ? $treatment : 'standard-grid';
};

$aat_related_review_treatment_class = 'aat-related-treatment-' . $aat_get_related_review_treatment();

$aat_build_hub_review_cards = function($title_entries, $limit = 6) use ($aat) {
    $cards = array();
    $seen_reviews = array();

    foreach ((array) $title_entries as $entry) {
        $film_id = strtolower(trim((string) ($entry['film_id'] ?? '')));
        if (!preg_match('/^tt\d+$/', $film_id)) {
            continue;
        }

        $review_ids = $aat->get_review_ids_for_title_id($film_id, 1);
        if (empty($review_ids[0])) {
            continue;
        }

        $review_id = intval($review_ids[0]);
        if ($review_id <= 0 || isset($seen_reviews[$review_id])) {
            continue;
        }

        $review_url = get_permalink($review_id);
        if (!is_string($review_url) || $review_url === '') {
            continue;
        }

        $film_label = trim((string) ($entry['film'] ?? ''));
        if ($film_label === '') {
            $film_label = $aat->lookup_title_label($film_id);
        }

        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($film_id, 'medium_large') : array();
        $visual_media_html = '';
        if (!empty($visual['poster_html'])) {
            $visual_media_html = (string) $visual['poster_html'];
        } elseif (!empty($visual['poster_url'])) {
            $visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($visual['poster_url']) . '" alt="' . esc_attr(sprintf(__('%s poster', 'academy-awards-table'), $film_label)) . '" loading="lazy" decoding="async" />';
        } elseif (!empty($visual['backdrop_url'])) {
            $visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($visual['backdrop_url']) . '" alt="' . esc_attr($film_label) . '" loading="lazy" decoding="async" />';
        }
        $sort_date = get_post_time('U', true, $review_id);
        if (!$sort_date) {
            $sort_date = 0;
        }

        $cards[] = array(
            'review_id' => $review_id,
            'review_url' => $review_url,
            'review_title' => get_the_title($review_id),
            'review_excerpt' => get_the_excerpt($review_id),
            'review_thumb' => get_the_post_thumbnail($review_id, 'medium_large', array(
                'class' => 'aat-related-review-image',
                'loading' => 'lazy',
                'decoding' => 'async',
                'sizes' => '(max-width: 720px) 100vw, 360px',
            )),
            'film_id' => $film_id,
            'film_label' => $film_label,
            'film_url' => $aat->get_entity_url($film_id),
            'film_year' => trim((string) ($entry['year'] ?? '')),
            'fallback_html' => $visual_media_html,
            'sort_date' => intval($sort_date),
        );

        $seen_reviews[$review_id] = true;
    }

    usort($cards, function($a, $b) {
        $cmp = intval($b['sort_date'] ?? 0) <=> intval($a['sort_date'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcasecmp((string) ($a['review_title'] ?? ''), (string) ($b['review_title'] ?? ''));
    });

    if ($limit > 0) {
        $cards = array_slice($cards, 0, $limit);
    }

    return $cards;
};

$aat_build_title_spotlight = function($film_id, $fallback_label = '', $meta_lines = array(), $badge_label = '') use ($aat, $db_url) {
    $film_id = strtolower(trim((string) $film_id));
    if (!preg_match('/^tt\d+$/', $film_id)) {
        return array();
    }

    $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($film_id, 'medium_large') : array();
    $film_label = trim((string) $fallback_label);
    if ($film_label === '') {
        $film_label = $aat->lookup_title_label($film_id);
    }
    if ($film_label === '' && !empty($visual['title'])) {
        $film_label = (string) $visual['title'];
    }
    if ($film_label === '') {
        $film_label = strtoupper($film_id);
    }

    $film_url = $aat->get_entity_url($film_id);
    $meta_lines = array_values(array_filter(array_map('trim', (array) $meta_lines), 'strlen'));

    return array(
        'film_id' => $film_id,
        'film_label' => $film_label,
        'film_url' => $film_url ? $film_url : $db_url,
        'poster_html' => !empty($visual['poster_html']) ? $visual['poster_html'] : '',
        'poster_url' => !empty($visual['poster_url']) ? $visual['poster_url'] : '',
        'fallback_html' => !empty($visual['fallback_html']) ? $visual['fallback_html'] : '',
        'backdrop_url' => !empty($visual['backdrop_url']) ? $visual['backdrop_url'] : '',
        'meta_lines' => $meta_lines,
        'badge_label' => trim((string) $badge_label),
    );
};

// Optional: if the site owner created WordPress pages for these hubs (as recommended),
// pull their editor content in as the intro copy so they can control tone/voice.
$wp_hub_page = null;
$wp_hub_content = '';
if (in_array($hub, array('ceremonies','categories','about'), true)) {
    $wp_hub_page = $aat->get_hub_page_post($hub);
    if ($wp_hub_page instanceof WP_Post) {
        $wp_hub_content = trim((string) $wp_hub_page->post_content);
    }
}

get_header();
?>

<div class="aat-container aat-hub-page">

    <p class="aat-hub-breadcrumbs">
        <a href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Oscar Ledger', 'academy-awards-table'); ?></a>
        <span class="aat-footer-sep">&rsaquo;</span>
        <?php echo esc_html(ucfirst($hub)); ?>
        <?php if (!empty($hub_id)) : ?>
            <span class="aat-footer-sep">&rsaquo;</span>
            <?php echo esc_html($hub_id); ?>
        <?php endif; ?>
    </p>

    <?php
        // CEREMONIES INDEX
        if ($hub === 'ceremonies') :
            $rows = $wpdb->get_results(
                "SELECT ceremony, MIN(year) AS year_label FROM $table_name GROUP BY ceremony ORDER BY ceremony DESC",
                ARRAY_A
            );
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('Explore every Academy Awards ceremony in the Lunara Oscar Ledger.', 'academy-awards-table'); ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_page->post_content); ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('Browse Categories', 'academy-awards-table'); ?></a>
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_records)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_winners)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></span></div>
        </div>

        <div class="aat-hub-grid">
            <?php if (!empty($rows)) : foreach ($rows as $r) :
                $c = intval($r['ceremony'] ?? 0);
                if ($c <= 0) continue;
                $year_label = (string) ($r['year_label'] ?? '');
                $url = $aat->get_ceremony_url($c);
            ?>
                <article class="aat-hub-card">
                    <h3 class="aat-hub-card-title">
                        <?php echo $aat_render_hub_text_link($aat->ordinal($c) . ' ' . __('Academy Awards', 'academy-awards-table'), $url, 'aat-hub-card-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </h3>
                    <p class="aat-hub-card-meta"><?php echo $aat_render_hub_text_link($year_label, $url, 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                    <p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open ceremony', 'academy-awards-table'), $url, 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                </article>
            <?php endforeach; endif; ?>
        </div>

    <?php
        // CATEGORIES INDEX
        elseif ($hub === 'categories') :
            $cats = $wpdb->get_results(
                "SELECT canonical_category, MIN(class) AS class_label FROM $table_name WHERE canonical_category != '' GROUP BY canonical_category ORDER BY MIN(class) ASC, canonical_category ASC",
                ARRAY_A
            );
            $grouped = array();
            if (is_array($cats)) {
                foreach ($cats as $r) {
                    $cat = (string) ($r['canonical_category'] ?? '');
                    if ($cat === '') continue;
                    $cls = (string) ($r['class_label'] ?? '');
                    if ($cls === '') $cls = 'Other';
                    if (!isset($grouped[$cls])) $grouped[$cls] = array();
                    $grouped[$cls][] = $cat;
                }
            }
            ksort($grouped);
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('Browse every canonical Oscar category in the Lunara Oscar Ledger.', 'academy-awards-table'); ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_page->post_content); ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Browse Ceremonies', 'academy-awards-table'); ?></a>
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_records)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_winners)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span></div>
        </div>

        <?php foreach ($grouped as $cls => $list) : ?>
            <div class="aat-hub-section">
                <h2><?php echo esc_html($cls); ?></h2>
                <div class="aat-hub-grid">
                    <?php foreach ($list as $cat) :
                        $url = $aat->get_category_url($cat);
                        $label = $aat->format_category_display($cat);
                    ?>
                        <article class="aat-hub-card">
                            <h3 class="aat-hub-card-title"><?php echo $aat_render_hub_text_link($label, $url, 'aat-hub-card-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                            <p class="aat-hub-card-meta"><?php echo esc_html($cat); ?></p>
                            <p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open category', 'academy-awards-table'), $url, 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php
        // ABOUT
        elseif ($hub === 'about') :
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('About the Oscar Ledger', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('A bespoke, normalized Academy Awards dataset compiled for Lunara Film by Dalton Johnson.', 'academy-awards-table'); ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_page->post_content); ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_records)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_winners)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span></div>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Scope', 'academy-awards-table'); ?></h2>
            <p class="aat-hub-copy">
                <?php echo esc_html__('This ledger spans the full history of the Academy Awards as represented in our dataset.', 'academy-awards-table'); ?>
                <?php if ($span) : ?>
                    <?php echo esc_html(sprintf(__('Coverage: %s.', 'academy-awards-table'), $span)); ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Method', 'academy-awards-table'); ?></h2>
            <p class="aat-hub-copy">
                <?php echo esc_html__('We treat Oscar history as structured data: categories are normalized, nominee and title credits are linked, and each record is curated to support search, filtering, and internal discovery.', 'academy-awards-table'); ?>
            </p>
            <p class="aat-hub-copy">
                <?php echo esc_html__('Primary factual sourcing: Academy of Motion Picture Arts and Sciences. This dataset is independently structured, compiled, and maintained by Lunara Film.', 'academy-awards-table'); ?>
            </p>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Explore', 'academy-awards-table'); ?></h2>
            <div class="aat-hub-chips">
                <a class="aat-hub-chip" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                <a class="aat-hub-chip" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></a>
            </div>
        </div>

    <?php
        // CEREMONY PAGE
        elseif ($hub === 'ceremony') :
            $ceremony = intval($hub_id);
            if ($ceremony <= 0) {
                $mark_404();
            }
            $ceremony_summary = $aat->get_ceremony_summary($ceremony);
            $year_label = (string) ($ceremony_summary['year_label'] ?? '');
            $noms = intval($ceremony_summary['nominations'] ?? 0);
            $wins = intval($ceremony_summary['wins'] ?? 0);
            $cats_count = intval($ceremony_summary['categories_count'] ?? 0);
            $cats = !empty($ceremony_summary['categories']) && is_array($ceremony_summary['categories']) ? $ceremony_summary['categories'] : array();
            $ceremony_rollup = method_exists($aat, 'get_ceremony_rollup') ? $aat->get_ceremony_rollup($ceremony) : array();
            $is_latest_ceremony = ($ceremony === intval($aat->get_max_ceremony()));
            $newer_ceremony = intval($ceremony_summary['newer_ceremony'] ?? 0);
            $older_ceremony = intval($ceremony_summary['older_ceremony'] ?? 0);
            $ceremony_ballot_ledger = method_exists($aat, 'get_ceremony_ballot_ledger') ? $aat->get_ceremony_ballot_ledger($ceremony) : array();
            $ceremony_ballot_groups = !empty($ceremony_ballot_ledger['categories']) && is_array($ceremony_ballot_ledger['categories']) ? $ceremony_ballot_ledger['categories'] : array();
            $ceremony_review_map = !empty($ceremony_ballot_ledger['review_map']) && is_array($ceremony_ballot_ledger['review_map']) ? $ceremony_ballot_ledger['review_map'] : array();
            $ceremony_ballot_full_requested = isset($_GET['ledger']) && sanitize_key(wp_unslash($_GET['ledger'])) === 'full';
            $ceremony_ballot_full_url = add_query_arg('ledger', 'full');
            $ceremony_ballot_fast_url = remove_query_arg('ledger');
            $ceremony_spotlight = array();
            $best_picture = !empty($ceremony_rollup['best_picture']) ? $ceremony_rollup['best_picture'] : array();
            $best_picture_nominees = !empty($ceremony_rollup['best_picture_nominees']) ? $ceremony_rollup['best_picture_nominees'] : array();
            $most_wins = !empty($ceremony_rollup['most_wins']) ? $ceremony_rollup['most_wins'] : array();
            $most_nominated = !empty($ceremony_rollup['most_nominated']) ? $ceremony_rollup['most_nominated'] : array();
            $ceremony_title_label = sprintf(
                /* translators: %s: ceremony ordinal */
                __('%s Academy Awards', 'academy-awards-table'),
                $aat->ordinal($ceremony)
            );
            $ceremony_dossier_label = sprintf(
                /* translators: %s: ceremony ordinal */
                __('%s Dossier', 'academy-awards-table'),
                $aat->ordinal($ceremony)
            );
            $best_picture_label = !empty($best_picture['film']) ? (string) $best_picture['film'] : __('Pending', 'academy-awards-table');
            $most_wins_label = !empty($most_wins['film']) ? $aat_pipe_display((string) $most_wins['film']) : __('Pending', 'academy-awards-table');
            $winner_record_label = sprintf(
                /* translators: 1: winners, 2: categories */
                __('%1$s/%2$s', 'academy-awards-table'),
                number_format_i18n($wins),
                number_format_i18n($cats_count)
            );
            $ceremony_major_race_order = array(
                'BEST PICTURE',
                'DIRECTING',
                'ACTOR IN A LEADING ROLE',
                'ACTRESS IN A LEADING ROLE',
            );
            $ceremony_major_race_rank = array_flip($ceremony_major_race_order);
            $ceremony_major_race_groups = array();
            foreach ($ceremony_ballot_groups as $major_race_candidate) {
                $major_race_category = strtoupper(trim((string) ($major_race_candidate['category'] ?? '')));
                if ($major_race_category === '' || !isset($ceremony_major_race_rank[$major_race_category])) {
                    continue;
                }

                $major_race_rows = !empty($major_race_candidate['rows']) && is_array($major_race_candidate['rows']) ? $major_race_candidate['rows'] : array();
                if (empty($major_race_rows)) {
                    continue;
                }

                $major_race_candidate['major_rank'] = intval($ceremony_major_race_rank[$major_race_category]);
                $ceremony_major_race_groups[] = $major_race_candidate;
            }
            if (!empty($ceremony_major_race_groups)) {
                usort($ceremony_major_race_groups, function($left, $right) {
                    return intval($left['major_rank'] ?? 999) <=> intval($right['major_rank'] ?? 999);
                });
            }

            $ceremony_major_briefing_cards = array();
            $ceremony_major_nominee_total = 0;
            $ceremony_major_completed_count = 0;
            $ceremony_major_reviewed_titles = array();
            $ceremony_major_category_links = array();

            foreach ($ceremony_major_race_groups as $briefing_group) {
                $briefing_category = trim((string) ($briefing_group['category'] ?? ''));
                $briefing_category_key = strtoupper($briefing_category);
                $briefing_label = trim((string) ($briefing_group['label'] ?? $aat->format_category_display($briefing_category)));
                $briefing_url = trim((string) ($briefing_group['url'] ?? ($briefing_category !== '' ? $aat->get_category_url($briefing_category) : '')));
                $briefing_rows = !empty($briefing_group['rows']) && is_array($briefing_group['rows']) ? $briefing_group['rows'] : array();
                $briefing_winner_rows = array_values(array_filter($briefing_rows, function($candidate_row) {
                    return !empty($candidate_row['winner']);
                }));
                $briefing_winner_row = !empty($briefing_winner_rows[0]) ? $aat_enrich_winner_entry_links($briefing_winner_rows[0]) : array();
                $briefing_winner_label = trim((string) ($briefing_winner_row['primary_label'] ?? (!empty($briefing_winner_row) ? $aat_winner_primary($briefing_winner_row) : '')));
                $briefing_winner_url = !empty($briefing_winner_row['primary_url']) ? (string) $briefing_winner_row['primary_url'] : '';
                $briefing_secondary_label = trim((string) ($briefing_winner_row['secondary_label'] ?? (!empty($briefing_winner_row) ? $aat_winner_secondary($briefing_winner_row) : '')));
                $briefing_review_count = 0;

                foreach ($briefing_rows as $briefing_row) {
                    foreach ($aat_extract_title_ids($briefing_row) as $briefing_title_id) {
                        if (empty($ceremony_review_map[$briefing_title_id]) || isset($ceremony_major_reviewed_titles[$briefing_title_id])) {
                            continue;
                        }

                        $briefing_review_count++;
                        $ceremony_major_reviewed_titles[$briefing_title_id] = true;
                    }
                }

                $ceremony_major_nominee_total += count($briefing_rows);
                if (!empty($briefing_winner_rows)) {
                    $ceremony_major_completed_count++;
                }

                if ($briefing_url !== '') {
                    $ceremony_major_category_links[$briefing_category_key] = array(
                        'label' => $briefing_label,
                        'url'   => $briefing_url,
                    );
                }

                $ceremony_major_briefing_cards[] = array(
                    'category'        => $briefing_category,
                    'category_key'    => $briefing_category_key,
                    'label'           => $briefing_label,
                    'url'             => $briefing_url,
                    'field_count'     => count($briefing_rows),
                    'winner_label'    => $briefing_winner_label,
                    'winner_url'      => $briefing_winner_url,
                    'secondary_label' => $briefing_secondary_label,
                    'review_count'    => $briefing_review_count,
                    'is_complete'     => !empty($briefing_winner_rows),
                );
            }

            $ceremony_major_review_total = count($ceremony_major_reviewed_titles);
            $best_picture_display_label = !empty($best_picture['film']) ? $aat_pipe_display((string) $best_picture['film']) : '';
            $most_wins_display_label = !empty($most_wins['film']) ? $aat_pipe_display((string) $most_wins['film']) : '';
            $ceremony_thesis_headline = __('The annual file starts with the major races.', 'academy-awards-table');
            if ($best_picture_display_label !== '' && $most_wins_display_label !== '' && strcasecmp($best_picture_display_label, $most_wins_display_label) !== 0) {
                $ceremony_thesis_headline = sprintf(
                    /* translators: 1: Best Picture winner, 2: most-winning title */
                    __('%1$s took Picture; %2$s controlled the board.', 'academy-awards-table'),
                    $best_picture_display_label,
                    $most_wins_display_label
                );
            } elseif ($best_picture_display_label !== '') {
                $ceremony_thesis_headline = sprintf(
                    /* translators: 1: ceremony year, 2: Best Picture winner */
                    __('The %s ceremony centers on %s.', 'academy-awards-table'),
                    (string) $year_label,
                    $best_picture_display_label
                );
            }

            $ceremony_thesis_copy = sprintf(
                /* translators: 1: completed races, 2: total major races, 3: nominee count, 4: total winner count, 5: category count */
                __('This dossier opens with the four races readers check first: Picture, Director, Actor, and Actress. %1$s of %2$s major races have winners recorded, with %3$s major-field records feeding into the full %4$s/%5$s ceremony ledger below.', 'academy-awards-table'),
                number_format_i18n($ceremony_major_completed_count),
                number_format_i18n(count($ceremony_major_race_order)),
                number_format_i18n($ceremony_major_nominee_total),
                number_format_i18n($wins),
                number_format_i18n($cats_count)
            );

            $ceremony_critical_links = array(
                array(
                    'label' => __('Full Ballot', 'academy-awards-table'),
                    'url'   => $ceremony_ballot_full_url,
                    'kind'  => 'ceremony',
                ),
                array(
                    'label' => __('Winner Circle', 'academy-awards-table'),
                    'url'   => '#ceremony-winner-circle',
                    'kind'  => 'winner',
                ),
                array(
                    'label' => __('All Ceremonies', 'academy-awards-table'),
                    'url'   => $aat->get_ceremonies_index_url(),
                    'kind'  => 'ceremony',
                ),
            );

            foreach ($ceremony_major_race_order as $critical_category_key) {
                if (empty($ceremony_major_category_links[$critical_category_key])) {
                    continue;
                }

                $ceremony_critical_links[] = array(
                    'label' => $ceremony_major_category_links[$critical_category_key]['label'],
                    'url'   => $ceremony_major_category_links[$critical_category_key]['url'],
                    'kind'  => 'category',
                );
            }
    ?>
        <div class="aat-ceremony-dossier">
            <style>
                body .aat-container .aat-ceremony-dossier{display:grid!important;gap:clamp(20px,3vw,34px)!important;min-width:0!important;max-width:100%!important}
                body .aat-container .aat-ceremony-dossier .aat-stats-bar.aat-entity-stats{display:none!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero{display:grid!important;grid-template-columns:minmax(0,1.08fr) minmax(300px,.92fr)!important;gap:clamp(18px,3vw,32px)!important;align-items:stretch!important;padding:clamp(22px,4vw,42px)!important;border:1px solid rgba(201,169,97,.24)!important;border-radius:18px!important;background:radial-gradient(circle at 88% 14%,rgba(201,169,97,.18),transparent 30%),linear-gradient(135deg,rgba(255,255,255,.065),rgba(255,255,255,.018)),rgba(8,18,29,.94)!important;overflow:hidden!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero .aat-hub-title{color:var(--aat-white)!important;font-size:clamp(2.45rem,5vw,5.15rem)!important;line-height:.98!important;max-width:9.5ch!important;text-align:left!important;text-transform:none!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero .aat-hub-subtitle{margin:0!important;max-width:58ch!important;color:rgba(244,239,227,.84)!important;line-height:1.72!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-band{display:grid!important;grid-template-columns:minmax(0,.94fr) minmax(0,1.06fr)!important;gap:12px!important;align-self:center!important;min-width:0!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card{display:grid!important;align-content:end!important;gap:8px!important;min-width:0!important;min-height:124px!important;padding:17px!important;border:1px solid rgba(201,169,97,.2)!important;border-radius:12px!important;background:linear-gradient(180deg,rgba(255,255,255,.052),rgba(255,255,255,.018)),rgba(6,15,26,.72)!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card.is-primary{grid-row:span 2!important;min-height:218px!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card span{color:var(--aat-gold-light)!important;font-size:.7rem!important;letter-spacing:.14em!important;text-transform:uppercase!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card strong{color:var(--aat-white)!important;max-width:100%!important;font-size:clamp(1.12rem,1.65vw,1.72rem)!important;line-height:1.06!important;overflow-wrap:anywhere!important;white-space:normal!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card.is-primary strong{color:var(--aat-gold)!important;font-size:clamp(2.25rem,3.2vw,2.95rem)!important}
                body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-actions{grid-column:1/-1!important;justify-content:start!important;margin-top:4px!important}
                @media(max-width:980px){body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero{grid-template-columns:minmax(0,1fr)!important}}
                @media(max-width:640px){body .aat-container .aat-ceremony-dossier{width:min(100%,calc(100vw - 24px))!important;max-width:calc(100vw - 24px)!important;margin-left:auto!important;margin-right:auto!important;overflow-x:hidden!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero{grid-template-columns:minmax(0,1fr)!important;padding:16px!important;border-radius:12px!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero .aat-hub-title{font-size:clamp(2.15rem,14vw,3.05rem)!important;max-width:10ch!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-hero .aat-hub-subtitle,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-nav,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-nav *,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-card,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-card strong,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-index,body .aat-container .aat-ceremony-dossier .aat-ceremony-marquee .aat-hub-copy,body .aat-container .aat-ceremony-dossier .aat-ceremony-marquee .aat-hub-copy *{max-width:29ch!important;overflow-wrap:anywhere!important;text-wrap:auto!important;white-space:normal!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-nav{grid-template-columns:minmax(0,1fr)!important;gap:10px!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-slot,body .aat-container .aat-ceremony-dossier .aat-ceremony-neighbor-index{width:100%!important;min-width:0!important;justify-self:stretch!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-command-band{grid-template-columns:minmax(0,1fr)!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card,body .aat-container .aat-ceremony-dossier .aat-ceremony-command-card.is-primary{grid-row:auto!important;min-height:0!important;padding:15px!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-actions{display:grid!important;grid-template-columns:minmax(0,1fr)!important;width:100%!important}body .aat-container .aat-ceremony-dossier .aat-ceremony-dossier-actions .aat-btn{width:100%!important;justify-content:center!important}}
            </style>
            <section class="aat-ceremony-dossier-hero">
                <div class="aat-ceremony-dossier-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Oscar Ledger Year Dossier', 'academy-awards-table'); ?></p>
                    <h1 class="aat-hub-title"><?php echo esc_html($ceremony_dossier_label); ?></h1>
                    <p class="aat-hub-subtitle">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: ceremony year, 2: ceremony title */
                            __('The %1$s file for %2$s: winners first, ballot trails on demand, and the films that shaped the ceremony.', 'academy-awards-table'),
                            (string) $year_label,
                            $ceremony_title_label
                        )); ?>
                    </p>
                </div>
                <div class="aat-ceremony-command-band" aria-label="<?php esc_attr_e('Ceremony ledger summary', 'academy-awards-table'); ?>">
                    <div class="aat-ceremony-command-card is-primary">
                        <span><?php echo esc_html__('Year', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($year_label !== '' ? $year_label : '-'); ?></strong>
                    </div>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Best Picture', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($best_picture_label); ?></strong>
                    </div>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Winner Record', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($winner_record_label); ?></strong>
                    </div>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Top Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($most_wins_label); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-ceremony-dossier-actions">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('All Ceremonies', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Full Ballot', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>

        <?php if ($newer_ceremony > 0 || $older_ceremony > 0) : ?>
            <nav class="aat-ceremony-neighbor-nav" aria-label="<?php echo esc_attr__('Ceremony navigation', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-neighbor-slot is-newer">
                    <?php if ($newer_ceremony > 0) : ?>
                        <a class="aat-ceremony-neighbor-card" href="<?php echo esc_url($aat->get_ceremony_url($newer_ceremony)); ?>">
                            <span class="aat-ceremony-neighbor-kicker"><?php echo esc_html__('Later Ceremony', 'academy-awards-table'); ?></span>
                            <strong><?php echo esc_html($aat->ordinal($newer_ceremony)); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->get_ceremony_year($newer_ceremony)); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <a class="aat-ceremony-neighbor-index" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>">
                    <span><?php echo esc_html__('All Ceremonies', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></strong>
                </a>
                <div class="aat-ceremony-neighbor-slot is-older">
                    <?php if ($older_ceremony > 0) : ?>
                        <a class="aat-ceremony-neighbor-card" href="<?php echo esc_url($aat->get_ceremony_url($older_ceremony)); ?>">
                            <span class="aat-ceremony-neighbor-kicker"><?php echo esc_html__('Earlier Ceremony', 'academy-awards-table'); ?></span>
                            <strong><?php echo esc_html($aat->ordinal($older_ceremony)); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->get_ceremony_year($older_ceremony)); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <?php
            $ceremony_editorial_writeup = method_exists($aat, 'get_approved_ceremony_writeup') ? $aat->get_approved_ceremony_writeup($ceremony) : array();
            $ceremony_editorial_body = trim((string) ($ceremony_editorial_writeup['body'] ?? ''));
            $ceremony_editorial_headline = trim((string) ($ceremony_editorial_writeup['headline'] ?? ''));
            $ceremony_editorial_dek = trim((string) ($ceremony_editorial_writeup['dek'] ?? ''));
        ?>
        <?php if ($ceremony_editorial_body !== '') : ?>
            <section class="aat-hub-section aat-ceremony-editorial-writeup" aria-label="<?php echo esc_attr__('Ceremony editorial guide', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-editorial-heading">
                    <p class="aat-hub-kicker aat-ceremony-guide-kicker"><?php echo esc_html__('Ceremony Guide', 'academy-awards-table'); ?></p>
                    <span class="aat-ceremony-guide-file"><?php echo esc_html__('Guide File', 'academy-awards-table'); ?></span>
                    <h2><?php echo esc_html($ceremony_editorial_headline !== '' ? $ceremony_editorial_headline : (string) ($ceremony_editorial_writeup['ceremony_label'] ?? $ceremony_dossier_label)); ?></h2>
                    <div class="aat-ceremony-guide-meta" aria-label="<?php echo esc_attr__('Ceremony guide metadata', 'academy-awards-table'); ?>">
                        <?php if ($ceremony_editorial_dek !== '') : ?>
                            <span><?php echo esc_html($ceremony_editorial_dek); ?></span>
                        <?php endif; ?>
                        <span><?php echo esc_html(sprintf(__('Ceremony %d', 'academy-awards-table'), (int) $ceremony)); ?></span>
                        <span><?php echo esc_html__('Approved Guide', 'academy-awards-table'); ?></span>
                    </div>
                    <div class="aat-ceremony-guide-actions" aria-label="<?php echo esc_attr__('Ceremony guide actions', 'academy-awards-table'); ?>">
                        <a class="aat-hub-card-action" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Full Ballot', 'academy-awards-table'); ?></a>
                        <a class="aat-hub-card-action" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('All Ceremonies', 'academy-awards-table'); ?></a>
                    </div>
                </div>
                <div class="aat-ceremony-editorial-body aat-ceremony-guide-copy">
                    <?php echo wp_kses_post(wpautop(esc_html($ceremony_editorial_body))); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($ceremony_major_briefing_cards)) : ?>
            <section class="aat-hub-section aat-ceremony-thesis" aria-label="<?php echo esc_attr__('Ceremony thesis and major race briefing', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-thesis-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Annual Thesis', 'academy-awards-table'); ?></p>
                    <h2><?php echo esc_html($ceremony_thesis_headline); ?></h2>
                    <p class="aat-hub-copy"><?php echo esc_html($ceremony_thesis_copy); ?></p>
                    <div class="aat-ceremony-thesis-stats" aria-label="<?php echo esc_attr__('Major race status', 'academy-awards-table'); ?>">
                        <span><strong><?php echo esc_html(number_format_i18n(count($ceremony_major_briefing_cards))); ?></strong><?php echo esc_html__('major races', 'academy-awards-table'); ?></span>
                        <span><strong><?php echo esc_html(number_format_i18n($ceremony_major_nominee_total)); ?></strong><?php echo esc_html__('major-field records', 'academy-awards-table'); ?></span>
                        <span><strong><?php echo esc_html(number_format_i18n($ceremony_major_completed_count)); ?>/<?php echo esc_html(number_format_i18n(count($ceremony_major_race_order))); ?></strong><?php echo esc_html__('winners recorded', 'academy-awards-table'); ?></span>
                        <?php if ($ceremony_major_review_total > 0) : ?>
                            <span><strong><?php echo esc_html(number_format_i18n($ceremony_major_review_total)); ?></strong><?php echo esc_html__('linked reviews', 'academy-awards-table'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="aat-ceremony-critical-path" aria-label="<?php echo esc_attr__('Ceremony critical path links', 'academy-awards-table'); ?>">
                        <?php foreach ($ceremony_critical_links as $critical_link) : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-<?php echo esc_attr($critical_link['kind']); ?>" href="<?php echo esc_url($critical_link['url']); ?>"><?php echo esc_html($critical_link['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="aat-major-race-briefing" aria-label="<?php echo esc_attr__('Major race briefing cards', 'academy-awards-table'); ?>">
                    <?php foreach ($ceremony_major_briefing_cards as $briefing_card) : ?>
                        <article class="aat-major-race-briefing-card<?php echo !empty($briefing_card['is_complete']) ? ' is-complete' : ' is-pending'; ?>">
                            <div class="aat-major-race-briefing-head">
                                <?php echo $aat_render_hub_text_link((string) $briefing_card['label'], (string) $briefing_card['url'], 'aat-major-race-category aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="<?php echo !empty($briefing_card['is_complete']) ? 'aat-winner-badge' : 'aat-nominee-badge'; ?>"><?php echo esc_html(!empty($briefing_card['is_complete']) ? __('Winner Set', 'academy-awards-table') : __('Open Field', 'academy-awards-table')); ?></span>
                            </div>
                            <?php if (!empty($briefing_card['winner_label'])) : ?>
                                <h3>
                                    <?php echo $aat_render_hub_text_link((string) $briefing_card['winner_label'], (string) $briefing_card['winner_url'], 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </h3>
                            <?php endif; ?>
                            <?php if (!empty($briefing_card['secondary_label'])) : ?>
                                <p class="aat-major-race-briefing-secondary"><?php echo esc_html($briefing_card['secondary_label']); ?></p>
                            <?php endif; ?>
                            <p class="aat-major-race-briefing-meta">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: nominee count */
                                    _n('%s record', '%s records', intval($briefing_card['field_count']), 'academy-awards-table'),
                                    number_format_i18n(intval($briefing_card['field_count']))
                                )); ?>
                                <?php if (intval($briefing_card['review_count']) > 0) : ?>
                                    <span><?php echo esc_html(sprintf(
                                        /* translators: %s: review count */
                                        _n('%s linked review', '%s linked reviews', intval($briefing_card['review_count']), 'academy-awards-table'),
                                        number_format_i18n(intval($briefing_card['review_count']))
                                    )); ?></span>
                                <?php endif; ?>
                            </p>
                            <a class="aat-major-race-briefing-jump" href="<?php echo esc_url($ceremony_ballot_full_url . '#ceremony-category-' . sanitize_title((string) $briefing_card['category'])); ?>"><?php echo esc_html__('Open race ledger', 'academy-awards-table'); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($ceremony_rollup)) :
            $spotlight_film_id = '';
            $spotlight_film_label = '';
            $spotlight_meta = array();
            $spotlight_badge = '';

            if (!empty($best_picture['film_id'])) {
                $spotlight_film_id = (string) $best_picture['film_id'];
                $spotlight_film_label = (string) ($best_picture['film'] ?? '');
                $spotlight_meta[] = __('Best Picture winner', 'academy-awards-table');
                if (!empty($year_label)) {
                    $spotlight_meta[] = (string) $year_label;
                }
                $spotlight_badge = __('Best Picture', 'academy-awards-table');
            } elseif (!empty($most_wins['film_id'])) {
                $spotlight_film_id = (string) $most_wins['film_id'];
                $spotlight_film_label = (string) ($most_wins['film'] ?? '');
                if (!empty($most_wins['wins'])) {
                    $spotlight_meta[] = sprintf(__('%s wins', 'academy-awards-table'), number_format_i18n(intval($most_wins['wins'])));
                }
                if (!empty($year_label)) {
                    $spotlight_meta[] = (string) $year_label;
                }
                $spotlight_badge = __('Ceremony leader', 'academy-awards-table');
            }

            if ($spotlight_film_id !== '') {
                $ceremony_spotlight = $aat_build_title_spotlight($spotlight_film_id, $spotlight_film_label, $spotlight_meta, $spotlight_badge);
            }

            $best_picture_metric_visual = !empty($best_picture['film_id']) ? $aat_get_visual_package((string) $best_picture['film_id'], 'medium_large') : array();
            $best_picture_metric_backdrop_style = $aat_get_card_backdrop_style($best_picture_metric_visual['poster_url'] ?? '', $best_picture_metric_visual['backdrop_url'] ?? '');
            $most_wins_metric_visual = !empty($most_wins['film_id']) ? $aat_get_visual_package((string) $most_wins['film_id'], 'medium_large') : array();
            $most_wins_metric_backdrop_style = $aat_get_card_backdrop_style($most_wins_metric_visual['poster_url'] ?? '', $most_wins_metric_visual['backdrop_url'] ?? '');
            $most_nominated_metric_visual = !empty($most_nominated['film_id']) ? $aat_get_visual_package((string) $most_nominated['film_id'], 'medium_large') : array();
            $most_nominated_metric_backdrop_style = $aat_get_card_backdrop_style($most_nominated_metric_visual['poster_url'] ?? '', $most_nominated_metric_visual['backdrop_url'] ?? '');
        ?>
            <section class="aat-hub-section aat-ceremony-marquee">
                <div class="aat-ceremony-marquee-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html($is_latest_ceremony ? __('Winners Now Live', 'academy-awards-table') : __('Ceremony Snapshot', 'academy-awards-table')); ?></p>
                    <h2><?php echo esc_html($is_latest_ceremony && !empty($ceremony_rollup['has_full_winners']) ? __('The latest ceremony is fully updated in the Lunara Oscar Ledger.', 'academy-awards-table') : __('A live, winner-driven snapshot of this ceremony.', 'academy-awards-table')); ?></h2>
                    <p class="aat-hub-copy">
                        <?php if (!empty($best_picture['film'])) : ?>
                            <?php
                                $best_picture_link = $aat_render_hub_text_link(
                                    (string) $best_picture['film'],
                                    !empty($best_picture['film_url']) ? (string) $best_picture['film_url'] : '',
                                    'aat-hub-inline-link'
                                );
                            ?>
                            <?php echo wp_kses_post(sprintf(__('Best Picture went to %s.', 'academy-awards-table'), $best_picture_link)); ?>
                        <?php endif; ?>
                        <?php if (!empty($most_wins['film']) && !empty($most_wins['wins'])) : ?>
                            <?php
                                $most_wins_link = $aat_render_hub_text_link(
                                    (string) $most_wins['film'],
                                    !empty($most_wins['film_url']) ? (string) $most_wins['film_url'] : '',
                                    'aat-hub-inline-link'
                                );
                            ?>
                            <?php echo ' '; ?>
                            <?php echo wp_kses_post(sprintf(__('The winning leader is %1$s with %2$s win%3$s.', 'academy-awards-table'), $most_wins_link, number_format_i18n(intval($most_wins['wins'])), intval($most_wins['wins']) === 1 ? '' : 's')); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="aat-ceremony-marquee-stack">
                    <?php if (!empty($ceremony_spotlight)) : ?>
                        <?php $ceremony_spotlight_backdrop_style = $aat_get_card_backdrop_style($ceremony_spotlight['poster_url'] ?? '', $ceremony_spotlight['backdrop_url'] ?? ''); ?>
                        <article class="aat-hub-spotlight-card<?php echo $ceremony_spotlight_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($ceremony_spotlight_backdrop_style !== '') : ?> style="<?php echo esc_attr($ceremony_spotlight_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-hub-spotlight-media-link" href="<?php echo esc_url($ceremony_spotlight['film_url']); ?>">
                                <div class="aat-hub-spotlight-media"<?php if (!empty($ceremony_spotlight['backdrop_url'])) : ?> style="background-image: linear-gradient(180deg, rgba(3,10,22,.1), rgba(3,10,22,.78)), url('<?php echo esc_url($ceremony_spotlight['backdrop_url']); ?>'); background-size: cover; background-position: center;"<?php endif; ?>>
                                    <?php if (!empty($ceremony_spotlight['poster_html'])) : ?>
                                        <?php echo $ceremony_spotlight['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($ceremony_spotlight['poster_url'])) : ?>
                                        <img class="aat-hub-spotlight-poster" src="<?php echo esc_url($ceremony_spotlight['poster_url']); ?>" alt="<?php echo esc_attr($ceremony_spotlight['film_label']); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($ceremony_spotlight['fallback_html'])) : ?>
                                        <?php echo $ceremony_spotlight['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($ceremony_spotlight['film_label']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($ceremony_spotlight['badge_label'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html($ceremony_spotlight['badge_label']); ?></span><?php endif; ?>
                                </div>
                            </a>
                            <div class="aat-hub-spotlight-body">
                                <h3 class="aat-hub-spotlight-title"><?php echo $aat_render_hub_text_link($ceremony_spotlight['film_label'], $ceremony_spotlight['film_url'], 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                                <?php if (!empty($ceremony_spotlight['meta_lines'])) : ?>
                                    <p class="aat-hub-spotlight-meta"><?php echo $aat_join_meta($ceremony_spotlight['meta_lines']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endif; ?>
                    <?php if (!empty($ceremony_rollup['top_titles'])) : ?>
                        <div class="aat-hub-chip-stack">
                            <?php foreach ($ceremony_rollup['top_titles'] as $title_entry) : ?>
                                <?php
                                    $title_entry_visual = !empty($title_entry['film_id']) ? $aat_get_visual_package((string) $title_entry['film_id'], 'medium_large') : array();
                                    $title_entry_backdrop_style = $aat_get_card_backdrop_style($title_entry_visual['poster_url'] ?? '', $title_entry_visual['backdrop_url'] ?? '');
                                ?>
                                <div class="aat-hub-chip aat-hub-chip-rich<?php echo $title_entry_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($title_entry_backdrop_style !== '') : ?> style="<?php echo esc_attr($title_entry_backdrop_style); ?>"<?php endif; ?>>
                                    <strong><?php echo $aat_render_hub_text_link((string) $title_entry['film'], !empty($title_entry['film_url']) ? (string) $title_entry['film_url'] : $db_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                    <span><?php echo esc_html(number_format_i18n(intval($title_entry['wins']))); ?> <?php echo esc_html__('wins', 'academy-awards-table'); ?> | <?php echo esc_html(number_format_i18n(intval($title_entry['nominations']))); ?> <?php echo esc_html__('nominations', 'academy-awards-table'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="aat-hub-metric-grid">
                <article class="aat-hub-metric-card">
                    <span class="aat-hub-metric-label"><?php echo esc_html__('Winner Record', 'academy-awards-table'); ?></span>
                    <strong class="aat-hub-metric-value"><?php echo esc_html(number_format_i18n(intval($ceremony_rollup['winner_categories'] ?? 0))); ?>/<?php echo esc_html(number_format_i18n(intval($ceremony_rollup['categories_total'] ?? 0))); ?></strong>
                    <p class="aat-hub-metric-copy"><?php echo esc_html__('Categories settled and marked as winners in the ledger.', 'academy-awards-table'); ?></p>
                    <p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open Data Explorer', 'academy-awards-table'), add_query_arg('view', 'table', $aat->get_ceremony_url($ceremony)), 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                </article>
                <article class="aat-hub-metric-card<?php echo $best_picture_metric_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($best_picture_metric_backdrop_style !== '') : ?> style="<?php echo esc_attr($best_picture_metric_backdrop_style); ?>"<?php endif; ?>>
                    <span class="aat-hub-metric-label"><?php echo esc_html__('Best Picture', 'academy-awards-table'); ?></span>
                    <strong class="aat-hub-metric-value"><?php echo !empty($best_picture['film']) ? $aat_render_hub_text_link((string) $best_picture['film'], !empty($best_picture['film_url']) ? (string) $best_picture['film_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title') : esc_html('-'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                    <p class="aat-hub-metric-copy"><?php echo esc_html__("The ceremony's top prize, updated dynamically from the winner row.", 'academy-awards-table'); ?></p>
                    <?php if (!empty($best_picture['film_url'])) : ?><p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open film profile', 'academy-awards-table'), (string) $best_picture['film_url'], 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p><?php endif; ?>
                </article>
                <article class="aat-hub-metric-card<?php echo $most_wins_metric_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($most_wins_metric_backdrop_style !== '') : ?> style="<?php echo esc_attr($most_wins_metric_backdrop_style); ?>"<?php endif; ?>>
                    <span class="aat-hub-metric-label"><?php echo esc_html__('Most Wins', 'academy-awards-table'); ?></span>
                    <strong class="aat-hub-metric-value"><?php echo !empty($most_wins['film']) ? $aat_render_hub_text_link((string) $most_wins['film'], !empty($most_wins['film_url']) ? (string) $most_wins['film_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title') : esc_html('-'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                    <p class="aat-hub-metric-copy"><?php echo !empty($most_wins['wins']) ? esc_html(sprintf(__('%s wins across the ceremony.', 'academy-awards-table'), number_format_i18n(intval($most_wins['wins'])))) : esc_html__('Awaiting winner data.', 'academy-awards-table'); ?></p>
                    <?php if (!empty($most_wins['film_url'])) : ?><p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open film profile', 'academy-awards-table'), (string) $most_wins['film_url'], 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p><?php endif; ?>
                </article>
                <article class="aat-hub-metric-card<?php echo $most_nominated_metric_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($most_nominated_metric_backdrop_style !== '') : ?> style="<?php echo esc_attr($most_nominated_metric_backdrop_style); ?>"<?php endif; ?>>
                    <span class="aat-hub-metric-label"><?php echo esc_html__('Most Nominated', 'academy-awards-table'); ?></span>
                    <strong class="aat-hub-metric-value"><?php echo !empty($most_nominated['film']) ? $aat_render_hub_text_link((string) $most_nominated['film'], !empty($most_nominated['film_url']) ? (string) $most_nominated['film_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title') : esc_html('-'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                    <p class="aat-hub-metric-copy"><?php echo !empty($most_nominated['nominations']) ? esc_html(sprintf(__('%s nominations in this ceremony.', 'academy-awards-table'), number_format_i18n(intval($most_nominated['nominations'])))) : esc_html__('Awaiting nomination data.', 'academy-awards-table'); ?></p>
                    <?php if (!empty($most_nominated['film_url'])) : ?><p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open film profile', 'academy-awards-table'), (string) $most_nominated['film_url'], 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p><?php endif; ?>
                </article>
            </div>
        <?php endif; ?>

        <?php if (!empty($best_picture_nominees)) : ?>
            <div class="aat-hub-section aat-best-picture-nominees-section">
                <h2><?php echo esc_html__('Best Picture Nominees', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy">
                    <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: nominee count */
                                __('The shortlist most readers look for first: %s Best Picture nominees from this ceremony.', 'academy-awards-table'),
                                number_format_i18n(count($best_picture_nominees))
                            )
                        );
                    ?>
                </p>
                <div class="aat-filmography-grid aat-hub-film-grid aat-best-picture-grid">
                    <?php foreach ($best_picture_nominees as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        $film_label = trim((string) ($entry['film'] ?? ''));
                        if ($film_label === '' && $fid !== '') {
                            $film_label = $aat->lookup_title_label($fid);
                        }
                        if ($film_label === '') {
                            continue;
                        }
                        $film_url = !empty($entry['film_url']) ? (string) $entry['film_url'] : ($fid !== '' ? $aat->get_entity_url($fid) : '');
                        $visual = $fid !== '' && method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium_large') : array();
                        $best_picture_backdrop_style = $aat_get_card_backdrop_style($visual['poster_url'] ?? '', $visual['backdrop_url'] ?? '');
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card aat-best-picture-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?><?php echo $best_picture_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($best_picture_backdrop_style !== '') : ?> style="<?php echo esc_attr($best_picture_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>">
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
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span><?php endif; ?>
                                </div>
                                <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                                <p class="aat-filmography-meta">
                                    <span><?php echo esc_html__('Best Picture nominee', 'academy-awards-table'); ?></span>
                                    <?php if (!empty($entry['person_label'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span>
                                        <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php endif; ?>
                                </p>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($noms)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($wins)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($cats_count)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></span></div>
                    <div class="aat-stat"><span class="aat-stat-number"><?php echo $span ? esc_html($span) : '-'; ?></span><span class="aat-stat-label"><?php echo esc_html__('Ledger span', 'academy-awards-table'); ?></span></div>
        </div>

        <?php if (!empty($ceremony_major_race_groups)) : ?>
            <section class="aat-hub-section aat-ceremony-major-races" aria-label="<?php echo esc_attr__('Major Oscar races', 'academy-awards-table'); ?>">
                <div class="aat-section-head">
                    <div>
                        <p class="aat-hub-kicker"><?php echo esc_html__('Major Races', 'academy-awards-table'); ?></p>
                        <h2 class="aat-section-title"><?php echo esc_html__('The Four Races That Shape the Night', 'academy-awards-table'); ?></h2>
                        <p class="aat-section-description"><?php echo esc_html__('A fast editorial scan of Picture, Director, Actor, and Actress before the full ceremony ledger opens out below.', 'academy-awards-table'); ?></p>
                    </div>
                    <p class="aat-ceremony-ledger-mode">
                        <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Open Full Ballot', 'academy-awards-table'); ?></a>
                    </p>
                </div>
                <div class="aat-major-race-grid">
                    <?php foreach ($ceremony_major_race_groups as $major_group) :
                        $major_category = trim((string) ($major_group['category'] ?? ''));
                        $major_category_key = strtoupper($major_category);
                        $major_label = trim((string) ($major_group['label'] ?? $aat->format_category_display($major_category)));
                        $major_url = trim((string) ($major_group['url'] ?? ($major_category !== '' ? $aat->get_category_url($major_category) : '')));
                        $major_rows = !empty($major_group['rows']) && is_array($major_group['rows']) ? $major_group['rows'] : array();
                        $winner_rows = array_values(array_filter($major_rows, function($candidate_row) {
                            return !empty($candidate_row['winner']);
                        }));
                        $nominee_rows = array_values(array_filter($major_rows, function($candidate_row) {
                            return empty($candidate_row['winner']);
                        }));
                        $feature_row = !empty($winner_rows[0]) ? $winner_rows[0] : (!empty($major_rows[0]) ? $major_rows[0] : array());
                        if (empty($feature_row)) {
                            continue;
                        }

                        $feature_row = $aat_enrich_winner_entry_links($feature_row);
                        $feature_primary = trim((string) ($feature_row['primary_label'] ?? $aat_winner_primary($feature_row)));
                        $feature_secondary = trim((string) ($feature_row['secondary_label'] ?? $aat_winner_secondary($feature_row)));
                        $feature_title_id = '';
                        foreach (explode('|', (string) ($feature_row['film_id'] ?? '')) as $feature_candidate_title_id) {
                            $feature_candidate_title_id = strtolower(trim((string) $feature_candidate_title_id));
                            if (preg_match('/^tt\d+$/', $feature_candidate_title_id)) {
                                $feature_title_id = $feature_candidate_title_id;
                                break;
                            }
                        }
                        $feature_review_url = ($feature_title_id !== '' && !empty($ceremony_review_map[$feature_title_id])) ? (string) $ceremony_review_map[$feature_title_id] : '';
                        $feature_visual = $feature_title_id !== '' ? $aat_get_visual_package($feature_title_id, 'medium_large') : array();
                        $feature_backdrop_style = $aat_get_card_backdrop_style($feature_visual['poster_url'] ?? '', $feature_visual['backdrop_url'] ?? '');
                        $feature_film_history_url = !empty($feature_row['film_history_url']) ? (string) $feature_row['film_history_url'] : '';
                        $feature_person_history_url = !empty($feature_row['person_history_url']) ? (string) $feature_row['person_history_url'] : '';
                        $feature_person_history_meta = $aat_person_history_action_meta($feature_person_history_url, $major_category_key);
                        $feature_person_history_label = $feature_person_history_meta['label'];
                        $feature_person_history_kind = $feature_person_history_meta['kind'];
                        $major_card_classes = array('aat-major-race-card');
                        if ($major_category_key === 'BEST PICTURE') {
                            $major_card_classes[] = 'is-best-picture';
                        }
                        if ($feature_backdrop_style !== '') {
                            $major_card_classes[] = 'aat-card-has-backdrop';
                        }
                    ?>
                        <article class="<?php echo esc_attr(implode(' ', $major_card_classes)); ?>"<?php if ($feature_backdrop_style !== '') : ?> style="<?php echo esc_attr($feature_backdrop_style); ?>"<?php endif; ?>>
                            <div class="aat-major-race-top">
                                <?php echo $aat_render_hub_text_link($major_label, $major_url, 'aat-major-race-category aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="<?php echo !empty($winner_rows) ? 'aat-winner-badge' : 'aat-nominee-badge'; ?>"><?php echo esc_html(!empty($winner_rows) ? __('Winner', 'academy-awards-table') : __('Pending', 'academy-awards-table')); ?></span>
                            </div>
                            <div class="aat-major-race-feature">
                                <?php if (!empty($feature_visual['poster_url'])) : ?>
                                    <a class="aat-major-race-media" href="<?php echo esc_url(!empty($feature_row['primary_url']) ? (string) $feature_row['primary_url'] : (!empty($feature_row['film_url']) ? (string) $feature_row['film_url'] : $major_url)); ?>">
                                        <?php if (!empty($feature_visual['poster_html'])) : ?>
                                            <?php echo $feature_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php else : ?>
                                            <img class="aat-major-race-poster" src="<?php echo esc_url($feature_visual['poster_url']); ?>" alt="<?php echo esc_attr($feature_primary); ?>" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                                <div class="aat-major-race-copy">
                                    <?php if ($feature_primary !== '') : ?>
                                        <h3 class="aat-major-race-title">
                                            <?php echo $aat_render_hub_text_link($feature_primary, !empty($feature_row['primary_url']) ? (string) $feature_row['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </h3>
                                    <?php endif; ?>
                                    <?php if ($feature_secondary !== '') : ?>
                                        <p class="aat-major-race-meta">
                                            <?php echo $aat_render_hub_text_link($feature_secondary, !empty($feature_row['secondary_url']) ? (string) $feature_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </p>
                                    <?php endif; ?>
                                    <p class="aat-major-race-summary">
                                        <?php echo esc_html(sprintf(
                                            /* translators: 1: category label, 2: nominee count */
                                            __('%1$s anchors a %2$s-nominee field.', 'academy-awards-table'),
                                            $major_label,
                                            number_format_i18n(count($major_rows))
                                        )); ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (!empty($nominee_rows)) : ?>
                                <div class="aat-major-race-nominees">
                                    <span class="aat-major-race-nominee-label"><?php echo esc_html__('Nominee Field', 'academy-awards-table'); ?></span>
                                    <ul class="aat-major-race-nominee-list">
                                        <?php foreach ($nominee_rows as $major_nominee_row) :
                                            $major_nominee_row = $aat_enrich_winner_entry_links($major_nominee_row);
                                            $nominee_primary = trim((string) ($major_nominee_row['primary_label'] ?? $aat_winner_primary($major_nominee_row)));
                                            $nominee_secondary = trim((string) ($major_nominee_row['secondary_label'] ?? $aat_winner_secondary($major_nominee_row)));
                                            if ($nominee_primary === '') {
                                                continue;
                                            }
                                        ?>
                                            <li>
                                                <span class="aat-major-race-nominee-primary"><?php echo $aat_render_hub_text_link($nominee_primary, !empty($major_nominee_row['primary_url']) ? (string) $major_nominee_row['primary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                <?php if ($nominee_secondary !== '') : ?>
                                                    <span class="aat-major-race-nominee-secondary"><?php echo $aat_render_hub_text_link($nominee_secondary, !empty($major_nominee_row['secondary_url']) ? (string) $major_nominee_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <div class="aat-major-race-actions">
                                <?php if ($major_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-category" href="<?php echo esc_url($major_url); ?>"><?php echo esc_html__('Category File', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                                <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url . '#ceremony-category-' . sanitize_title($major_category)); ?>"><?php echo esc_html__('Full Race Ballot', 'academy-awards-table'); ?></a>
                                <?php if ($feature_film_history_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($feature_film_history_url); ?>"><?php echo esc_html__('Film History', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                                <?php if ($feature_person_history_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-<?php echo esc_attr($feature_person_history_kind); ?>" href="<?php echo esc_url($feature_person_history_url); ?>"><?php echo esc_html($feature_person_history_label); ?></a>
                                <?php endif; ?>
                                <?php if ($feature_review_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-review" href="<?php echo esc_url($feature_review_url); ?>"><?php echo esc_html__('Review', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($ceremony_ballot_groups)) : ?>
            <section class="aat-hub-section aat-ceremony-ballot-ledger">
                <div class="aat-section-head">
                    <h2 class="aat-section-title"><?php echo esc_html__('Complete Ceremony Ledger', 'academy-awards-table'); ?></h2>
                    <p class="aat-section-description">
                        <?php
                            echo esc_html(
                                $ceremony_ballot_full_requested
                                    ? __('Full ballot mode is showing every winner, nominee, film, person, and review link for this ceremony.', 'academy-awards-table')
                                    : __('Fast view shows every category and winner first, with nominee groups linked to the full ballot when you need the deep ledger.', 'academy-awards-table')
                            );
                        ?>
                    </p>
                    <p class="aat-ceremony-ledger-mode">
                        <?php if ($ceremony_ballot_full_requested) : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_fast_url); ?>"><?php echo esc_html__('Use Fast View', 'academy-awards-table'); ?></a>
                        <?php else : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Open Full Ballot', 'academy-awards-table'); ?></a>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="aat-ceremony-ballot-summary">
                    <span><?php echo esc_html(sprintf(__('%s categories', 'academy-awards-table'), number_format_i18n(count($ceremony_ballot_groups)))); ?></span>
                    <span><?php echo esc_html(sprintf(__('%s records', 'academy-awards-table'), number_format_i18n($noms))); ?></span>
                    <span><?php echo esc_html(sprintf(__('%s winners', 'academy-awards-table'), number_format_i18n($wins))); ?></span>
                    <?php if (!$ceremony_ballot_full_requested) : ?>
                        <span><?php echo esc_html__('Nominees summarized for speed', 'academy-awards-table'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="aat-ceremony-ballot-groups">
                    <?php foreach ($ceremony_ballot_groups as $ballot_group) :
                        $group_category = trim((string) ($ballot_group['category'] ?? ''));
                        $group_label = trim((string) ($ballot_group['label'] ?? $aat->format_category_display($group_category)));
                        $group_url = trim((string) ($ballot_group['url'] ?? ($group_category !== '' ? $aat->get_category_url($group_category) : '')));
                        $group_rows = !empty($ballot_group['rows']) && is_array($ballot_group['rows']) ? $ballot_group['rows'] : array();
                        $group_winner_count = intval($ballot_group['winner_count'] ?? 0);
                        $group_nominee_count = max(0, count($group_rows) - $group_winner_count);
                        $rendered_group_rows = $ceremony_ballot_full_requested ? $group_rows : array_values(array_filter($group_rows, function($candidate_row) {
                            return !empty($candidate_row['winner']);
                        }));
                        if ($group_category === '' || empty($group_rows)) {
                            continue;
                        }
                    ?>
                        <section class="aat-ceremony-ballot-group" id="ceremony-category-<?php echo esc_attr(sanitize_title($group_category)); ?>">
                            <header class="aat-ceremony-ballot-group-head">
                                <div>
                                    <p class="aat-hub-kicker"><?php echo esc_html__('Category', 'academy-awards-table'); ?></p>
                                    <h3>
                                        <?php echo $aat_render_hub_text_link($group_label, $group_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </h3>
                                </div>
                                <p class="aat-ceremony-ballot-group-count">
                                    <?php echo esc_html(sprintf(__('%1$s winner%2$s / %3$s record%4$s', 'academy-awards-table'), number_format_i18n($group_winner_count), $group_winner_count === 1 ? '' : 's', number_format_i18n(count($group_rows)), count($group_rows) === 1 ? '' : 's')); ?>
                                </p>
                            </header>
                            <?php if (!$ceremony_ballot_full_requested && $group_nominee_count > 0) : ?>
                                <div class="aat-ceremony-nominee-summary">
                                    <span>
                                        <?php echo esc_html(sprintf(
                                            /* translators: %d: number of non-winning nominee rows summarized in fast ceremony mode */
                                            _n('%d nominee summarized', '%d nominees summarized', $group_nominee_count, 'academy-awards-table'),
                                            $group_nominee_count
                                        )); ?>
                                    </span>
                                    <a class="aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url . '#ceremony-category-' . sanitize_title($group_category)); ?>"><?php echo esc_html__('Open Full Category Ballot', 'academy-awards-table'); ?></a>
                                </div>
                            <?php endif; ?>
                            <div class="aat-ceremony-ballot-rows">
                                <?php foreach ($rendered_group_rows as $ballot_row) :
                                    $ballot_row = $aat_enrich_winner_entry_links($ballot_row);
                                    $is_row_winner = !empty($ballot_row['winner']);
                                    $row_film_html = $aat_render_pipe_links($ballot_row['film'] ?? '', $ballot_row['film_id'] ?? '', 'aat-hub-inline-link aat-ballot-main-link');
                                    $row_credit_source = trim((string) ($ballot_row['nominees'] ?? ''));
                                    if ($row_credit_source === '') {
                                        $row_credit_source = trim((string) ($ballot_row['name'] ?? ''));
                                    }
                                    $row_credit_html = $aat_render_pipe_links($row_credit_source, $ballot_row['nominee_ids'] ?? '', 'aat-hub-inline-link');
                                    $row_film_plain = $aat_pipe_display($ballot_row['film'] ?? '');
                                    $row_credit_plain = $aat_clean_nominee_label($aat_pipe_display($row_credit_source));
                                    $row_primary_html = $row_film_html !== '' ? $row_film_html : $row_credit_html;
                                    $show_credit = $row_credit_html !== '' && $aat_normalize_comparable_name($row_credit_plain) !== $aat_normalize_comparable_name($row_film_plain);
                                    $row_primary_title_id = '';
                                    foreach (explode('|', (string) ($ballot_row['film_id'] ?? '')) as $row_title_id) {
                                        $row_title_id = strtolower(trim((string) $row_title_id));
                                        if (preg_match('/^tt\d+$/', $row_title_id)) {
                                            $row_primary_title_id = $row_title_id;
                                            break;
                                        }
                                    }
                                    $row_film_url = !empty($ballot_row['film_url']) ? (string) $ballot_row['film_url'] : ($row_primary_title_id !== '' ? $aat_build_entity_url($row_primary_title_id) : '');
                                    $row_film_history_url = !empty($ballot_row['film_history_url']) ? (string) $ballot_row['film_history_url'] : ($row_film_url !== '' ? $aat_entity_section_url($row_film_url, 'oscar-history') : '');
                                    $row_person_history_url = !empty($ballot_row['person_history_url']) ? (string) $ballot_row['person_history_url'] : '';
                                    $row_person_history_meta = $aat_person_history_action_meta($row_person_history_url, $ballot_row['canonical_category'] ?? '');
                                    $row_person_history_label = $row_person_history_meta['label'];
                                    $row_person_history_kind = $row_person_history_meta['kind'];
                                    $row_review_url = ($row_primary_title_id !== '' && !empty($ceremony_review_map[$row_primary_title_id])) ? (string) $ceremony_review_map[$row_primary_title_id] : '';
                                ?>
                                    <article class="aat-ceremony-ballot-row<?php echo $is_row_winner ? ' is-winner' : ''; ?>">
                                        <div class="aat-ceremony-ballot-status">
                                            <span class="<?php echo $is_row_winner ? 'aat-winner-badge' : 'aat-nominee-badge'; ?>"><?php echo esc_html($is_row_winner ? __('Winner', 'academy-awards-table') : __('Nominee', 'academy-awards-table')); ?></span>
                                        </div>
                                        <div class="aat-ceremony-ballot-copy">
                                            <?php if ($row_primary_html !== '') : ?>
                                                <h4><?php echo wp_kses_post($row_primary_html); ?></h4>
                                            <?php endif; ?>
                                            <?php if ($show_credit) : ?>
                                                <p class="aat-ballot-credit"><strong><?php echo esc_html__('Credit', 'academy-awards-table'); ?>:</strong> <?php echo wp_kses_post($row_credit_html); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($ballot_row['detail'])) : ?>
                                                <p class="aat-ballot-detail"><?php echo esc_html((string) $ballot_row['detail']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($ballot_row['note'])) : ?>
                                                <p class="aat-ballot-note"><?php echo esc_html((string) $ballot_row['note']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($row_film_history_url !== '' || $row_person_history_url !== '' || $row_review_url !== '') : ?>
                                            <div class="aat-ceremony-ballot-actions">
                                                <?php if ($row_film_history_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($row_film_history_url); ?>"><?php echo esc_html__('Film History', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($row_person_history_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-<?php echo esc_attr($row_person_history_kind); ?>" href="<?php echo esc_url($row_person_history_url); ?>"><?php echo esc_html($row_person_history_label); ?></a>
                                                <?php endif; ?>
                                                <?php if ($row_review_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-review" href="<?php echo esc_url($row_review_url); ?>"><?php echo esc_html__('Review', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php $ceremony_titles = method_exists($aat, 'get_ceremony_title_highlights') ? $aat->get_ceremony_title_highlights($ceremony, 18) : array(); ?>
        <?php $ceremony_review_cards = !empty($ceremony_titles) ? $aat_build_hub_review_cards($ceremony_titles, $aat_get_related_review_limit()) : array(); ?>
        <?php if (!empty($ceremony_titles)) : ?>
            <div class="aat-hub-section aat-ceremony-gallery-section">
                <h2><?php echo esc_html__('Ceremony Highlights', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('Poster-first highlights from this ceremony, led by winners and the titles that defined the night.', 'academy-awards-table'); ?></p>
                <div class="aat-filmography-grid aat-hub-film-grid">
                    <?php foreach ($ceremony_titles as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        if (!$fid) { continue; }
                        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium_large') : array();
                        $film_label = !empty($entry['film']) ? (string) $entry['film'] : $aat->lookup_title_label($fid);
                        $film_url = $aat->get_entity_url($fid);
                        $ceremony_title_backdrop_style = $aat_get_card_backdrop_style($visual['poster_url'] ?? '', $visual['backdrop_url'] ?? '');
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?><?php echo $ceremony_title_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($ceremony_title_backdrop_style !== '') : ?> style="<?php echo esc_attr($ceremony_title_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>">
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
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge">Winner</span><?php endif; ?>
                                </div>
                                <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                                <p class="aat-filmography-meta">
                                    <?php echo $aat_render_hub_text_link($aat->format_category_display($entry['canonical_category'] ?? ''), !empty($entry['category_url']) ? (string) $entry['category_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php if (!empty($entry['person_label'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span>
                                        <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php endif; ?>
                                </p>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($ceremony_review_cards)) : ?>
            <div class="aat-hub-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <h2><?php echo esc_html__('On Lunara', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__("Criticism from the Lunara archive tied to this ceremony's most visible contenders and winners.", 'academy-awards-table'); ?></p>
                <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                    <?php foreach ($ceremony_review_cards as $card) : ?>
                        <?php
                            $aat_related_review_has_media = !empty($card['review_thumb']) || !empty($card['fallback_html']);
                            $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                        ?>
                        <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                            <?php if ($aat_related_review_has_media) : ?>
                            <a class="aat-related-review-media" href="<?php echo esc_url($card['review_url']); ?>">
                                <?php if (!empty($card['review_thumb'])) : ?>
                                    <?php echo $card['review_thumb']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($card['fallback_html'])) : ?>
                                    <?php echo $card['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                            <div class="aat-related-review-body">
                                <div class="aat-related-review-kicker"><?php echo esc_html__('Lunara Film Review', 'academy-awards-table'); ?></div>
                                <h3 class="aat-related-review-title"><a href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html($card['review_title']); ?></a></h3>
                                <p class="aat-related-review-meta">
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html($card['film_label']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($card['film_label']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($card['film_year'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html($card['film_year']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="aat-related-review-excerpt"><?php echo esc_html__('Open the review and enter the full argument.', 'academy-awards-table'); ?></p>
                                <div class="aat-related-review-actions">
                                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html__('Read Review', 'academy-awards-table'); ?></a>
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html__('Title Profile', 'academy-awards-table'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($cats)) : ?>
            <div class="aat-hub-section">
                <h2><?php echo esc_html__('Categories in this ceremony', 'academy-awards-table'); ?></h2>
                <div class="aat-hub-chips">
                    <?php foreach ($cats as $cat) :
                        $url = $aat->get_category_url($cat);
                        $label = $aat->format_category_display($cat);
                    ?>
                        <a class="aat-hub-chip" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($ceremony_rollup['winner_rows'])) : ?>
            <div class="aat-hub-section aat-winner-circle-section<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?>" id="ceremony-winner-circle">
                <h2><?php echo esc_html__('Winner Circle', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('A category-by-category winner roll call generated directly from the ceremony ledger.', 'academy-awards-table'); ?></p>
                <div class="aat-winner-circle-grid<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?>">
                    <?php foreach ($ceremony_rollup['winner_rows'] as $winner_entry) :
                        $winner_entry = $aat_enrich_winner_entry_links($winner_entry);
                        $primary_label = $winner_entry['primary_label'] ?? $aat_winner_primary($winner_entry);
                        $secondary_label = $winner_entry['secondary_label'] ?? $aat_winner_secondary($winner_entry);
                        $category_url = $winner_entry['category_url'] ?? $aat->get_category_url($winner_entry['canonical_category'] ?? '');
                        $winner_visual = $is_latest_ceremony ? $aat_get_curated_winner_visual($winner_entry, 'medium_large') : array();
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
                        $winner_actions = $aat_build_winner_actions($winner_entry, (string) $category_url);
                    ?>
                        <article class="aat-winner-circle-card<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?><?php echo !empty($winner_visual['poster_url']) ? ' has-hero-media' : ''; ?>">
                            <div class="aat-winner-circle-top">
                                <?php if ($category_url) : ?>
                                    <a class="aat-winner-circle-category" href="<?php echo esc_url($category_url); ?>"><?php echo esc_html($winner_entry['category_label']); ?></a>
                                <?php else : ?>
                                    <span class="aat-winner-circle-category"><?php echo esc_html($winner_entry['category_label']); ?></span>
                                <?php endif; ?>
                                <span class="aat-winner-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span>
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

        <?php
            $table_view_url = add_query_arg('view', 'table');
            $poster_view_url = remove_query_arg('view');
        ?>
        <div class="aat-hub-section aat-explorer-callout">
            <div class="aat-explorer-shell">
                <div class="aat-explorer-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Research Mode', 'academy-awards-table'); ?></p>
                    <h2><?php echo esc_html__('Keep the ceremony page visual first. Open the raw table only when the research asks for it.', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy"><?php echo esc_html__('Poster view is the default Lunara surface. Data Explorer stays here for sortable rows, filters, and deep record-level digging when you actually need the raw ledger.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-hub-actions aat-view-toggle">
                    <a class="aat-btn aat-btn-secondary<?php echo !$table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($poster_view_url); ?>"><?php echo esc_html__('Poster View', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-primary<?php echo $table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($table_view_url); ?>"><?php echo esc_html__('Data Explorer', 'academy-awards-table'); ?></a>
                </div>
            </div>
        </div>

        <?php if ($table_view_requested) : ?>
            <div class="aat-hub-section aat-table-shell">
                <?php
                    echo $aat->render_shortcode(array(
                        'ceremony' => (string) $ceremony,
                        'layout' => 'embedded',
                    ));
                ?>
            </div>
        <?php endif; ?>
        </div>

    <?php
        // CATEGORY PAGE
        elseif ($hub === 'category') :
            $canonical = $aat->resolve_category_slug($hub_id);
            if (empty($canonical)) {
                $mark_404();
            }

            $label = $aat->format_category_display($canonical);
            $category_heading_label = $label;
            if ($category_heading_label !== '' && strtoupper($category_heading_label) === $category_heading_label) {
                $category_heading_label = ucwords(strtolower($category_heading_label));
                $category_heading_label = str_replace(
                    array(' And ', ' Of ', ' In ', ' For '),
                    array(' and ', ' of ', ' in ', ' for '),
                    $category_heading_label
                );
            }
            $category_summary = method_exists($aat, 'get_category_summary') ? $aat->get_category_summary($canonical) : array();
            $noms = intval($category_summary['nominations'] ?? 0);
            $wins = intval($category_summary['wins'] ?? 0);
            $cers = intval($category_summary['ceremonies'] ?? 0);
            $first_cer = intval($category_summary['first_ceremony'] ?? 0);
            $last_cer = intval($category_summary['last_ceremony'] ?? 0);
            $first_year = $first_cer ? $aat->get_ceremony_year($first_cer) : '';
            $last_year = $last_cer ? $aat->get_ceremony_year($last_cer) : '';
            $latest_winner = method_exists($aat, 'get_category_latest_winner') ? $aat->get_category_latest_winner($canonical) : array();
            if (!empty($latest_winner)) {
                $latest_winner = $aat_enrich_winner_entry_links($latest_winner);
            }
            $category_spotlight = array();
            $category_winner_rows = array();
            $category_key = strtoupper(trim((string) $canonical));
            $premium_category_profiles = array(
                'BEST PICTURE' => array(
                    'class' => 'aat-best-picture-dossier',
                    'kicker' => __('Oscar Ledger Dossier', 'academy-awards-table'),
                    'title' => __('Best Picture Dossier', 'academy-awards-table'),
                    'subtitle' => __('The Academy Awards top category, built as a living historical file: every winner, every ceremony trail, and the films that keep pulling readers deeper into the ledger.', 'academy-awards-table'),
                    'summary_label' => __('Best Picture ledger summary', 'academy-awards-table'),
                    'history_kicker' => __('Era Browser', 'academy-awards-table'),
                    'history_title' => __('Best Picture Through the Eras', 'academy-awards-table'),
                    'era_copy' => __('A visual entry point into the %1$s Best Picture run.', 'academy-awards-table'),
                ),
                'DIRECTING' => array(
                    'class' => 'aat-directing-dossier',
                    'title' => __('Best Director Dossier', 'academy-awards-table'),
                    'subtitle' => __('The director file: winners, nominated films, and the authorship trails that shaped Oscar history from studio craft to modern auteur campaigns.', 'academy-awards-table'),
                ),
                'ACTOR IN A LEADING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-actor-dossier',
                    'title' => __('Best Actor Dossier', 'academy-awards-table'),
                    'subtitle' => __('A performance ledger for leading men: every winning turn, every ceremony trail, and the films that made the race feel larger than a list of names.', 'academy-awards-table'),
                ),
                'ACTRESS IN A LEADING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-actress-dossier',
                    'title' => __('Best Actress Dossier', 'academy-awards-table'),
                    'subtitle' => __('A performance ledger for leading women: the winning turns, career pivots, star-making races, and ceremony trails that carry the category.', 'academy-awards-table'),
                ),
                'ACTOR IN A SUPPORTING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-supporting-actor-dossier',
                    'title' => __('Best Supporting Actor Dossier', 'academy-awards-table'),
                    'subtitle' => __('The supporting actor file: character turns, scene-stealing campaigns, and every Oscar trail behind the category.', 'academy-awards-table'),
                ),
                'ACTRESS IN A SUPPORTING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-supporting-actress-dossier',
                    'title' => __('Best Supporting Actress Dossier', 'academy-awards-table'),
                    'subtitle' => __('The supporting actress file: breakthrough performances, veteran coronations, and the ceremony trails that give the category its shape.', 'academy-awards-table'),
                ),
                'WRITING (ORIGINAL SCREENPLAY)' => array(
                    'class' => 'aat-writing-dossier',
                    'title' => __('Original Screenplay Dossier', 'academy-awards-table'),
                    'subtitle' => __('The original writing ledger: Academy-winning ideas, scripts, and narrative turns tracked through every ceremony trail.', 'academy-awards-table'),
                ),
                'WRITING (ADAPTED SCREENPLAY)' => array(
                    'class' => 'aat-writing-dossier',
                    'title' => __('Adapted Screenplay Dossier', 'academy-awards-table'),
                    'subtitle' => __('The adaptation file: books, plays, articles, lives, sequels, and source material transformed into Oscar-winning screen stories.', 'academy-awards-table'),
                ),
                'CINEMATOGRAPHY' => array(
                    'class' => 'aat-craft-dossier aat-cinematography-dossier',
                    'title' => __('Cinematography Dossier', 'academy-awards-table'),
                    'subtitle' => __('The image-making ledger: winning visual languages, photographed worlds, and the films that defined how Oscar saw the movies.', 'academy-awards-table'),
                ),
                'FILM EDITING' => array(
                    'class' => 'aat-craft-dossier aat-editing-dossier',
                    'title' => __('Film Editing Dossier', 'academy-awards-table'),
                    'subtitle' => __('The cutting-room file: pacing, structure, momentum, and the invisible craft behind the Academy winning edits.', 'academy-awards-table'),
                ),
                'SOUND MIXING' => array(
                    'class' => 'aat-craft-dossier aat-sound-mixing-dossier',
                    'title' => __('Sound Mixing Dossier', 'academy-awards-table'),
                    'subtitle' => __('The sound file: credited mixers, department records, studio-era traces, and the sonic craft trail behind Oscar history.', 'academy-awards-table'),
                    'history_kicker' => __('Sound Era Browser', 'academy-awards-table'),
                    'history_title' => __('Sound Mixing Through the Eras', 'academy-awards-table'),
                    'era_copy' => __('A visual entry point into the %1$s Sound Mixing run.', 'academy-awards-table'),
                ),
                'SOUND EDITING' => array(
                    'class' => 'aat-craft-dossier aat-sound-editing-dossier',
                    'title' => __('Sound Editing Dossier', 'academy-awards-table'),
                    'subtitle' => __('The editorial sound file: effects, design, cutting, sonic architecture, and the craft races that made Oscar history audible.', 'academy-awards-table'),
                    'history_kicker' => __('Sound Era Browser', 'academy-awards-table'),
                    'history_title' => __('Sound Editing Through the Eras', 'academy-awards-table'),
                    'era_copy' => __('A visual entry point into the %1$s Sound Editing run.', 'academy-awards-table'),
                ),
                'COSTUME DESIGN' => array(
                    'class' => 'aat-craft-dossier aat-costume-dossier',
                    'title' => __('Costume Design Dossier', 'academy-awards-table'),
                    'subtitle' => __('The costume file: period worlds, character silhouettes, and the design campaigns that turned fabric into Oscar history.', 'academy-awards-table'),
                ),
                'ART DIRECTION' => array(
                    'class' => 'aat-craft-dossier aat-art-direction-dossier',
                    'title' => __('Art Direction Dossier', 'academy-awards-table'),
                    'subtitle' => __('The production design file: sets, architecture, studio craft, color, space, and the built worlds that turned Oscar history into lived-in cinema.', 'academy-awards-table'),
                    'history_kicker' => __('Design Era Browser', 'academy-awards-table'),
                    'history_title' => __('Art Direction Through the Eras', 'academy-awards-table'),
                    'era_copy' => __('A visual entry point into the %1$s Art Direction run.', 'academy-awards-table'),
                ),
                'MAKEUP AND HAIRSTYLING' => array(
                    'class' => 'aat-craft-dossier aat-makeup-dossier',
                    'title' => __('Makeup and Hairstyling Dossier', 'academy-awards-table'),
                    'subtitle' => __('The transformation file: prosthetics, hair, aging, creature work, beauty, and the craft races that turned faces into Oscar history.', 'academy-awards-table'),
                    'history_kicker' => __('Transformation Era Browser', 'academy-awards-table'),
                    'history_title' => __('Makeup and Hairstyling Through the Eras', 'academy-awards-table'),
                    'era_copy' => __('A visual entry point into the %1$s Makeup and Hairstyling run.', 'academy-awards-table'),
                ),
                'VISUAL EFFECTS' => array(
                    'class' => 'aat-craft-dossier aat-effects-dossier',
                    'title' => __('Visual Effects Dossier', 'academy-awards-table'),
                    'subtitle' => __('The effects ledger: spectacle, invention, technical leaps, and the films that expanded what Academy voters could see on screen.', 'academy-awards-table'),
                ),
                'MUSIC (ORIGINAL SCORE)' => array(
                    'class' => 'aat-music-dossier',
                    'title' => __('Original Score Dossier', 'academy-awards-table'),
                    'subtitle' => __('The score file: composers, musical signatures, and the Academy-winning sounds that gave films their emotional architecture.', 'academy-awards-table'),
                ),
                'MUSIC (ORIGINAL SONG)' => array(
                    'class' => 'aat-music-dossier',
                    'title' => __('Original Song Dossier', 'academy-awards-table'),
                    'subtitle' => __('The song ledger: movie music as campaign engine, cultural memory, and Oscar-night signature.', 'academy-awards-table'),
                ),
                'INTERNATIONAL FEATURE FILM' => array(
                    'class' => 'aat-feature-dossier aat-international-dossier',
                    'title' => __('International Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The international feature file: countries, auteurs, breakthrough films, and the Academy trails that widened Oscar history beyond Hollywood.', 'academy-awards-table'),
                ),
                'ANIMATED FEATURE FILM' => array(
                    'class' => 'aat-feature-dossier aat-animation-dossier',
                    'title' => __('Animated Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The animation file: studio eras, hand-drawn legacies, digital dominance, and every Oscar trail behind the feature race.', 'academy-awards-table'),
                ),
                'DOCUMENTARY (FEATURE)' => array(
                    'class' => 'aat-feature-dossier aat-documentary-dossier',
                    'title' => __('Documentary Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The documentary feature file: nonfiction filmmaking, public memory, and the Oscar races that turned reportage into cinema history.', 'academy-awards-table'),
                ),
            );
            $premium_category_profile = isset($premium_category_profiles[$category_key]) ? $premium_category_profiles[$category_key] : array();
            $is_premium_category_dossier = !empty($premium_category_profile);
            if ($is_premium_category_dossier) {
                $premium_category_profile = array_merge(array(
                    'class' => '',
                    'kicker' => __('Oscar Ledger Dossier', 'academy-awards-table'),
                    'title' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s Dossier', 'academy-awards-table'),
                        $label
                    ),
                    'subtitle' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('A premium category file for %s: every winner, every ceremony trail, and the routes that keep the ledger explorable.', 'academy-awards-table'),
                        $label
                    ),
                    'summary_label' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s ledger summary', 'academy-awards-table'),
                        $label
                    ),
                    'history_kicker' => __('Era Browser', 'academy-awards-table'),
                    'history_title' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s Through the Eras', 'academy-awards-table'),
                        $label
                    ),
                    'era_copy' => __('A visual entry point into the %1$s %2$s run.', 'academy-awards-table'),
                ), $premium_category_profile);
            }
            $category_span_label = ($first_year && $last_year) ? ($first_year . '-' . $last_year) : '';
            $latest_ceremony_url = $last_cer ? $aat->get_ceremony_url($last_cer) : '';
            $latest_winner_label = '';
            if (!empty($latest_winner)) {
                $latest_winner_label = !empty($latest_winner['primary_label']) ? (string) $latest_winner['primary_label'] : (!empty($latest_winner['film']) ? (string) $latest_winner['film'] : (string) ($latest_winner['name'] ?? ''));
            }
    ?>
        <div class="aat-category-dossier aat-inner-route-system<?php echo $is_premium_category_dossier ? ' aat-premium-category-dossier ' . esc_attr((string) $premium_category_profile['class']) : ' aat-generic-category-dossier'; ?>">
        <?php if ($is_premium_category_dossier) : ?>
            <style>
                body .aat-container .aat-premium-category-dossier{min-width:0!important;max-width:100%!important;overflow:hidden!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-hero{display:grid!important;grid-template-columns:minmax(0,1.22fr) minmax(280px,.78fr)!important;gap:clamp(18px,3vw,34px)!important;align-items:stretch!important;min-width:0!important;max-width:100%!important;overflow:hidden!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-hero .aat-hub-title{color:var(--aat-white)!important;font-size:clamp(2.55rem,5.4vw,5.25rem)!important;line-height:.98!important;max-width:9.4ch!important;text-align:left!important;text-transform:none!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-hero-copy,body .aat-container .aat-premium-category-dossier .aat-hub-subtitle{min-width:0!important;max-width:100%!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-command-band{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important;align-self:center!important;min-width:0!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-command-card{display:grid!important;align-content:end!important;gap:8px!important;min-height:118px!important;padding:18px!important;border:1px solid rgba(201,169,97,.2)!important;border-radius:12px!important;background:linear-gradient(180deg,rgba(255,255,255,.052),rgba(255,255,255,.018)),rgba(6,15,26,.7)!important}
                body .aat-container .aat-premium-category-dossier .aat-dossier-command-card.is-latest{grid-column:1/-1!important;min-height:148px!important}
                body .aat-container .aat-premium-category-dossier .aat-era-chapter-visual{display:grid!important;grid-template-columns:minmax(96px,148px) minmax(0,1fr)!important;gap:18px!important;margin:0 0 18px!important;padding:14px!important}
                body .aat-container .aat-premium-category-dossier .aat-era-chapter-media{display:block!important;min-height:188px!important;max-width:148px!important;aspect-ratio:2/3!important}
                body .aat-container .aat-premium-category-dossier .aat-ledger-card{display:grid!important;grid-template-columns:minmax(136px,.26fr) minmax(0,1fr)!important;gap:16px!important}
                body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-category-history-actions{display:flex!important;flex-wrap:wrap!important;gap:6px!important;margin-top:8px!important;padding-top:8px!important}
                body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-winner-circle-action{width:auto!important;min-height:30px!important;padding:6px 10px!important;flex:0 1 auto!important}
                @media(max-width:900px){body .aat-container .aat-premium-category-dossier .aat-dossier-hero{grid-template-columns:minmax(0,1fr)!important}body .aat-container .aat-premium-category-dossier .aat-ledger-card{grid-template-columns:minmax(110px,.3fr) minmax(0,1fr)!important}}
                @media(max-width:620px){body .aat-container .aat-category-dossier.aat-premium-category-dossier{width:min(100%,calc(100vw - 24px))!important;max-width:calc(100vw - 24px)!important;margin-left:auto!important;margin-right:auto!important;overflow-x:hidden!important}body .aat-container .aat-premium-category-dossier .aat-dossier-command-band,body .aat-container .aat-premium-category-dossier .aat-era-chapter-visual,body .aat-container .aat-premium-category-dossier .aat-ledger-card{grid-template-columns:minmax(0,1fr)!important}body .aat-container .aat-premium-category-dossier .aat-dossier-hero{width:100%!important;padding:16px!important}body .aat-container .aat-premium-category-dossier .aat-dossier-hero .aat-hub-subtitle{max-width:29ch!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}body .aat-container .aat-premium-category-dossier .aat-dossier-command-card,body .aat-container .aat-premium-category-dossier .aat-dossier-command-card.is-latest{min-height:0!important;padding:15px!important}body .aat-container .aat-premium-category-dossier .aat-dossier-actions{display:grid!important;grid-template-columns:minmax(0,1fr)!important;width:100%!important;max-width:100%!important}body .aat-container .aat-premium-category-dossier .aat-dossier-actions .aat-btn{width:100%!important;min-width:0!important;max-width:100%!important;justify-content:center!important}body .aat-container .aat-premium-category-dossier .aat-era-chapter-media{min-height:0!important;max-width:158px!important;aspect-ratio:2/3!important}body .aat-container .aat-premium-category-dossier .aat-era-chapter-copy h4,body .aat-container .aat-premium-category-dossier .aat-era-chapter-copy p{max-width:29ch!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}body .aat-container .aat-premium-category-dossier .aat-ledger-card{padding:12px!important;gap:10px!important}body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-category-ceremony-meta{display:grid!important;justify-content:start!important;gap:4px!important}body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-category-history-title,body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-category-history-meta,body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-category-history-detail{max-width:29ch!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}body .aat-container .aat-premium-category-dossier .aat-ledger-card .aat-winner-circle-action{min-height:28px!important;padding:5px 9px!important;font-size:.63rem!important}}
                @media(max-width:620px){body .aat-container .aat-premium-category-dossier .aat-section-title{max-width:100%!important;font-size:clamp(1.46rem,8vw,1.92rem)!important;line-height:1.12!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-card{display:grid!important;grid-template-columns:minmax(0,1fr)!important;width:100%!important;max-width:100%!important;min-height:0!important;padding:12px!important;gap:12px!important}body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-media-link,body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-media{display:block!important;width:min(100%,148px)!important;max-width:148px!important;min-height:0!important;aspect-ratio:2/3!important}body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-body,body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-title,body .aat-container .aat-premium-category-dossier .aat-hub-spotlight-meta,body .aat-container .aat-premium-category-dossier .aat-hub-chip-stack,body .aat-container .aat-premium-category-dossier .aat-hub-chip{min-width:0!important;max-width:100%!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}body .aat-container .aat-premium-category-dossier .aat-hub-chip,body .aat-container .aat-premium-category-dossier .aat-winner-circle-action{justify-content:center!important;text-align:center!important;white-space:normal!important}}
            </style>
        <?php endif; ?>
        <?php if ($is_premium_category_dossier) : ?>
            <section class="aat-dossier-hero">
                <div class="aat-dossier-hero-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html((string) $premium_category_profile['kicker']); ?></p>
                    <h1 class="aat-hub-title"><?php echo esc_html((string) $premium_category_profile['title']); ?></h1>
                    <p class="aat-hub-subtitle"><?php echo esc_html((string) $premium_category_profile['subtitle']); ?></p>
                </div>
                <div class="aat-dossier-command-band" aria-label="<?php echo esc_attr((string) $premium_category_profile['summary_label']); ?>">
                    <div class="aat-dossier-command-card is-latest">
                        <span><?php echo esc_html__('Latest Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($latest_winner_label !== '' ? $latest_winner_label : __('Pending', 'academy-awards-table')); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($category_span_label !== '' ? $category_span_label : '-'); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($cers)); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Winners', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($wins)); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-dossier-actions">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('All Categories', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                    <?php if ($latest_ceremony_url !== '') : ?>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($latest_ceremony_url); ?>"><?php echo esc_html__('Latest Ceremony', 'academy-awards-table'); ?></a>
                    <?php endif; ?>
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>
        <?php else : ?>
            <section class="aat-dossier-hero aat-generic-category-hero">
                <div class="aat-dossier-hero-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Oscar Category File', 'academy-awards-table'); ?></p>
                    <h1 class="aat-hub-title"><?php echo esc_html($category_heading_label); ?></h1>
                    <p class="aat-hub-subtitle"><?php echo esc_html(sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('A working %s dossier with winners, craft credits, ceremony trails, and nominee history kept close to the surface.', 'academy-awards-table'),
                        $category_heading_label
                    )); ?></p>
                </div>
                <div class="aat-dossier-command-band" aria-label="<?php echo esc_attr(sprintf(__('%s category summary', 'academy-awards-table'), $category_heading_label)); ?>">
                    <div class="aat-dossier-command-card is-latest">
                        <span><?php echo esc_html__('Latest Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($latest_winner_label !== '' ? $latest_winner_label : __('Pending', 'academy-awards-table')); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($category_span_label !== '' ? $category_span_label : '-'); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($cers)); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Winners', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($wins)); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-dossier-actions">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('All Categories', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                    <?php if ($latest_ceremony_url !== '') : ?>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($latest_ceremony_url); ?>"><?php echo esc_html__('Latest Ceremony', 'academy-awards-table'); ?></a>
                    <?php endif; ?>
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($latest_winner)) : ?>
            <?php
                $category_spotlight_meta = array();
                if (!empty($latest_winner['year'])) {
                    $category_spotlight_meta[] = (string) $latest_winner['year'];
                }
                if (!empty($latest_winner['name']) && !empty($latest_winner['film']) && $latest_winner['name'] !== $latest_winner['film']) {
                    $category_spotlight_meta[] = (string) $latest_winner['name'];
                } elseif (!empty($latest_winner['detail'])) {
                    $category_spotlight_meta[] = (string) $latest_winner['detail'];
                }
                if (!empty($latest_winner['film_id'])) {
                    $category_spotlight = $aat_build_title_spotlight((string) $latest_winner['film_id'], (string) ($latest_winner['film'] ?? ''), $category_spotlight_meta, __('Latest winner', 'academy-awards-table'));
                }
            ?>
            <section class="aat-hub-section aat-category-latest-winner">
                <div class="aat-ceremony-marquee-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Latest Winner', 'academy-awards-table'); ?></p>
                    <?php $latest_winner_headline = !empty($latest_winner['primary_label']) ? (string) $latest_winner['primary_label'] : (!empty($latest_winner['film']) ? (string) $latest_winner['film'] : (string) ($latest_winner['name'] ?? '')); ?>
                    <h2><?php echo $aat_render_hub_text_link($latest_winner_headline, !empty($latest_winner['primary_url']) ? (string) $latest_winner['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
                    <p class="aat-hub-copy">
                        <?php echo esc_html(sprintf(__('Most recent winning record: %1$s ceremony (%2$s).', 'academy-awards-table'), $aat->ordinal(intval($latest_winner['ceremony'] ?? 0)), (string) ($latest_winner['year'] ?? ''))); ?>
                        <?php if (!empty($latest_winner['secondary_label'])) : ?>
                            <?php echo ' '; ?>
                            <?php echo $aat_render_hub_text_link((string) $latest_winner['secondary_label'], !empty($latest_winner['secondary_url']) ? (string) $latest_winner['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php elseif (!empty($latest_winner['detail'])) : ?>
                            <?php echo esc_html(' ' . $latest_winner['detail']); ?>
                        <?php elseif (!empty($latest_winner['name']) && !empty($latest_winner['film']) && $latest_winner['name'] !== $latest_winner['film']) : ?>
                            <?php echo ' '; ?>
                            <?php echo $aat_render_hub_text_link((string) $latest_winner['name'], !empty($latest_winner['person_url']) ? (string) $latest_winner['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="aat-ceremony-marquee-stack">
                    <?php if (!empty($category_spotlight)) : ?>
                        <?php $category_spotlight_backdrop_style = $aat_get_card_backdrop_style($category_spotlight['poster_url'] ?? '', $category_spotlight['backdrop_url'] ?? ''); ?>
                        <article class="aat-hub-spotlight-card<?php echo $category_spotlight_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($category_spotlight_backdrop_style !== '') : ?> style="<?php echo esc_attr($category_spotlight_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-hub-spotlight-media-link" href="<?php echo esc_url($category_spotlight['film_url']); ?>">
                                <div class="aat-hub-spotlight-media"<?php if (!empty($category_spotlight['backdrop_url'])) : ?> style="background-image: linear-gradient(180deg, rgba(3,10,22,.1), rgba(3,10,22,.78)), url('<?php echo esc_url($category_spotlight['backdrop_url']); ?>'); background-size: cover; background-position: center;"<?php endif; ?>>
                                    <?php if (!empty($category_spotlight['poster_html'])) : ?>
                                        <?php echo $category_spotlight['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($category_spotlight['poster_url'])) : ?>
                                        <img class="aat-hub-spotlight-poster" src="<?php echo esc_url($category_spotlight['poster_url']); ?>" alt="<?php echo esc_attr($category_spotlight['film_label']); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($category_spotlight['fallback_html'])) : ?>
                                        <?php echo $category_spotlight['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($category_spotlight['film_label']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($category_spotlight['badge_label'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html($category_spotlight['badge_label']); ?></span><?php endif; ?>
                                </div>
                            </a>
                            <div class="aat-hub-spotlight-body">
                                <h3 class="aat-hub-spotlight-title"><?php echo $aat_render_hub_text_link($category_spotlight['film_label'], $category_spotlight['film_url'], 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                                <?php if (!empty($category_spotlight['meta_lines'])) : ?>
                                    <p class="aat-hub-spotlight-meta"><?php echo $aat_join_meta($category_spotlight['meta_lines']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php elseif (!empty($latest_winner['film_url'])) : ?>
                        <?php
                            $latest_winner_visual = !empty($latest_winner['film_id']) ? $aat_get_visual_package((string) $latest_winner['film_id'], 'medium') : array();
                            $latest_winner_backdrop_style = $aat_get_card_backdrop_style($latest_winner_visual['poster_url'] ?? '', $latest_winner_visual['backdrop_url'] ?? '');
                        ?>
                        <a class="aat-hub-chip aat-hub-chip-rich<?php echo $latest_winner_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>" href="<?php echo esc_url($latest_winner['film_url']); ?>"<?php if ($latest_winner_backdrop_style !== '') : ?> style="<?php echo esc_attr($latest_winner_backdrop_style); ?>"<?php endif; ?>>
                            <strong><?php echo esc_html__('Open Film Page', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($latest_winner['film']); ?></span>
                        </a>
                    <?php endif; ?>
                    <div class="aat-hub-chip-stack">
                        <?php if (!empty($latest_winner['person_url']) && !empty($latest_winner['person_label'])) : ?>
                            <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($latest_winner['person_url']); ?>">
                                <strong><?php echo esc_html__('Open Winner', 'academy-awards-table'); ?></strong>
                                <span><?php echo esc_html($latest_winner['person_label']); ?></span>
                            </a>
                        <?php endif; ?>
                        <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($aat->get_ceremony_url(intval($latest_winner['ceremony'] ?? 0))); ?>">
                            <strong><?php echo esc_html__('Open Ceremony', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->ordinal(intval($latest_winner['ceremony'] ?? 0))); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></span>
                        </a>
                        <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($db_url); ?>">
                            <strong><?php echo esc_html__('Open Ledger', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($label); ?></span>
                        </a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($noms)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($wins)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($cers)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html($first_year && $last_year ? ($first_year . '-' . $last_year) : '-'); ?></span><span class="aat-stat-label"><?php echo esc_html__('Span', 'academy-awards-table'); ?></span></div>
        </div>

        <?php
        // Round 2 Step 2 (2026-05-24): Category History — decade-grouped ledger
        // with collapsible nominee trails. Replaces the legacy Year-by-Year Winners
        // section (which is still preserved below behind an if(false) guard for
        // easy revert).
        $category_decade_ledger = method_exists($aat, 'get_category_decade_ledger') ? $aat->get_category_decade_ledger($canonical) : array();
        $category_decade_buckets = !empty($category_decade_ledger['decades']) && is_array($category_decade_ledger['decades']) ? $category_decade_ledger['decades'] : array();
        $category_decade_counts  = !empty($category_decade_ledger['decade_counts']) && is_array($category_decade_ledger['decade_counts']) ? $category_decade_ledger['decade_counts'] : array();
        $category_decade_totals  = !empty($category_decade_ledger['totals']) && is_array($category_decade_ledger['totals']) ? $category_decade_ledger['totals'] : array('ceremonies' => 0, 'winners' => 0, 'nominees' => 0);
        $category_history_full_requested = isset($_GET['history']) && sanitize_key(wp_unslash($_GET['history'])) === 'full';
        $category_history_recent_trail_limit = (int) apply_filters('aat_category_history_recent_trail_limit', 12, $canonical);
        $category_history_recent_trail_limit = max(0, $category_history_recent_trail_limit);
        $category_history_rendered_ceremonies = 0;
        $category_history_full_url = add_query_arg('history', 'full');
        $category_history_fast_url = remove_query_arg('history');
        ?>
        <?php if (!empty($category_decade_buckets)) : ?>
            <div class="aat-hub-section aat-category-history<?php echo $is_premium_category_dossier ? ' aat-era-browser' : ''; ?>">
                <div class="aat-section-head">
                    <p class="aat-hub-kicker"><?php echo esc_html($is_premium_category_dossier ? (string) $premium_category_profile['history_kicker'] : __('Category Ledger', 'academy-awards-table')); ?></p>
                    <h2 class="aat-section-title"><?php echo esc_html($is_premium_category_dossier ? (string) $premium_category_profile['history_title'] : __('Category History', 'academy-awards-table')); ?></h2>
                    <p class="aat-section-description"><?php echo esc_html(sprintf(
                        /* translators: 1: ceremony count, 2: winner count, 3: nominee count */
                        $category_history_full_requested
                            ? __('%1$s ceremonies, %2$s winners, %3$s other nominees across the full Academy Awards span. Full nominee trails are loaded for every ceremony.', 'academy-awards-table')
                            : __('%1$s ceremonies, %2$s winners, %3$s other nominees across the full Academy Awards span. Fast view shows every winner and keeps older nominee trails linked through each ceremony ledger.', 'academy-awards-table'),
                        number_format_i18n((int) $category_decade_totals['ceremonies']),
                        number_format_i18n((int) $category_decade_totals['winners']),
                        number_format_i18n((int) $category_decade_totals['nominees'])
                    )); ?></p>
                    <div class="aat-category-history-mode">
                        <?php if ($category_history_full_requested) : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($category_history_fast_url); ?>"><?php echo esc_html__('Use Fast View', 'academy-awards-table'); ?></a>
                        <?php else : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($category_history_full_url); ?>"><?php echo esc_html__('Open Full Nominee Trails', 'academy-awards-table'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <nav class="aat-decade-nav" aria-label="<?php esc_attr_e('Jump to decade', 'academy-awards-table'); ?>">
                    <?php foreach ($category_decade_buckets as $decade_key => $decade_bucket) :
                        $decade_count = isset($category_decade_counts[$decade_key]) ? (int) $category_decade_counts[$decade_key] : count($decade_bucket['ceremonies']);
                    ?>
                        <a class="aat-decade-pill" href="#aat-decade-<?php echo esc_attr($decade_key); ?>">
                            <span class="aat-decade-pill-label"><?php echo esc_html($decade_bucket['label']); ?></span>
                            <span class="aat-decade-pill-count" aria-label="<?php echo esc_attr(sprintf(_n('%d ceremony', '%d ceremonies', $decade_count, 'academy-awards-table'), $decade_count)); ?>"><?php echo esc_html(number_format_i18n($decade_count)); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="aat-decade-groups">
                    <?php foreach ($category_decade_buckets as $decade_key => $decade_bucket) : ?>
                        <section class="aat-decade-group<?php echo $is_premium_category_dossier ? ' aat-era-chapter' : ''; ?>" id="aat-decade-<?php echo esc_attr($decade_key); ?>">
                            <h3 class="aat-decade-heading"><?php echo esc_html($decade_bucket['label']); ?></h3>

                            <?php
                            $era_spotlight = array();
                            if ($is_premium_category_dossier && !empty($decade_bucket['ceremonies']) && is_array($decade_bucket['ceremonies'])) {
                                foreach ($decade_bucket['ceremonies'] as $era_ceremony_data) {
                                    $era_winners = !empty($era_ceremony_data['winner_rows']) && is_array($era_ceremony_data['winner_rows']) ? $era_ceremony_data['winner_rows'] : array();
                                    foreach ($era_winners as $era_winner) {
                                        $era_film_id = strtolower(trim((string) ($era_winner['film_id'] ?? '')));
                                        if ($era_film_id === '') {
                                            continue;
                                        }
                                        $era_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($era_film_id, 'medium') : array();
                                        if (empty($era_visual['poster_html']) && empty($era_visual['poster_url']) && empty($era_visual['backdrop_url'])) {
                                            continue;
                                        }
                                        $era_spotlight = array(
                                            'entry'  => $aat_enrich_winner_entry_links($era_winner),
                                            'visual' => $era_visual,
                                        );
                                        break 2;
                                    }
                                }
                            }
                            ?>
                            <?php if (!empty($era_spotlight)) :
                                $era_entry = $era_spotlight['entry'];
                                $era_visual = $era_spotlight['visual'];
                                $era_film_label = !empty($era_entry['film']) ? (string) $era_entry['film'] : (string) ($era_entry['primary_label'] ?? '');
                                $era_film_url = !empty($era_entry['film_url']) ? (string) $era_entry['film_url'] : (!empty($era_entry['primary_url']) ? (string) $era_entry['primary_url'] : '');
                                $era_backdrop_style = $aat_get_card_backdrop_style($era_visual['poster_url'] ?? '', $era_visual['backdrop_url'] ?? '');
                            ?>
                                <article class="aat-era-chapter-visual<?php echo $era_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($era_backdrop_style !== '') : ?> style="<?php echo esc_attr($era_backdrop_style); ?>"<?php endif; ?>>
                                    <a class="aat-era-chapter-media" href="<?php echo esc_url($era_film_url !== '' ? $era_film_url : $db_url); ?>">
                                        <?php if (!empty($era_visual['poster_html'])) : ?>
                                            <?php echo $era_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php elseif (!empty($era_visual['poster_url'])) : ?>
                                            <img src="<?php echo esc_url($era_visual['poster_url']); ?>" alt="<?php echo esc_attr($era_film_label); ?> poster" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </a>
                                    <div class="aat-era-chapter-copy">
                                        <span class="aat-winner-badge"><?php echo esc_html__('Era Marker', 'academy-awards-table'); ?></span>
                                        <h4><?php echo $aat_render_hub_text_link($era_film_label, $era_film_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h4>
                                        <p><?php echo esc_html(sprintf((string) $premium_category_profile['era_copy'], $decade_bucket['label'], $label)); ?></p>
                                    </div>
                                </article>
                            <?php endif; ?>

                            <div class="aat-decade-ceremonies">
                                <?php foreach ($decade_bucket['ceremonies'] as $ceremony_data) :
                                    $cer          = isset($ceremony_data['ceremony']) ? (int) $ceremony_data['ceremony'] : 0;
                                    $cer_year     = isset($ceremony_data['year']) ? trim((string) $ceremony_data['year']) : '';
                                    $cer_url      = ($cer > 0 && method_exists($aat, 'get_ceremony_url')) ? $aat->get_ceremony_url($cer) : '';
                                    $winner_rows  = !empty($ceremony_data['winner_rows']) && is_array($ceremony_data['winner_rows']) ? $ceremony_data['winner_rows'] : array();
                                    $nominee_rows = !empty($ceremony_data['nominee_rows']) && is_array($ceremony_data['nominee_rows']) ? $ceremony_data['nominee_rows'] : array();
                                    $category_history_rendered_ceremonies++;
                                    $render_full_nominee_trail = $category_history_full_requested || $category_history_rendered_ceremonies <= $category_history_recent_trail_limit;
                                    $render_compact_actions = !$category_history_full_requested && !$render_full_nominee_trail;
                                ?>
                                    <article class="aat-category-ceremony-row aat-ledger-card<?php echo $is_premium_category_dossier ? ' is-premium-ledger-card' : ''; ?>">
                                        <header class="aat-category-ceremony-meta">
                                            <?php if ($cer_url !== '' && $cer > 0) : ?>
                                                <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($cer_url); ?>"><?php echo esc_html($aat->ordinal($cer)); ?> <?php esc_html_e('Ceremony', 'academy-awards-table'); ?></a>
                                            <?php elseif ($cer > 0) : ?>
                                                <span class="aat-timeline-link"><?php echo esc_html($aat->ordinal($cer)); ?> <?php esc_html_e('Ceremony', 'academy-awards-table'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($cer_year !== '') : ?>
                                                <span class="aat-category-ceremony-year"><?php echo esc_html($cer_year); ?></span>
                                            <?php endif; ?>
                                        </header>

                                        <?php foreach ($winner_rows as $winner_row) :
                                            $winner_row = $aat_enrich_winner_entry_links($winner_row);
                                            $winner_row['category_label'] = $label;
                                            $winner_actions  = $aat_build_winner_actions($winner_row, (string) ($winner_row['category_url'] ?? ''), $cer_url);
                                            $winner_people   = $aat_build_person_link_items($winner_row);
                                            if ($render_compact_actions && !empty($winner_actions)) {
                                                $winner_actions = array_values(array_filter($winner_actions, function($winner_action) {
                                                    $kind = isset($winner_action['kind']) ? sanitize_key((string) $winner_action['kind']) : '';
                                                    return $kind === 'ceremony';
                                                }));
                                            }
                                            $primary_label   = trim((string) ($winner_row['primary_label'] ?? ''));
                                            $secondary_label = trim((string) ($winner_row['secondary_label'] ?? ''));
                                        ?>
                                            <div class="aat-category-history-winner">
                                                <span class="aat-winner-badge"><?php esc_html_e('Winner', 'academy-awards-table'); ?></span>
                                                <?php if ($primary_label !== '') : ?>
                                                    <h4 class="aat-category-history-title"><?php echo $aat_render_hub_text_link($primary_label, !empty($winner_row['primary_url']) ? (string) $winner_row['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h4>
                                                <?php endif; ?>
                                                <?php if ($secondary_label !== '') : ?>
                                                    <p class="aat-category-history-meta"><?php echo $aat_render_hub_text_link($secondary_label, !empty($winner_row['secondary_url']) ? (string) $winner_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_row['detail'])) : ?>
                                                    <p class="aat-category-history-detail"><?php echo esc_html((string) $winner_row['detail']); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_people) && (count($winner_people) > 1 || empty($winner_row['primary_url']))) : ?>
                                                    <div class="aat-category-person-strip" aria-label="<?php echo esc_attr__('Linked craft credits', 'academy-awards-table'); ?>">
                                                        <span class="aat-category-person-strip-label"><?php echo esc_html__('Craft Credits', 'academy-awards-table'); ?></span>
                                                        <?php foreach ($winner_people as $person_item) : ?>
                                                            <a class="aat-category-person-chip" href="<?php echo esc_url($person_item['url']); ?>"><?php echo esc_html($person_item['label']); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_actions)) : ?>
                                                    <div class="aat-category-history-actions">
                                                        <?php foreach ($winner_actions as $winner_action) : ?>
                                                            <a class="aat-hub-card-action aat-winner-circle-action<?php echo !empty($winner_action['kind']) ? ' is-kind-' . sanitize_html_class((string) $winner_action['kind']) : ''; ?>" href="<?php echo esc_url($winner_action['url']); ?>"><?php echo esc_html($winner_action['label']); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <?php if (!empty($nominee_rows) && $render_full_nominee_trail) : ?>
                                            <details class="aat-nominee-trail">
                                                <summary class="aat-nominee-trail-summary">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %d: number of other nominees in this ceremony for this category */
                                                        _n('%d other nominee', '%d other nominees', count($nominee_rows), 'academy-awards-table'),
                                                        count($nominee_rows)
                                                    )); ?>
                                                </summary>
                                                <ul class="aat-nominee-trail-list">
                                                    <?php foreach ($nominee_rows as $nominee_row) :
                                                        $nominee_row = $aat_enrich_winner_entry_links($nominee_row);
                                                        $nominee_row['category_label'] = $label;
                                                        $nominee_primary   = trim((string) ($nominee_row['primary_label'] ?? ''));
                                                        $nominee_secondary = trim((string) ($nominee_row['secondary_label'] ?? ''));
                                                        $nominee_people    = $aat_build_person_link_items($nominee_row);
                                                        $nominee_deep_links = array();
                                                        if (!empty($nominee_row['film_history_url'])) {
                                                            $nominee_deep_links[] = array(
                                                                'label' => __('Film History', 'academy-awards-table'),
                                                                'url' => (string) $nominee_row['film_history_url'],
                                                                'kind' => 'film-history',
                                                            );
                                                        }
                                                        if (!empty($nominee_row['person_history_url'])) {
                                                            $nominee_history_url = (string) $nominee_row['person_history_url'];
                                                            $nominee_history_meta = $aat_person_history_action_meta($nominee_history_url, $nominee_row['canonical_category'] ?? '');
                                                            $nominee_deep_links[] = array(
                                                                'label' => $nominee_history_meta['label'],
                                                                'url' => $nominee_history_url,
                                                                'kind' => $nominee_history_meta['kind'],
                                                            );
                                                        }
                                                    ?>
                                                        <li class="aat-nominee-trail-item">
                                                            <span class="aat-nominee-badge"><?php esc_html_e('Nominee', 'academy-awards-table'); ?></span>
                                                            <?php if ($nominee_primary !== '') : ?>
                                                                <span class="aat-nominee-primary"><?php echo $aat_render_hub_text_link($nominee_primary, !empty($nominee_row['primary_url']) ? (string) $nominee_row['primary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($nominee_secondary !== '') : ?>
                                                                <span class="aat-nominee-secondary"><?php echo $aat_render_hub_text_link($nominee_secondary, !empty($nominee_row['secondary_url']) ? (string) $nominee_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_row['detail'])) : ?>
                                                                <span class="aat-nominee-detail"><?php echo esc_html((string) $nominee_row['detail']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_people) && (count($nominee_people) > 1 || empty($nominee_row['primary_url']))) : ?>
                                                                <span class="aat-category-person-strip is-compact" aria-label="<?php echo esc_attr__('Linked nominee credits', 'academy-awards-table'); ?>">
                                                                    <span class="aat-category-person-strip-label"><?php echo esc_html__('Credits', 'academy-awards-table'); ?></span>
                                                                    <?php foreach ($nominee_people as $person_item) : ?>
                                                                        <a class="aat-category-person-chip" href="<?php echo esc_url($person_item['url']); ?>"><?php echo esc_html($person_item['label']); ?></a>
                                                                    <?php endforeach; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_deep_links)) : ?>
                                                                <span class="aat-nominee-trail-actions">
                                                                    <?php foreach ($nominee_deep_links as $nominee_deep_link) : ?>
                                                                        <a class="aat-winner-circle-action<?php echo !empty($nominee_deep_link['kind']) ? ' is-kind-' . sanitize_html_class((string) $nominee_deep_link['kind']) : ''; ?>" href="<?php echo esc_url($nominee_deep_link['url']); ?>"><?php echo esc_html($nominee_deep_link['label']); ?></a>
                                                                    <?php endforeach; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php elseif (!empty($nominee_rows)) : ?>
                                            <div class="aat-nominee-trail aat-nominee-trail-compact">
                                                <span class="aat-nominee-trail-summary">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %d: number of other nominees in this ceremony for this category */
                                                        _n('%d other nominee', '%d other nominees', count($nominee_rows), 'academy-awards-table'),
                                                        count($nominee_rows)
                                                    )); ?>
                                                </span>
                                                <?php if ($cer_url !== '' && !$render_compact_actions) : ?>
                                                    <a class="aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($cer_url); ?>"><?php echo esc_html__('Open Ceremony Ledger', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // LEGACY YEAR-BY-YEAR WINNERS — disabled 2026-05-24, superseded by Category History above. ?>
        <?php if (false && !empty($category_winner_rows)) : ?>
            <div class="aat-hub-section aat-category-year-ledger">
                <div class="aat-section-head">
                    <h2 class="aat-section-title"><?php echo esc_html__('Year-by-Year Winners', 'academy-awards-table'); ?></h2>
                    <p class="aat-section-description"><?php echo esc_html__('A readable ceremony-by-ceremony trail for this category, so the posters below have historical context.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-year-ledger-list">
                    <?php foreach ($category_winner_rows as $winner_row) :
                        $winner_row = $aat_enrich_winner_entry_links($winner_row);
                        $winner_row['category_label'] = $label;
                        $row_ceremony = intval($winner_row['ceremony'] ?? 0);
                        $row_year = trim((string) ($winner_row['year'] ?? ''));
                        $row_ceremony_url = $row_ceremony > 0 ? $aat->get_ceremony_url($row_ceremony) : '';
                        $winner_actions = $aat_build_winner_actions($winner_row, (string) ($winner_row['category_url'] ?? ''), $row_ceremony_url);
                        $primary_label = trim((string) ($winner_row['primary_label'] ?? ''));
                        $secondary_label = trim((string) ($winner_row['secondary_label'] ?? ''));
                    ?>
                        <article class="aat-year-ledger-card">
                            <div class="aat-timeline-meta">
                                <div class="aat-timeline-ceremony">
                                    <?php if ($row_ceremony_url) : ?>
                                        <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($row_ceremony_url); ?>"><?php echo esc_html($aat->ordinal($row_ceremony)); ?> <?php echo esc_html__('Ceremony', 'academy-awards-table'); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($aat->ordinal($row_ceremony)); ?> <?php echo esc_html__('Ceremony', 'academy-awards-table'); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="aat-timeline-year"><?php echo esc_html($row_year); ?></div>
                            </div>
                            <div class="aat-year-ledger-body">
                                <span class="aat-winner-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span>
                                <?php if ($primary_label !== '') : ?>
                                    <h3 class="aat-year-ledger-title">
                                        <?php echo $aat_render_hub_text_link($primary_label, !empty($winner_row['primary_url']) ? (string) $winner_row['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </h3>
                                <?php endif; ?>
                                <?php if ($secondary_label !== '') : ?>
                                    <p class="aat-year-ledger-meta">
                                        <?php echo $aat_render_hub_text_link($secondary_label, !empty($winner_row['secondary_url']) ? (string) $winner_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($winner_row['detail'])) : ?>
                                    <p class="aat-year-ledger-detail"><?php echo esc_html((string) $winner_row['detail']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($winner_actions)) : ?>
                                    <div class="aat-year-ledger-actions">
                                        <?php foreach ($winner_actions as $winner_action) : ?>
                                            <a class="aat-hub-card-action aat-winner-circle-action<?php echo !empty($winner_action['kind']) ? ' is-kind-' . sanitize_html_class((string) $winner_action['kind']) : ''; ?>" href="<?php echo esc_url($winner_action['url']); ?>"><?php echo esc_html($winner_action['label']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php $category_highlight_limit = $category_history_full_requested ? 18 : (int) apply_filters('aat_category_title_highlights_fast_limit', 9, $canonical); ?>
        <?php $category_highlight_limit = max(6, min(18, $category_highlight_limit)); ?>
        <?php $category_titles = method_exists($aat, 'get_category_title_highlights') ? $aat->get_category_title_highlights($canonical, $category_highlight_limit) : array(); ?>
        <?php $category_review_cards = !empty($category_titles) ? $aat_build_hub_review_cards($category_titles, $aat_get_related_review_limit()) : array(); ?>
        <?php if (!empty($category_titles)) : ?>
            <div class="aat-hub-section aat-category-gallery-section">
                <h2><?php echo esc_html__('Category Highlights', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('Poster-first highlights from this category across the full span of the Oscar ledger.', 'academy-awards-table'); ?></p>
                <div class="aat-filmography-grid aat-hub-film-grid">
                    <?php foreach ($category_titles as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        if (!$fid) { continue; }
                        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium') : array();
                        $film_label = !empty($entry['film']) ? (string) $entry['film'] : $aat->lookup_title_label($fid);
                        $film_url = $aat->get_entity_url($fid);
                        $category_title_backdrop_style = $aat_get_card_backdrop_style($visual['poster_url'] ?? '', $visual['backdrop_url'] ?? '');
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?><?php echo $category_title_backdrop_style !== '' ? ' aat-card-has-backdrop' : ''; ?>"<?php if ($category_title_backdrop_style !== '') : ?> style="<?php echo esc_attr($category_title_backdrop_style); ?>"<?php endif; ?>>
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>">
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
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge">Winner</span><?php endif; ?>
                                </div>
                                <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                                <p class="aat-filmography-meta">
                                    <?php if (!empty($entry['year'])) : ?><span><?php echo esc_html($entry['year']); ?></span><?php endif; ?>
                                    <?php if (!empty($entry['person_label'])) : ?>
                                        <?php if (!empty($entry['year'])) : ?><span class="aat-meta-sep" aria-hidden="true">&middot;</span><?php endif; ?>
                                        <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php endif; ?>
                                </p>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($category_review_cards)) : ?>
            <div class="aat-hub-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <h2><?php echo esc_html__('On Lunara', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('Criticism from the Lunara archive connected to the films that define this Oscar category.', 'academy-awards-table'); ?></p>
                <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                    <?php foreach ($category_review_cards as $card) : ?>
                        <?php
                            $aat_related_review_has_media = !empty($card['review_thumb']) || !empty($card['fallback_html']);
                            $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                        ?>
                        <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                            <?php if ($aat_related_review_has_media) : ?>
                            <a class="aat-related-review-media" href="<?php echo esc_url($card['review_url']); ?>">
                                <?php if (!empty($card['review_thumb'])) : ?>
                                    <?php echo $card['review_thumb']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($card['fallback_html'])) : ?>
                                    <?php echo $card['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                            <div class="aat-related-review-body">
                                <div class="aat-related-review-kicker"><?php echo esc_html__('Lunara Film Review', 'academy-awards-table'); ?></div>
                                <h3 class="aat-related-review-title"><a href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html($card['review_title']); ?></a></h3>
                                <p class="aat-related-review-meta">
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html($card['film_label']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($card['film_label']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($card['film_year'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html($card['film_year']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="aat-related-review-excerpt"><?php echo esc_html__('Open the review and enter the full argument.', 'academy-awards-table'); ?></p>
                                <div class="aat-related-review-actions">
                                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html__('Read Review', 'academy-awards-table'); ?></a>
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html__('Title Profile', 'academy-awards-table'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
            $table_view_url = add_query_arg('view', 'table');
            $poster_view_url = remove_query_arg('view');
        ?>
        <div class="aat-hub-section aat-explorer-callout">
            <div class="aat-explorer-shell">
                <div class="aat-explorer-copy">
                    <p class="aat-hub-kicker"><?php echo esc_html__('Research Mode', 'academy-awards-table'); ?></p>
                    <h2><?php echo esc_html__('Lead with posters and winners. Open the raw table only when the category work turns forensic.', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy"><?php echo esc_html__('This keeps category pages fast, visual, and browseable while preserving the sortable dataset as an optional research surface.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-hub-actions aat-view-toggle">
                    <a class="aat-btn aat-btn-secondary<?php echo !$table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($poster_view_url); ?>"><?php echo esc_html__('Poster View', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-primary<?php echo $table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($table_view_url); ?>"><?php echo esc_html__('Data Explorer', 'academy-awards-table'); ?></a>
                </div>
            </div>
        </div>

        <?php if ($table_view_requested) : ?>
            <div class="aat-hub-section aat-table-shell">
                <?php
                    echo $aat->render_shortcode(array(
                        'category' => $canonical,
                        'layout' => 'embedded',
                    ));
                ?>
            </div>
        <?php endif; ?>
        </div>

    <?php
        // Unknown hub
        else :
            $mark_404();
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Not Found', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('This page does not exist in the Lunara Oscar Ledger.', 'academy-awards-table'); ?></p>
            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>
    <?php endif; ?>

</div>


<style>
.aat-hub-film-grid .aat-filmography-card{position:relative}
.aat-hub-film-card.is-winner .aat-filmography-poster-wrap{box-shadow:0 0 0 1px rgba(212,175,55,.45),0 18px 40px rgba(0,0,0,.35)}
.aat-card-badge{position:absolute;top:10px;right:10px;z-index:2}
.aat-ceremony-gallery-section .aat-filmography-title,.aat-category-gallery-section .aat-filmography-title{font-size:1rem;line-height:1.2}
.aat-hub-card-link{color:inherit;text-decoration:none}
.aat-hub-card-action{margin-top:auto;font-size:.78rem;letter-spacing:.08em;text-transform:uppercase}
.aat-hub-spotlight-media-link{display:block;color:inherit;text-decoration:none}
.aat-hub-inline-link{color:inherit;text-decoration:none;border-bottom:1px solid rgba(212,175,55,.35);transition:border-color .18s ease,color .18s ease}
.aat-hub-inline-link:hover,.aat-hub-inline-link:focus{color:#f2d47a;border-bottom-color:rgba(242,212,122,.82)}
.aat-hub-inline-link-title{border-bottom:none}
.aat-winner-circle-meta a{color:inherit;text-decoration:none;border-bottom:1px solid rgba(212,175,55,.35)}
.aat-winner-circle-meta a:hover,.aat-winner-circle-meta a:focus{color:#f2d47a;border-bottom-color:rgba(242,212,122,.82)}
@media(max-width:620px){
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-card{display:grid!important;grid-template-columns:minmax(0,1fr)!important;width:100%!important;max-width:100%!important;min-height:0!important;padding:12px!important;gap:12px!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-media-link,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-media{display:block!important;width:min(100%,148px)!important;max-width:148px!important;min-width:0!important;min-height:0!important;aspect-ratio:2/3!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-body,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-title,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-spotlight-meta,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip-stack,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip{min-width:0!important;max-width:100%!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-section-title{max-width:100%!important;font-size:clamp(1.46rem,8vw,1.92rem)!important;line-height:1.12!important;white-space:normal!important;overflow-wrap:anywhere!important;text-wrap:auto!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-winner-circle-action{justify-content:center!important;text-align:center!important;white-space:normal!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip{display:grid!important;justify-items:center!important;gap:2px!important;line-height:1.18!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip strong,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-hub-chip span{display:block!important;width:100%!important;min-width:0!important;max-width:100%!important;overflow-wrap:anywhere!important;white-space:normal!important}
body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-category-latest-winner .aat-winner-circle-action,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-category-history-actions .aat-winner-circle-action,body .aat-container .aat-category-dossier.aat-premium-category-dossier .aat-nominee-trail-actions .aat-winner-circle-action{width:100%!important;max-width:100%!important;flex:1 1 100%!important;min-width:0!important;padding-left:10px!important;padding-right:10px!important;font-size:.56rem!important;letter-spacing:.055em!important;line-height:1.18!important;overflow-wrap:anywhere!important}
}
</style>

<?php
get_footer();
?>
