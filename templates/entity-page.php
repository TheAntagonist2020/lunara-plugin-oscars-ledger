<?php
/**
 * Academy Awards Table - Entity Page Template
 * Richer Film / Person / Company pages for Lunara Film
 */

if (!defined('ABSPATH')) {
    exit;
}

$aat = Academy_Awards_Table::get_instance();

$entity = sanitize_text_field(get_query_var('aat_entity'));
$id = sanitize_text_field(get_query_var('aat_entity_id'));

$rows = $aat->get_entity_rows($entity, $id);
$label = sanitize_text_field($aat->get_entity_display_name($entity, $id));
$label = trim((string) $label);

if (empty($rows)) {
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
}

$ordinal = function($n) {
    $n = intval($n);
    if ($n <= 0) return '';
    $s = array('th', 'st', 'nd', 'rd');
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
};

$format_category = function($cat) {
    $cat = trim((string) $cat);
    if ($cat === '') return '';
    $category_key = strtoupper($cat);
    $map = array(
        'ACTOR IN A LEADING ROLE' => 'Best Actor',
        'ACTRESS IN A LEADING ROLE' => 'Best Actress',
        'ACTOR IN A SUPPORTING ROLE' => 'Best Supporting Actor',
        'ACTRESS IN A SUPPORTING ROLE' => 'Best Supporting Actress',
        'BEST PICTURE' => 'Best Picture',
        'DIRECTING' => 'Best Director',
        'WRITING (ORIGINAL SCREENPLAY)' => 'Original Screenplay',
        'WRITING (ADAPTED SCREENPLAY)' => 'Adapted Screenplay',
    );
    return $map[$category_key] ?? ucwords(strtolower($cat));
};

$build_entity_url = function($id) use ($aat) {
    $id = trim((string) $id);
    return $id !== '' ? esc_url($aat->build_entity_url_from_id($id)) : '';
};

$build_imdb_url = function($id) use ($aat) {
    $id = trim((string) $id);
    return $id !== '' ? $aat->build_imdb_url($id) : '';
};

$build_ceremony_url = function($ceremony) use ($aat) {
    $ceremony = intval($ceremony);
    if ($ceremony <= 0) {
        return '';
    }

    return $aat->get_ceremony_url($ceremony);
};

$pipe_separator_html = '<span class="aat-sep"> &middot; </span>';

$render_linked_pipe = function($value_list, $id_list) use ($aat, $build_entity_url, $pipe_separator_html) {
    $values = array_values(array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen'));
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) $id_list)), 'strlen'));

    if (empty($values)) {
        return '<span class="aat-no-film">&mdash;</span>';
    }

    if (!empty($ids) && count($ids) === count($values)) {
        $out = array();
        foreach ($values as $i => $value) {
            $resolved_id = (string) ($ids[$i] ?? '');
            if (method_exists($aat, 'canonicalize_name_entity_id_for_label')) {
                $resolved_id = $aat->canonicalize_name_entity_id_for_label($resolved_id, $value);
            }
            $url = $build_entity_url($resolved_id);
            if ($url) {
                $out[] = '<a class="aat-entity-link" href="' . esc_url($url) . '">' . esc_html($value) . '</a>';
            } else {
                $out[] = '<span class="aat-entity-text">' . esc_html($value) . '</span>';
            }
        }

        return implode($pipe_separator_html, $out);
    }

    return implode($pipe_separator_html, array_map(function($value) {
        return '<span class="aat-entity-text">' . esc_html($value) . '</span>';
    }, $values));
};

$get_primary_pipe_value = function($value_list) {
    $values = array_values(array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen'));

    return !empty($values[0]) ? (string) $values[0] : '';
};

$title_visual_cache = array();
$title_label_cache = array();
$get_title_visual = function($title_id, $size = 'large') use ($aat, &$title_visual_cache) {
    $title_id = trim((string) $title_id);
    if ($title_id === '' || !method_exists($aat, 'get_title_visual_package')) {
        return array();
    }

    $cache_key = $title_id . '|' . $size;
    if (!array_key_exists($cache_key, $title_visual_cache)) {
        $visual = $aat->get_title_visual_package($title_id, $size);
        $title_visual_cache[$cache_key] = is_array($visual) ? $visual : array();
    }

    return $title_visual_cache[$cache_key];
};

$get_title_label = function($title_id) use ($aat, &$title_label_cache) {
    $title_id = strtolower(trim((string) $title_id));
    if ($title_id === '' || !preg_match('/^tt\d+$/', $title_id)) {
        return '';
    }

    if (!array_key_exists($title_id, $title_label_cache)) {
        $label = method_exists($aat, 'lookup_title_label') ? $aat->lookup_title_label($title_id) : '';
        if ($label === '' && method_exists($aat, 'get_entity_display_name')) {
            $label = (string) $aat->get_entity_display_name('title', $title_id);
        }
        $title_label_cache[$title_id] = trim((string) $label);
    }

    return $title_label_cache[$title_id];
};

$get_visual_backdrop_style = function($visual_package, $args = array()) {
    $visual_package = is_array($visual_package) ? $visual_package : array();
    if (empty($visual_package)) {
        return '';
    }

    $prefer_poster = !empty($args['prefer_poster']);
    $position = !empty($args['position']) ? (string) $args['position'] : 'center';
    $overlay = !empty($args['overlay'])
        ? (string) $args['overlay']
        : "linear-gradient(180deg, rgba(5, 13, 24, 0.58), rgba(5, 13, 24, 0.94)), radial-gradient(circle at top right, rgba(201,169,97,.18), rgba(201,169,97,0) 44%)";

    $sources = $prefer_poster
        ? array(
            $visual_package['poster_url'] ?? '',
            $visual_package['backdrop_url'] ?? '',
            $visual_package['portrait_url'] ?? '',
        )
        : array(
            $visual_package['backdrop_url'] ?? '',
            $visual_package['poster_url'] ?? '',
            $visual_package['portrait_url'] ?? '',
        );

    $background_url = '';
    foreach ($sources as $source) {
        $source = trim((string) $source);
        if ($source !== '') {
            $background_url = $source;
            break;
        }
    }

    if ($background_url === '') {
        return '';
    }

    return sprintf(
        "background-image:%s, url('%s'); background-size: cover; background-position: %s;",
        $overlay,
        esc_url($background_url),
        esc_attr($position)
    );
};

$normalize_comparable_name = function($value) {
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

$resolve_title_nominee_display = function($row) use ($normalize_comparable_name, $aat) {
    $explicit_name = trim((string) ($row['name'] ?? ''));
    $nominee_value = trim((string) ($row['nominees'] ?? ''));
    $nominee_ids = trim((string) ($row['nominee_ids'] ?? ''));
    $nominee_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_value)), 'strlen'));
    $id_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_ids)), 'strlen'));
    $single_nominee_id = count($id_parts) === 1 ? strtolower((string) $id_parts[0]) : '';

    if ($explicit_name === '') {
        return array(
            'label' => $nominee_value,
            'ids' => $nominee_ids,
            'is_plural' => strpos($nominee_value, '|') !== false,
        );
    }

    $matched_ids = $nominee_ids;

    if (!empty($nominee_parts) && count($nominee_parts) === count($id_parts) && count($id_parts) > 1) {
        $target = $normalize_comparable_name($explicit_name);
        $matches = array();

        foreach ($nominee_parts as $index => $nominee_part) {
            if ($normalize_comparable_name($nominee_part) === $target && isset($id_parts[$index])) {
                $matches[] = $id_parts[$index];
            }
        }

        if (count($matches) === 1) {
            $matched_ids = $matches[0];
        } else {
            return array(
                'label' => implode('|', $nominee_parts),
                'ids' => implode('|', $id_parts),
                'is_plural' => true,
            );
        }
    }

    if ($explicit_name !== '' && $single_nominee_id !== '' && preg_match('/^(nm|co)\d+$/', $single_nominee_id)) {
        $entity_type = strpos($single_nominee_id, 'co') === 0 ? 'company' : 'name';
        $preferred_label = trim((string) $aat->get_entity_display_name($entity_type, $single_nominee_id));
        if ($preferred_label !== '') {
            $explicit_name = $preferred_label;
        }
    }

    return array(
        'label' => $explicit_name,
        'ids' => $matched_ids,
        'is_plural' => false,
    );
};

$total_nominations = is_array($rows) ? count($rows) : 0;
$total_wins = 0;
$categories_set = array();
$ceremonies_set = array();
$ceremony_year_map = array();
$distinct_films = array();
$timeline = array();
$category_rollups = array();
$ceremony_rollups = array();
$latest_year = '';
$latest_ceremony = 0;

if (is_array($rows)) {
    foreach ($rows as $r) {
        $winner = (!empty($r['winner']) && (int) $r['winner'] === 1);
        if ($winner) $total_wins++;

        $cat = (string) ($r['canonical_category'] ?? $r['category'] ?? '');
        if ($cat !== '') {
            $categories_set[$cat] = true;
            if (!isset($category_rollups[$cat])) {
                $category_rollups[$cat] = array(
                    'category' => $cat,
                    'label' => $format_category($cat),
                    'url' => $aat->get_category_url($cat),
                    'nominations' => 0,
                    'wins' => 0,
                );
            }
            $category_rollups[$cat]['nominations']++;
            if ($winner) {
                $category_rollups[$cat]['wins']++;
            }
        }

        $cer = intval($r['ceremony'] ?? 0);
        $year = (string) ($r['year'] ?? '');
        if ($cer > 0) {
            $ceremonies_set[$cer] = true;
            $ceremony_year_map[$cer] = $year;
            if (!isset($ceremony_rollups[$cer])) {
                $ceremony_rollups[$cer] = array(
                    'ceremony' => $cer,
                    'year' => $year,
                    'url' => $build_ceremony_url($cer),
                    'nominations' => 0,
                    'wins' => 0,
                );
            }
            $ceremony_rollups[$cer]['nominations']++;
            if ($winner) {
                $ceremony_rollups[$cer]['wins']++;
            }
            if ($cer > $latest_ceremony) {
                $latest_ceremony = $cer;
                $latest_year = $year;
            }

            if (!isset($timeline[$cer])) {
                $timeline[$cer] = array(
                    'year' => $year,
                    'rows' => array(),
                );
            }
            $timeline[$cer]['rows'][] = $r;
        }

        if ($entity !== 'title') {
            $film_ids = array_filter(array_map('trim', explode('|', (string) ($r['film_id'] ?? ''))), 'strlen');
            foreach ($film_ids as $fid) {
                if (!isset($distinct_films[$fid])) {
                    $distinct_films[$fid] = array(
                        'nominations' => 0,
                        'wins' => 0,
                        'categories' => array(),
                        'ceremonies' => array(),
                        'latest_ceremony' => 0,
                        'latest_year' => '',
                    );
                }

                $distinct_films[$fid]['nominations']++;
                if ($winner) {
                    $distinct_films[$fid]['wins']++;
                }
                if ($cat !== '') {
                    $distinct_films[$fid]['categories'][$cat] = true;
                }
                if ($cer > 0) {
                    $distinct_films[$fid]['ceremonies'][$cer] = true;
                    if ($cer > intval($distinct_films[$fid]['latest_ceremony'] ?? 0)) {
                        $distinct_films[$fid]['latest_ceremony'] = $cer;
                        $distinct_films[$fid]['latest_year'] = $year;
                    }
                }
            }
        }
    }
}

krsort($timeline, SORT_NUMERIC);

$category_rollups = array_values($category_rollups);
usort($category_rollups, function($left, $right) {
    $left_wins = intval($left['wins'] ?? 0);
    $right_wins = intval($right['wins'] ?? 0);
    if ($left_wins !== $right_wins) {
        return $right_wins <=> $left_wins;
    }

    $left_nominations = intval($left['nominations'] ?? 0);
    $right_nominations = intval($right['nominations'] ?? 0);
    if ($left_nominations !== $right_nominations) {
        return $right_nominations <=> $left_nominations;
    }

    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

$ceremony_rollups = array_values($ceremony_rollups);
usort($ceremony_rollups, function($left, $right) {
    return intval($right['ceremony'] ?? 0) <=> intval($left['ceremony'] ?? 0);
});

if (!empty($distinct_films)) {
    uasort($distinct_films, function($left, $right) {
        $left_wins = intval($left['wins'] ?? 0);
        $right_wins = intval($right['wins'] ?? 0);
        if ($left_wins !== $right_wins) {
            return $right_wins <=> $left_wins;
        }

        $left_nominations = intval($left['nominations'] ?? 0);
        $right_nominations = intval($right['nominations'] ?? 0);
        if ($left_nominations !== $right_nominations) {
            return $right_nominations <=> $left_nominations;
        }

        return intval($right['latest_ceremony'] ?? 0) <=> intval($left['latest_ceremony'] ?? 0);
    });
}

$total_categories = count($categories_set);
$total_ceremonies = count($ceremonies_set);
$span = '';
if (!empty($ceremony_year_map)) {
    $years = array_values(array_filter($ceremony_year_map, 'strlen'));
    sort($years);
    if (!empty($years)) {
        $first_year = reset($years);
        $last_year = end($years);
        $span = $first_year === $last_year ? $first_year : ($first_year . '-' . $last_year);
    }
}

if ($span !== '') {
    $span = str_replace(array('-', '&#8211;'), '&ndash;', $span);
}

$years = array_values(array_filter($ceremony_year_map, 'strlen'));
if (!empty($years)) {
    sort($years);
    $first_year = reset($years);
    $last_year = end($years);
    $span = $first_year === $last_year ? $first_year : ($first_year . '&ndash;' . $last_year);
}

$latest_result = array();
if ($latest_ceremony > 0 && !empty($timeline[$latest_ceremony]['rows'])) {
    $latest_rows = $timeline[$latest_ceremony]['rows'];
    $latest_wins = 0;
    $latest_categories = array();
    $latest_title_id = '';
    foreach ($latest_rows as $latest_row) {
        if (!empty($latest_row['winner']) && (int) $latest_row['winner'] === 1) {
            $latest_wins++;
        }
        $latest_cat = (string) ($latest_row['canonical_category'] ?? $latest_row['category'] ?? '');
        if ($latest_cat !== '') {
            $latest_categories[$latest_cat] = array(
                'label' => $format_category($latest_cat),
                'url' => $aat->get_category_url($latest_cat),
            );
        }

        if ($latest_title_id === '') {
            $latest_title_id = $get_primary_pipe_value($latest_row['film_id'] ?? '');
        }
    }

    $latest_result = array(
        'ceremony' => $latest_ceremony,
        'year' => (string) $latest_year,
        'nominations' => count($latest_rows),
        'wins' => $latest_wins,
        'categories' => array_values($latest_categories),
        'title_id' => $latest_title_id,
    );
}

$imdb_url = $build_imdb_url($id);
$search_url = home_url('/?s=' . rawurlencode($label ? $label : $id));
$database_url = home_url('/oscars/');
$type_label = $entity === 'title' ? 'Film' : ($entity === 'company' ? 'Company' : 'Person');
$profile_file_label = $entity === 'title' ? __('Title Profile File', 'academy-awards-table') : ($entity === 'company' ? __('Company Profile File', 'academy-awards-table') : __('Person Profile File', 'academy-awards-table'));
$profile_file_class = $entity === 'title' ? 'is-title-file' : ($entity === 'company' ? 'is-company-file' : 'is-person-file');
$profile_subject_label = $label ? $label : strtoupper($id);
$summary = '';
$visual = array();
$entity_anchor_title = '';
if ($entity === 'title' && method_exists($aat, 'get_title_visual_package')) {
    $visual = $aat->get_title_visual_package($id, 'medium_large');
} elseif ($entity === 'name' && method_exists($aat, 'get_person_visual_package')) {
    $visual = $aat->get_person_visual_package($id, 'medium_large');
} elseif ($entity === 'company' && !empty($distinct_films) && method_exists($aat, 'get_title_visual_package')) {
    foreach (array_keys($distinct_films) as $company_film_id) {
        $company_film_id = trim((string) $company_film_id);
        if ($company_film_id === '') {
            continue;
        }

        $company_visual = $aat->get_title_visual_package($company_film_id, 'medium_large');
        $entity_anchor_title = method_exists($aat, 'lookup_title_label') ? $aat->lookup_title_label($company_film_id) : '';
        if ($entity_anchor_title === '' && !empty($company_visual['title'])) {
            $entity_anchor_title = (string) $company_visual['title'];
        }
        if ($entity_anchor_title === '') {
            $entity_anchor_title = strtoupper($company_film_id);
        }

        if (
            !empty($company_visual['poster_html']) ||
            !empty($company_visual['poster_url']) ||
            !empty($company_visual['backdrop_url']) ||
            !empty($company_visual['fallback_html'])
        ) {
            $visual = $company_visual;
            break;
        }
    }
}
$tmdb = !empty($visual['tmdb']) ? $visual['tmdb'] : array();
$hero_classes = array('aat-entity-hero');
$aat_person_visual_state = $entity === 'name' ? sanitize_html_class((string) ($visual['visual_state'] ?? 'no-portrait')) : '';
$aat_person_visual_source = $entity === 'name' ? sanitize_html_class((string) ($visual['visual_source'] ?? 'none')) : '';
if ($entity === 'name') {
    $aat_person_visual_state = $aat_person_visual_state !== '' ? $aat_person_visual_state : 'no-portrait';
    $aat_person_visual_source = $aat_person_visual_source !== '' ? $aat_person_visual_source : 'none';
    $hero_classes[] = 'has-person-visual-state-' . $aat_person_visual_state;
    $hero_classes[] = 'has-person-visual-source-' . $aat_person_visual_source;
    if ($aat_person_visual_state === 'no-portrait') {
        $hero_classes[] = 'is-person-visual-fallback';
    }
}
$hero_visual = ($entity === 'name' && !empty($latest_result['title_id']))
    ? $get_title_visual($latest_result['title_id'], 'medium_large')
    : $visual;
$hero_style = $get_visual_backdrop_style($hero_visual, array(
    'prefer_poster' => true,
    'position' => $entity === 'name' ? 'center top' : 'center',
));
if ($hero_style !== '') {
    $hero_classes[] = 'aat-card-has-backdrop';
}
if (
    $entity === 'company' &&
    empty($visual['poster_html']) &&
    empty($visual['poster_url']) &&
    empty($visual['fallback_html'])
) {
    $hero_classes[] = 'is-meta-only';
} elseif ($entity === 'title' && empty($visual['poster_html']) && empty($visual['poster_url']) && empty($visual['fallback_html'])) {
    $hero_classes[] = 'is-no-poster';
} elseif ($entity === 'name' && empty($visual['portrait_url']) && empty($visual['fallback_html'])) {
    $hero_classes[] = 'is-no-poster';
}
$latest_result_visual = array();
if (!empty($latest_result['title_id'])) {
    $latest_result_visual = $get_title_visual($latest_result['title_id'], 'medium_large');
} elseif ($entity === 'title') {
    $latest_result_visual = $visual;
}
$latest_result_style = $get_visual_backdrop_style($latest_result_visual, array(
    'prefer_poster' => true,
    'position' => 'center top',
));
if ($total_nominations > 0) {
    $summary = sprintf(
        '%s appears in the Lunara Oscar Ledger with %s nomination%s and %s win%s across %s ceremon%s.',
        $label ? $label : strtoupper($id),
        number_format_i18n($total_nominations),
        $total_nominations === 1 ? '' : 's',
        number_format_i18n($total_wins),
        $total_wins === 1 ? '' : 's',
        number_format_i18n($total_ceremonies),
        $total_ceremonies === 1 ? 'y' : 'ies'
    );
}

$aat_review_ids = array();
if ($entity === 'title') {
    $aat_review_ids = $aat->get_review_ids_for_title_id($id, 3);
}

$aat_editorial_refs = method_exists($aat, 'get_entity_editorial_references')
    ? $aat->get_entity_editorial_references($entity, $id, $label, 6)
    : array();

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

$aat_related_reviews = array();
if ($entity !== 'title' && !empty($distinct_films)) {
    foreach (array_keys($distinct_films) as $related_film_id) {
        $related_film_id = trim((string) $related_film_id);
        if ($related_film_id === '') {
            continue;
        }

        $related_review_ids = $aat->get_review_ids_for_title_id($related_film_id, 1);
        if (empty($related_review_ids[0])) {
            continue;
        }

        $related_review_id = (int) $related_review_ids[0];
        $related_review_url = get_permalink($related_review_id);
        if (!$related_review_url) {
            continue;
        }

        $related_film_label = $get_title_label($related_film_id);
        if ($related_film_label === '') {
            $related_film_label = strtoupper($related_film_id);
        }

        $related_review_thumb = get_the_post_thumbnail_url($related_review_id, 'medium_large');
        $related_review_excerpt = get_the_excerpt($related_review_id);
        $related_film_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($related_film_id, 'medium') : array();
        $related_visual_media_html = '';
        if (!empty($related_film_visual['poster_html'])) {
            $related_visual_media_html = (string) $related_film_visual['poster_html'];
        } elseif (!empty($related_film_visual['poster_url'])) {
            $related_visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($related_film_visual['poster_url']) . '" alt="' . esc_attr(sprintf(__('%s poster', 'academy-awards-table'), $related_film_label)) . '" loading="lazy" decoding="async" />';
        } elseif (!empty($related_film_visual['backdrop_url'])) {
            $related_visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($related_film_visual['backdrop_url']) . '" alt="' . esc_attr($related_film_label) . '" loading="lazy" decoding="async" />';
        }
        $related_release_year = '';
        if (!empty($related_film_visual['release_year'])) {
            $related_release_year = (string) $related_film_visual['release_year'];
        } elseif (!empty($related_film_visual['tmdb']['release_date'])) {
            $related_release_year = substr((string) $related_film_visual['tmdb']['release_date'], 0, 4);
        }

        $aat_related_reviews[] = array(
            'review_id' => $related_review_id,
            'review_url' => $related_review_url,
            'review_title' => get_the_title($related_review_id),
            'review_excerpt' => $related_review_excerpt,
            'review_thumb' => $related_review_thumb,
            'film_id' => $related_film_id,
            'film_label' => $related_film_label,
            'film_url' => $build_entity_url($related_film_id),
            'film_year' => $related_release_year,
            'fallback_html' => $related_visual_media_html,
            'sort_date' => get_post_field('post_date', $related_review_id),
        );
    }

    if (!empty($aat_related_reviews)) {
        usort($aat_related_reviews, function($left, $right) {
            return strcmp((string) ($right['sort_date'] ?? ''), (string) ($left['sort_date'] ?? ''));
        });
        $aat_related_reviews = array_slice($aat_related_reviews, 0, $aat_get_related_review_limit());
    }
}

$profile_dossier_cards = array();
$profile_dossier_seen = array();
$profile_dossier_limit = (int) apply_filters('aat_entity_profile_dossier_limit', 6, $entity, $id);
$profile_dossier_limit = max(3, min(8, $profile_dossier_limit));

$add_profile_dossier_card = function($card) use (&$profile_dossier_cards, &$profile_dossier_seen, $profile_dossier_limit) {
    if (count($profile_dossier_cards) >= $profile_dossier_limit) {
        return;
    }

    $key = trim((string) ($card['key'] ?? ''));
    if ($key === '') {
        $key = md5((string) wp_json_encode($card));
    }
    if (isset($profile_dossier_seen[$key])) {
        return;
    }

    $profile_dossier_seen[$key] = true;
    $profile_dossier_cards[] = $card;
};

$build_profile_dossier_media = function($visual_package, $visual_label, $media_class = 'aat-profile-dossier-image') {
    $visual_package = is_array($visual_package) ? $visual_package : array();
    $visual_label = trim((string) $visual_label);
    if (!empty($visual_package['poster_html'])) {
        return (string) $visual_package['poster_html'];
    }
    if (!empty($visual_package['portrait_url'])) {
        return '<img class="' . esc_attr($media_class) . '" src="' . esc_url($visual_package['portrait_url']) . '" alt="' . esc_attr($visual_label !== '' ? sprintf(__('%s portrait', 'academy-awards-table'), $visual_label) : __('Portrait', 'academy-awards-table')) . '" loading="lazy" decoding="async" />';
    }
    if (!empty($visual_package['poster_url'])) {
        return '<img class="' . esc_attr($media_class) . '" src="' . esc_url($visual_package['poster_url']) . '" alt="' . esc_attr($visual_label !== '' ? sprintf(__('%s poster', 'academy-awards-table'), $visual_label) : __('Poster', 'academy-awards-table')) . '" loading="lazy" decoding="async" />';
    }
    if (!empty($visual_package['backdrop_url'])) {
        return '<img class="' . esc_attr($media_class) . '" src="' . esc_url($visual_package['backdrop_url']) . '" alt="' . esc_attr($visual_label !== '' ? $visual_label : __('Oscar profile image', 'academy-awards-table')) . '" loading="lazy" decoding="async" />';
    }
    if (!empty($visual_package['fallback_html'])) {
        return (string) $visual_package['fallback_html'];
    }

    return '';
};

if ($entity === 'title' && is_array($rows)) {
    $title_visual_package = is_array($visual) ? $visual : array();
    $title_dossier_media = $build_profile_dossier_media($title_visual_package, $label ? $label : strtoupper($id));
    $title_dossier_style = $get_visual_backdrop_style($title_visual_package, array('prefer_poster' => true, 'position' => 'center top'));
    $title_dossier_index = 0;
    foreach ($rows as $dossier_row) {
        $dossier_ceremony = intval($dossier_row['ceremony'] ?? 0);
        $dossier_year = trim((string) ($dossier_row['year'] ?? ''));
        $dossier_category = trim((string) ($dossier_row['canonical_category'] ?? $dossier_row['category'] ?? ''));
        $dossier_category_label = $dossier_category !== '' ? $format_category($dossier_category) : __('Oscar Result', 'academy-awards-table');
        $dossier_is_winner = (!empty($dossier_row['winner']) && (int) $dossier_row['winner'] === 1);
        $dossier_url = $dossier_category !== '' ? $aat->get_category_url($dossier_category) : '';
        if ($dossier_url === '' && $dossier_ceremony > 0) {
            $dossier_url = $build_ceremony_url($dossier_ceremony);
        }
        $dossier_meta = array();
        if ($dossier_year !== '') {
            $dossier_meta[] = $dossier_year;
        }
        if ($dossier_ceremony > 0) {
            $dossier_meta[] = sprintf(__('%s ceremony', 'academy-awards-table'), $ordinal($dossier_ceremony));
        }
        $current_title_dossier_media = $title_dossier_index === 0 ? $title_dossier_media : '';

        $add_profile_dossier_card(array(
            'key' => 'title-' . $dossier_ceremony . '-' . $dossier_category,
            'kind' => 'title-touchpoint',
            'classes' => array('is-title-touchpoint', $current_title_dossier_media !== '' ? 'has-media' : 'has-no-media', $title_dossier_style !== '' ? 'aat-card-has-backdrop' : ''),
            'url' => $dossier_url,
            'style' => $title_dossier_style,
            'media_html' => $current_title_dossier_media,
            'kicker' => $dossier_is_winner ? __('Winner', 'academy-awards-table') : __('Nominee', 'academy-awards-table'),
            'title' => $dossier_category_label,
            'meta' => implode(' / ', $dossier_meta),
            'body' => $dossier_is_winner
                ? __('A winning ledger stop in this title profile.', 'academy-awards-table')
                : __('A nominated ledger stop in this title profile.', 'academy-awards-table'),
            'tags' => array_filter(array($dossier_is_winner ? __('Winner', 'academy-awards-table') : __('Nominee', 'academy-awards-table'), $dossier_year)),
        ));
        $title_dossier_index++;
    }
} elseif ($entity !== 'title' && !empty($distinct_films)) {
    foreach ($distinct_films as $dossier_film_id => $dossier_film_stats) {
        $dossier_film_label = $get_title_label($dossier_film_id);
        if ($dossier_film_label === '') {
            $dossier_film_label = strtoupper((string) $dossier_film_id);
        }
        $dossier_film_visual = $get_title_visual($dossier_film_id, 'medium_large');
        $dossier_film_media = $build_profile_dossier_media($dossier_film_visual, $dossier_film_label);
        $dossier_film_style = $get_visual_backdrop_style($dossier_film_visual, array('prefer_poster' => true, 'position' => 'center top'));
        $dossier_film_nominations = intval($dossier_film_stats['nominations'] ?? 0);
        $dossier_film_wins = intval($dossier_film_stats['wins'] ?? 0);
        $dossier_film_categories = is_array($dossier_film_stats['categories'] ?? null) ? count($dossier_film_stats['categories']) : 0;
        $dossier_film_year = trim((string) ($dossier_film_stats['latest_year'] ?? ''));
        $dossier_film_meta = array();
        if ($dossier_film_year !== '') {
            $dossier_film_meta[] = $dossier_film_year;
        }
        if ($dossier_film_categories > 0) {
            $dossier_film_meta[] = sprintf(_n('%s category', '%s categories', $dossier_film_categories, 'academy-awards-table'), number_format_i18n($dossier_film_categories));
        }

        $add_profile_dossier_card(array(
            'key' => 'film-' . $dossier_film_id,
            'kind' => 'film-touchpoint',
            'classes' => array('is-film-touchpoint', $dossier_film_media !== '' ? 'has-media' : 'has-no-media', $dossier_film_style !== '' ? 'aat-card-has-backdrop' : ''),
            'url' => $build_entity_url($dossier_film_id),
            'style' => $dossier_film_style,
            'media_html' => $dossier_film_media,
            'kicker' => $dossier_film_wins > 0 ? __('Winning Film', 'academy-awards-table') : __('Nominated Film', 'academy-awards-table'),
            'title' => $dossier_film_label,
            'meta' => implode(' / ', $dossier_film_meta),
            'body' => sprintf(
                _n('%1$s nomination and %2$s win attached to this profile file.', '%1$s nominations and %2$s wins attached to this profile file.', $dossier_film_nominations, 'academy-awards-table'),
                number_format_i18n($dossier_film_nominations),
                number_format_i18n($dossier_film_wins)
            ),
            'tags' => array_filter(array(
                sprintf(_n('%s nomination', '%s nominations', $dossier_film_nominations, 'academy-awards-table'), number_format_i18n($dossier_film_nominations)),
                $dossier_film_wins > 0 ? sprintf(_n('%s win', '%s wins', $dossier_film_wins, 'academy-awards-table'), number_format_i18n($dossier_film_wins)) : '',
                $dossier_film_year,
            )),
        ));
    }
}

$profile_reader_path_cards = array();
$profile_reader_path_seen = array();
$profile_reader_path_limit = (int) apply_filters('aat_entity_profile_reader_path_limit', 4, $entity, $id);
$profile_reader_path_limit = max(3, min(5, $profile_reader_path_limit));

$add_profile_reader_path_card = function($card) use (&$profile_reader_path_cards, &$profile_reader_path_seen, $profile_reader_path_limit) {
    if (count($profile_reader_path_cards) >= $profile_reader_path_limit) {
        return;
    }

    $url = trim((string) ($card['url'] ?? ''));
    $title = trim((string) ($card['title'] ?? ''));
    if ($url === '' || $title === '') {
        return;
    }

    $key = trim((string) ($card['key'] ?? ''));
    if ($key === '') {
        $key = md5($url . '|' . $title);
    }
    if (isset($profile_reader_path_seen[$key])) {
        return;
    }

    $profile_reader_path_seen[$key] = true;
    $profile_reader_path_cards[] = $card;
};

$build_profile_review_media = function($review_id, $visual_label, $media_class = 'aat-profile-reader-path-image') {
    $review_id = absint($review_id);
    if ($review_id <= 0) {
        return '';
    }

    $thumb = get_the_post_thumbnail_url($review_id, 'medium_large');
    if (!$thumb) {
        return '';
    }

    $visual_label = trim((string) $visual_label);

    return '<img class="' . esc_attr($media_class) . '" src="' . esc_url($thumb) . '" alt="' . esc_attr($visual_label !== '' ? $visual_label : __('Lunara review image', 'academy-awards-table')) . '" loading="lazy" decoding="async" />';
};

if ($entity === 'title') {
    if (!empty($aat_review_ids[0])) {
        $reader_review_id = absint($aat_review_ids[0]);
        $reader_review_url = $reader_review_id > 0 ? get_permalink($reader_review_id) : '';
        if ($reader_review_url) {
            $reader_review_title = trim((string) get_the_title($reader_review_id));
            $reader_review_media = $build_profile_review_media($reader_review_id, $reader_review_title !== '' ? $reader_review_title : $profile_subject_label);
            $add_profile_reader_path_card(array(
                'key' => 'title-review-' . $reader_review_id,
                'kind' => 'review-path',
                'classes' => array('is-title-reader-path', 'is-review-path', $reader_review_media !== '' ? 'has-media' : 'has-no-media'),
                'url' => $reader_review_url,
                'media_html' => $reader_review_media,
                'kicker' => __('Lunara Review', 'academy-awards-table'),
                'title' => $reader_review_title !== '' ? $reader_review_title : __('Read the Review', 'academy-awards-table'),
                'meta' => __('Criticism File', 'academy-awards-table'),
                'body' => __('Move from the Oscar record into the Lunara argument for this film.', 'academy-awards-table'),
                'action' => __('Read Review', 'academy-awards-table'),
            ));
        }
    }

    if (!empty($latest_result['ceremony'])) {
        $reader_ceremony = intval($latest_result['ceremony']);
        $reader_ceremony_url = $build_ceremony_url($reader_ceremony);
        if ($reader_ceremony_url !== '') {
            $reader_ceremony_label = !empty($latest_result['year'])
                ? (string) $latest_result['year']
                : sprintf(__('%s Academy Awards', 'academy-awards-table'), $ordinal($reader_ceremony));
            $reader_ceremony_media = $build_profile_dossier_media($latest_result_visual, $profile_subject_label, 'aat-profile-reader-path-image');
            $reader_ceremony_style = $get_visual_backdrop_style($latest_result_visual, array('prefer_poster' => true, 'position' => 'center top'));
            $add_profile_reader_path_card(array(
                'key' => 'title-ceremony-' . $reader_ceremony,
                'kind' => 'ceremony-path',
                'classes' => array('is-title-reader-path', 'is-ceremony-path', $reader_ceremony_media !== '' ? 'has-media' : 'has-no-media', $reader_ceremony_style !== '' ? 'aat-card-has-backdrop' : ''),
                'url' => $reader_ceremony_url,
                'style' => $reader_ceremony_style,
                'media_html' => $reader_ceremony_media,
                'kicker' => __('Ceremony File', 'academy-awards-table'),
                'title' => sprintf(__('%s Ceremony', 'academy-awards-table'), $ordinal($reader_ceremony)),
                'meta' => $reader_ceremony_label,
                'body' => __('Jump into the ceremony dossier where this title sits in the night around it.', 'academy-awards-table'),
                'action' => __('Open Ceremony', 'academy-awards-table'),
            ));
        }
    }

    if (!empty($category_rollups[0]['url']) && !empty($category_rollups[0]['label'])) {
        $reader_category = $category_rollups[0];
        $reader_category_wins = intval($reader_category['wins'] ?? 0);
        $reader_category_nominations = intval($reader_category['nominations'] ?? 0);
        $add_profile_reader_path_card(array(
            'key' => 'title-category-' . sanitize_key((string) ($reader_category['category'] ?? $reader_category['label'])),
            'kind' => 'category-path',
            'classes' => array('is-title-reader-path', 'is-category-path', 'has-no-media'),
            'url' => (string) $reader_category['url'],
            'kicker' => __('Category File', 'academy-awards-table'),
            'title' => (string) $reader_category['label'],
            'meta' => sprintf(
                _n('%1$s nomination / %2$s win', '%1$s nominations / %2$s wins', $reader_category_nominations, 'academy-awards-table'),
                number_format_i18n($reader_category_nominations),
                number_format_i18n($reader_category_wins)
            ),
            'body' => __('Follow the category history that surrounds this title inside the larger Oscar field.', 'academy-awards-table'),
            'action' => __('Open Category', 'academy-awards-table'),
        ));
    }

    $add_profile_reader_path_card(array(
        'key' => 'title-ledger-history',
        'kind' => 'history-path',
        'classes' => array('is-title-reader-path', 'is-history-path', 'has-no-media'),
        'url' => '#oscar-history',
        'kicker' => __('Full Trail', 'academy-awards-table'),
        'title' => __('Oscar History', 'academy-awards-table'),
        'meta' => sprintf(_n('%s recorded result', '%s recorded results', $total_nominations, 'academy-awards-table'), number_format_i18n($total_nominations)),
        'body' => __('Drop into the full result run with every category, ceremony, and linked participant intact.', 'academy-awards-table'),
        'action' => __('Open Trail', 'academy-awards-table'),
    ));

    if ($imdb_url !== '') {
        $add_profile_reader_path_card(array(
            'key' => 'title-imdb-reference',
            'kind' => 'source-path',
            'classes' => array('is-title-reader-path', 'is-source-path', 'has-no-media'),
            'url' => $imdb_url,
            'external' => true,
            'kicker' => __('Source Trail', 'academy-awards-table'),
            'title' => __('IMDb Record', 'academy-awards-table'),
            'meta' => strtoupper((string) $id),
            'body' => __('Open the external reference used for ID checks and title reconciliation.', 'academy-awards-table'),
            'action' => __('Open IMDb', 'academy-awards-table'),
        ));
    }
} elseif ($entity === 'name') {
    $reader_anchor_title_id = trim((string) ($latest_result['title_id'] ?? ''));
    if ($reader_anchor_title_id !== '') {
        $reader_anchor_title = $get_title_label($reader_anchor_title_id);
        if ($reader_anchor_title === '') {
            $reader_anchor_title = strtoupper($reader_anchor_title_id);
        }
        $reader_anchor_url = $build_entity_url($reader_anchor_title_id);
        $reader_anchor_visual = $get_title_visual($reader_anchor_title_id, 'medium_large');
        $reader_anchor_media = $build_profile_dossier_media($reader_anchor_visual, $reader_anchor_title, 'aat-profile-reader-path-image');
        $reader_anchor_style = $get_visual_backdrop_style($reader_anchor_visual, array('prefer_poster' => true, 'position' => 'center top'));
        if ($reader_anchor_url !== '') {
            $add_profile_reader_path_card(array(
                'key' => 'person-title-' . $reader_anchor_title_id,
                'kind' => 'title-path',
                'classes' => array('is-person-reader-path', 'is-title-path', $reader_anchor_media !== '' ? 'has-media' : 'has-no-media', $reader_anchor_style !== '' ? 'aat-card-has-backdrop' : ''),
                'url' => $reader_anchor_url,
                'style' => $reader_anchor_style,
                'media_html' => $reader_anchor_media,
                'kicker' => __('Anchor Title', 'academy-awards-table'),
                'title' => $reader_anchor_title,
                'meta' => !empty($latest_result['year']) ? (string) $latest_result['year'] : '',
                'body' => __('Open the title file currently anchoring this person profile in the Oscar Ledger.', 'academy-awards-table'),
                'action' => __('Open Title', 'academy-awards-table'),
            ));
        }
    }

    if (!empty($aat_related_reviews[0]['review_url'])) {
        $reader_related_review = $aat_related_reviews[0];
        $reader_related_title = trim((string) ($reader_related_review['review_title'] ?? ''));
        $reader_related_media = '';
        if (!empty($reader_related_review['review_thumb'])) {
            $reader_related_media = '<img class="aat-profile-reader-path-image" src="' . esc_url($reader_related_review['review_thumb']) . '" alt="' . esc_attr($reader_related_title !== '' ? $reader_related_title : __('Lunara review image', 'academy-awards-table')) . '" loading="lazy" decoding="async" />';
        } elseif (!empty($reader_related_review['fallback_html'])) {
            $reader_related_media = (string) $reader_related_review['fallback_html'];
        }
        $add_profile_reader_path_card(array(
            'key' => 'person-review-' . absint($reader_related_review['review_id'] ?? 0),
            'kind' => 'review-path',
            'classes' => array('is-person-reader-path', 'is-review-path', $reader_related_media !== '' ? 'has-media' : 'has-no-media'),
            'url' => (string) $reader_related_review['review_url'],
            'media_html' => $reader_related_media,
            'kicker' => __('On Lunara', 'academy-awards-table'),
            'title' => $reader_related_title !== '' ? $reader_related_title : __('Related Review', 'academy-awards-table'),
            'meta' => trim((string) (($reader_related_review['film_label'] ?? '') . (!empty($reader_related_review['film_year']) ? ' / ' . $reader_related_review['film_year'] : ''))),
            'body' => __('Connect this Oscar file back to the criticism archive.', 'academy-awards-table'),
            'action' => __('Read Review', 'academy-awards-table'),
        ));
    }

    if (!empty($latest_result['ceremony'])) {
        $reader_ceremony = intval($latest_result['ceremony']);
        $reader_ceremony_url = $build_ceremony_url($reader_ceremony);
        if ($reader_ceremony_url !== '') {
            $add_profile_reader_path_card(array(
                'key' => 'person-ceremony-' . $reader_ceremony,
                'kind' => 'ceremony-path',
                'classes' => array('is-person-reader-path', 'is-ceremony-path', 'has-no-media'),
                'url' => $reader_ceremony_url,
                'kicker' => __('Ceremony File', 'academy-awards-table'),
                'title' => sprintf(__('%s Ceremony', 'academy-awards-table'), $ordinal($reader_ceremony)),
                'meta' => !empty($latest_result['year']) ? (string) $latest_result['year'] : '',
                'body' => __('See the ceremony dossier that frames this profile’s most recent Oscar appearance.', 'academy-awards-table'),
                'action' => __('Open Ceremony', 'academy-awards-table'),
            ));
        }
    }

    if (!empty($category_rollups[0]['url']) && !empty($category_rollups[0]['label'])) {
        $reader_category = $category_rollups[0];
        $add_profile_reader_path_card(array(
            'key' => 'person-category-' . sanitize_key((string) ($reader_category['category'] ?? $reader_category['label'])),
            'kind' => 'category-path',
            'classes' => array('is-person-reader-path', 'is-category-path', 'has-no-media'),
            'url' => (string) $reader_category['url'],
            'kicker' => __('Category File', 'academy-awards-table'),
            'title' => (string) $reader_category['label'],
            'meta' => sprintf(
                _n('%1$s nomination / %2$s win', '%1$s nominations / %2$s wins', intval($reader_category['nominations'] ?? 0), 'academy-awards-table'),
                number_format_i18n(intval($reader_category['nominations'] ?? 0)),
                number_format_i18n(intval($reader_category['wins'] ?? 0))
            ),
            'body' => __('Follow the award field where this profile has the deepest Oscar footprint.', 'academy-awards-table'),
            'action' => __('Open Category', 'academy-awards-table'),
        ));
    }

    if (!empty($distinct_films)) {
        $add_profile_reader_path_card(array(
            'key' => 'person-filmography',
            'kind' => 'filmography-path',
            'classes' => array('is-person-reader-path', 'is-filmography-path', 'has-no-media'),
            'url' => '#nominated-films',
            'kicker' => __('Film Trail', 'academy-awards-table'),
            'title' => __('Nominated Films', 'academy-awards-table'),
            'meta' => sprintf(_n('%s linked film', '%s linked films', count($distinct_films), 'academy-awards-table'), number_format_i18n(count($distinct_films))),
            'body' => __('Scan the poster-led film run attached to this person’s Oscar history.', 'academy-awards-table'),
            'action' => __('Open Films', 'academy-awards-table'),
        ));
    }

    if ($imdb_url !== '') {
        $add_profile_reader_path_card(array(
            'key' => 'person-imdb-reference',
            'kind' => 'source-path',
            'classes' => array('is-person-reader-path', 'is-source-path', 'has-no-media'),
            'url' => $imdb_url,
            'external' => true,
            'kicker' => __('Source Trail', 'academy-awards-table'),
            'title' => __('IMDb Profile', 'academy-awards-table'),
            'meta' => strtoupper((string) $id),
            'body' => __('Open the external reference used for person ID checks and reconciliation.', 'academy-awards-table'),
            'action' => __('Open IMDb', 'academy-awards-table'),
        ));
    }
}

get_header();
?>
<div class="aat-container aat-entity-page aat-profile-file <?php echo esc_attr($profile_file_class); ?>">
    <style>
        body .aat-container.aat-profile-file{display:grid!important;gap:clamp(22px,3vw,36px)!important;min-width:0!important;max-width:100%!important}
        body .aat-container.aat-profile-file .aat-entity-hero{display:grid!important;grid-template-columns:minmax(220px,320px) minmax(0,1fr)!important;gap:clamp(20px,3vw,38px)!important;align-items:stretch!important;margin:0!important;padding:clamp(20px,4vw,42px)!important;border:1px solid rgba(201,169,97,.24)!important;border-radius:18px!important;background-color:rgba(8,18,29,.95)!important}
        body .aat-container.aat-profile-file .aat-entity-hero-copy{display:grid!important;align-content:center!important;gap:14px!important;min-width:0!important}
        body .aat-container.aat-profile-file .aat-entity-title{color:var(--aat-white)!important;font-size:clamp(2.25rem,5vw,5.05rem)!important;line-height:.98!important;letter-spacing:0!important;text-transform:none!important;text-align:left!important;max-width:12ch!important}
        body .aat-container.aat-profile-file .aat-entity-summary,body .aat-container.aat-profile-file .aat-entity-overview{max-width:64ch!important}
        body .aat-container.aat-profile-file .aat-profile-command-band{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important;margin:4px 0 2px!important;min-width:0!important}
        body .aat-container.aat-profile-file .aat-profile-command-card{display:grid!important;gap:7px!important;align-content:end!important;min-width:0!important;min-height:104px!important;padding:14px!important;border:1px solid rgba(201,169,97,.19)!important;border-radius:12px!important;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.016)),rgba(6,15,26,.72)!important}
        body .aat-container.aat-profile-file .aat-profile-command-card span{color:var(--aat-gold-light)!important;font-size:.68rem!important;letter-spacing:.13em!important;text-transform:uppercase!important}
        body .aat-container.aat-profile-file .aat-profile-command-card strong{color:var(--aat-white)!important;font-size:clamp(1.15rem,2vw,1.85rem)!important;line-height:1.05!important;overflow-wrap:anywhere!important}
        body .aat-container.aat-profile-file .aat-entity-actions{display:flex!important;flex-wrap:wrap!important;gap:10px!important;margin-top:4px!important}
        body .aat-container.aat-profile-file .aat-stats-bar.aat-entity-stats{display:none!important}
        body .aat-container.aat-profile-file .aat-entity-section,body .aat-container.aat-profile-file .aat-lunara-review-module{margin-top:0!important}
        @media(max-width:900px){body .aat-container.aat-profile-file .aat-entity-hero{grid-template-columns:minmax(0,1fr)!important}body .aat-container.aat-profile-file .aat-entity-poster-wrap{max-width:230px!important}body .aat-container.aat-profile-file .aat-profile-command-band{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
        @media(max-width:620px){body .aat-container.aat-profile-file{width:min(100%,calc(100vw - 24px))!important;max-width:calc(100vw - 24px)!important;margin-left:auto!important;margin-right:auto!important;overflow-x:hidden!important}body .aat-container.aat-profile-file .aat-entity-hero{padding:16px!important;border-radius:12px!important}body .aat-container.aat-profile-file .aat-entity-poster-wrap,body .aat-container.aat-profile-file .aat-entity-poster-wrap.is-person,body .aat-container.aat-profile-file .aat-entity-poster-wrap.is-company{width:min(230px,100%)!important;max-width:min(230px,100%)!important;justify-self:start!important}body .aat-container.aat-profile-file .aat-entity-poster-wrap img,body .aat-container.aat-profile-file .aat-entity-poster,body .aat-container.aat-profile-file .aat-entity-portrait{width:100%!important;height:auto!important;max-height:none!important;object-fit:contain!important}body .aat-container.aat-profile-file .aat-entity-title{font-size:clamp(2rem,12vw,3rem)!important;max-width:10ch!important}body .aat-container.aat-profile-file .aat-profile-command-band{grid-template-columns:minmax(0,1fr)!important}body .aat-container.aat-profile-file .aat-profile-command-card{min-height:0!important;padding:13px!important}body .aat-container.aat-profile-file .aat-entity-summary,body .aat-container.aat-profile-file .aat-entity-overview,body .aat-container.aat-profile-file .aat-section-description,body .aat-container.aat-profile-file .aat-history-line{max-width:29ch!important;overflow-wrap:anywhere!important;text-wrap:auto!important;white-space:normal!important}body .aat-container.aat-profile-file .aat-entity-actions{display:grid!important;grid-template-columns:minmax(0,1fr)!important;width:100%!important}body .aat-container.aat-profile-file .aat-entity-actions .aat-btn{width:100%!important;justify-content:center!important}}
    </style>
    <nav class="aat-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
        <span class="aat-sep">/</span>
        <a href="<?php echo esc_url($database_url); ?>">Oscars</a>
        <span class="aat-sep">/</span>
        <span><?php echo esc_html($type_label); ?></span>
    </nav>

    <section class="<?php echo esc_attr(implode(' ', $hero_classes)); ?>"<?php if ($hero_style !== '') : ?> style="<?php echo esc_attr($hero_style); ?>"<?php endif; ?>>
        <?php if (in_array($entity, array('title', 'name', 'company'), true)) : ?>
            <?php $aat_poster_html = ($entity === 'title' && !empty($visual['poster_html'])) ? $visual['poster_html'] : ''; ?>
            <?php $aat_person_portrait_url = ($entity === 'name' && !empty($visual['portrait_url'])) ? $visual['portrait_url'] : ''; ?>
            <?php $aat_company_poster_html = ($entity === 'company' && !empty($visual['poster_html'])) ? $visual['poster_html'] : ''; ?>
            <?php $aat_fallback_html = !empty($visual['fallback_html']) ? $visual['fallback_html'] : ''; ?>
            <?php if (!empty($aat_poster_html) || !empty($aat_company_poster_html) || !empty($visual['poster_url']) || !empty($aat_person_portrait_url) || !empty($aat_fallback_html)) : ?>
                <?php
                $aat_visual_wrap_classes = array('aat-entity-poster-wrap');
                if ($entity === 'name') {
                    $aat_visual_wrap_classes[] = 'is-person';
                    $aat_visual_wrap_classes[] = 'has-person-visual-state-' . $aat_person_visual_state;
                    $aat_visual_wrap_classes[] = 'has-person-visual-source-' . $aat_person_visual_source;
                    if (empty($aat_person_portrait_url)) {
                        $aat_visual_wrap_classes[] = 'is-person-fallback';
                    }
                }
                if ($entity === 'company') {
                    $aat_visual_wrap_classes[] = 'is-company';
                }
                ?>
                <div class="<?php echo esc_attr(implode(' ', $aat_visual_wrap_classes)); ?>">
                    <?php if (!empty($aat_poster_html)) : ?>
                        <?php echo $aat_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php elseif (!empty($aat_company_poster_html)) : ?>
                        <?php echo $aat_company_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php elseif (!empty($visual['poster_url'])) : ?>
                        <img class="aat-entity-poster" src="<?php echo esc_url($visual['poster_url']); ?>" alt="<?php echo esc_attr($label ? $label : strtoupper($id)); ?> poster" loading="lazy" decoding="async" />
                    <?php elseif (!empty($aat_person_portrait_url)) : ?>
                        <img class="aat-entity-portrait" src="<?php echo esc_url($aat_person_portrait_url); ?>" alt="<?php echo esc_attr($label ? $label : strtoupper($id)); ?> portrait" loading="lazy" decoding="async" />
                    <?php elseif (!empty($aat_fallback_html)) : ?>
                        <?php echo $aat_fallback_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="aat-entity-hero-copy">
            <div class="aat-entity-kicker"><?php echo esc_html($profile_file_label); ?></div>
            <h1 class="aat-entity-title"><?php echo esc_html($profile_subject_label); ?></h1>
            <p class="aat-entity-subtitle"><?php echo esc_html__('Lunara Oscar Ledger profile file', 'academy-awards-table'); ?></p>
            <?php if ($summary) : ?>
                <p class="aat-entity-summary"><?php echo esc_html($summary); ?></p>
            <?php endif; ?>
            <?php if ($entity === 'title' && !empty($tmdb)) : ?>
                <div class="aat-entity-meta-line">
                    <?php if (!empty($visual['release_year'])) : ?><span><?php echo esc_html($visual['release_year']); ?></span><?php endif; ?>
                    <?php if (!empty($visual['director'])) : ?><span><?php echo esc_html($visual['director']); ?></span><?php endif; ?>
                    <?php if (!empty($visual['runtime'])) : ?><span><?php echo esc_html($visual['runtime']); ?> min</span><?php endif; ?>
                </div>
                <?php if (!empty($visual['overview'])) : ?><p class="aat-entity-overview"><?php echo esc_html($visual['overview']); ?></p><?php endif; ?>
            <?php elseif ($entity === 'name' && !empty($tmdb)) : ?>
                <?php $person_meta_bits = array_values(array_filter((array) ($visual['meta_bits'] ?? array()), 'strlen')); ?>
                <?php if (!empty($person_meta_bits)) : ?>
                    <div class="aat-entity-meta-line">
                        <?php foreach ($person_meta_bits as $person_meta_bit) : ?>
                            <span><?php echo esc_html($person_meta_bit); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($visual['biography'])) : ?><p class="aat-entity-overview"><?php echo esc_html(wp_trim_words((string) $visual['biography'], 55)); ?></p><?php endif; ?>
            <?php elseif ($entity === 'company') : ?>
                <?php
                $company_meta_bits = array();
                if ($entity_anchor_title !== '') {
                    $company_meta_bits[] = sprintf(__('Representative title: %s', 'academy-awards-table'), $entity_anchor_title);
                }
                if (!empty($visual['release_year'])) {
                    $company_meta_bits[] = (string) $visual['release_year'];
                }
                ?>
                <?php if (!empty($company_meta_bits)) : ?>
                    <div class="aat-entity-meta-line">
                        <?php foreach ($company_meta_bits as $company_meta_bit) : ?>
                            <span><?php echo esc_html($company_meta_bit); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($entity_anchor_title !== '') : ?>
                    <p class="aat-entity-overview"><?php echo esc_html(sprintf(__('%1$s is anchored visually through %2$s so company profiles can sit inside the same poster-led Oscars world as titles and people.', 'academy-awards-table'), $label ? $label : strtoupper($id), $entity_anchor_title)); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <div class="aat-profile-command-band" aria-label="<?php esc_attr_e('Oscar profile summary', 'academy-awards-table'); ?>">
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_nominations)); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_wins)); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                    <strong><?php echo $span ? wp_kses_post($span) : esc_html__('Pending', 'academy-awards-table'); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html($entity === 'title' ? __('Categories', 'academy-awards-table') : __('Films', 'academy-awards-table')); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($entity === 'title' ? $total_categories : count($distinct_films))); ?></strong>
                </div>
            </div>

            <div class="aat-entity-actions">
                <a class="aat-btn aat-btn-primary" href="#oscar-history"><?php echo esc_html__('Oscar History', 'academy-awards-table'); ?></a>
                <?php if (!empty($category_rollups) || !empty($ceremony_rollups) || !empty($aat_editorial_refs)) : ?>
                    <a class="aat-btn aat-btn-secondary" href="#ledger-crossroads"><?php echo esc_html__('Ledger Crossroads', 'academy-awards-table'); ?></a>
                <?php endif; ?>
                <?php if ($entity !== 'title' && !empty($distinct_films)) : ?>
                    <a class="aat-btn aat-btn-secondary" href="#nominated-films"><?php echo esc_html__('Nominated Films', 'academy-awards-table'); ?></a>
                <?php endif; ?>
                <?php if (!empty($imdb_url)) : ?>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($imdb_url); ?>" target="_blank" rel="noopener noreferrer">IMDb Reference</a>
                <?php endif; ?>
                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($database_url); ?>"><?php echo esc_html__('Return to Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>
    </section>

    <?php if (!empty($latest_result)) : ?>
        <?php $latest_status_classes = array('aat-entity-status-banner'); ?>
        <?php if (!empty($latest_result['wins'])) { $latest_status_classes[] = 'is-winner'; } ?>
        <?php if ($latest_result_style !== '') { $latest_status_classes[] = 'aat-card-has-backdrop'; } ?>
        <section class="<?php echo esc_attr(implode(' ', $latest_status_classes)); ?>"<?php if ($latest_result_style !== '') : ?> style="<?php echo esc_attr($latest_result_style); ?>"<?php endif; ?>>
            <div class="aat-entity-status-copy">
                <?php $latest_ceremony_url = $build_ceremony_url(intval($latest_result['ceremony'] ?? 0)); ?>
                <p class="aat-entity-status-kicker"><?php echo esc_html__('Latest Oscar Result', 'academy-awards-table'); ?></p>
                <h2 class="aat-entity-status-title">
                    <?php
                    if (!empty($latest_result['wins'])) {
                        echo esc_html(sprintf(__('%1$s win%2$s at the %3$s ceremony', 'academy-awards-table'), number_format_i18n(intval($latest_result['wins'])), intval($latest_result['wins']) === 1 ? '' : 's', $ordinal(intval($latest_result['ceremony']))));
                    } else {
                        echo esc_html(sprintf(__('%1$s nomination%2$s at the %3$s ceremony', 'academy-awards-table'), number_format_i18n(intval($latest_result['nominations'])), intval($latest_result['nominations']) === 1 ? '' : 's', $ordinal(intval($latest_result['ceremony']))));
                    }
                    ?>
                </h2>
                <p class="aat-entity-status-summary">
                    <?php
                    $latest_appearance_label = (string) ($latest_result['year'] ?: $ordinal(intval($latest_result['ceremony'])) . ' Academy Awards');
                    if ($latest_ceremony_url) {
                        $latest_appearance_label = '<a class="aat-entity-link aat-timeline-link" href="' . esc_url($latest_ceremony_url) . '">' . esc_html($latest_appearance_label) . '</a>';
                    } else {
                        $latest_appearance_label = esc_html($latest_appearance_label);
                    }
                    echo wp_kses_post(sprintf(__('Most recent appearance: %1$s. %2$s nomination%3$s and %4$s win%5$s recorded for %6$s.', 'academy-awards-table'), $latest_appearance_label, number_format_i18n(intval($latest_result['nominations'])), intval($latest_result['nominations']) === 1 ? '' : 's', number_format_i18n(intval($latest_result['wins'])), intval($latest_result['wins']) === 1 ? '' : 's', esc_html($label ? $label : strtoupper($id))));
                    ?>
                </p>
            </div>
            <?php if (!empty($latest_result['categories'])) : ?>
                <div class="aat-entity-status-tags">
                    <?php foreach (array_slice($latest_result['categories'], 0, 6) as $result_category) : ?>
                        <?php if (!empty($result_category['url'])) : ?>
                            <a class="aat-entity-status-tag aat-hub-link" href="<?php echo esc_url($result_category['url']); ?>"><?php echo esc_html($result_category['label'] ?? ''); ?></a>
                        <?php else : ?>
                            <span class="aat-entity-status-tag"><?php echo esc_html($result_category['label'] ?? ''); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($profile_dossier_cards)) : ?>
        <?php
        $profile_dossier_title = $entity === 'title'
            ? __('Title Oscar Path', 'academy-awards-table')
            : __('Profile Dossier', 'academy-awards-table');
        $profile_dossier_description = $entity === 'title'
            ? __('A tighter pass through the Oscar results that shape this title file before the full ledger opens below.', 'academy-awards-table')
            : __('The films and results that keep this profile connected to the wider Oscar Ledger.', 'academy-awards-table');
        ?>
        <section class="aat-profile-dossier-strip" aria-label="<?php echo esc_attr($profile_dossier_title); ?>">
            <div class="aat-section-head aat-profile-dossier-head">
                <div>
                    <p class="aat-profile-dossier-kicker"><?php echo esc_html__('Dossier Strip', 'academy-awards-table'); ?></p>
                    <h2 class="aat-section-title"><?php echo esc_html($profile_dossier_title); ?></h2>
                </div>
                <p class="aat-section-description"><?php echo esc_html($profile_dossier_description); ?></p>
            </div>

            <div class="aat-profile-dossier-track">
                <?php foreach ($profile_dossier_cards as $profile_dossier_card) : ?>
                    <?php
                    $profile_card_classes = array_filter(array_merge(array('aat-profile-dossier-card'), (array) ($profile_dossier_card['classes'] ?? array())));
                    $profile_card_url = trim((string) ($profile_dossier_card['url'] ?? ''));
                    $profile_card_style = trim((string) ($profile_dossier_card['style'] ?? ''));
                    $profile_card_media_html = (string) ($profile_dossier_card['media_html'] ?? '');
                    $profile_card_tags = array_values(array_filter((array) ($profile_dossier_card['tags'] ?? array()), 'strlen'));
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $profile_card_classes)); ?>"<?php if ($profile_card_style !== '') : ?> style="<?php echo esc_attr($profile_card_style); ?>"<?php endif; ?>>
                        <?php if ($profile_card_media_html !== '') : ?>
                            <a class="aat-profile-dossier-media" href="<?php echo esc_url($profile_card_url !== '' ? $profile_card_url : '#oscar-history'); ?>">
                                <?php echo $profile_card_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </a>
                        <?php endif; ?>
                        <div class="aat-profile-dossier-body">
                            <p class="aat-profile-dossier-label"><?php echo esc_html($profile_dossier_card['kicker'] ?? ''); ?></p>
                            <h3 class="aat-profile-dossier-title">
                                <?php if ($profile_card_url !== '') : ?>
                                    <a href="<?php echo esc_url($profile_card_url); ?>"><?php echo esc_html($profile_dossier_card['title'] ?? ''); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($profile_dossier_card['title'] ?? ''); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if (!empty($profile_dossier_card['meta'])) : ?>
                                <p class="aat-profile-dossier-meta"><?php echo esc_html($profile_dossier_card['meta']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($profile_dossier_card['body'])) : ?>
                                <p class="aat-profile-dossier-copy"><?php echo esc_html($profile_dossier_card['body']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($profile_card_tags)) : ?>
                                <div class="aat-profile-dossier-tags" aria-label="<?php esc_attr_e('Profile dossier markers', 'academy-awards-table'); ?>">
                                    <?php foreach (array_slice($profile_card_tags, 0, 3) as $profile_card_tag) : ?>
                                        <span class="aat-profile-dossier-badge"><?php echo esc_html($profile_card_tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($profile_reader_path_cards)) : ?>
        <?php
        $profile_reader_path_title = $entity === 'title'
            ? __('Where This Title Leads', 'academy-awards-table')
            : __('Where This Profile Leads', 'academy-awards-table');
        $profile_reader_path_description = $entity === 'title'
            ? __('Move from the title file into criticism, ceremony context, category history, and the full ledger trail.', 'academy-awards-table')
            : __('Move from this person file into title context, Lunara criticism, ceremony dossiers, and the award fields around the work.', 'academy-awards-table');
        $profile_reader_path_classes = array('aat-profile-reader-path', $entity === 'title' ? 'is-title-reader-path' : 'is-person-reader-path');
        ?>
        <section class="<?php echo esc_attr(implode(' ', $profile_reader_path_classes)); ?>" aria-label="<?php echo esc_attr($profile_reader_path_title); ?>">
            <div class="aat-section-head aat-profile-reader-path-head">
                <div>
                    <p class="aat-profile-reader-path-kicker"><?php echo esc_html__('Reader Path', 'academy-awards-table'); ?></p>
                    <h2 class="aat-section-title"><?php echo esc_html($profile_reader_path_title); ?></h2>
                </div>
                <p class="aat-section-description"><?php echo esc_html($profile_reader_path_description); ?></p>
            </div>

            <div class="aat-profile-reader-path-grid">
                <?php foreach ($profile_reader_path_cards as $profile_reader_path_card) : ?>
                    <?php
                    $reader_card_classes = array_filter(array_merge(array('aat-profile-reader-path-card'), (array) ($profile_reader_path_card['classes'] ?? array())));
                    $reader_card_url = trim((string) ($profile_reader_path_card['url'] ?? ''));
                    $reader_card_style = trim((string) ($profile_reader_path_card['style'] ?? ''));
                    $reader_card_media_html = (string) ($profile_reader_path_card['media_html'] ?? '');
                    $reader_card_external = !empty($profile_reader_path_card['external']);
                    $reader_card_action = trim((string) ($profile_reader_path_card['action'] ?? __('Open File', 'academy-awards-table')));
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $reader_card_classes)); ?>"<?php if ($reader_card_style !== '') : ?> style="<?php echo esc_attr($reader_card_style); ?>"<?php endif; ?>>
                        <?php if ($reader_card_media_html !== '') : ?>
                            <a class="aat-profile-reader-path-media" href="<?php echo esc_url($reader_card_url); ?>"<?php if ($reader_card_external) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                                <?php echo $reader_card_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </a>
                        <?php endif; ?>
                        <div class="aat-profile-reader-path-body">
                            <p class="aat-profile-reader-path-label"><?php echo esc_html($profile_reader_path_card['kicker'] ?? ''); ?></p>
                            <h3 class="aat-profile-reader-path-title">
                                <a href="<?php echo esc_url($reader_card_url); ?>"<?php if ($reader_card_external) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($profile_reader_path_card['title'] ?? ''); ?></a>
                            </h3>
                            <?php if (!empty($profile_reader_path_card['meta'])) : ?>
                                <p class="aat-profile-reader-path-meta"><?php echo esc_html($profile_reader_path_card['meta']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($profile_reader_path_card['body'])) : ?>
                                <p class="aat-profile-reader-path-copy"><?php echo esc_html($profile_reader_path_card['body']); ?></p>
                            <?php endif; ?>
                            <a class="aat-profile-reader-path-action" href="<?php echo esc_url($reader_card_url); ?>"<?php if ($reader_card_external) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($reader_card_action); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="aat-stats-bar aat-entity-stats">
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_nominations)); ?></span><span class="aat-stat-label">Nominations</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_wins)); ?></span><span class="aat-stat-label">Wins</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label">Categories</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label">Ceremonies</span></div>
        <?php if ($entity !== 'title') : ?>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n(count($distinct_films))); ?></span><span class="aat-stat-label">Films</span></div>
        <?php endif; ?>
        <?php if ($span) : ?>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo wp_kses_post($span); ?></span><span class="aat-stat-label">Span</span></div>
        <?php endif; ?>
        <?php if ($latest_year) : ?>
            <div class="aat-stat">
                <span class="aat-stat-number">
                    <?php if ($latest_ceremony > 0 && $build_ceremony_url($latest_ceremony)) : ?>
                        <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($build_ceremony_url($latest_ceremony)); ?>"><?php echo esc_html($latest_year); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($latest_year); ?>
                    <?php endif; ?>
                </span>
                <span class="aat-stat-label">Most Recent</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($category_rollups) || !empty($ceremony_rollups) || !empty($aat_editorial_refs)) : ?>
        <section id="ledger-crossroads" class="aat-entity-section aat-entity-crossroads" aria-label="<?php echo esc_attr__('Ledger Crossroads', 'academy-awards-table'); ?>">
            <div class="aat-section-head">
                <h2 class="aat-section-title">Ledger Crossroads</h2>
                <p class="aat-section-description">Fast links from this <?php echo esc_html(strtolower($type_label)); ?> profile into the categories, ceremonies, and editorial notes that surround it.</p>
            </div>

            <div class="aat-crossroads-grid">
                <?php if (!empty($category_rollups)) : ?>
                    <article class="aat-crossroads-card">
                        <div class="aat-crossroads-kicker">Category Trail</div>
                        <h3>Where this profile competes</h3>
                        <div class="aat-crossroads-list">
                            <?php foreach (array_slice($category_rollups, 0, 8) as $category_rollup) : ?>
                                <?php
                                $rollup_nominations = intval($category_rollup['nominations'] ?? 0);
                                $rollup_wins = intval($category_rollup['wins'] ?? 0);
                                $rollup_meta = sprintf(_n('%s nomination', '%s nominations', $rollup_nominations, 'academy-awards-table'), number_format_i18n($rollup_nominations));
                                if ($rollup_wins > 0) {
                                    $rollup_meta .= ' / ' . sprintf(_n('%s win', '%s wins', $rollup_wins, 'academy-awards-table'), number_format_i18n($rollup_wins));
                                }
                                $rollup_url = (string) ($category_rollup['url'] ?? '');
                                ?>
                                <?php if ($rollup_url) : ?>
                                    <a class="aat-crossroad-pill" href="<?php echo esc_url($rollup_url); ?>">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($category_rollup['label'] ?? ''); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span class="aat-crossroad-pill">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($category_rollup['label'] ?? ''); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if (!empty($ceremony_rollups)) : ?>
                    <article class="aat-crossroads-card">
                        <div class="aat-crossroads-kicker">Ceremony Trail</div>
                        <h3>Recent ceremony touchpoints</h3>
                        <div class="aat-crossroads-list">
                            <?php foreach (array_slice($ceremony_rollups, 0, 8) as $ceremony_rollup) : ?>
                                <?php
                                $rollup_ceremony = intval($ceremony_rollup['ceremony'] ?? 0);
                                $rollup_nominations = intval($ceremony_rollup['nominations'] ?? 0);
                                $rollup_wins = intval($ceremony_rollup['wins'] ?? 0);
                                $rollup_meta = sprintf(_n('%s nomination', '%s nominations', $rollup_nominations, 'academy-awards-table'), number_format_i18n($rollup_nominations));
                                if ($rollup_wins > 0) {
                                    $rollup_meta .= ' / ' . sprintf(_n('%s win', '%s wins', $rollup_wins, 'academy-awards-table'), number_format_i18n($rollup_wins));
                                }
                                $rollup_title = trim($ordinal($rollup_ceremony) . ' Ceremony');
                                $rollup_year = trim((string) ($ceremony_rollup['year'] ?? ''));
                                if ($rollup_year !== '') {
                                    $rollup_title .= ' (' . $rollup_year . ')';
                                }
                                $rollup_url = (string) ($ceremony_rollup['url'] ?? '');
                                ?>
                                <?php if ($rollup_url) : ?>
                                    <a class="aat-crossroad-pill" href="<?php echo esc_url($rollup_url); ?>">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($rollup_title); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span class="aat-crossroad-pill">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($rollup_title); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if (!empty($aat_editorial_refs)) : ?>
                    <article class="aat-crossroads-card aat-crossroads-card-editorial">
                        <div class="aat-crossroads-kicker">Oscar Picks and Facts</div>
                        <h3>Editorial links</h3>
                        <div class="aat-editorial-ref-list">
                            <?php foreach ($aat_editorial_refs as $editorial_ref) : ?>
                                <?php if (empty($editorial_ref['url']) || empty($editorial_ref['title'])) { continue; } ?>
                                <a class="aat-editorial-ref-card" href="<?php echo esc_url($editorial_ref['url']); ?>">
                                    <span class="aat-editorial-ref-type"><?php echo esc_html($editorial_ref['type_label'] ?? ''); ?></span>
                                    <strong><?php echo esc_html($editorial_ref['title']); ?></strong>
                                    <?php if (!empty($editorial_ref['excerpt'])) : ?>
                                        <span class="aat-editorial-ref-excerpt"><?php echo esc_html($editorial_ref['excerpt']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($editorial_ref['meta_label'])) : ?>
                                        <span class="aat-editorial-ref-meta"><?php echo esc_html($editorial_ref['meta_label']); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($entity === 'title' && !empty($aat_review_ids)) : ?>
        <?php
        $aat_primary_review_id = (int) $aat_review_ids[0];
        $aat_review_url = get_permalink($aat_primary_review_id);
        $aat_review_title = get_the_title($aat_primary_review_id);
        $aat_review_excerpt = get_the_excerpt($aat_primary_review_id);
        $aat_review_thumb = get_the_post_thumbnail_url($aat_primary_review_id, 'medium');
        ?>
        <section class="aat-lunara-review-module" aria-label="Lunara Film review">
            <div class="aat-lunara-review-inner">
                <?php if (!empty($aat_review_thumb)) : ?>
                    <a class="aat-lunara-review-poster" href="<?php echo esc_url($aat_review_url); ?>">
                        <img src="<?php echo esc_url($aat_review_thumb); ?>" alt="<?php echo esc_attr($aat_review_title); ?>" loading="lazy" decoding="async" />
                    </a>
                <?php endif; ?>
                <div class="aat-lunara-review-content">
                    <div class="aat-lunara-review-kicker">LUNARA FILM REVIEW</div>
                    <h2 class="aat-lunara-review-title"><a href="<?php echo esc_url($aat_review_url); ?>"><?php echo esc_html($aat_review_title); ?></a></h2>
                    <p class="aat-lunara-review-excerpt"><?php echo esc_html__('Open the review and enter the full argument.', 'academy-awards-table'); ?></p>
                    <div class="aat-lunara-review-actions">
                        <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($aat_review_url); ?>">Read the Review</a>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url(home_url('/reviews/')); ?>">Review Archive</a>
                    </div>
                    <?php if (count($aat_review_ids) > 1) : ?>
                        <div class="aat-lunara-review-more">
                            <span class="aat-lunara-review-more-label">Also on Lunara:</span>
                            <?php
                            $more_links = array();
                            foreach (array_slice($aat_review_ids, 1) as $rid) {
                                $rid = (int) $rid;
                                $more_links[] = '<a href="' . esc_url(get_permalink($rid)) . '">' . esc_html(get_the_title($rid)) . '</a>';
                            }
                            echo implode($pipe_separator_html, $more_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

        <section id="oscar-history" class="aat-entity-section aat-entity-timeline">
        <div class="aat-section-head">
            <h2 class="aat-section-title">Oscar History</h2>
            <p class="aat-section-description">Every ceremony touchpoint for this <?php echo esc_html(strtolower($type_label)); ?>, tracked through the Lunara Oscar ledger.</p>
        </div>

        <?php if (empty($rows)) : ?>
            <div class="aat-no-results">
                <div class="aat-no-results-icon">Awards</div>
                <h3>No records found</h3>
                <p>This profile has not yet been matched to a verified Oscar record in the ledger.</p>
            </div>
        <?php else : ?>
            <div class="aat-timeline-list">
                <?php foreach ($timeline as $cer => $group) : ?>
                    <?php $ceremony_url = $build_ceremony_url($cer); ?>
                    <?php
                    $timeline_visual = array();
                    if ($entity === 'title') {
                        $timeline_visual = $visual;
                    } else {
                        foreach ($group['rows'] as $group_row) {
                            $group_title_id = $get_primary_pipe_value($group_row['film_id'] ?? '');
                            if ($group_title_id !== '') {
                                $timeline_visual = $get_title_visual($group_title_id, 'medium_large');
                                if (!empty($timeline_visual)) {
                                    break;
                                }
                            }
                        }
                    }
                    $timeline_style = $get_visual_backdrop_style($timeline_visual, array(
                        'prefer_poster' => true,
                        'position' => 'center top',
                    ));
                    $timeline_classes = array('aat-timeline-card');
                    if ($timeline_style !== '') {
                        $timeline_classes[] = 'aat-card-has-backdrop';
                    }
                    ?>
                    <section class="<?php echo esc_attr(implode(' ', $timeline_classes)); ?>"<?php if ($timeline_style !== '') : ?> style="<?php echo esc_attr($timeline_style); ?>"<?php endif; ?>>
                        <div class="aat-timeline-meta">
                            <div class="aat-timeline-ceremony">
                                <?php if ($ceremony_url) : ?>
                                    <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($ceremony_url); ?>"><?php echo esc_html($ordinal($cer)); ?> Ceremony</a>
                                <?php else : ?>
                                    <?php echo esc_html($ordinal($cer)); ?> Ceremony
                                <?php endif; ?>
                            </div>
                            <div class="aat-timeline-year">
                                <?php if ($ceremony_url) : ?>
                                    <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($ceremony_url); ?>"><?php echo esc_html($group['year']); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($group['year']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="aat-timeline-body">
                            <?php foreach ($group['rows'] as $r) :
                                $cat = (string) ($r['canonical_category'] ?? $r['category'] ?? '');
                                $cat_url = $aat->get_category_url($cat);
                                $cat_label = $format_category($cat);
                                $is_winner = (!empty($r['winner']) && (int) $r['winner'] === 1);
                                $row_title_id = $entity === 'title' ? $id : $get_primary_pipe_value($r['film_id'] ?? '');
                                $row_visual = ($entity === 'title') ? $visual : $get_title_visual($row_title_id, 'medium');
                                $history_style = $get_visual_backdrop_style($row_visual, array(
                                    'prefer_poster' => true,
                                    'position' => 'center top',
                                ));
                                $history_classes = array('aat-history-item');
                                if ($is_winner) {
                                    $history_classes[] = 'is-winner';
                                }
                                if ($history_style !== '') {
                                    $history_classes[] = 'aat-card-has-backdrop';
                                }
                            ?>
                                <article class="<?php echo esc_attr(implode(' ', $history_classes)); ?>"<?php if ($history_style !== '') : ?> style="<?php echo esc_attr($history_style); ?>"<?php endif; ?>>
                                    <div class="aat-history-main">
                                        <div class="aat-history-category">
                                            <?php if ($cat_url) : ?>
                                                <a class="aat-hub-link" href="<?php echo esc_url($cat_url); ?>"><span class="aat-category-pill"><?php echo esc_html($cat_label); ?></span></a>
                                            <?php else : ?>
                                                <span class="aat-category-pill"><?php echo esc_html($cat_label); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="aat-history-detail">
                                            <?php if ($entity === 'title') : ?>
                                                <?php $nominee_display = $resolve_title_nominee_display($r); ?>
                                                <?php if (!empty($nominee_display['label'])) : ?>
                                                    <div class="aat-history-line"><strong>Nominee<?php echo !empty($nominee_display['is_plural']) ? 's' : ''; ?>:</strong> <?php echo $render_linked_pipe($nominee_display['label'], $nominee_display['ids']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                                <?php else : ?>
                                                    <div class="aat-history-line aat-history-line-muted">Nominee data is still being verified for this entry.</div>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <div class="aat-history-line"><strong>Film:</strong> <?php echo $render_linked_pipe($r['film'] ?? '', $r['film_id'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($r['detail'])) : ?>
                                                <div class="aat-history-line"><strong>Detail:</strong> <?php echo esc_html((string) $r['detail']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['citation'])) : ?>
                                                <div class="aat-history-line"><strong>Citation:</strong> <?php echo esc_html((string) $r['citation']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['note'])) : ?>
                                                <div class="aat-history-line"><strong>Note:</strong> <?php echo esc_html((string) $r['note']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="aat-history-status">
                                        <?php if ($is_winner) : ?>
                                            <span class="aat-winner-badge">Winner</span>
                                        <?php else : ?>
                                            <span class="aat-nominee-badge">Nominee</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>


    <?php if ($entity !== 'title' && !empty($distinct_films)) : ?>
        <?php
            $filmography_full_requested = isset($_GET['filmography']) && sanitize_key(wp_unslash($_GET['filmography'])) === 'full';
            $filmography_fast_limit = (int) apply_filters('aat_entity_filmography_fast_limit', 12, $entity, $id);
            $filmography_fast_limit = max(1, $filmography_fast_limit);
            $filmography_ids = array_keys($distinct_films);
            $filmography_total = count($filmography_ids);
            $filmography_render_ids = $filmography_full_requested ? $filmography_ids : array_slice($filmography_ids, 0, $filmography_fast_limit);
            $filmography_hidden_count = max(0, $filmography_total - count($filmography_render_ids));
            $filmography_full_url = add_query_arg('filmography', 'full');
            $filmography_fast_url = remove_query_arg('filmography');
        ?>
        <section id="nominated-films" class="aat-entity-section aat-filmography-section">
            <div class="aat-section-head">
                <h2 class="aat-section-title">Nominated Films</h2>
                <p class="aat-section-description">
                    <?php if ($filmography_total > $filmography_fast_limit && !$filmography_full_requested) : ?>
                        <?php echo esc_html(sprintf(__('Poster-first passage through the films that shape this Oscar trail. Fast view shows the strongest %1$s of %2$s films.', 'academy-awards-table'), number_format_i18n(count($filmography_render_ids)), number_format_i18n($filmography_total))); ?>
                    <?php else : ?>
                        <?php echo esc_html__('Poster-first passage through the films that shape this Oscar trail.', 'academy-awards-table'); ?>
                    <?php endif; ?>
                </p>
                <?php if ($filmography_total > $filmography_fast_limit) : ?>
                    <p class="aat-entity-mode-link">
                        <?php if ($filmography_full_requested) : ?>
                            <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_fast_url); ?>"><?php echo esc_html__('Use Fast Film View', 'academy-awards-table'); ?></a>
                        <?php else : ?>
                            <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_full_url); ?>"><?php echo esc_html__('Open Full Filmography', 'academy-awards-table'); ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="aat-filmography-grid">
                <?php foreach ($filmography_render_ids as $fid) :
                    $fid = trim((string) $fid);
                    if (!$fid) { continue; }
                    $film_stats = is_array($distinct_films[$fid] ?? null) ? $distinct_films[$fid] : array();
                    $film_nominations = intval($film_stats['nominations'] ?? 0);
                    $film_wins = intval($film_stats['wins'] ?? 0);
                    $film_category_count = !empty($film_stats['categories']) && is_array($film_stats['categories']) ? count($film_stats['categories']) : 0;
                    $film_ceremony_count = !empty($film_stats['ceremonies']) && is_array($film_stats['ceremonies']) ? count($film_stats['ceremonies']) : 0;
                    $film_label = $get_title_label($fid);
                    if (!$film_label) { $film_label = strtoupper($fid); }
                    $poster_html = $aat->get_poster_img_html_for_title($fid, 'medium', array('class' => 'aat-filmography-poster', 'sizes' => '(max-width: 720px) 132px, 160px'));
                    $tmdb_item = method_exists($aat, 'get_tmdb_data_for_imdb_id') ? $aat->get_tmdb_data_for_imdb_id($fid) : array();
                    $film_url = $build_entity_url($fid);
                ?>
                    <article class="aat-filmography-card">
                        <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $database_url); ?>">
                            <div class="aat-filmography-poster-wrap">
                                <?php if ($poster_html) : ?>
                                    <?php echo $poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($tmdb_item['poster_full'])) : ?>
                                    <img class="aat-filmography-poster" src="<?php echo esc_url($tmdb_item['poster_full']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                <?php else : ?>
                                    <?php $film_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium') : array(); ?>
                                    <?php if (!empty($film_visual['card_fallback_html'])) : ?>
                                        <?php echo $film_visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                            <?php if (!empty($tmdb_item['release_date'])) : ?><p class="aat-filmography-meta"><?php echo esc_html(substr($tmdb_item['release_date'], 0, 4)); ?></p><?php endif; ?>
                            <?php if ($film_nominations > 0) : ?>
                                <div class="aat-filmography-trail" aria-label="<?php echo esc_attr(sprintf(__('%s Oscar trail summary', 'academy-awards-table'), $film_label)); ?>">
                                    <span><?php echo esc_html(sprintf(_n('%s nomination', '%s nominations', $film_nominations, 'academy-awards-table'), number_format_i18n($film_nominations))); ?></span>
                                    <?php if ($film_wins > 0) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s win', '%s wins', $film_wins, 'academy-awards-table'), number_format_i18n($film_wins))); ?></span>
                                    <?php endif; ?>
                                    <?php if ($film_category_count > 0) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s category', '%s categories', $film_category_count, 'academy-awards-table'), number_format_i18n($film_category_count))); ?></span>
                                    <?php endif; ?>
                                    <?php if ($film_ceremony_count > 1) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s ceremony', '%s ceremonies', $film_ceremony_count, 'academy-awards-table'), number_format_i18n($film_ceremony_count))); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($filmography_hidden_count > 0) : ?>
                <div class="aat-entity-compact-notice">
                    <span><?php echo esc_html(sprintf(_n('%s additional film is summarized for speed.', '%s additional films are summarized for speed.', $filmography_hidden_count, 'academy-awards-table'), number_format_i18n($filmography_hidden_count))); ?></span>
                    <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_full_url); ?>"><?php echo esc_html__('Open Full Filmography', 'academy-awards-table'); ?></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($entity !== 'title' && !empty($aat_related_reviews)) : ?>
        <section id="on-lunara" class="aat-entity-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
            <div class="aat-section-head">
                <h2 class="aat-section-title">On Lunara</h2>
                <p class="aat-section-description">Criticism from the Lunara archive that keeps this Oscar history tied to the writing.</p>
            </div>
            <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <?php foreach ($aat_related_reviews as $related_review) : ?>
                    <?php
                        $aat_related_review_has_media = !empty($related_review['review_thumb']) || !empty($related_review['fallback_html']);
                        $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                        <?php if ($aat_related_review_has_media) : ?>
                        <a class="aat-related-review-media" href="<?php echo esc_url($related_review['review_url']); ?>">
                            <?php if (!empty($related_review['review_thumb'])) : ?>
                                <img class="aat-related-review-image" src="<?php echo esc_url($related_review['review_thumb']); ?>" alt="<?php echo esc_attr($related_review['review_title']); ?>" loading="lazy" decoding="async" />
                            <?php elseif (!empty($related_review['fallback_html'])) : ?>
                                <?php echo $related_review['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        <div class="aat-related-review-body">
                            <div class="aat-related-review-kicker">LUNARA FILM REVIEW</div>
                            <h3 class="aat-related-review-title"><a href="<?php echo esc_url($related_review['review_url']); ?>"><?php echo esc_html($related_review['review_title']); ?></a></h3>
                            <p class="aat-related-review-meta">
                                <?php if (!empty($related_review['film_url'])) : ?>
                                    <a href="<?php echo esc_url($related_review['film_url']); ?>"><?php echo esc_html($related_review['film_label']); ?></a>
                                <?php else : ?>
                                    <span><?php echo esc_html($related_review['film_label']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($related_review['film_year'])) : ?>
                                    <span class="aat-sep"> &middot; </span><span><?php echo esc_html($related_review['film_year']); ?></span>
                                <?php endif; ?>
                            </p>
                            <p class="aat-related-review-excerpt"><?php echo esc_html__('Open the review and enter the full argument.', 'academy-awards-table'); ?></p>
                            <div class="aat-related-review-actions">
                                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($related_review['review_url']); ?>">Read Review</a>
                                <?php if (!empty($related_review['film_url'])) : ?>
                                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($related_review['film_url']); ?>"><?php echo esc_html__('Title Profile', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="aat-footer">
        <p>Data sourced from the Academy of Motion Picture Arts and Sciences. Structured dataset compiled and maintained by Lunara Film.</p>
        <p>Profiles are generated directly from the Lunara Film Oscars dataset. New nominations and winners appear automatically after each annual import.</p>
    </div>
</div>
<?php get_footer();
