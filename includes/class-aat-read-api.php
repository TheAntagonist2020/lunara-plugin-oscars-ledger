<?php
/**
 * Oscar Ledger read API: the public lunara-ledger/v1 REST namespace.
 *
 * Read-only JSON over the live Oscars tables, meaning the master awards table
 * and the reporting tables that every bundled import rebuilds. It adds no
 * tables and no writes, and it needs no keys and exposes no admin data. Every
 * parameter is allowlisted, and every value reaches SQL through
 * $wpdb->prepare(). Answers are cached under the dataset stamp, which changes
 * on every completed import, so a new dataset never serves an old answer.
 *
 * Routes (all GET):
 *   /status                        live counts, dataset stamp, source and licence
 *   /ceremonies, /categories       the lists, with their stats
 *   /nominations                   filtered, sorted, paged records
 *   /facets                        disjunctive counts for every filter
 *   /groups                        results grouped by ceremony, category, film, person or company
 *   /search                        typeahead over people, films, companies, categories and years
 *   /entities/{tt|nm|co id}        one film, person or company with its record
 *
 * Filters, shared by /nominations, /facets and /groups:
 *   ceremony (1..N; takes precedence over decade), decade (1920, 1930, ...),
 *   class, category (a slug; implies its class), winner=1, and entity (up to
 *   four IMDb IDs, comma-separated, all of which must appear on a record).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AAT_Read_API {

    const NS = 'lunara-ledger/v1';
    const CACHE_GROUP = 'aat_read_api';
    const CACHE_TTL = 21600; // 6 hours; the stamp retires old answers sooner.
    const PER_PAGE_DEFAULT = 25;
    const PER_PAGE_MAX = 50;
    const MAX_ENTITIES = 4;
    const ID_PATTERN = '/^(tt|nm|co)\d{7,10}$/';

    /** Display names for the dataset's award classes. URL values stay the dataset codes. */
    const CLASS_LABELS = array(
        'Title' => 'Films',
        'Acting' => 'Acting',
        'Directing' => 'Directing',
        'Writing' => 'Writing',
        'Music' => 'Music',
        'Production' => 'Craft',
        'SciTech' => 'Scientific & Technical',
        'Special' => 'Honorary & Special',
    );

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        $routes = array(
            '/status' => 'route_status',
            '/ceremonies' => 'route_ceremonies',
            '/categories' => 'route_categories',
            '/nominations' => 'route_nominations',
            '/facets' => 'route_facets',
            '/groups' => 'route_groups',
            '/search' => 'route_search',
            '/entities/(?P<id>(?:tt|nm|co)\d{7,10})' => 'route_entity',
        );
        foreach ($routes as $route => $callback) {
            register_rest_route(self::NS, $route, array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, $callback),
                'permission_callback' => '__return_true',
            ));
        }
    }

    /* ------------------------------------------------------------------
     * Routes
     * ------------------------------------------------------------------ */

    public static function route_status(WP_REST_Request $request) {
        $data = self::cached(array('status'), true, function () {
            global $wpdb;
            $facts = self::table('aat_award_facts');
            $totals = $wpdb->get_row("SELECT COUNT(*) AS nominations, COALESCE(SUM(winner), 0) AS wins FROM $facts", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (!is_array($totals)) {
                return self::unavailable();
            }
            $by_type = $wpdb->get_results('SELECT entity_type, COUNT(*) AS n FROM ' . self::table('aat_entity_stats') . ' WHERE nominations > 0 GROUP BY entity_type', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $entities = array('films' => 0, 'people' => 0, 'companies' => 0);
            foreach ((array) $by_type as $row) {
                $kind = self::kind_from_type($row['entity_type'] ?? '');
                if ($kind !== '') {
                    $entities[$kind === 'film' ? 'films' : ($kind === 'person' ? 'people' : 'companies')] = (int) $row['n'];
                }
            }
            return array(
                'plugin_version' => AAT_VERSION,
                'dataset' => array(
                    'stamp' => self::stamp(),
                    'nominations' => (int) $totals['nominations'],
                    'wins' => (int) $totals['wins'],
                    'ceremonies' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('aat_ceremonies')), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    'categories' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('aat_categories')), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    'entities' => $entities,
                ),
                'source' => 'DLu/oscar_data, corrected and reconciled against the Academy Awards Database',
                'license' => 'BSD-2-Clause (DLu/oscar_data)',
            );
        });
        return self::respond($data);
    }

    public static function route_ceremonies(WP_REST_Request $request) {
        $data = self::cached(array('ceremonies'), true, function () {
            global $wpdb;
            $rows = $wpdb->get_results(
                'SELECT ce.ceremony, ce.year_label, ce.sort_year, COALESCE(s.nominations, 0) AS nominations, COALESCE(s.wins, 0) AS wins, COALESCE(s.categories_total, 0) AS categories'
                . ' FROM ' . self::table('aat_ceremonies') . ' ce LEFT JOIN ' . self::table('aat_ceremony_stats') . ' s ON s.ceremony = ce.ceremony'
                . ' ORDER BY ce.ceremony DESC',
                ARRAY_A
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if (!is_array($rows)) {
                return self::unavailable();
            }
            $plugin = self::plugin();
            $items = array();
            foreach ($rows as $row) {
                $n = (int) $row['ceremony'];
                $items[] = array(
                    'ceremony' => $n,
                    'label' => $plugin->ordinal($n),
                    'year_label' => (string) $row['year_label'],
                    'year' => (int) $row['sort_year'],
                    'nominations' => (int) $row['nominations'],
                    'wins' => (int) $row['wins'],
                    'categories' => (int) $row['categories'],
                    'url' => $plugin->get_ceremony_url($n),
                );
            }
            return array('items' => $items);
        });
        return self::respond($data);
    }

    public static function route_categories(WP_REST_Request $request) {
        $data = self::cached(array('categories'), true, function () {
            $plugin = self::plugin();
            $items = array();
            foreach (self::category_map() as $slug => $category) {
                $items[] = array(
                    'slug' => $slug,
                    'name' => $plugin->format_category_display($category['canonical']),
                    'canonical' => $category['canonical'],
                    'class' => $category['class'],
                    'class_label' => self::class_label($category['class']),
                    'nominations' => $category['nominations'],
                    'wins' => $category['wins'],
                    'first_ceremony' => $category['first_ceremony'],
                    'last_ceremony' => $category['last_ceremony'],
                    'url' => $plugin->get_category_url($category['canonical']),
                );
            }
            return array('items' => $items);
        });
        return self::respond($data);
    }

    public static function route_nominations(WP_REST_Request $request) {
        $filters = self::parse_filters($request);
        if (is_wp_error($filters)) {
            return $filters;
        }
        $paging = self::parse_paging($request);
        $sort = self::param($request, 'sort') === 'oldest' ? 'oldest' : 'newest';

        $data = self::cached(array('nominations', $filters, $paging, $sort), false, function () use ($filters, $paging, $sort) {
            global $wpdb;
            list($where, $params) = self::where($filters);
            $from = self::from_sql();

            $totals = self::get_row("SELECT COUNT(*) AS total, COALESCE(SUM(f.winner), 0) AS wins $from WHERE $where", $params);
            if (!is_array($totals)) {
                return self::unavailable();
            }
            $order = $sort === 'oldest' ? 'f.ceremony ASC, f.source_award_id ASC' : 'f.ceremony DESC, f.source_award_id ASC';
            $rows = self::get_results(
                'SELECT f.source_award_id AS id, f.ceremony, f.winner, f.category_slug, ce.year_label, c.canonical_category, c.award_class,'
                . ' a.category AS category_as_given, a.film, a.film_id, a.name, a.nominees, a.nominee_ids, a.detail, a.note, a.citation'
                . " $from WHERE $where ORDER BY $order LIMIT %d OFFSET %d",
                array_merge($params, array($paging['per_page'], ($paging['page'] - 1) * $paging['per_page']))
            );
            if (!is_array($rows)) {
                return self::unavailable();
            }

            $total = (int) $totals['total'];
            return array(
                'total' => $total,
                'wins' => (int) $totals['wins'],
                'page' => $paging['page'],
                'per_page' => $paging['per_page'],
                'pages' => (int) max(1, ceil($total / $paging['per_page'])),
                'sort' => $sort,
                'filters' => self::public_filters($filters),
                'items' => self::serialize_records($rows),
            );
        });
        return self::respond($data);
    }

    public static function route_facets(WP_REST_Request $request) {
        $filters = self::parse_filters($request);
        if (is_wp_error($filters)) {
            return $filters;
        }

        $data = self::cached(array('facets', $filters), false, function () use ($filters) {
            $from = self::from_sql();
            $plugin = self::plugin();

            list($where, $params) = self::where($filters);
            $totals = self::get_row("SELECT COUNT(*) AS total, COALESCE(SUM(f.winner), 0) AS wins $from WHERE $where", $params);
            if (!is_array($totals)) {
                return self::unavailable();
            }

            // Each facet counts with every filter except its own (disjunctive),
            // so a chip always shows what choosing it would return.
            list($where, $params) = self::where($filters, array('class', 'category'));
            $class_rows = self::get_results("SELECT c.award_class AS k, COUNT(*) AS n, COALESCE(SUM(f.winner), 0) AS w $from WHERE $where GROUP BY c.award_class", $params);

            list($where, $params) = self::where($filters, array('category'));
            $category_rows = self::get_results("SELECT f.category_slug AS k, c.canonical_category AS canonical, COUNT(*) AS n, COALESCE(SUM(f.winner), 0) AS w $from WHERE $where GROUP BY f.category_slug, c.canonical_category", $params);

            list($where, $params) = self::where($filters, array('ceremony', 'decade'));
            $decade_rows = self::get_results("SELECT FLOOR(ce.sort_year / 10) * 10 AS k, COUNT(*) AS n, COALESCE(SUM(f.winner), 0) AS w $from WHERE $where GROUP BY k ORDER BY k ASC", $params);
            $ceremony_rows = self::get_results("SELECT f.ceremony AS k, ce.year_label AS year_label, COUNT(*) AS n, COALESCE(SUM(f.winner), 0) AS w $from WHERE $where GROUP BY f.ceremony, ce.year_label ORDER BY f.ceremony DESC", $params);

            if (!is_array($class_rows) || !is_array($category_rows) || !is_array($decade_rows) || !is_array($ceremony_rows)) {
                return self::unavailable();
            }

            $classes = array();
            foreach ($class_rows as $row) {
                $classes[] = array('value' => (string) $row['k'], 'label' => self::class_label((string) $row['k']), 'nominations' => (int) $row['n'], 'wins' => (int) $row['w']);
            }
            usort($classes, function ($a, $b) {
                return strcmp($a['label'], $b['label']);
            });

            $categories = array();
            foreach ($category_rows as $row) {
                $categories[] = array('value' => (string) $row['k'], 'label' => $plugin->format_category_display((string) $row['canonical']), 'nominations' => (int) $row['n'], 'wins' => (int) $row['w']);
            }
            usort($categories, function ($a, $b) {
                return strcasecmp($a['label'], $b['label']);
            });

            $decades = array();
            foreach ($decade_rows as $row) {
                $decade = (int) $row['k'];
                if ($decade > 0) {
                    $decades[] = array('value' => $decade, 'label' => $decade . 's', 'nominations' => (int) $row['n'], 'wins' => (int) $row['w']);
                }
            }

            $ceremonies = array();
            foreach ($ceremony_rows as $row) {
                $n = (int) $row['k'];
                $ceremonies[] = array('value' => $n, 'label' => $plugin->ordinal($n), 'year_label' => (string) $row['year_label'], 'nominations' => (int) $row['n'], 'wins' => (int) $row['w']);
            }

            return array(
                'total' => (int) $totals['total'],
                'wins' => (int) $totals['wins'],
                'filters' => self::public_filters($filters),
                'class' => $classes,
                'category' => $categories,
                'decade' => $decades,
                'ceremony' => $ceremonies,
            );
        });
        return self::respond($data);
    }

    public static function route_groups(WP_REST_Request $request) {
        $filters = self::parse_filters($request);
        if (is_wp_error($filters)) {
            return $filters;
        }
        $by = self::param($request, 'by');
        if (!in_array($by, array('ceremony', 'category', 'film', 'person', 'company'), true)) {
            return self::invalid('by', 'Use by=ceremony, category, film, person or company.');
        }
        $paging = self::parse_paging($request);

        $data = self::cached(array('groups', $by, $filters, $paging), false, function () use ($filters, $by, $paging) {
            $from = self::from_sql();
            $plugin = self::plugin();
            list($where, $params) = self::where($filters);
            $limit = array($paging['per_page'], ($paging['page'] - 1) * $paging['per_page']);
            $count_distinct = 'COUNT(DISTINCT f.source_award_id) AS n, COUNT(DISTINCT CASE WHEN f.winner = 1 THEN f.source_award_id END) AS w';

            if ($by === 'ceremony') {
                $total = self::get_var("SELECT COUNT(DISTINCT f.ceremony) $from WHERE $where", $params);
                $rows = self::get_results("SELECT f.ceremony AS k, ce.year_label AS year_label, $count_distinct $from WHERE $where GROUP BY f.ceremony, ce.year_label ORDER BY f.ceremony DESC LIMIT %d OFFSET %d", array_merge($params, $limit));
            } elseif ($by === 'category') {
                $total = self::get_var("SELECT COUNT(DISTINCT f.category_slug) $from WHERE $where", $params);
                $rows = self::get_results("SELECT f.category_slug AS k, c.canonical_category AS canonical, $count_distinct $from WHERE $where GROUP BY f.category_slug, c.canonical_category ORDER BY n DESC, f.category_slug ASC LIMIT %d OFFSET %d", array_merge($params, $limit));
            } elseif ($by === 'film') {
                $where .= " AND f.film_entity_id <> ''";
                $total = self::get_var("SELECT COUNT(DISTINCT f.film_entity_id) $from WHERE $where", $params);
                $rows = self::get_results("SELECT f.film_entity_id AS k, e.label AS label, $count_distinct $from LEFT JOIN " . self::table('aat_entities') . " e ON e.entity_id = f.film_entity_id WHERE $where GROUP BY f.film_entity_id, e.label ORDER BY w DESC, n DESC, e.label ASC LIMIT %d OFFSET %d", array_merge($params, $limit));
            } else {
                $type = $by === 'person' ? 'name' : 'company';
                $join = ' INNER JOIN ' . self::table('aat_award_nominees') . ' g ON g.source_award_id = f.source_award_id AND g.entity_type = %s';
                $join_params = array_merge(array($type), $params);
                $total = self::get_var("SELECT COUNT(DISTINCT g.entity_id) $from $join WHERE $where", $join_params);
                $rows = self::get_results("SELECT g.entity_id AS k, e.label AS label, $count_distinct $from $join LEFT JOIN " . self::table('aat_entities') . " e ON e.entity_id = g.entity_id WHERE $where GROUP BY g.entity_id, e.label ORDER BY w DESC, n DESC, e.label ASC LIMIT %d OFFSET %d", array_merge($join_params, $limit));
            }

            if ($total === null || !is_array($rows)) {
                return self::unavailable();
            }

            $items = array();
            foreach ($rows as $row) {
                $item = array('nominations' => (int) $row['n'], 'wins' => (int) $row['w']);
                if ($by === 'ceremony') {
                    $n = (int) $row['k'];
                    $item = array('value' => $n, 'label' => $plugin->ordinal($n), 'year_label' => (string) $row['year_label'], 'url' => $plugin->get_ceremony_url($n)) + $item;
                } elseif ($by === 'category') {
                    $item = array('value' => (string) $row['k'], 'label' => $plugin->format_category_display((string) $row['canonical']), 'url' => $plugin->get_category_url((string) $row['canonical'])) + $item;
                } else {
                    $id = (string) $row['k'];
                    $item = array('value' => $id, 'label' => (string) $row['label'], 'kind' => $by, 'url' => $plugin->build_entity_url_from_id($id)) + $item;
                }
                $items[] = $item;
            }

            $total = (int) $total;
            return array(
                'by' => $by,
                'total' => $total,
                'page' => $paging['page'],
                'per_page' => $paging['per_page'],
                'pages' => (int) max(1, ceil($total / $paging['per_page'])),
                'filters' => self::public_filters($filters),
                'items' => $items,
            );
        });
        return self::respond($data);
    }

    public static function route_search(WP_REST_Request $request) {
        $q = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) self::param($request, 'q'))));
        $length = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
        if ($length < 2 || $length > 80) {
            return self::invalid('q', 'Search needs 2 to 80 characters.');
        }
        $kind = self::param($request, 'kind');
        if ($kind !== '' && !in_array($kind, array('film', 'person', 'company'), true)) {
            return self::invalid('kind', 'Use kind=film, person or company.');
        }
        $limit = max(1, min(20, (int) (self::param($request, 'limit') ?: 10)));

        $data = self::cached(array('search', strtolower($q), $kind, $limit), false, function () use ($q, $kind, $limit) {
            global $wpdb;
            $plugin = self::plugin();
            $results = array();
            $entities = self::table('aat_entities');
            $stats = self::table('aat_entity_stats');

            $id = strtolower($q);
            if (preg_match(self::ID_PATTERN, $id)) {
                $rows = self::get_results("SELECT e.entity_id, e.entity_type, e.label, COALESCE(s.nominations, 0) AS nominations, COALESCE(s.wins, 0) AS wins FROM $entities e LEFT JOIN $stats s ON s.entity_id = e.entity_id WHERE e.entity_id = %s AND COALESCE(s.nominations, 0) > 0", array($id));
            } else {
                $key = self::name_key($q);
                if ($key === '') {
                    return array('query' => $q, 'results' => array());
                }
                $type_sql = '';
                $params = array('%' . $wpdb->esc_like($key) . '%');
                if ($kind !== '') {
                    $type_sql = ' AND e.entity_type = %s';
                    $params[] = self::type_from_kind($kind);
                }
                $params[] = $wpdb->esc_like($key) . '%';
                $params[] = '% ' . $wpdb->esc_like($key) . '%';
                $params[] = $limit;
                $rows = self::get_results(
                    "SELECT e.entity_id, e.entity_type, e.label, COALESCE(s.nominations, 0) AS nominations, COALESCE(s.wins, 0) AS wins"
                    . " FROM $entities e LEFT JOIN $stats s ON s.entity_id = e.entity_id"
                    . " WHERE e.sort_label LIKE %s AND e.label <> '' AND COALESCE(s.nominations, 0) > 0$type_sql"
                    . ' ORDER BY (e.sort_label LIKE %s) DESC, (e.sort_label LIKE %s) DESC, nominations DESC, e.label ASC LIMIT %d',
                    $params
                );
            }
            if (!is_array($rows)) {
                return self::unavailable();
            }
            foreach ($rows as $row) {
                $entity_kind = self::kind_from_type($row['entity_type']);
                if ($entity_kind === '') {
                    continue;
                }
                $results[] = array(
                    'type' => $entity_kind,
                    'id' => (string) $row['entity_id'],
                    'name' => (string) $row['label'],
                    'nominations' => (int) $row['nominations'],
                    'wins' => (int) $row['wins'],
                    'url' => $plugin->build_entity_url_from_id((string) $row['entity_id']),
                    'explore' => array('entity' => (string) $row['entity_id']),
                );
            }

            if ($kind === '') {
                // A ceremony year ("1996") and matching categories ("cinematography").
                if (preg_match('/^(19[2-9]\d|20\d\d)$/', $q)) {
                    $ceremony_rows = self::get_results('SELECT ceremony, year_label FROM ' . self::table('aat_ceremonies') . ' WHERE sort_year = %d ORDER BY ceremony ASC', array((int) $q));
                    foreach ((array) $ceremony_rows as $row) {
                        $n = (int) $row['ceremony'];
                        array_unshift($results, array(
                            'type' => 'ceremony',
                            'id' => (string) $n,
                            'name' => sprintf('%s Academy Awards (films of %s)', $plugin->ordinal($n), (string) $row['year_label']),
                            'url' => $plugin->get_ceremony_url($n),
                            'explore' => array('ceremony' => $n),
                        ));
                    }
                }
                $needle = strtolower($q);
                $matched = 0;
                foreach (self::category_map() as $slug => $category) {
                    $name = $plugin->format_category_display($category['canonical']);
                    if (strpos(strtolower($name . ' ' . $category['canonical']), $needle) === false) {
                        continue;
                    }
                    $results[] = array(
                        'type' => 'category',
                        'id' => $slug,
                        'name' => $name,
                        'nominations' => $category['nominations'],
                        'wins' => $category['wins'],
                        'url' => $plugin->get_category_url($category['canonical']),
                        'explore' => array('category' => $slug),
                    );
                    if (++$matched >= 3) {
                        break;
                    }
                }
            }

            return array('query' => $q, 'results' => array_slice($results, 0, $limit + 4));
        });
        return self::respond($data);
    }

    public static function route_entity(WP_REST_Request $request) {
        $id = strtolower((string) $request['id']);
        if (!preg_match(self::ID_PATTERN, $id)) {
            return self::invalid('id', 'Use an IMDb ID such as nm0000122, tt0018773 or co0071509.');
        }

        $data = self::cached(array('entity', $id), false, function () use ($id) {
            global $wpdb;
            $plugin = self::plugin();
            $row = self::get_row(
                'SELECT e.entity_id, e.entity_type, e.label, COALESCE(s.nominations, 0) AS nominations, COALESCE(s.wins, 0) AS wins, COALESCE(s.ceremonies, 0) AS ceremonies,'
                . ' COALESCE(s.first_ceremony, 0) AS first_ceremony, COALESCE(s.last_ceremony, 0) AS last_ceremony'
                . ' FROM ' . self::table('aat_entities') . ' e LEFT JOIN ' . self::table('aat_entity_stats') . ' s ON s.entity_id = e.entity_id WHERE e.entity_id = %s',
                array($id)
            );
            if (!is_array($row) || (int) $row['nominations'] <= 0) {
                return new WP_Error('aat_not_found', 'No Oscar record for this ID.', array('status' => 404));
            }

            list($where, $params) = self::where(array('entities' => array($id)) + self::empty_filters());
            $category_rows = self::get_results(
                'SELECT f.category_slug AS k, c.canonical_category AS canonical, COUNT(*) AS n, COALESCE(SUM(f.winner), 0) AS w ' . self::from_sql()
                . " WHERE $where GROUP BY f.category_slug, c.canonical_category ORDER BY n DESC, f.category_slug ASC",
                $params
            );
            $categories = array();
            foreach ((array) $category_rows as $category) {
                $categories[] = array(
                    'slug' => (string) $category['k'],
                    'name' => $plugin->format_category_display((string) $category['canonical']),
                    'nominations' => (int) $category['n'],
                    'wins' => (int) $category['w'],
                );
            }

            $ceremony = function ($n) use ($plugin) {
                $n = (int) $n;
                if ($n <= 0) {
                    return null;
                }
                $year = (string) self::get_var('SELECT year_label FROM ' . self::table('aat_ceremonies') . ' WHERE ceremony = %d', array($n));
                return array('ceremony' => $n, 'label' => $plugin->ordinal($n), 'year_label' => $year, 'url' => $plugin->get_ceremony_url($n));
            };

            return array(
                'id' => $id,
                'kind' => self::kind_from_type($row['entity_type']),
                'name' => (string) $row['label'],
                'url' => $plugin->build_entity_url_from_id($id),
                'imdb_url' => self::imdb_url($id),
                'nominations' => (int) $row['nominations'],
                'wins' => (int) $row['wins'],
                'ceremonies' => (int) $row['ceremonies'],
                'first_ceremony' => $ceremony($row['first_ceremony']),
                'last_ceremony' => $ceremony($row['last_ceremony']),
                'categories' => $categories,
                'explore' => array('entity' => $id),
            );
        });
        return self::respond($data);
    }

    /* ------------------------------------------------------------------
     * Filters and SQL
     * ------------------------------------------------------------------ */

    private static function empty_filters() {
        return array('ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'winner' => false, 'entities' => array());
    }

    /**
     * Normalize the shared filters, or return a 400 error naming the bad one.
     * The result is canonical (sorted, de-duplicated), so it doubles as a cache key.
     */
    private static function parse_filters(WP_REST_Request $request) {
        $filters = self::empty_filters();

        $ceremony = self::param($request, 'ceremony');
        if ($ceremony !== '') {
            if (!ctype_digit($ceremony) || (int) $ceremony < 1 || (int) $ceremony > 200) {
                return self::invalid('ceremony', 'Use a ceremony number such as 69.');
            }
            $filters['ceremony'] = (int) $ceremony;
        }

        $decade = self::param($request, 'decade');
        if ($decade !== '' && $filters['ceremony'] === 0) {
            if (!ctype_digit($decade) || (int) $decade % 10 !== 0 || (int) $decade < 1920 || (int) $decade > 2090) {
                return self::invalid('decade', 'Use a decade such as 1970.');
            }
            $filters['decade'] = (int) $decade;
        }

        $category = sanitize_title(self::param($request, 'category'));
        $categories = self::category_map();
        if ($category !== '') {
            if (!isset($categories[$category])) {
                return self::invalid('category', 'Unknown category. See /categories for the slugs.');
            }
            $filters['category'] = $category;
        }

        $class = self::param($request, 'class');
        if ($class !== '' && $filters['category'] === '') {
            $classes = array_unique(wp_list_pluck($categories, 'class'));
            if (!in_array($class, $classes, true)) {
                return self::invalid('class', 'Use one of: ' . implode(', ', $classes) . '.');
            }
            $filters['class'] = $class;
        }

        $winner = self::param($request, 'winner');
        if ($winner !== '') {
            if (!in_array($winner, array('0', '1', 'true', 'false'), true)) {
                return self::invalid('winner', 'Use winner=1 to show winners only.');
            }
            $filters['winner'] = in_array($winner, array('1', 'true'), true);
        }

        $entity = self::param($request, 'entity');
        if ($entity !== '') {
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($entity))), 'strlen')));
            if (count($ids) > self::MAX_ENTITIES) {
                return self::invalid('entity', 'Use at most ' . self::MAX_ENTITIES . ' IDs.');
            }
            foreach ($ids as $id) {
                if (!preg_match(self::ID_PATTERN, $id)) {
                    return self::invalid('entity', 'Use IMDb IDs such as nm0000122, tt0018773 or co0071509.');
                }
            }
            sort($ids, SORT_STRING);
            $filters['entities'] = $ids;
        }

        return $filters;
    }

    private static function parse_paging(WP_REST_Request $request) {
        $page = (int) self::param($request, 'page');
        $per_page = (int) self::param($request, 'per_page');
        return array(
            'page' => max(1, min(1000, $page ?: 1)),
            'per_page' => max(1, min(self::PER_PAGE_MAX, $per_page ?: self::PER_PAGE_DEFAULT)),
        );
    }

    private static function public_filters($filters) {
        $out = array();
        foreach (array('ceremony', 'decade', 'class', 'category') as $key) {
            if (!empty($filters[$key])) {
                $out[$key] = $filters[$key];
            }
        }
        if (!empty($filters['winner'])) {
            $out['winner'] = 1;
        }
        if (!empty($filters['entities'])) {
            $out['entity'] = implode(',', $filters['entities']);
        }
        return (object) $out;
    }

    /** The shared FROM clause: facts f, master a, categories c, ceremonies ce. */
    private static function from_sql() {
        return 'FROM ' . self::table('aat_award_facts') . ' f'
            . ' INNER JOIN ' . self::table('academy_awards') . ' a ON a.id = f.source_award_id'
            . ' LEFT JOIN ' . self::table('aat_categories') . ' c ON c.category_slug = f.category_slug'
            . ' LEFT JOIN ' . self::table('aat_ceremonies') . ' ce ON ce.ceremony = f.ceremony';
    }

    /**
     * WHERE clause and its prepare() values for the filters, leaving out the
     * filter names in $omit (used for disjunctive facet counts).
     */
    private static function where($filters, $omit = array()) {
        global $wpdb;
        $where = array('1=1');
        $params = array();
        $use = function ($key) use ($omit) {
            return !in_array($key, $omit, true);
        };

        if ($use('ceremony') && !empty($filters['ceremony'])) {
            $where[] = 'f.ceremony = %d';
            $params[] = (int) $filters['ceremony'];
        }
        if ($use('decade') && !empty($filters['decade'])) {
            $where[] = 'ce.sort_year BETWEEN %d AND %d';
            $params[] = (int) $filters['decade'];
            $params[] = (int) $filters['decade'] + 9;
        }
        if ($use('class') && !empty($filters['class'])) {
            $where[] = 'c.award_class = %s';
            $params[] = (string) $filters['class'];
        }
        if ($use('category') && !empty($filters['category'])) {
            $where[] = 'f.category_slug = %s';
            $params[] = (string) $filters['category'];
        }
        if ($use('winner') && !empty($filters['winner'])) {
            $where[] = 'f.winner = 1';
        }
        foreach ((array) ($filters['entities'] ?? array()) as $id) {
            if (strpos($id, 'tt') === 0) {
                // A film: its first title (facts) or any title slot of the record.
                $where[] = "(f.film_entity_id = %s OR CONCAT('|', a.film_id, '|') LIKE %s)";
                $params[] = $id;
                $params[] = '%|' . $wpdb->esc_like($id) . '|%';
            } else {
                $where[] = 'EXISTS (SELECT 1 FROM ' . self::table('aat_award_nominees') . ' n WHERE n.source_award_id = f.source_award_id AND n.entity_id = %s)';
                $params[] = $id;
            }
        }
        return array(implode(' AND ', $where), $params);
    }

    /* ------------------------------------------------------------------
     * Records
     * ------------------------------------------------------------------ */

    /**
     * Records for the API, pairing each credited name and film with the IDs in
     * its own slot. A row whose name and ID slot counts differ keeps its names
     * and links none of them rather than guess. A slot credited jointly (the
     * Coens' "Roderick Jaynes") lists every person it names.
     */
    private static function serialize_records($rows) {
        $plugin = self::plugin();
        $records = array();
        $all_ids = array();

        foreach ($rows as $row) {
            $n = (int) $row['ceremony'];
            $films = self::pair_slots($row['film'], $row['film_id']);
            $nominees = self::pair_slots($row['nominees'], $row['nominee_ids']);
            foreach (array_merge($films, $nominees) as $slot) {
                foreach ($slot['ids'] as $id) {
                    $all_ids[$id] = true;
                }
            }
            $records[] = array(
                'id' => (int) $row['id'],
                'ceremony' => $n,
                'ceremony_label' => $plugin->ordinal($n),
                'year_label' => (string) $row['year_label'],
                'ceremony_url' => $plugin->get_ceremony_url($n),
                'category' => array(
                    'slug' => (string) $row['category_slug'],
                    'name' => $plugin->format_category_display((string) $row['canonical_category'], $n),
                    'as_given' => (string) $row['category_as_given'],
                    'class' => (string) $row['award_class'],
                    'class_label' => self::class_label((string) $row['award_class']),
                    'url' => $plugin->get_category_url((string) $row['canonical_category']),
                ),
                'winner' => (bool) (int) $row['winner'],
                'films' => $films,
                'credit' => (string) $row['name'],
                'nominees' => $nominees,
                'detail' => self::split_pipes($row['detail']),
                'note' => (string) $row['note'],
                'citation' => (string) $row['citation'],
            );
        }

        // One lookup names every linked identity on the page, so a credit that
        // differs from the canonical name can read "credited as".
        $names = self::entity_names(array_keys($all_ids));
        foreach ($records as &$record) {
            foreach (array('films', 'nominees') as $field) {
                foreach ($record[$field] as &$slot) {
                    $slot['links'] = array();
                    foreach ($slot['ids'] as $id) {
                        $slot['links'][] = array(
                            'id' => $id,
                            'name' => isset($names[$id]) && $names[$id] !== '' ? $names[$id] : $slot['name'],
                            'url' => $plugin->build_entity_url_from_id($id),
                        );
                    }
                    $slot['joint'] = count($slot['ids']) > 1;
                }
                unset($slot);
            }
        }
        unset($record);

        return $records;
    }

    private static function pair_slots($labels_raw, $ids_raw) {
        $labels = self::split_pipes($labels_raw);
        $raw_ids = trim((string) $ids_raw);
        $id_slots = $raw_ids === '' ? array() : array_map('trim', explode('|', $raw_ids));
        $aligned = !empty($id_slots) && count($id_slots) === count($labels);

        $slots = array();
        foreach ($labels as $index => $label) {
            $ids = array();
            if ($aligned) {
                foreach (explode(',', $id_slots[$index]) as $token) {
                    $token = strtolower(trim($token));
                    if (preg_match(self::ID_PATTERN, $token)) {
                        $ids[] = $token;
                    }
                }
            }
            $slots[] = array('name' => $label, 'ids' => array_values(array_unique($ids)));
        }
        return $slots;
    }

    private static function split_pipes($value) {
        $value = trim((string) $value);
        return $value === '' ? array() : array_map('trim', explode('|', $value));
    }

    private static function entity_names($ids) {
        $ids = array_values(array_filter($ids, function ($id) {
            return (bool) preg_match(self::ID_PATTERN, $id);
        }));
        if (empty($ids)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $rows = self::get_results('SELECT entity_id, label FROM ' . self::table('aat_entities') . " WHERE entity_id IN ($placeholders)", $ids);
        $names = array();
        foreach ((array) $rows as $row) {
            $names[(string) $row['entity_id']] = (string) $row['label'];
        }
        return $names;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /** slug => canonical, class, stats. One query, cached under the stamp. */
    private static function category_map() {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }
        $map = self::cached(array('category_map'), true, function () {
            global $wpdb;
            $rows = $wpdb->get_results(
                'SELECT c.category_slug, c.canonical_category, c.award_class, COALESCE(s.nominations, 0) AS nominations, COALESCE(s.wins, 0) AS wins,'
                . ' COALESCE(s.first_ceremony, 0) AS first_ceremony, COALESCE(s.last_ceremony, 0) AS last_ceremony'
                . ' FROM ' . self::table('aat_categories') . ' c LEFT JOIN ' . self::table('aat_category_stats') . ' s ON s.category_slug = c.category_slug'
                . ' ORDER BY c.award_class ASC, c.canonical_category ASC',
                ARRAY_A
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $out = array();
            foreach ((array) $rows as $row) {
                $out[(string) $row['category_slug']] = array(
                    'canonical' => (string) $row['canonical_category'],
                    'class' => (string) $row['award_class'],
                    'nominations' => (int) $row['nominations'],
                    'wins' => (int) $row['wins'],
                    'first_ceremony' => (int) $row['first_ceremony'],
                    'last_ceremony' => (int) $row['last_ceremony'],
                );
            }
            return $out;
        });
        if (!is_array($map)) {
            $map = array();
        }
        return $map;
    }

    /**
     * Cache one answer under AAT_VERSION and the dataset stamp. Bounded answers
     * (status, lists) use transients; open-ended ones (filters, search, entities)
     * use the object cache only, so crawlers cannot fill the options table. The
     * edge cache in front of /wp-json/ does the rest.
     */
    private static function cached($parts, $persistent, $build) {
        $key = 'aat_api_' . md5(wp_json_encode(array(AAT_VERSION, self::stamp(), $parts)));
        $hit = $persistent ? get_transient($key) : wp_cache_get($key, self::CACHE_GROUP);
        if ($hit !== false) {
            return $hit;
        }
        $value = call_user_func($build);
        if (!is_wp_error($value)) {
            if ($persistent) {
                set_transient($key, $value, self::CACHE_TTL);
            } else {
                wp_cache_set($key, $value, self::CACHE_GROUP, self::CACHE_TTL);
            }
        }
        return $value;
    }

    private static function respond($data) {
        if (is_wp_error($data)) {
            return $data;
        }
        $response = rest_ensure_response($data);
        $response->header('Cache-Control', 'public, max-age=300');
        return $response;
    }

    private static function stamp() {
        $plugin = self::plugin();
        return method_exists($plugin, 'get_dataset_stamp') ? (string) $plugin->get_dataset_stamp() : '';
    }

    private static function plugin() {
        return Academy_Awards_Table::get_instance();
    }

    private static function table($suffix) {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    private static function param(WP_REST_Request $request, $name) {
        $value = $request->get_param($name);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function invalid($param, $message) {
        return new WP_Error('aat_invalid_param', $message, array('status' => 400, 'param' => $param));
    }

    private static function unavailable() {
        return new WP_Error('aat_unavailable', 'The Oscars data is temporarily unavailable.', array('status' => 503));
    }

    private static function class_label($class) {
        return self::CLASS_LABELS[$class] ?? $class;
    }

    private static function imdb_url($id) {
        if (strpos($id, 'tt') === 0) {
            return 'https://www.imdb.com/title/' . $id . '/';
        }
        if (strpos($id, 'nm') === 0) {
            return 'https://www.imdb.com/name/' . $id . '/';
        }
        return 'https://www.imdb.com/search/title/?companies=' . $id;
    }

    private static function kind_from_type($type) {
        $map = array('title' => 'film', 'name' => 'person', 'company' => 'company');
        return $map[(string) $type] ?? '';
    }

    private static function type_from_kind($kind) {
        $map = array('film' => 'title', 'person' => 'name', 'company' => 'company');
        return $map[(string) $kind] ?? '';
    }

    /** The same comparison key the plugin writes to aat_entities.sort_label. */
    private static function name_key($value) {
        $value = (string) $value;
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = strtolower($value);
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) preg_replace('/\s+/', ' ', (string) $value));
    }

    /* prepare() only when there are values: WordPress warns on a query without placeholders. */

    private static function prepared($sql, $params) {
        global $wpdb;
        return empty($params) ? $sql : $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function get_results($sql, $params = array()) {
        global $wpdb;
        return $wpdb->get_results(self::prepared($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function get_row($sql, $params = array()) {
        global $wpdb;
        return $wpdb->get_row(self::prepared($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function get_var($sql, $params = array()) {
        global $wpdb;
        return $wpdb->get_var(self::prepared($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
