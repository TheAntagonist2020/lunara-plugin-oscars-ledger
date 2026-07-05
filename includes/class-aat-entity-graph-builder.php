<?php
/**
 * Entity Graph Builder — Phase 2 of the Lunara knowledge graph.
 *
 * Translates the Academy Awards tables (entities / award facts / nominees /
 * categories / ceremonies) into the WordPress entity models registered by
 * Lunara Core 0.2.0: movie posts, person posts, lunara_studio terms, and
 * ledger_entry join posts — with posters attached from the local poster
 * library and director/cast relationships wired from the award record.
 *
 * Design contract:
 * - IDEMPOTENT. Natural keys (entity_id, source_award_id) are stored as
 *   post meta; re-running updates in place and never duplicates.
 * - BATCHED + RESUMABLE. A cursor-based state machine advances through
 *   movies → people → studios → ledger → verify. Runs via an AJAX
 *   self-loop while the admin page is open AND a chained cron event as
 *   background insurance, guarded by a shared lock.
 * - LOCAL-ONLY. No external API calls: imagery comes from the existing
 *   aat_posters attachment map. Missing art backfills later through the
 *   normal importers.
 * - NON-DESTRUCTIVE to the live site: the Oscars surfaces keep rendering
 *   from the SQL tables; the graph is built alongside.
 *
 * @package Academy_Awards_Table
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AAT_Entity_Graph_Builder {

    const STATE_OPTION = 'aat_entity_graph_state';
    const LOCK_KEY     = 'aat_entity_graph_lock';
    const CRON_HOOK    = 'aat_entity_graph_cron';
    const HEARTBEAT_HOOK = 'aat_entity_graph_heartbeat';

    const BATCH_ENTITIES = 200;
    const BATCH_LEDGER   = 150;
    const BATCH_TEARDOWN = 200;

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'), 40);
        add_action('wp_ajax_aat_entity_graph_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_aat_entity_graph_step', array(__CLASS__, 'ajax_step'));
        add_action('wp_ajax_aat_entity_graph_stop', array(__CLASS__, 'ajax_stop'));
        add_action('wp_ajax_aat_entity_graph_teardown', array(__CLASS__, 'ajax_teardown'));
        add_action('wp_ajax_aat_entity_graph_integrity', array(__CLASS__, 'ajax_integrity'));
        add_action('wp_ajax_aat_entity_graph_resync', array(__CLASS__, 'ajax_resync'));
        add_action('wp_ajax_aat_entity_graph_backfill_preview', array(__CLASS__, 'ajax_backfill_preview'));
        add_action('wp_ajax_aat_entity_graph_backfill_apply', array(__CLASS__, 'ajax_backfill_apply'));
        add_action('wp_ajax_aat_entity_graph_name_census', array(__CLASS__, 'ajax_name_census'));
        add_action('wp_ajax_aat_entity_graph_name_repair_step', array(__CLASS__, 'ajax_name_repair_step'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_step'));

        // Living-graph plumbing: any data import re-syncs the graph
        // automatically, and a daily heartbeat catches direct master-table
        // edits (phpMyAdmin) by comparing win counts across layers.
        add_action('aat_after_data_import', array(__CLASS__, 'auto_resync'), 20);
        add_action(self::HEARTBEAT_HOOK, array(__CLASS__, 'heartbeat'));
        if (!wp_next_scheduled(self::HEARTBEAT_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HEARTBEAT_HOOK);
        }
    }

    /* ---------------------------------------------------------------------
     * Table + model plumbing
     * ------------------------------------------------------------------ */

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'aat_' . $name;
    }

    private static function models_ready() {
        return post_type_exists('movie') && post_type_exists('person') && post_type_exists('ledger_entry');
    }

    private static function post_status() {
        $status = apply_filters('aat_entity_graph_post_status', 'publish');
        return in_array($status, array('publish', 'draft', 'pending', 'private'), true) ? $status : 'publish';
    }

    private static function default_state() {
        return array(
            'stage'      => 'idle',
            'running'    => false,
            'cursor'     => '',
            'test_ceremony' => 0,
            'totals'     => array('movies' => 0, 'people' => 0, 'studios' => 0, 'ledger' => 0),
            'processed'  => array('movies' => 0, 'people' => 0, 'studios' => 0, 'ledger' => 0),
            'created'    => 0,
            'updated'    => 0,
            'started_at' => 0,
            'finished_at' => 0,
            'last_error' => '',
            'report'     => array(),
        );
    }

    private static function get_state() {
        $state = get_option(self::STATE_OPTION);
        return is_array($state) ? array_merge(self::default_state(), $state) : self::default_state();
    }

    private static function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }

    /** Small lookup maps, cached per request. */
    private static function categories_map() {
        static $map = null;
        if (null === $map) {
            global $wpdb;
            $map = array();
            $rows = $wpdb->get_results('SELECT category_slug, canonical_category, display_category, award_class FROM ' . self::table('categories'), ARRAY_A);
            foreach ((array) $rows as $row) {
                $map[$row['category_slug']] = $row;
            }
        }
        return $map;
    }

    private static function ceremonies_map() {
        static $map = null;
        if (null === $map) {
            global $wpdb;
            $map = array();
            $rows = $wpdb->get_results('SELECT ceremony, year_label, ceremony_label, sort_year FROM ' . self::table('ceremonies'), ARRAY_A);
            foreach ((array) $rows as $row) {
                $map[(int) $row['ceremony']] = $row;
            }
        }
        return $map;
    }

    /**
     * post_id map for a set of natural keys stored under $meta_key.
     */
    private static function existing_posts_map($meta_key, array $values) {
        global $wpdb;
        if (empty($values)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = $wpdb->prepare(
            "SELECT pm.meta_value AS k, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND p.post_status != 'trash' AND pm.meta_value IN ($placeholders)",
            array_merge(array($meta_key), $values)
        );
        $map = array();
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $map[$row['k']] = (int) $row['post_id'];
        }
        return $map;
    }

    /** attachment_id map from the local poster/portrait library. */
    private static function attachment_map(array $imdb_ids) {
        global $wpdb;
        if (empty($imdb_ids)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($imdb_ids), '%s'));
        $sql = $wpdb->prepare(
            'SELECT imdb_id, attachment_id FROM ' . self::table('posters') . " WHERE attachment_id > 0 AND imdb_id IN ($placeholders)",
            $imdb_ids
        );
        $map = array();
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $map[$row['imdb_id']] = (int) $row['attachment_id'];
        }
        return $map;
    }

    /** ACF-aware field write with a raw-meta fallback. */
    private static function set_field($field_key, $field_name, $value, $post_id) {
        if (function_exists('update_field')) {
            update_field($field_key, $value, $post_id);
            return;
        }
        update_post_meta($post_id, $field_name, $value);
        update_post_meta($post_id, '_' . $field_name, $field_key);
    }

    /* ---------------------------------------------------------------------
     * Stage runners
     * ------------------------------------------------------------------ */

    /**
     * Run one batch of whatever stage the state machine is in.
     * Returns the updated state.
     */
    public static function run_step() {
        $state = self::get_state();
        if (!$state['running']) {
            return $state;
        }
        if (!self::models_ready()) {
            $state['last_error'] = 'Lunara Core 0.2.0 (entity graph models) is not active — deploy/activate it first.';
            $state['running'] = false;
            self::save_state($state);
            return $state;
        }

        // Shared lock so the AJAX loop and the cron chain never overlap.
        if (get_transient(self::LOCK_KEY)) {
            return $state;
        }
        set_transient(self::LOCK_KEY, 1, 50);

        try {
            switch ($state['stage']) {
                case 'movies':
                    $state = self::step_entities($state, 'title', 'movie', 'movies');
                    break;
                case 'people':
                    $state = self::step_entities($state, 'name', 'person', 'people');
                    break;
                case 'studios':
                    $state = self::step_studios($state);
                    break;
                case 'ledger':
                    $state = self::step_ledger($state);
                    break;
                case 'verify':
                    $state = self::step_verify($state);
                    break;
                default:
                    $state['running'] = false;
            }
        } catch (Throwable $e) {
            $state['last_error'] = $e->getMessage();
            $state['running'] = false;
        }

        self::save_state($state);
        delete_transient(self::LOCK_KEY);

        // Chain the background insurance while running.
        if ($state['running'] && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }

        return $state;
    }

    /** SQL filter limiting a run to one ceremony (the proving batch). */
    private static function ceremony_entity_filter($test_ceremony, $entity_col = 'e.entity_id') {
        global $wpdb;
        if ($test_ceremony <= 0) {
            return '';
        }
        $facts = self::table('award_facts');
        $noms  = self::table('award_nominees');
        return $wpdb->prepare(
            " AND ( EXISTS (SELECT 1 FROM $facts f WHERE f.ceremony = %d AND (f.film_entity_id = $entity_col OR f.primary_entity_id = $entity_col))
                 OR EXISTS (SELECT 1 FROM $noms n WHERE n.ceremony = %d AND n.entity_id = $entity_col) )",
            $test_ceremony,
            $test_ceremony
        );
    }

    private static function step_entities($state, $entity_type, $post_type, $counter_key) {
        global $wpdb;
        $entities = self::table('entities');
        $filter   = self::ceremony_entity_filter((int) $state['test_ceremony']);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.entity_id, e.label FROM $entities e
                 WHERE e.entity_type = %s AND e.entity_id > %s $filter
                 ORDER BY e.entity_id ASC LIMIT %d",
                $entity_type,
                (string) $state['cursor'],
                self::BATCH_ENTITIES
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            // Stage complete — advance.
            $state['cursor'] = '';
            $state['stage']  = ('movies' === $state['stage']) ? 'people' : 'studios';
            return $state;
        }

        $ids      = wp_list_pluck($rows, 'entity_id');
        $existing = self::existing_posts_map('_lunara_entity_id', $ids);
        $posters  = self::attachment_map($ids);
        $years    = array();

        if ('title' === $entity_type) {
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            $year_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT f.film_entity_id AS k, MIN(c.sort_year) AS y FROM ' . self::table('award_facts') . ' f
                     INNER JOIN ' . self::table('ceremonies') . " c ON c.ceremony = f.ceremony
                     WHERE f.film_entity_id IN ($placeholders) GROUP BY f.film_entity_id",
                    $ids
                ),
                ARRAY_A
            );
            foreach ((array) $year_rows as $yr) {
                // sort_year is extracted from the Academy's own year_label,
                // which names the HONORED FILM YEAR (e.g. "2025" for the 98th
                // ceremony) — so it IS the release year. No arithmetic. (The
                // first pass subtracted one and shipped 2024 for Hamnet.)
                $years[$yr['k']] = max(1888, (int) $yr['y']);
            }
        }

        $status = self::post_status();

        foreach ($rows as $row) {
            $entity_id = $row['entity_id'];
            $label     = trim(wp_strip_all_tags((string) $row['label']));
            if ('' === $label) {
                $label = $entity_id;
            }

            if (isset($existing[$entity_id])) {
                $post_id = $existing[$entity_id];
                // Keep the title fresh; content/excerpt are editorial and untouched.
                if (get_post_field('post_title', $post_id) !== $label) {
                    wp_update_post(array('ID' => $post_id, 'post_title' => $label));
                }
                $state['updated']++;
            } else {
                $post_id = wp_insert_post(
                    array(
                        'post_type'   => $post_type,
                        'post_status' => $status,
                        'post_title'  => $label,
                    ),
                    true
                );
                if (is_wp_error($post_id)) {
                    $state['last_error'] = $post_id->get_error_message();
                    continue;
                }
                update_post_meta($post_id, '_lunara_entity_id', $entity_id);
                $state['created']++;
            }

            if ('title' === $entity_type) {
                self::set_field('field_lunara_movie_imdb_title_id', 'imdb_title_id', $entity_id, $post_id);
                if (isset($years[$entity_id])) {
                    self::set_field('field_lunara_movie_release_year', 'release_year', $years[$entity_id], $post_id);
                }
            } else {
                update_post_meta($post_id, '_lunara_imdb_name_id', $entity_id);
            }

            if (isset($posters[$entity_id]) && !has_post_thumbnail($post_id)) {
                set_post_thumbnail($post_id, $posters[$entity_id]);
            }

            $state['cursor'] = $entity_id;
            $state['processed'][$counter_key]++;
        }

        return $state;
    }

    private static function step_studios($state) {
        global $wpdb;
        if (!taxonomy_exists('lunara_studio')) {
            $state['stage'] = 'ledger';
            $state['cursor'] = '0';
            return $state;
        }
        $entities = self::table('entities');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entity_id, label FROM $entities WHERE entity_type = 'company' AND entity_id > %s ORDER BY entity_id ASC LIMIT %d",
                (string) $state['cursor'],
                self::BATCH_ENTITIES
            ),
            ARRAY_A
        );
        if (empty($rows)) {
            $state['stage']  = 'ledger';
            $state['cursor'] = '0';
            return $state;
        }
        foreach ($rows as $row) {
            $label = trim(wp_strip_all_tags((string) $row['label']));
            if ('' !== $label) {
                $term = term_exists($label, 'lunara_studio');
                if (!$term) {
                    $term = wp_insert_term($label, 'lunara_studio');
                }
                if (!is_wp_error($term) && !empty($term['term_id'])) {
                    update_term_meta((int) $term['term_id'], '_lunara_entity_id', $row['entity_id']);
                }
            }
            $state['cursor'] = $row['entity_id'];
            $state['processed']['studios']++;
        }
        return $state;
    }

    private static function step_ledger($state) {
        global $wpdb;
        $facts = self::table('award_facts');

        $where = $wpdb->prepare('f.id > %d', (int) $state['cursor']);
        if ((int) $state['test_ceremony'] > 0) {
            $where .= $wpdb->prepare(' AND f.ceremony = %d', (int) $state['test_ceremony']);
        }

        $rows = $wpdb->get_results(
            "SELECT f.id, f.source_award_id, f.ceremony, f.year_label, f.category_slug, f.winner,
                    f.film_entity_id, f.primary_entity_id, f.primary_label
             FROM $facts f WHERE $where ORDER BY f.id ASC LIMIT " . self::BATCH_LEDGER,
            ARRAY_A
        );

        if (empty($rows)) {
            $state['stage']  = 'verify';
            $state['cursor'] = '';
            return $state;
        }

        $categories = self::categories_map();
        $ceremonies = self::ceremonies_map();

        $award_keys  = array_map('strval', wp_list_pluck($rows, 'source_award_id'));
        $existing    = self::existing_posts_map('_aat_source_award_id', $award_keys);
        $entity_refs = array();
        foreach ($rows as $row) {
            if ('' !== $row['film_entity_id']) {
                $entity_refs[] = $row['film_entity_id'];
            }
            if ('' !== $row['primary_entity_id']) {
                $entity_refs[] = $row['primary_entity_id'];
            }
        }
        $entity_posts = self::existing_posts_map('_lunara_entity_id', array_values(array_unique($entity_refs)));

        $status = self::post_status();

        foreach ($rows as $row) {
            $cat      = isset($categories[$row['category_slug']]) ? $categories[$row['category_slug']] : array();
            $cat_name = !empty($cat['display_category']) ? $cat['display_category'] : (!empty($cat['canonical_category']) ? $cat['canonical_category'] : $row['category_slug']);
            $cer      = isset($ceremonies[(int) $row['ceremony']]) ? $ceremonies[(int) $row['ceremony']] : array();
            $year     = !empty($cer['sort_year']) ? (int) $cer['sort_year'] : 0;

            $title = trim(($row['year_label'] ? $row['year_label'] : ('Ceremony ' . $row['ceremony'])) . ' — ' . $cat_name . ' — ' . ($row['primary_label'] ? $row['primary_label'] : $row['film_entity_id']));

            $key = (string) $row['source_award_id'];
            if (isset($existing[$key])) {
                $post_id = $existing[$key];
                $state['updated']++;
            } else {
                $post_id = wp_insert_post(
                    array(
                        'post_type'   => 'ledger_entry',
                        'post_status' => $status,
                        'post_title'  => $title,
                    ),
                    true
                );
                if (is_wp_error($post_id)) {
                    $state['last_error'] = $post_id->get_error_message();
                    $state['cursor'] = (string) $row['id'];
                    continue;
                }
                update_post_meta($post_id, '_aat_source_award_id', $key);
                $state['created']++;
            }

            $movie_post  = ('' !== $row['film_entity_id'] && isset($entity_posts[$row['film_entity_id']])) ? $entity_posts[$row['film_entity_id']] : 0;
            $person_post = 0;
            if ('' !== $row['primary_entity_id'] && 0 === strpos($row['primary_entity_id'], 'nm') && isset($entity_posts[$row['primary_entity_id']])) {
                $person_post = $entity_posts[$row['primary_entity_id']];
            }

            if ($movie_post) {
                self::set_field('field_lunara_ledger_movie', 'movie', $movie_post, $post_id);
            }
            if ($person_post) {
                self::set_field('field_lunara_ledger_person', 'person', $person_post, $post_id);
            }
            self::set_field('field_lunara_ledger_category', 'category', $cat_name, $post_id);
            self::set_field('field_lunara_ledger_ceremony', 'ceremony_number', (int) $row['ceremony'], $post_id);
            if ($year) {
                self::set_field('field_lunara_ledger_year', 'ceremony_year', $year, $post_id);
            }
            self::set_field('field_lunara_ledger_won', 'won', (int) $row['winner'] ? 1 : 0, $post_id);

            // Relationship wiring: directing → movie.directors, acting →
            // movie.principal_cast. Classified by the source's own AMPAS
            // award_class taxonomy (ACTING / DIRECTING / TITLE / ...), with a
            // strict word-boundary fallback for rows missing a class. The
            // first pass substring-matched 'direct', which swept ART DIRECTION
            // nominees into the director slot (Fiona Crombie on Hamnet).
            if ($movie_post && $person_post) {
                $class     = strtoupper(trim((string) ($cat['award_class'] ?? '')));
                $canonical = strtoupper(trim((string) ($cat['canonical_category'] ?? $cat_name)));
                $is_directing = ('DIRECTING' === $class)
                    || ('' === $class && preg_match('/^(BEST\s+)?DIRECT(OR|ING)\b/', $canonical));
                $is_acting = ('ACTING' === $class)
                    || ('' === $class && preg_match('/^(BEST\s+)?(SUPPORTING\s+)?(ACTOR|ACTRESS)\b/', $canonical));
                if ($is_directing) {
                    self::append_relationship($movie_post, 'field_lunara_movie_directors', 'directors', $person_post);
                } elseif ($is_acting) {
                    self::append_relationship($movie_post, 'field_lunara_movie_principal_cast', 'principal_cast', $person_post);
                }
            }

            $state['cursor'] = (string) $row['id'];
            $state['processed']['ledger']++;
        }

        return $state;
    }

    private static function append_relationship($post_id, $field_key, $field_name, $related_id) {
        $current = function_exists('get_field') ? get_field($field_name, $post_id, false) : get_post_meta($post_id, $field_name, true);
        $current = is_array($current) ? array_map('intval', $current) : array();
        if (!in_array((int) $related_id, $current, true)) {
            $current[] = (int) $related_id;
            self::set_field($field_key, $field_name, $current, $post_id);
        }
    }

    private static function step_verify($state) {
        global $wpdb;
        $test = (int) $state['test_ceremony'];

        $entities = self::table('entities');
        $facts    = self::table('award_facts');

        if ($test > 0) {
            $filter = self::ceremony_entity_filter($test);
            $src_movies = (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities e WHERE e.entity_type = 'title' $filter");
            $src_people = (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities e WHERE e.entity_type = 'name' $filter");
            $src_ledger = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $facts f WHERE f.ceremony = %d", $test));
        } else {
            $src_movies = (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'title'");
            $src_people = (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'name'");
            $src_ledger = (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts");
        }

        $count_posts = function ($type, $key) use ($wpdb) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = %s AND p.post_status != 'trash'",
                $key,
                $type
            ));
        };

        $state['report'] = array(
            'movies' => array('source' => $src_movies, 'built' => $count_posts('movie', '_lunara_entity_id')),
            'people' => array('source' => $src_people, 'built' => $count_posts('person', '_lunara_entity_id')),
            'ledger' => array('source' => $src_ledger, 'built' => $count_posts('ledger_entry', '_aat_source_award_id')),
        );
        $state['stage']       = 'done';
        $state['running']     = false;
        $state['finished_at'] = time();
        return $state;
    }

    /* ---------------------------------------------------------------------
     * AJAX + cron endpoints
     * ------------------------------------------------------------------ */

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        check_ajax_referer('aat_entity_graph', 'nonce');
    }

    public static function ajax_start() {
        self::guard();
        wp_send_json_success(self::start_run(isset($_POST['test_ceremony']) ? absint($_POST['test_ceremony']) : 0));
    }

    /**
     * Begin (or restart) a graph build programmatically. Used by the admin
     * page, the post-import auto-resync, and the drift heartbeat.
     */
    public static function start_run($test_ceremony = 0) {
        global $wpdb;

        // Relationships derive purely from the award record at this phase, so
        // every fresh run rebuilds them from zero — this is what purges
        // misclassifications from earlier builder versions (append-only
        // wiring cannot remove a wrong director on re-run).
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'movie'
             INNER JOIN {$wpdb->postmeta} tag ON tag.post_id = p.ID AND tag.meta_key = '_lunara_entity_id'
             WHERE pm.meta_key IN ('directors', 'principal_cast')"
        );

        $state = self::default_state();
        $state['running']       = true;
        $state['stage']         = 'movies';
        $state['test_ceremony'] = (int) $test_ceremony;
        $state['started_at']    = time();

        $entities = self::table('entities');
        $facts    = self::table('award_facts');
        if ($state['test_ceremony'] > 0) {
            $filter = self::ceremony_entity_filter($state['test_ceremony']);
            $state['totals'] = array(
                'movies'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities e WHERE e.entity_type = 'title' $filter"),
                'people'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities e WHERE e.entity_type = 'name' $filter"),
                'studios' => 0,
                'ledger'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $facts WHERE ceremony = %d", $state['test_ceremony'])),
            );
        } else {
            $state['totals'] = array(
                'movies'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'title'"),
                'people'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'name'"),
                'studios' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'company'"),
                'ledger'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts"),
            );
        }

        self::save_state($state);
        return self::run_step();
    }

    public static function ajax_step() {
        self::guard();
        wp_send_json_success(self::run_step());
    }

    public static function ajax_stop() {
        self::guard();
        $state = self::get_state();
        $state['running'] = false;
        self::save_state($state);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_send_json_success($state);
    }

    public static function cron_step() {
        $state = self::run_step();
        if ($state['running']) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }

    /** Batched removal of everything the builder created. */
    public static function ajax_teardown() {
        self::guard();
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type IN ('movie','person','ledger_entry') AND pm.meta_key IN ('_lunara_entity_id','_aat_source_award_id')
             LIMIT %d",
            self::BATCH_TEARDOWN
        ));
        foreach ((array) $ids as $id) {
            wp_delete_post((int) $id, true);
        }
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type IN ('movie','person','ledger_entry') AND pm.meta_key IN ('_lunara_entity_id','_aat_source_award_id')"
        );
        if (0 === $remaining) {
            delete_option(self::STATE_OPTION);
        }
        wp_send_json_success(array('deleted' => count($ids), 'remaining' => $remaining));
    }

    /* ---------------------------------------------------------------------
     * Data integrity: master table vs derived tables vs graph
     * ------------------------------------------------------------------ */

    /**
     * Layer-by-layer truth audit. The master wp_academy_awards table is the
     * layer Dalton maintains directly and trusts; every count downstream
     * must reconcile against it.
     */
    public static function integrity_report() {
        global $wpdb;
        $master = $wpdb->prefix . 'academy_awards';
        $facts  = self::table('award_facts');

        $report = array(
            'master' => array(
                'rows'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $master"),
                'winners' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $master WHERE winner = 1"),
            ),
            'facts' => array(
                'rows'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts"),
                'winners' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts WHERE winner = 1"),
            ),
            'graph' => array(
                'ledger'         => (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_aat_source_award_id'
                     WHERE p.post_type = 'ledger_entry' AND p.post_status != 'trash'"
                ),
                'ledger_winners' => (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = '_aat_source_award_id'
                     INNER JOIN {$wpdb->postmeta} won ON won.post_id = p.ID AND won.meta_key = 'won' AND won.meta_value = '1'
                     WHERE p.post_type = 'ledger_entry' AND p.post_status != 'trash'"
                ),
            ),
            'ceremony_drift'      => array(),
            'zero_winner_groups'  => array(),
            'generated_at'        => current_time('mysql'),
        );

        $drift = $wpdb->get_results(
            "SELECT m.ceremony, m.wins AS master_wins, COALESCE(f.wins, 0) AS facts_wins
             FROM (SELECT ceremony, SUM(winner = 1) AS wins FROM $master GROUP BY ceremony) m
             LEFT JOIN (SELECT ceremony, SUM(winner = 1) AS wins FROM $facts GROUP BY ceremony) f ON f.ceremony = m.ceremony
             HAVING master_wins != facts_wins
             ORDER BY m.ceremony ASC",
            ARRAY_A
        );
        $report['ceremony_drift'] = array_slice((array) $drift, 0, 40);
        $report['ceremony_drift_total'] = count((array) $drift);

        $lost = $wpdb->get_results(
            "SELECT m.id, m.ceremony, m.canonical_category, m.film FROM $master m
             LEFT JOIN $facts f ON f.source_award_id = m.id
             WHERE m.winner = 1 AND f.id IS NULL
             ORDER BY m.ceremony ASC LIMIT 20",
            ARRAY_A
        );
        $report['lost_winner_rows'] = (array) $lost;

        $zero = $wpdb->get_results(
            "SELECT ceremony, category_slug, COUNT(*) AS nominees FROM $facts
             GROUP BY ceremony, category_slug HAVING SUM(winner = 1) = 0
             ORDER BY ceremony ASC, category_slug ASC",
            ARRAY_A
        );
        $report['zero_winner_groups'] = array_slice((array) $zero, 0, 40);
        $report['zero_winner_total']  = count((array) $zero);

        return $report;
    }

    public static function ajax_integrity() {
        self::guard();
        wp_send_json_success(self::integrity_report());
    }

    /**
     * Heal the whole chain from the master table: re-derive the reporting
     * tables, then rebuild the graph in the background.
     */
    public static function resync_from_master() {
        if (class_exists('Academy_Awards_Table')) {
            $plugin = Academy_Awards_Table::get_instance();
            if (method_exists($plugin, 'lunara_rebuild_reporting_tables')) {
                $plugin->lunara_rebuild_reporting_tables();
            }
        }
        return self::start_run(0);
    }

    public static function ajax_resync() {
        self::guard();
        wp_send_json_success(self::resync_from_master());
    }

    /** Fired after any plugin data import completes. */
    public static function auto_resync() {
        if (!self::models_ready()) {
            return;
        }
        // Imports already rebuilt the reporting tables; just re-sync the graph.
        self::start_run(0);
    }

    /**
     * Daily drift check: catches direct master-table edits (phpMyAdmin) that
     * never fire an import hook. Any winner/row drift between master and
     * facts triggers a full heal.
     */
    public static function heartbeat() {
        if (!self::models_ready()) {
            return;
        }
        global $wpdb;
        $master = $wpdb->prefix . 'academy_awards';
        $facts  = self::table('award_facts');
        $m_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $master");
        $m_wins = (int) $wpdb->get_var("SELECT COUNT(*) FROM $master WHERE winner = 1");
        $f_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts");
        $f_wins = (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts WHERE winner = 1");
        if ($m_rows !== $f_rows || $m_wins !== $f_wins) {
            $state = self::get_state();
            if (!$state['running']) {
                self::resync_from_master();
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Winner Flag Backfill — restore flags from the bundled dataset
     * ------------------------------------------------------------------ */

    /**
     * The bundled data/oscars.csv is winner-complete (every ceremony-category
     * carries its winner; 3,515 flags), while the live master lost roughly
     * 688 flags during its original import. This computes the exact set of
     * master rows the bundled dataset marks as winners but the master does
     * not — matched strictly on (ceremony, canonical_category, film_id,
     * nominee_ids) so mojibake in display names can never cause a mismatch.
     * SET-ONLY by design: rows Dalton has already flagged are never touched,
     * and nothing is ever unset.
     */
    private static function backfill_candidates() {
        global $wpdb;
        $csv_path = trailingslashit(AAT_PLUGIN_DIR) . 'data/oscars.csv';
        if (!is_readable($csv_path)) {
            return new WP_Error('no_csv', 'Bundled dataset data/oscars.csv not found.');
        }

        $winner_keys = array();
        $handle = fopen($csv_path, 'r');
        $header = fgetcsv($handle, 0, "\t");
        $idx = array_flip(array_map('trim', (array) $header));
        foreach (array('Ceremony', 'CanonicalCategory', 'FilmId', 'NomineeIds', 'Winner') as $col) {
            if (!isset($idx[$col])) {
                fclose($handle);
                return new WP_Error('bad_csv', 'Bundled dataset is missing the ' . $col . ' column.');
            }
        }
        $csv_rows = 0;
        while (false !== ($row = fgetcsv($handle, 0, "\t"))) {
            if (!isset($row[$idx['Winner']]) || 'True' !== trim((string) $row[$idx['Winner']])) {
                continue;
            }
            $csv_rows++;
            $key = self::backfill_key(
                $row[$idx['Ceremony']],
                $row[$idx['CanonicalCategory']],
                (string) $row[$idx['FilmId']] . ' ' . (string) $row[$idx['NomineeIds']]
            );
            $winner_keys[$key] = true;
        }
        fclose($handle);

        $master = $wpdb->prefix . 'academy_awards';
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, canonical_category, category, film_id, nominee_ids FROM $master WHERE winner != 1 OR winner IS NULL",
            ARRAY_A
        );
        $ids = array();
        $per_ceremony = array();
        $sample_unmatched = array();
        foreach ((array) $rows as $row) {
            // Try canonical first, then the raw category — masters imported by
            // different tools disagree about which column holds which variant.
            $matched = false;
            foreach (array($row['canonical_category'], $row['category']) as $cat) {
                $key = self::backfill_key($row['ceremony'], $cat, (string) $row['film_id'] . ' ' . (string) $row['nominee_ids']);
                if (isset($winner_keys[$key])) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $ids[] = (int) $row['id'];
                $cer = (int) $row['ceremony'];
                $per_ceremony[$cer] = isset($per_ceremony[$cer]) ? $per_ceremony[$cer] + 1 : 1;
            } elseif (count($sample_unmatched) < 3) {
                $sample_unmatched[] = self::backfill_key($row['ceremony'], $row['canonical_category'], (string) $row['film_id'] . ' ' . (string) $row['nominee_ids']);
            }
        }
        ksort($per_ceremony);

        $sample_csv = array_slice(array_keys($winner_keys), 0, 3);

        return array(
            'csv_winner_rows'       => $csv_rows,
            'csv_winner_keys'       => count($winner_keys),
            'candidates'            => count($ids),
            'ids'                   => $ids,
            'per_ceremony'          => $per_ceremony,
            'sample_csv_keys'       => $sample_csv,
            'sample_unflagged_keys' => $sample_unmatched,
        );
    }

    /**
     * Format-immune matching key: ceremony number, category reduced to bare
     * alphanumerics (so "WRITING (Adapted Screenplay)", "Best Adapted
     * Screenplay", and "writing-adapted-screenplay" variants collide only
     * when they truly are the same words), and the SORTED SET of every
     * tt/nm token found across film and nominee id fields — immune to
     * pipe/comma/order/column-assignment differences between the bundled
     * dataset and however the master was originally loaded.
     */
    private static function backfill_key($ceremony, $category, $id_blob) {
        $cat = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $category));
        preg_match_all('/(?:tt|nm)\d{5,10}/i', strtolower((string) $id_blob), $m);
        $tokens = array_unique($m[0]);
        sort($tokens);
        return intval($ceremony) . '|' . $cat . '|' . implode(',', $tokens);
    }

    public static function ajax_backfill_preview() {
        self::guard();
        $result = self::backfill_candidates();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        unset($result['ids']);
        wp_send_json_success($result);
    }

    public static function ajax_backfill_apply() {
        self::guard();
        global $wpdb;
        $result = self::backfill_candidates();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        $applied = 0;
        foreach (array_chunk($result['ids'], 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $applied += (int) $wpdb->query("UPDATE {$wpdb->prefix}academy_awards SET winner = 1 WHERE id IN ($in)");
        }
        // Master changed — heal the whole chain immediately.
        $state = self::resync_from_master();
        wp_send_json_success(array('applied' => $applied, 'state' => $state));
    }

    /* ---------------------------------------------------------------------
     * Name Repair — restore true names for U+FFFD-corrupted labels
     * ------------------------------------------------------------------ */

    /**
     * Accented names arrived with U+FFFD replacement characters baked into
     * the source data (Chlo\xEF\xBF\xBD Zhao) — unrecoverable in place, but
     * every entity carries an intact IMDb bridge id, and TMDB's /find
     * endpoint resolves both tt and nm ids to canonical names. The repair
     * heals ALL layers so no future Heal reintroduces the corruption:
     * graph post title + slug, aat_entities.label, and byte-exact REPLACE()
     * surgery on the master's film/name/nominees strings.
     */
    const FFFD = "\xEF\xBF\xBD";

    private static function tmdb_lookup_name($imdb_id) {
        if (!defined('AAT_TMDB_API_KEY') || '' === AAT_TMDB_API_KEY) {
            return new WP_Error('no_key', 'TMDB API key is not configured.');
        }
        $url = add_query_arg(
            array('api_key' => AAT_TMDB_API_KEY, 'external_source' => 'imdb_id'),
            'https://api.themoviedb.org/3/find/' . rawurlencode($imdb_id)
        );
        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['movie_results'][0]['title'])) {
            return (string) $data['movie_results'][0]['title'];
        }
        if (!empty($data['person_results'][0]['name'])) {
            return (string) $data['person_results'][0]['name'];
        }
        if (!empty($data['tv_results'][0]['name'])) {
            return (string) $data['tv_results'][0]['name'];
        }
        return new WP_Error('not_found', 'TMDB has no record for ' . $imdb_id);
    }

    private static function corrupted_posts_query($limit, $after_id = 0) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, pm.meta_value AS entity_id FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lunara_entity_id'
                 WHERE p.post_type IN ('movie','person') AND p.post_status != 'trash'
                   AND p.post_title LIKE %s AND p.ID > %d
                 ORDER BY p.ID ASC LIMIT %d",
                '%' . $wpdb->esc_like(self::FFFD) . '%',
                (int) $after_id,
                (int) $limit
            ),
            ARRAY_A
        );
    }

    public static function ajax_name_census() {
        self::guard();
        global $wpdb;
        $like   = '%' . $wpdb->esc_like(self::FFFD) . '%';
        $master = $wpdb->prefix . 'academy_awards';
        wp_send_json_success(array(
            'graph_posts' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('movie','person') AND post_status != 'trash' AND post_title LIKE %s",
                $like
            )),
            'entities' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table('entities') . ' WHERE label LIKE %s',
                $like
            )),
            'master_rows' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $master WHERE film LIKE %s OR name LIKE %s OR nominees LIKE %s",
                $like, $like, $like
            )),
            'tmdb_key' => defined('AAT_TMDB_API_KEY') && '' !== AAT_TMDB_API_KEY,
        ));
    }

    /**
     * Repair one batch of corrupted graph posts (and their entity + master
     * echoes). Cursor comes from the client so the loop is stateless.
     */
    public static function ajax_name_repair_step() {
        self::guard();
        global $wpdb;
        $after = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;
        $rows  = self::corrupted_posts_query(12, $after);
        $master   = $wpdb->prefix . 'academy_awards';
        $entities = self::table('entities');
        $fixed = array();
        $unresolved = array();
        $last_id = $after;

        foreach ((array) $rows as $row) {
            $last_id   = (int) $row['ID'];
            $entity_id = strtolower(trim((string) $row['entity_id']));
            $old_title = (string) $row['post_title'];
            if (!preg_match('/^(tt|nm)\d+$/', $entity_id)) {
                $unresolved[] = $old_title . ' (no bridge id)';
                continue;
            }
            $name = self::tmdb_lookup_name($entity_id);
            if (is_wp_error($name)) {
                $unresolved[] = $old_title . ' (' . $name->get_error_message() . ')';
                usleep(120000);
                continue;
            }

            wp_update_post(array(
                'ID'         => (int) $row['ID'],
                'post_title' => $name,
                'post_name'  => sanitize_title($name),
            ));

            // Heal the derived label and the master strings byte-exactly, so
            // future Heal-From-Master runs re-derive CLEAN labels.
            $old_label = $wpdb->get_var($wpdb->prepare("SELECT label FROM $entities WHERE entity_id = %s", $entity_id));
            $wpdb->update($entities, array('label' => $name, 'sort_label' => $name), array('entity_id' => $entity_id));
            if (is_string($old_label) && false !== strpos($old_label, self::FFFD)) {
                foreach (array('film', 'name', 'nominees') as $col) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $master SET $col = REPLACE($col, %s, %s) WHERE $col LIKE %s",
                        $old_label,
                        $name,
                        '%' . $wpdb->esc_like($old_label) . '%'
                    ));
                }
            }

            $fixed[] = $old_title . ' → ' . $name;
            usleep(120000); // stay well inside TMDB rate limits
        }

        wp_send_json_success(array(
            'fixed'      => $fixed,
            'unresolved' => $unresolved,
            'after_id'   => $last_id,
            'done'       => count($rows) < 12,
        ));
    }

    /* ---------------------------------------------------------------------
     * Admin page
     * ------------------------------------------------------------------ */

    public static function register_admin_page() {
        add_submenu_page(
            'academy-awards-table',
            __('Entity Graph Builder', 'academy-awards-table'),
            __('Entity Graph', 'academy-awards-table'),
            'manage_options',
            'aat-entity-graph',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $entities = self::table('entities');
        $facts    = self::table('award_facts');
        $counts = array(
            'movies'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'title'"),
            'people'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'name'"),
            'studios' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $entities WHERE entity_type = 'company'"),
            'ledger'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $facts"),
        );
        $latest_ceremony = (int) $wpdb->get_var('SELECT MAX(ceremony) FROM ' . self::table('ceremonies'));
        $state = self::get_state();
        $ready = self::models_ready();
        $nonce = wp_create_nonce('aat_entity_graph');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Entity Graph Builder', 'academy-awards-table'); ?></h1>
            <p><?php esc_html_e('Translates the Academy Awards tables into Movie, Person, and Ledger Entry entities with posters attached from the local library. Idempotent and resumable: re-running updates in place, never duplicates.', 'academy-awards-table'); ?></p>

            <?php if (!$ready) : ?>
                <div class="notice notice-error"><p><strong><?php esc_html_e('Lunara Core 0.2.0 is not active (movie/person/ledger_entry models missing). Deploy and activate it, then reload this page.', 'academy-awards-table'); ?></strong></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Source data', 'academy-awards-table'); ?></h2>
            <table class="widefat striped" style="max-width:640px">
                <tbody>
                    <tr><td><?php esc_html_e('Films (title entities)', 'academy-awards-table'); ?></td><td><?php echo esc_html(number_format_i18n($counts['movies'])); ?></td></tr>
                    <tr><td><?php esc_html_e('People (name entities)', 'academy-awards-table'); ?></td><td><?php echo esc_html(number_format_i18n($counts['people'])); ?></td></tr>
                    <tr><td><?php esc_html_e('Studios (company entities)', 'academy-awards-table'); ?></td><td><?php echo esc_html(number_format_i18n($counts['studios'])); ?></td></tr>
                    <tr><td><?php esc_html_e('Award facts (ledger rows)', 'academy-awards-table'); ?></td><td><?php echo esc_html(number_format_i18n($counts['ledger'])); ?></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:24px"><?php esc_html_e('Build', 'academy-awards-table'); ?></h2>
            <p>
                <button class="button button-primary" id="aat-eg-start-full" <?php disabled(!$ready); ?>><?php esc_html_e('Start Full Build', 'academy-awards-table'); ?></button>
                <button class="button" id="aat-eg-start-test" <?php disabled(!$ready); ?>><?php echo esc_html(sprintf(__('Proving Run: Ceremony %d Only', 'academy-awards-table'), $latest_ceremony)); ?></button>
                <button class="button" id="aat-eg-stop"><?php esc_html_e('Pause', 'academy-awards-table'); ?></button>
                <button class="button button-link-delete" id="aat-eg-teardown"><?php esc_html_e('Tear Down Built Entities', 'academy-awards-table'); ?></button>
            </p>
            <div id="aat-eg-status" style="max-width:640px;padding:14px 16px;border:1px solid #c3c4c7;background:#fff">
                <strong id="aat-eg-stage"><?php echo esc_html($state['stage']); ?></strong>
                <div id="aat-eg-progress" style="margin-top:8px;font-family:monospace;white-space:pre-line"></div>
            </div>

            <h2 style="margin-top:28px"><?php esc_html_e('Data Integrity', 'academy-awards-table'); ?></h2>
            <p><?php esc_html_e('The master wp_academy_awards table (the one you maintain) is ground truth. This audit reconciles every downstream layer against it; Heal re-derives the reporting tables from the master and re-syncs the graph. Imports auto-resync, and a daily heartbeat catches direct database edits.', 'academy-awards-table'); ?></p>
            <p>
                <button class="button" id="aat-eg-audit"><?php esc_html_e('Run Integrity Audit', 'academy-awards-table'); ?></button>
                <button class="button button-primary" id="aat-eg-resync"><?php esc_html_e('Heal From Master + Re-sync Graph', 'academy-awards-table'); ?></button>
            </p>
            <div id="aat-eg-integrity" style="max-width:760px;padding:14px 16px;border:1px solid #c3c4c7;background:#fff;font-family:monospace;white-space:pre-line"><?php esc_html_e('Audit not run yet.', 'academy-awards-table'); ?></div>

            <h2 style="margin-top:28px"><?php esc_html_e('Winner Flag Backfill', 'academy-awards-table'); ?></h2>
            <p><?php esc_html_e('The bundled data/oscars.csv is winner-complete (every ceremony-category has its winner flagged). This restores flags the master table lost during its original import — matched strictly on ceremony + category + film ID + nominee IDs. It only ever SETS winner = 1: rows you have flagged are never touched, and nothing is ever unset. Applying auto-heals the reporting tables and the graph.', 'academy-awards-table'); ?></p>
            <p>
                <button class="button" id="aat-eg-backfill-preview"><?php esc_html_e('Preview Backfill', 'academy-awards-table'); ?></button>
                <button class="button button-primary" id="aat-eg-backfill-apply"><?php esc_html_e('Apply Backfill + Heal Everything', 'academy-awards-table'); ?></button>
            </p>
            <div id="aat-eg-backfill" style="max-width:760px;padding:14px 16px;border:1px solid #c3c4c7;background:#fff;font-family:monospace;white-space:pre-line"><?php esc_html_e('Preview not run yet.', 'academy-awards-table'); ?></div>

            <h2 style="margin-top:28px"><?php esc_html_e('Name Repair', 'academy-awards-table'); ?></h2>
            <p><?php esc_html_e('Accented names arrived with corrupted characters baked into the source (Chlo� Zhao). Every entity carries an intact IMDb bridge id, so this resolves true names from TMDB and heals every layer — graph titles and URLs, entity labels, and the master strings — so future heals stay clean. Only rows containing the corruption marker are ever touched.', 'academy-awards-table'); ?></p>
            <p>
                <button class="button" id="aat-eg-name-census"><?php esc_html_e('Census Corrupted Names', 'academy-awards-table'); ?></button>
                <button class="button button-primary" id="aat-eg-name-repair"><?php esc_html_e('Repair All Names', 'academy-awards-table'); ?></button>
            </p>
            <div id="aat-eg-names" style="max-width:760px;max-height:340px;overflow:auto;padding:14px 16px;border:1px solid #c3c4c7;background:#fff;font-family:monospace;white-space:pre-line"><?php esc_html_e('Census not run yet.', 'academy-awards-table'); ?></div>

            <script>
            (function () {
                var nonce = <?php echo wp_json_encode($nonce); ?>;
                var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var initial = <?php echo wp_json_encode($state); ?>;
                var looping = false;

                function post(action, extra) {
                    var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, extra || {}));
                    return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
                }

                function render(state) {
                    document.getElementById('aat-eg-stage').textContent =
                        (state.running ? 'RUNNING — ' : '') + state.stage.toUpperCase() + (state.last_error ? ('  |  ERROR: ' + state.last_error) : '');
                    var p = state.processed || {}, t = state.totals || {};
                    var lines = ['movies  ' + (p.movies || 0) + ' / ' + (t.movies || 0),
                                 'people  ' + (p.people || 0) + ' / ' + (t.people || 0),
                                 'studios ' + (p.studios || 0) + ' / ' + (t.studios || 0),
                                 'ledger  ' + (p.ledger || 0) + ' / ' + (t.ledger || 0),
                                 'created ' + (state.created || 0) + '   updated ' + (state.updated || 0)];
                    if (state.report && state.report.movies) {
                        lines.push('');
                        lines.push('VERIFY  movies ' + state.report.movies.built + '/' + state.report.movies.source +
                                   '  people ' + state.report.people.built + '/' + state.report.people.source +
                                   '  ledger ' + state.report.ledger.built + '/' + state.report.ledger.source);
                    }
                    document.getElementById('aat-eg-progress').textContent = lines.join('\n');
                }

                function loop() {
                    if (looping) { return; }
                    looping = true;
                    (function tick() {
                        post('aat_entity_graph_step').then(function (res) {
                            if (!res || !res.success) { looping = false; return; }
                            render(res.data);
                            if (res.data.running) { setTimeout(tick, 250); } else { looping = false; }
                        }).catch(function () { looping = false; });
                    })();
                }

                document.getElementById('aat-eg-start-full').addEventListener('click', function () {
                    post('aat_entity_graph_start').then(function (res) { if (res.success) { render(res.data); loop(); } });
                });
                document.getElementById('aat-eg-start-test').addEventListener('click', function () {
                    post('aat_entity_graph_start', { test_ceremony: <?php echo (int) $latest_ceremony; ?> }).then(function (res) { if (res.success) { render(res.data); loop(); } });
                });
                document.getElementById('aat-eg-stop').addEventListener('click', function () {
                    post('aat_entity_graph_stop').then(function (res) { if (res.success) { render(res.data); } });
                });
                document.getElementById('aat-eg-audit').addEventListener('click', function () {
                    document.getElementById('aat-eg-integrity').textContent = 'Auditing…';
                    post('aat_entity_graph_integrity').then(function (res) {
                        if (!res.success) { return; }
                        var r = res.data;
                        var lines = [
                            'MASTER  rows ' + r.master.rows + '   winners ' + r.master.winners,
                            'FACTS   rows ' + r.facts.rows + '   winners ' + r.facts.winners + (r.facts.winners === r.master.winners && r.facts.rows === r.master.rows ? '   ✓ in sync' : '   ✗ DRIFT'),
                            'GRAPH   ledger ' + r.graph.ledger + '   winners ' + r.graph.ledger_winners,
                            '',
                            'Ceremonies with win-count drift: ' + r.ceremony_drift_total
                        ];
                        r.ceremony_drift.forEach(function (d) { lines.push('  ceremony ' + d.ceremony + ': master ' + d.master_wins + ' vs facts ' + d.facts_wins); });
                        if (r.lost_winner_rows && r.lost_winner_rows.length) {
                            lines.push('');
                            lines.push('Winner rows LOST IN DERIVATION (master flagged, no facts row):');
                            r.lost_winner_rows.forEach(function (l) { lines.push('  #' + l.id + ' cer ' + l.ceremony + ' — ' + (l.canonical_category || '(blank category)') + ' — ' + (l.film || '')); });
                        }
                        lines.push('');
                        lines.push('Categories with zero winners (facts): ' + r.zero_winner_total);
                        r.zero_winner_groups.forEach(function (z) { lines.push('  cer ' + z.ceremony + ' — ' + z.category_slug + ' (' + z.nominees + ' nominees)'); });
                        document.getElementById('aat-eg-integrity').textContent = lines.join('\n');
                    });
                });
                document.getElementById('aat-eg-resync').addEventListener('click', function () {
                    if (!window.confirm('Re-derive the reporting tables from the master table and rebuild the graph from them?')) { return; }
                    document.getElementById('aat-eg-integrity').textContent = 'Healing from master…';
                    post('aat_entity_graph_resync').then(function (res) {
                        if (res.success) { render(res.data); loop(); document.getElementById('aat-eg-integrity').textContent = 'Reporting tables rebuilt from master. Graph re-sync running above.'; }
                    });
                });
                document.getElementById('aat-eg-backfill-preview').addEventListener('click', function () {
                    document.getElementById('aat-eg-backfill').textContent = 'Computing…';
                    post('aat_entity_graph_backfill_preview').then(function (res) {
                        if (!res.success) { document.getElementById('aat-eg-backfill').textContent = 'Error: ' + (res.data && res.data.message); return; }
                        var r = res.data;
                        var lines = ['Bundled dataset winner rows: ' + r.csv_winner_rows + ' (' + r.csv_winner_keys + ' unique nominations)',
                                     'Master rows missing their flag: ' + r.candidates, ''];
                        Object.keys(r.per_ceremony).forEach(function (c) { lines.push('  ceremony ' + c + ': +' + r.per_ceremony[c]); });
                        if (r.candidates === 0 && r.sample_unflagged_keys && r.sample_unflagged_keys.length) {
                            lines.push('');
                            lines.push('DIAGNOSTIC — zero matches. Sample keys:');
                            lines.push('  bundled: ' + (r.sample_csv_keys || []).join('  '));
                            lines.push('  master:  ' + r.sample_unflagged_keys.join('  '));
                        }
                        document.getElementById('aat-eg-backfill').textContent = lines.join('\n');
                    });
                });
                document.getElementById('aat-eg-backfill-apply').addEventListener('click', function () {
                    if (!window.confirm('Set winner = 1 on every master row the bundled dataset marks as a winner (never unsets anything), then heal the reporting tables and re-sync the graph?')) { return; }
                    document.getElementById('aat-eg-backfill').textContent = 'Applying + healing…';
                    post('aat_entity_graph_backfill_apply').then(function (res) {
                        if (!res.success) { document.getElementById('aat-eg-backfill').textContent = 'Error: ' + (res.data && res.data.message); return; }
                        document.getElementById('aat-eg-backfill').textContent = 'Applied ' + res.data.applied + ' winner flags. Full heal + graph re-sync running above.';
                        render(res.data.state); loop();
                    });
                });
                document.getElementById('aat-eg-name-census').addEventListener('click', function () {
                    document.getElementById('aat-eg-names').textContent = 'Counting…';
                    post('aat_entity_graph_name_census').then(function (res) {
                        if (!res.success) { return; }
                        var r = res.data;
                        document.getElementById('aat-eg-names').textContent =
                            'Corrupted graph titles: ' + r.graph_posts + '\nCorrupted entity labels: ' + r.entities + '\nCorrupted master rows: ' + r.master_rows + '\nTMDB key configured: ' + (r.tmdb_key ? 'yes' : 'NO — configure it first');
                    });
                });
                document.getElementById('aat-eg-name-repair').addEventListener('click', function () {
                    if (!window.confirm('Resolve true names from TMDB for every corrupted title and heal graph + entities + master?')) { return; }
                    var box = document.getElementById('aat-eg-names');
                    box.textContent = 'Repairing…';
                    var totalFixed = 0, log = [];
                    (function step(after) {
                        post('aat_entity_graph_name_repair_step', { after_id: after }).then(function (res) {
                            if (!res.success) { box.textContent = 'Error.'; return; }
                            var d = res.data;
                            totalFixed += d.fixed.length;
                            d.fixed.forEach(function (f) { log.push('✓ ' + f); });
                            d.unresolved.forEach(function (u) { log.push('— ' + u); });
                            box.textContent = 'Repaired ' + totalFixed + ' so far…\n' + log.join('\n');
                            if (!d.done) { step(d.after_id); }
                            else { box.textContent = 'DONE — repaired ' + totalFixed + '.\n' + log.join('\n'); }
                        });
                    })(0);
                });
                document.getElementById('aat-eg-teardown').addEventListener('click', function () {
                    if (!window.confirm('Delete every built movie/person/ledger entity? The Academy Awards tables are untouched; this only removes the generated posts.')) { return; }
                    (function purge() {
                        post('aat_entity_graph_teardown').then(function (res) {
                            if (res.success && res.data.remaining > 0) { purge(); }
                            else { document.getElementById('aat-eg-progress').textContent = 'Teardown complete.'; }
                        });
                    })();
                });

                render(initial);
                if (initial.running) { loop(); }
            })();
            </script>
        </div>
        <?php
    }
}
