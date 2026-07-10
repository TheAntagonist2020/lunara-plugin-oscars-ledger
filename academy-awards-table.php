<?php
/**
 * Plugin Name: Lunara Film - Academy Awards Database
 * Plugin URI: https://lunarafilm.com/oscars/
 * Description: A premium, server-side searchable database of every Academy Award nominee and winner (1st ceremony through 2025), compiled and maintained by Lunara Film.
 * Version: 2.7.77
 * Author: Lunara Film (Dalton Johnson)
 * Author URI: https://lunarafilm.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: academy-awards-table
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AAT_VERSION', '2.7.77');
define('AAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AAT_BUNDLED_CSV_PATH', AAT_PLUGIN_DIR . 'data/oscars.csv');

// TMDB API key — never committed to source control. Resolved (in order) from a
// wp-config AAT_TMDB_API_KEY constant, the AAT_TMDB_API_KEY environment
// variable, or the `aat_tmdb_api_key` option. Defined on plugins_loaded rather
// than at global scope, and skipped while WordPress is installing, so the option
// lookup never runs before the database is ready (WP install / WP-CLI bootstrap).
// Mirrors get_omdb_api_key() and keeps the secret out of git while preserving the
// constant indirection the theme relies on; batch imports work once the key is set.
if (!function_exists('aat_define_tmdb_api_key')) {
    function aat_define_tmdb_api_key() {
        if (defined('AAT_TMDB_API_KEY')) {
            return;
        }
        $key = getenv('AAT_TMDB_API_KEY');
        if (!is_string($key) || '' === trim($key)) {
            if (function_exists('wp_installing') && wp_installing()) {
                return; // DB not ready during install / some CLI bootstraps
            }
            $key = (string) get_option('aat_tmdb_api_key', '');
        }
        $key = trim($key);
        if ('' !== $key) {
            define('AAT_TMDB_API_KEY', $key);
        }
    }
}
add_action('plugins_loaded', 'aat_define_tmdb_api_key', 1);

require_once AAT_PLUGIN_DIR . 'includes/class-aat-ceremony-writeups.php';

// Entity Graph Builder (knowledge-graph Phase 2): translates the awards
// tables into the movie/person/ledger_entry models that Lunara Core 0.2.0
// registers. Admin-only tooling; inert until the models exist.
require_once AAT_PLUGIN_DIR . 'includes/class-aat-entity-graph-builder.php';
AAT_Entity_Graph_Builder::init();

/**
 * Main Plugin Class
 */
class Academy_Awards_Table {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Consistent helper for the main Academy Awards database table.
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . "academy_awards";
    }

    private function get_omdb_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_omdb_reviews';
    }

    private function get_person_credit_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_person_credit_reviews';
    }

    private function get_person_credit_row_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_person_credit_row_reviews';
    }

    private function get_company_credit_row_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_company_credit_row_reviews';
    }

    private function get_person_portrait_existing_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_person_portrait_existing_reviews';
    }

    private function get_omdb_poster_reviews_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_omdb_poster_reviews';
    }

    private function get_ceremony_writeups_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_ceremony_writeups';
    }

    private function get_ceremonies_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_ceremonies';
    }

    private function get_categories_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_categories';
    }

    private function get_entities_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_entities';
    }

    private function get_award_facts_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_award_facts';
    }

    private function get_award_nominees_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_award_nominees';
    }

    private function get_ceremony_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_ceremony_stats';
    }

    private function get_category_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_category_stats';
    }

    private function get_entity_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aat_entity_stats';
    }

    /**
     * Versioned in-place migrations for the derived reporting tables.
     *
     * Schema v2 widens every entity-id column from varchar(32) to varchar(64):
     * slug-based local name ids (lnm-…) exceed 32 characters for long
     * organization credits (e.g. "lnm-the-governments-of-great-britain"), and
     * strict-mode MySQL rejects the whole row — which is how the two
     * documentary winner rows (The True Glory, First Steps) silently vanished
     * from the facts table. ALTER … MODIFY keeps the tables populated, so the
     * live Oscars surfaces never see an empty window.
     */
    private function maybe_upgrade_reporting_schema() {
        global $wpdb;

        $target = '2';
        if ((string) get_option('aat_reporting_schema_version') === $target) {
            return true;
        }

        $alters = array(
            $this->get_entities_table_name() => array(
                "MODIFY entity_id varchar(64) NOT NULL",
            ),
            $this->get_award_facts_table_name() => array(
                "MODIFY film_entity_id varchar(64) NOT NULL DEFAULT ''",
                "MODIFY primary_entity_id varchar(64) NOT NULL DEFAULT ''",
            ),
            $this->get_award_nominees_table_name() => array(
                "MODIFY entity_id varchar(64) NOT NULL DEFAULT ''",
            ),
            $this->get_ceremony_stats_table_name() => array(
                "MODIFY top_title_entity_id varchar(64) NOT NULL DEFAULT ''",
            ),
            $this->get_entity_stats_table_name() => array(
                "MODIFY entity_id varchar(64) NOT NULL",
            ),
        );
        $failures = array();

        foreach ($alters as $table => $changes) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                // Fresh installs get the current width from dbDelta below.
                continue;
            }

            foreach ($changes as $change) {
                if ($wpdb->query("ALTER TABLE $table $change") === false) {
                    $failures[] = array(
                        'table' => (string) $table,
                        'change' => (string) $change,
                        'error' => (string) $wpdb->last_error,
                    );
                }
            }
        }

        if (!empty($failures)) {
            update_option('aat_reporting_schema_migration_failures', array(
                'failures' => $failures,
                'generated_at' => current_time('mysql'),
            ), false);
            return false;
        }

        delete_option('aat_reporting_schema_migration_failures');
        update_option('aat_reporting_schema_version', $target, false);
        return true;
    }

    private function maybe_create_reporting_tables($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reporting_schema_ready = $this->maybe_upgrade_reporting_schema();

        $ceremonies_table = $this->get_ceremonies_table_name();
        $categories_table = $this->get_categories_table_name();
        $entities_table = $this->get_entities_table_name();
        $facts_table = $this->get_award_facts_table_name();
        $nominees_table = $this->get_award_nominees_table_name();
        $ceremony_stats_table = $this->get_ceremony_stats_table_name();
        $category_stats_table = $this->get_category_stats_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();

        $sql_ceremonies = "CREATE TABLE IF NOT EXISTS $ceremonies_table (
            ceremony int(3) NOT NULL,
            year_label varchar(20) NOT NULL DEFAULT '',
            ceremony_label varchar(32) NOT NULL DEFAULT '',
            sort_year int(4) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (ceremony),
            KEY sort_year (sort_year)
        ) $charset_collate;";

        $sql_categories = "CREATE TABLE IF NOT EXISTS $categories_table (
            category_slug varchar(191) NOT NULL,
            canonical_category varchar(255) NOT NULL DEFAULT '',
            display_category varchar(255) NOT NULL DEFAULT '',
            award_class varchar(50) NOT NULL DEFAULT '',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (category_slug),
            KEY award_class (award_class),
            KEY canonical_category (canonical_category(191))
        ) $charset_collate;";

        $sql_entities = "CREATE TABLE IF NOT EXISTS $entities_table (
            entity_id varchar(64) NOT NULL,
            entity_type varchar(20) NOT NULL DEFAULT '',
            label varchar(500) NOT NULL DEFAULT '',
            sort_label varchar(500) NOT NULL DEFAULT '',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (entity_id),
            KEY entity_type (entity_type),
            KEY sort_label (sort_label(191))
        ) $charset_collate;";

        $sql_facts = "CREATE TABLE IF NOT EXISTS $facts_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_award_id mediumint(9) unsigned NOT NULL,
            ceremony int(3) NOT NULL,
            year_label varchar(20) NOT NULL DEFAULT '',
            category_slug varchar(191) NOT NULL DEFAULT '',
            winner tinyint(1) NOT NULL DEFAULT 0,
            film_entity_id varchar(64) NOT NULL DEFAULT '',
            primary_entity_id varchar(64) NOT NULL DEFAULT '',
            primary_label varchar(500) NOT NULL DEFAULT '',
            nominee_count smallint(5) unsigned NOT NULL DEFAULT 0,
            has_detail tinyint(1) NOT NULL DEFAULT 0,
            has_note tinyint(1) NOT NULL DEFAULT 0,
            has_citation tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_award_id (source_award_id),
            KEY ceremony (ceremony),
            KEY category_slug (category_slug),
            KEY winner (winner),
            KEY film_entity_id (film_entity_id),
            KEY primary_entity_id (primary_entity_id),
            KEY ceremony_category_winner (ceremony, category_slug, winner),
            KEY category_ceremony_winner (category_slug, ceremony, winner),
            KEY film_ceremony (film_entity_id, ceremony),
            KEY primary_entity_ceremony (primary_entity_id, ceremony)
        ) $charset_collate;";

        $sql_nominees = "CREATE TABLE IF NOT EXISTS $nominees_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_award_id mediumint(9) unsigned NOT NULL,
            ceremony int(3) NOT NULL,
            category_slug varchar(191) NOT NULL DEFAULT '',
            entity_id varchar(64) NOT NULL DEFAULT '',
            entity_type varchar(20) NOT NULL DEFAULT '',
            entity_label varchar(500) NOT NULL DEFAULT '',
            nominee_ordinal smallint(5) unsigned NOT NULL DEFAULT 0,
            is_primary tinyint(1) NOT NULL DEFAULT 0,
            winner tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_entity_nominee (source_award_id, entity_id, nominee_ordinal),
            KEY ceremony (ceremony),
            KEY category_slug (category_slug),
            KEY entity_id (entity_id),
            KEY entity_type (entity_type),
            KEY winner (winner),
            KEY ceremony_category_winner (ceremony, category_slug, winner),
            KEY category_ceremony_winner (category_slug, ceremony, winner),
            KEY entity_ceremony (entity_id, ceremony),
            KEY entity_type_entity (entity_type, entity_id)
        ) $charset_collate;";

        $sql_ceremony_stats = "CREATE TABLE IF NOT EXISTS $ceremony_stats_table (
            ceremony int(3) NOT NULL,
            year_label varchar(20) NOT NULL DEFAULT '',
            nominations int(11) NOT NULL DEFAULT 0,
            wins int(11) NOT NULL DEFAULT 0,
            categories_total int(11) NOT NULL DEFAULT 0,
            winner_categories int(11) NOT NULL DEFAULT 0,
            winning_titles_count int(11) NOT NULL DEFAULT 0,
            top_title_entity_id varchar(64) NOT NULL DEFAULT '',
            top_title_label varchar(500) NOT NULL DEFAULT '',
            top_title_mentions int(11) NOT NULL DEFAULT 0,
            top_title_wins int(11) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (ceremony),
            KEY top_title_entity_id (top_title_entity_id)
        ) $charset_collate;";

        $sql_category_stats = "CREATE TABLE IF NOT EXISTS $category_stats_table (
            category_slug varchar(191) NOT NULL,
            canonical_category varchar(255) NOT NULL DEFAULT '',
            nominations int(11) NOT NULL DEFAULT 0,
            wins int(11) NOT NULL DEFAULT 0,
            ceremonies int(11) NOT NULL DEFAULT 0,
            first_ceremony int(3) NOT NULL DEFAULT 0,
            last_ceremony int(3) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (category_slug),
            KEY canonical_category (canonical_category(191))
        ) $charset_collate;";

        $sql_entity_stats = "CREATE TABLE IF NOT EXISTS $entity_stats_table (
            entity_id varchar(64) NOT NULL,
            entity_type varchar(20) NOT NULL DEFAULT '',
            label varchar(500) NOT NULL DEFAULT '',
            nominations int(11) NOT NULL DEFAULT 0,
            wins int(11) NOT NULL DEFAULT 0,
            ceremonies int(11) NOT NULL DEFAULT 0,
            first_ceremony int(3) NOT NULL DEFAULT 0,
            last_ceremony int(3) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (entity_id),
            KEY entity_type (entity_type),
            KEY wins (wins),
            KEY ceremonies (ceremonies)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_ceremonies);
        dbDelta($sql_categories);
        dbDelta($sql_entities);
        dbDelta($sql_facts);
        dbDelta($sql_nominees);
        dbDelta($sql_ceremony_stats);
        dbDelta($sql_category_stats);
        dbDelta($sql_entity_stats);

        return $reporting_schema_ready;
    }

    private function maybe_create_person_credit_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reviews_table = $this->get_person_credit_reviews_table_name();
        $sql_reviews = "CREATE TABLE IF NOT EXISTS $reviews_table (
            review_key varchar(191) NOT NULL,
            source_award_id mediumint(9) unsigned NOT NULL DEFAULT 0,
            label_index smallint(5) unsigned NOT NULL DEFAULT 0,
            category_slug varchar(191) NOT NULL DEFAULT '',
            credit_label varchar(500) NOT NULL DEFAULT '',
            review_state varchar(32) NOT NULL DEFAULT 'needs_review',
            proposed_person_id varchar(32) NOT NULL DEFAULT '',
            correction_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (review_key),
            KEY review_state (review_state),
            KEY category_slug (category_slug),
            KEY proposed_person_id (proposed_person_id),
            KEY source_award_id (source_award_id),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reviews);
    }

    private function maybe_create_person_credit_row_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reviews_table = $this->get_person_credit_row_reviews_table_name();
        $sql_reviews = "CREATE TABLE IF NOT EXISTS $reviews_table (
            source_award_id mediumint(9) unsigned NOT NULL,
            category_slug varchar(191) NOT NULL DEFAULT '',
            credit_labels text,
            review_state varchar(32) NOT NULL DEFAULT 'needs_review',
            proposed_nominee_ids text,
            correction_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (source_award_id),
            KEY review_state (review_state),
            KEY category_slug (category_slug),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reviews);
    }

    private function maybe_create_company_credit_row_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reviews_table = $this->get_company_credit_row_reviews_table_name();
        $sql_reviews = "CREATE TABLE IF NOT EXISTS $reviews_table (
            source_award_id mediumint(9) unsigned NOT NULL,
            category_slug varchar(191) NOT NULL DEFAULT '',
            credit_labels text,
            review_state varchar(32) NOT NULL DEFAULT 'needs_review',
            entity_kind varchar(32) NOT NULL DEFAULT 'source_gap',
            proposed_nominee_ids text,
            display_label_override text,
            correction_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (source_award_id),
            KEY review_state (review_state),
            KEY entity_kind (entity_kind),
            KEY category_slug (category_slug),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reviews);
    }

    private function maybe_create_person_portrait_existing_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reviews_table = $this->get_person_portrait_existing_reviews_table_name();
        $sql_reviews = "CREATE TABLE IF NOT EXISTS $reviews_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            candidate_person_id varchar(32) NOT NULL DEFAULT '',
            review_state varchar(32) NOT NULL DEFAULT 'needs_review',
            issue_type varchar(32) NOT NULL DEFAULT 'none',
            correction_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY attachment_person (attachment_id, candidate_person_id),
            KEY attachment_id (attachment_id),
            KEY candidate_person_id (candidate_person_id),
            KEY review_state (review_state),
            KEY issue_type (issue_type),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reviews);
    }

    private function maybe_create_omdb_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $reviews_table = $this->get_omdb_reviews_table_name();
        $sql_reviews = "CREATE TABLE IF NOT EXISTS $reviews_table (
            imdb_id varchar(16) NOT NULL,
            review_state varchar(32) NOT NULL DEFAULT 'needs_review',
            issue_type varchar(32) DEFAULT '',
            correction_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (imdb_id),
            KEY review_state (review_state),
            KEY issue_type (issue_type),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reviews);
    }

    private function maybe_create_omdb_poster_reviews_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $poster_reviews_table = $this->get_omdb_poster_reviews_table_name();
        $sql_poster_reviews = "CREATE TABLE IF NOT EXISTS $poster_reviews_table (
            imdb_id varchar(16) NOT NULL,
            poster_state varchar(32) NOT NULL DEFAULT 'needs_review',
            poster_note text,
            reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (imdb_id),
            KEY poster_state (poster_state),
            KEY reviewed_at (reviewed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_poster_reviews);
    }

    private function maybe_create_ceremony_writeups_table($charset_collate = '') {
        global $wpdb;

        if ($charset_collate === '') {
            $charset_collate = $wpdb->get_charset_collate();
            if (stripos($charset_collate, 'latin1') !== false) {
                $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        $writeups_table = $this->get_ceremony_writeups_table_name();
        $sql_writeups = AAT_Ceremony_Writeups::get_create_table_sql($writeups_table, $charset_collate);

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_writeups);
    }

    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'maybe_upgrade_schema'));
        // Entity pages (Film / Person / Company)
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('init', array($this, 'register_rewrite_rules'), 9);
        add_action('template_redirect', array($this, 'fix_virtual_page_status'));
        add_filter('template_include', array($this, 'maybe_entity_template'));
        add_filter('template_include', array($this, 'maybe_hub_template'));
        add_filter('pre_get_document_title', array($this, 'filter_entity_document_title'), 20);
        add_filter('pre_get_document_title', array($this, 'filter_hub_document_title'), 20);
        add_filter('body_class', array($this, 'filter_body_classes'));
        add_filter('wp-optimize-minify-default-exclusions', array($this, 'exclude_wp_optimize_minify_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_shortcode('academy_awards', array($this, 'render_shortcode'));

        add_shortcode('lunara_awards_tracker', array($this, 'render_tracker_shortcode'));
        add_shortcode('lunara_oscar_ballot', array($this, 'render_ballot_shortcode'));
        add_shortcode('academy_awards_ballot', array($this, 'render_ballot_shortcode'));

        // Tracker V2 (Predictions / Locks / Watchlist)
        add_shortcode('lunara_awards_tracker_v2', array($this, 'render_tracker_v2_shortcode'));
        add_shortcode('academy_awards_tracker_v2', array($this, 'render_tracker_v2_shortcode'));

        // Keep poster library in sync when reviews are saved
        add_action('save_post', array($this, 'maybe_sync_poster_from_review'), 20, 2);


        // AJAX handlers
        // (Legacy) Used by older front-end code.
        add_action('wp_ajax_aat_get_awards_data', array($this, 'ajax_get_awards_data'));
        add_action('wp_ajax_nopriv_aat_get_awards_data', array($this, 'ajax_get_awards_data'));

        // Meta for filter dropdowns + global stats (loaded once)
        add_action('wp_ajax_aat_get_awards_meta', array($this, 'ajax_get_awards_meta'));
        add_action('wp_ajax_nopriv_aat_get_awards_meta', array($this, 'ajax_get_awards_meta'));

        // Server-side DataTables endpoint
        add_action('wp_ajax_aat_get_awards_datatable', array($this, 'ajax_get_awards_datatable'));
        add_action('wp_ajax_nopriv_aat_get_awards_datatable', array($this, 'ajax_get_awards_datatable'));

        // Admin
        add_action('wp_ajax_aat_import_data', array($this, 'ajax_import_data'));
        add_action('wp_ajax_aat_import_bundled_data', array($this, 'ajax_import_bundled_data'));
        add_action('wp_ajax_aat_import_ceremony_delta', array($this, 'ajax_import_ceremony_delta'));
        add_action('wp_ajax_aat_repair_schema', array($this, 'ajax_repair_schema'));
        add_action('wp_ajax_aat_clear_data', array($this, 'ajax_clear_data'));

        // Tracker V2 admin AJAX
        add_action('wp_ajax_aat_tracker_search_entities', array($this, 'ajax_tracker_search_entities'));
        add_action('wp_ajax_aat_tracker_add_pick', array($this, 'ajax_tracker_add_pick'));
        add_action('wp_ajax_aat_tracker_delete_pick', array($this, 'ajax_tracker_delete_pick'));

        // Poster Library admin AJAX
        add_action('wp_ajax_aat_posters_save', array($this, 'ajax_posters_save'));
        add_action('wp_ajax_aat_posters_delete', array($this, 'ajax_posters_delete'));
        add_action('wp_ajax_aat_posters_sync_from_reviews', array($this, 'ajax_posters_sync_from_reviews'));
        add_action('wp_ajax_aat_posters_sync_from_apis', array($this, 'ajax_posters_sync_from_apis'));

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('aat profile-images', array($this, 'handle_profile_image_batch_cli'));
        }


        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'academy_awards';
        $charset_collate = $wpdb->get_charset_collate();
        if (stripos($charset_collate, "latin1") !== false) {
            $charset_collate = "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ceremony int(3) NOT NULL,
            year varchar(10) NOT NULL,
            class varchar(50) NOT NULL,
            canonical_category varchar(255) NOT NULL,
            category varchar(255) NOT NULL,
            film varchar(500) DEFAULT '',
            film_id varchar(255) DEFAULT '',
            name varchar(500) NOT NULL,
            nominees text,
            nominee_ids text,
            winner tinyint(1) DEFAULT 0,
            detail text,
            note text,
            citation text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ceremony (ceremony),
            KEY year (year),
            KEY class (class),
            KEY canonical_category (canonical_category(191)),
            KEY category (category(191)),
            KEY winner (winner),
            KEY film (film(191)),
            KEY name (name(191)),
            KEY ceremony_cat_winner (ceremony, canonical_category(191), winner),
            KEY category_ceremony_winner (canonical_category(191), ceremony, winner),
            KEY winner_category_ceremony (winner, canonical_category(191), ceremony),
            KEY film_id (film_id(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Tracker + Poster Library tables (v1.9.0+)
        $tracker_table = $wpdb->prefix . 'aat_tracker';
        $poster_table  = $wpdb->prefix . 'aat_posters';

        $sql_tracker = "CREATE TABLE IF NOT EXISTS $tracker_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ceremony int(3) NOT NULL,
            canonical_category varchar(255) NOT NULL,
            entity_type varchar(20) NOT NULL DEFAULT 'title',
            entity_id varchar(32) NOT NULL,
            tier varchar(20) NOT NULL DEFAULT 'watch',
            rank int(11) NOT NULL DEFAULT 1,
            note text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ceremony (ceremony),
            KEY canonical_category (canonical_category(191)),
            KEY entity_id (entity_id),
            KEY tier (tier),
            UNIQUE KEY uniq_pick (ceremony, canonical_category(191), tier, entity_type, entity_id)
        ) $charset_collate;";

        $sql_posters = "CREATE TABLE IF NOT EXISTS $poster_table (
            imdb_id varchar(16) NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(191) DEFAULT '',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (imdb_id),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

        dbDelta($sql_tracker);
        dbDelta($sql_posters);
        $this->maybe_create_reporting_tables($charset_collate);
        $this->maybe_create_person_credit_reviews_table($charset_collate);
        $this->maybe_create_person_credit_row_reviews_table($charset_collate);
        $this->maybe_create_company_credit_row_reviews_table($charset_collate);
        $this->maybe_create_person_portrait_existing_reviews_table($charset_collate);
        $this->maybe_create_omdb_reviews_table($charset_collate);
        $this->maybe_create_omdb_poster_reviews_table($charset_collate);
        $this->maybe_create_ceremony_writeups_table($charset_collate);
        $this->rebuild_reporting_tables();


        // Store version
        add_option('aat_db_version', AAT_VERSION);

        // Ensure our Film/Person routes are registered.
        $this->register_rewrite_rules();
        flush_rewrite_rules();
        update_option('aat_rewrite_version', AAT_VERSION, false);
    }

    /**
     * Run schema upgrades when updating the plugin (plugin updates do not call activate()).
     */
    public function maybe_upgrade_schema() {
        // Ensure lightweight annotation tables even if a historical db_version is ahead of the plugin version.
        $this->maybe_create_person_credit_reviews_table();
        $this->maybe_create_person_credit_row_reviews_table();
        $this->maybe_create_company_credit_row_reviews_table();
        $this->maybe_create_person_portrait_existing_reviews_table();
        $this->maybe_create_omdb_reviews_table();
        $this->maybe_create_omdb_poster_reviews_table();
        $this->maybe_create_ceremony_writeups_table();
        $this->maybe_create_reporting_tables();

        $installed = get_option('aat_db_version', '0');
        if (version_compare((string) $installed, AAT_VERSION, '>=')) {
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'academy_awards';
        $charset_collate = $wpdb->get_charset_collate();
        if (stripos($charset_collate, "latin1") !== false) {
            $charset_collate = "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ceremony int(3) NOT NULL,
            year varchar(10) NOT NULL,
            class varchar(50) NOT NULL,
            canonical_category varchar(255) NOT NULL,
            category varchar(255) NOT NULL,
            film varchar(500) DEFAULT '',
            film_id varchar(255) DEFAULT '',
            name varchar(500) NOT NULL,
            nominees text,
            nominee_ids text,
            winner tinyint(1) DEFAULT 0,
            detail text,
            note text,
            citation text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ceremony (ceremony),
            KEY year (year),
            KEY class (class),
            KEY canonical_category (canonical_category(191)),
            KEY category (category(191)),
            KEY winner (winner),
            KEY film (film(191)),
            KEY name (name(191)),
            KEY ceremony_cat_winner (ceremony, canonical_category(191), winner),
            KEY category_ceremony_winner (canonical_category(191), ceremony, winner),
            KEY winner_category_ceremony (winner, canonical_category(191), ceremony),
            KEY film_id (film_id(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Tracker + Poster Library tables
        $tracker_table = $wpdb->prefix . 'aat_tracker';
        $poster_table  = $wpdb->prefix . 'aat_posters';

        $sql_tracker = "CREATE TABLE IF NOT EXISTS $tracker_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ceremony int(3) NOT NULL,
            canonical_category varchar(255) NOT NULL,
            entity_type varchar(20) NOT NULL DEFAULT 'title',
            entity_id varchar(32) NOT NULL,
            tier varchar(20) NOT NULL DEFAULT 'watch',
            rank int(11) NOT NULL DEFAULT 1,
            note text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ceremony (ceremony),
            KEY canonical_category (canonical_category(191)),
            KEY entity_id (entity_id),
            KEY tier (tier),
            UNIQUE KEY uniq_pick (ceremony, canonical_category(191), tier, entity_type, entity_id)
        ) $charset_collate;";

        $sql_posters = "CREATE TABLE IF NOT EXISTS $poster_table (
            imdb_id varchar(16) NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(191) DEFAULT '',
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (imdb_id),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

        dbDelta($sql_tracker);
        dbDelta($sql_posters);
        $this->maybe_create_reporting_tables($charset_collate);
        $this->maybe_create_person_credit_reviews_table($charset_collate);
        $this->maybe_create_person_credit_row_reviews_table($charset_collate);
        $this->maybe_create_company_credit_row_reviews_table($charset_collate);
        $this->maybe_create_person_portrait_existing_reviews_table($charset_collate);
        $this->maybe_create_omdb_reviews_table($charset_collate);
        $this->maybe_create_omdb_poster_reviews_table($charset_collate);
        $this->maybe_create_ceremony_writeups_table($charset_collate);

        $this->rebuild_reporting_tables();

        update_option('aat_db_version', AAT_VERSION);

        // Keep rewrites healthy on upgrades
        $rewrite_version = get_option('aat_rewrite_version', '0');
        if (version_compare((string) $rewrite_version, AAT_VERSION, '<')) {
            $this->register_rewrite_rules();
            flush_rewrite_rules();
            update_option('aat_rewrite_version', AAT_VERSION, false);
        }
    }

    private function split_pipe_tokens($value) {
        $value = (string) $value;
        if ($value === '') {
            return array();
        }

        return array_values(array_filter(array_map('trim', explode('|', $value)), 'strlen'));
    }

    private function clean_visible_person_credit_label($value) {
        $value = trim((string) wp_strip_all_tags($value));
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
    }

    private function split_visible_person_credit_labels($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $parts = strpos($value, '|') !== false
            ? explode('|', $value)
            : preg_split('/\s*(?:,|\s+and\s+)\s*/i', $value);

        return array_values(array_filter(array_map(array($this, 'clean_visible_person_credit_label'), (array) $parts), 'strlen'));
    }

    private function build_profile_media_person_label_id_index() {
        $folder_data = $this->get_profile_image_existing_media_attachment_ids(array(
            'folder' => 'PEOPLE',
            'all_media' => 0,
        ));
        if (is_wp_error($folder_data)) {
            return array();
        }

        $attachment_ids = is_array($folder_data['attachment_ids'] ?? null) ? $folder_data['attachment_ids'] : array();
        $attachment_rows = $this->get_profile_image_existing_media_attachment_rows($attachment_ids);
        $index = array();
        $conflicts = array();

        foreach ((array) $attachment_rows as $attachment) {
            $attached_file = (string) ($attachment['attached_file'] ?? '');
            $post_title = trim((string) ($attachment['post_title'] ?? ''));
            $post_name = (string) ($attachment['post_name'] ?? '');
            $alt_text = (string) ($attachment['alt_text'] ?? '');

            if (stripos($post_title, 'PERSON_BACKDROP') !== false || stripos($attached_file, 'backdrop') !== false) {
                continue;
            }
            if (stripos($post_title, 'PERSON_PROFILE') === false && stripos($attached_file, 'profile') === false) {
                continue;
            }

            $person_id = $this->extract_imdb_name_id_from_profile_media_text(array($attached_file, $post_title, $post_name, $alt_text));
            if (!$this->is_imdb_name_entity_id($person_id)) {
                continue;
            }

            $label = $post_title !== '' ? $post_title : preg_replace('/\.[A-Za-z0-9]+$/', '', wp_basename($attached_file));
            $label = preg_replace('/\s+PERSON_(?:PROFILE|BACKDROP)\s*$/i', '', (string) $label);
            $label = preg_replace('/[-_\s]+profile\s*$/i', '', (string) $label);
            $label = $this->clean_visible_person_credit_label($label);
            if ($label === '' || preg_match('/[,|]|\s+and\s+/i', $label)) {
                continue;
            }
            if (preg_match('/\b(?:inc|llc|ltd|company|companies|studio|studios|team|department|crew)\b/i', $label)) {
                continue;
            }

            $key = $this->normalize_entity_name_key($label);
            if ($key === '' || isset($conflicts[$key])) {
                continue;
            }
            if (isset($index[$key]) && $index[$key]['id'] !== $person_id) {
                unset($index[$key]);
                $conflicts[$key] = true;
                continue;
            }

            $index[$key] = array(
                'id' => $person_id,
                'label' => $label,
            );
        }

        return $index;
    }

    private function extract_entity_reference_ids($raw_ids) {
        $raw_ids = (string) $raw_ids;
        if ($raw_ids === '') {
            return array();
        }

        $tokens = preg_split('/\s*[|,]\s*/', $raw_ids);
        $entity_ids = array();

        foreach ((array) $tokens as $token) {
            $token = strtolower(trim((string) $token));
            if ($token === '') {
                continue;
            }

            if ($this->is_title_entity_id($token) || $this->is_name_entity_id($token) || $this->is_company_entity_id($token)) {
                $entity_ids[] = $token;
            }
        }

        return array_values(array_unique($entity_ids));
    }

    private function extract_sort_year_from_label($year_label) {
        $year_label = trim((string) $year_label);
        if ($year_label === '') {
            return 0;
        }

        if (preg_match('/(\d{4})/', $year_label, $matches)) {
            return intval($matches[1]);
        }

        return 0;
    }

    /**
     * Public gateway for the Entity Graph integrity tooling: re-derive every
     * reporting table from the master wp_academy_awards table (the layer
     * Dalton maintains directly and trusts as ground truth).
     */
    public function lunara_rebuild_reporting_tables() {
        $this->rebuild_reporting_tables();
    }

    private function rebuild_reporting_tables() {
        global $wpdb;

        $source_table = $this->get_table_name();
        $ceremonies_table = $this->get_ceremonies_table_name();
        $categories_table = $this->get_categories_table_name();
        $entities_table = $this->get_entities_table_name();
        $facts_table = $this->get_award_facts_table_name();
        $nominees_table = $this->get_award_nominees_table_name();
        $ceremony_stats_table = $this->get_ceremony_stats_table_name();
        $category_stats_table = $this->get_category_stats_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();

        $reporting_schema_ready = $this->maybe_create_reporting_tables();
        if (!$reporting_schema_ready) {
            return array(
                'ceremonies' => 0,
                'categories' => 0,
                'entities' => 0,
                'facts' => 0,
                'nominees' => 0,
                'ceremony_stats' => 0,
                'category_stats' => 0,
                'entity_stats' => 0,
                'insert_failures' => 0,
                'schema_migration_failed' => true,
                'preserved_existing_tables' => true,
            );
        }

        $rows = $wpdb->get_results(
            "SELECT id, " . $this->get_awards_row_fields_sql() . " FROM $source_table ORDER BY id ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array(
                'ceremonies' => 0,
                'categories' => 0,
                'entities' => 0,
                'facts' => 0,
                'nominees' => 0,
                'ceremony_stats' => 0,
                'category_stats' => 0,
                'entity_stats' => 0,
                'insert_failures' => 0,
                'source_read_failed' => true,
                'preserved_existing_tables' => true,
            );
        }

        $wpdb->query("TRUNCATE TABLE $ceremonies_table");
        $wpdb->query("TRUNCATE TABLE $categories_table");
        $wpdb->query("TRUNCATE TABLE $entities_table");
        $wpdb->query("TRUNCATE TABLE $facts_table");
        $wpdb->query("TRUNCATE TABLE $nominees_table");
        $wpdb->query("TRUNCATE TABLE $ceremony_stats_table");
        $wpdb->query("TRUNCATE TABLE $category_stats_table");
        $wpdb->query("TRUNCATE TABLE $entity_stats_table");

        $ceremonies = array();
        $categories = array();
        $entities = array();
        $facts = array();
        $nominees = array();
        $ceremony_stats = array();
        $category_stats = array();
        $entity_stats = array();
        $profile_media_person_index = null;

        $register_entity = function($entity_id, $entity_type, $label) use (&$entities) {
            $entity_id = strtolower(trim((string) $entity_id));
            $entity_type = trim((string) $entity_type);
            $label = trim((string) $label);

            if ($entity_id === '') {
                return;
            }

            if (!isset($entities[$entity_id])) {
                $entities[$entity_id] = array(
                    'entity_id' => $entity_id,
                    'entity_type' => $entity_type,
                    'label' => $label,
                    'sort_label' => $this->normalize_entity_name_key($label),
                );
                return;
            }

            if ($entities[$entity_id]['label'] === '' && $label !== '') {
                $entities[$entity_id]['label'] = $label;
                $entities[$entity_id]['sort_label'] = $this->normalize_entity_name_key($label);
            }
        };

        $touch_entity_stats = function($entity_id, $entity_type, $label, $ceremony, $winner) use (&$entity_stats) {
            $entity_id = strtolower(trim((string) $entity_id));
            if ($entity_id === '') {
                return;
            }

            if (!isset($entity_stats[$entity_id])) {
                $entity_stats[$entity_id] = array(
                    'entity_id' => $entity_id,
                    'entity_type' => trim((string) $entity_type),
                    'label' => trim((string) $label),
                    'nominations' => 0,
                    'wins' => 0,
                    'ceremonies_map' => array(),
                    'first_ceremony' => 0,
                    'last_ceremony' => 0,
                );
            }

            if ($entity_stats[$entity_id]['label'] === '' && trim((string) $label) !== '') {
                $entity_stats[$entity_id]['label'] = trim((string) $label);
            }

            $entity_stats[$entity_id]['nominations']++;
            $entity_stats[$entity_id]['wins'] += !empty($winner) ? 1 : 0;

            if ($ceremony > 0) {
                $entity_stats[$entity_id]['ceremonies_map'][$ceremony] = true;
                if (empty($entity_stats[$entity_id]['first_ceremony']) || $ceremony < $entity_stats[$entity_id]['first_ceremony']) {
                    $entity_stats[$entity_id]['first_ceremony'] = $ceremony;
                }
                if (empty($entity_stats[$entity_id]['last_ceremony']) || $ceremony > $entity_stats[$entity_id]['last_ceremony']) {
                    $entity_stats[$entity_id]['last_ceremony'] = $ceremony;
                }
            }
        };

        $recover_nominee_ids_from_profile_media = function($labels) use (&$profile_media_person_index) {
            $labels = array_values(array_filter(array_map('trim', (array) $labels), 'strlen'));
            if (empty($labels)) {
                return array(
                    'ids' => array(),
                    'labels' => array(),
                );
            }
            if ($profile_media_person_index === null) {
                $profile_media_person_index = $this->build_profile_media_person_label_id_index();
            }
            if (empty($profile_media_person_index)) {
                return array(
                    'ids' => array(),
                    'labels' => array(),
                );
            }

            $ids = array();
            $resolved_labels = array();
            foreach ($labels as $label) {
                $key = $this->normalize_entity_name_key($label);
                if ($key === '' || empty($profile_media_person_index[$key]['id'])) {
                    continue;
                }
                $ids[] = (string) $profile_media_person_index[$key]['id'];
                $resolved_labels[] = !empty($profile_media_person_index[$key]['label']) ? (string) $profile_media_person_index[$key]['label'] : (string) $label;
            }

            return array(
                'ids' => $ids,
                'labels' => $resolved_labels,
            );
        };

        foreach ($rows as $row) {
            $row = $this->normalize_awards_row($row);
            $source_award_id = intval($row['id'] ?? 0);
            $ceremony = intval($row['ceremony'] ?? 0);
            $year_label = trim((string) ($row['year'] ?? ''));
            $category_name = trim((string) ($row['canonical_category'] ?? $row['category'] ?? ''));
            $category_slug = $category_name !== '' ? sanitize_title($category_name) : '';
            $film_label = trim((string) ($row['film'] ?? ''));
            $film_ids = $this->extract_entity_reference_ids($row['film_id'] ?? '');
            $film_entity_id = '';

            foreach ($film_ids as $candidate_id) {
                if ($this->is_title_entity_id($candidate_id)) {
                    $film_entity_id = $candidate_id;
                    break;
                }
            }

            if ($ceremony > 0 && !isset($ceremonies[$ceremony])) {
                $ceremonies[$ceremony] = array(
                    'ceremony' => $ceremony,
                    'year_label' => $year_label,
                    'ceremony_label' => $this->ordinal($ceremony),
                    'sort_year' => $this->extract_sort_year_from_label($year_label),
                );
            }

            if ($category_slug !== '' && !isset($categories[$category_slug])) {
                $categories[$category_slug] = array(
                    'category_slug' => $category_slug,
                    'canonical_category' => $category_name,
                    'display_category' => $this->format_category_display($category_name),
                    'award_class' => trim((string) ($row['class'] ?? '')),
                );
            }

            if ($category_slug !== '') {
                if (!isset($category_stats[$category_slug])) {
                    $category_stats[$category_slug] = array(
                        'category_slug' => $category_slug,
                        'canonical_category' => $category_name,
                        'nominations' => 0,
                        'wins' => 0,
                        'ceremonies_map' => array(),
                        'first_ceremony' => 0,
                        'last_ceremony' => 0,
                    );
                }

                $category_stats[$category_slug]['nominations']++;
                $category_stats[$category_slug]['wins'] += !empty($row['winner']) ? 1 : 0;
                if ($ceremony > 0) {
                    $category_stats[$category_slug]['ceremonies_map'][$ceremony] = true;
                    if (empty($category_stats[$category_slug]['first_ceremony']) || $ceremony < $category_stats[$category_slug]['first_ceremony']) {
                        $category_stats[$category_slug]['first_ceremony'] = $ceremony;
                    }
                    if (empty($category_stats[$category_slug]['last_ceremony']) || $ceremony > $category_stats[$category_slug]['last_ceremony']) {
                        $category_stats[$category_slug]['last_ceremony'] = $ceremony;
                    }
                }
            }

            if ($ceremony > 0) {
                if (!isset($ceremony_stats[$ceremony])) {
                    $ceremony_stats[$ceremony] = array(
                        'ceremony' => $ceremony,
                        'year_label' => $year_label,
                        'nominations' => 0,
                        'wins' => 0,
                        'categories_map' => array(),
                        'winner_categories_map' => array(),
                        'title_mentions' => array(),
                        'title_labels' => array(),
                        'title_wins' => array(),
                    );
                }

                $ceremony_stats[$ceremony]['nominations']++;
                $ceremony_stats[$ceremony]['wins'] += !empty($row['winner']) ? 1 : 0;
                if ($category_slug !== '') {
                    $ceremony_stats[$ceremony]['categories_map'][$category_slug] = true;
                    if (!empty($row['winner'])) {
                        $ceremony_stats[$ceremony]['winner_categories_map'][$category_slug] = true;
                    }
                }
            }

            if ($film_entity_id !== '') {
                $register_entity($film_entity_id, 'title', $film_label);
                $touch_entity_stats($film_entity_id, 'title', $film_label, $ceremony, !empty($row['winner']));

                if ($ceremony > 0) {
                    if (!isset($ceremony_stats[$ceremony]['title_mentions'][$film_entity_id])) {
                        $ceremony_stats[$ceremony]['title_mentions'][$film_entity_id] = 0;
                        $ceremony_stats[$ceremony]['title_wins'][$film_entity_id] = 0;
                    }
                    $ceremony_stats[$ceremony]['title_mentions'][$film_entity_id]++;
                    if (!empty($row['winner'])) {
                        $ceremony_stats[$ceremony]['title_wins'][$film_entity_id]++;
                    }
                    if ($film_label !== '' && empty($ceremony_stats[$ceremony]['title_labels'][$film_entity_id])) {
                        $ceremony_stats[$ceremony]['title_labels'][$film_entity_id] = $film_label;
                    }
                }
            }

            $nominee_ids = $this->extract_entity_reference_ids($row['nominee_ids'] ?? '');
            $nominee_labels = $this->split_pipe_tokens($row['nominees'] ?? '');
            $fallback_name = trim((string) ($row['name'] ?? ''));
            if (empty($nominee_labels) && $fallback_name !== '') {
                $nominee_labels[] = $fallback_name;
            }
            $visible_nominee_labels = $this->split_visible_person_credit_labels(!empty($row['nominees']) ? $row['nominees'] : $fallback_name);
            if (empty($nominee_labels) && !empty($visible_nominee_labels)) {
                $nominee_labels = $visible_nominee_labels;
            }
            if (empty($nominee_ids) && !empty($visible_nominee_labels)) {
                $recovered_nominees = $recover_nominee_ids_from_profile_media($visible_nominee_labels);
                if (!empty($recovered_nominees['ids'])) {
                    $nominee_ids = $recovered_nominees['ids'];
                    $nominee_labels = $recovered_nominees['labels'];
                }
            }

            foreach ($nominee_ids as $index => $entity_id) {
                $label = isset($nominee_labels[$index]) ? trim((string) $nominee_labels[$index]) : '';
                if ($label === '' && count($nominee_ids) === 1 && $fallback_name !== '') {
                    $label = $fallback_name;
                }
                if ($label === '' && $entity_id === $film_entity_id && $film_label !== '') {
                    $label = $film_label;
                }

                $entity_type = $this->infer_entity_type_from_id($entity_id);
                $register_entity($entity_id, $entity_type, $label);
                $touch_entity_stats($entity_id, $entity_type, $label, $ceremony, !empty($row['winner']));

                if ($source_award_id > 0) {
                    $nominees[] = array(
                        'source_award_id' => $source_award_id,
                        'ceremony' => $ceremony,
                        'category_slug' => $category_slug,
                        'entity_id' => $entity_id,
                        'entity_type' => $entity_type,
                        'entity_label' => $label,
                        'nominee_ordinal' => $index + 1,
                        'is_primary' => $index === 0 ? 1 : 0,
                        'winner' => !empty($row['winner']) ? 1 : 0,
                    );
                }
            }

            if ($source_award_id > 0) {
                $facts[] = array(
                    'source_award_id' => $source_award_id,
                    'ceremony' => $ceremony,
                    'year_label' => $year_label,
                    'category_slug' => $category_slug,
                    'winner' => !empty($row['winner']) ? 1 : 0,
                    'film_entity_id' => $film_entity_id,
                    'primary_entity_id' => !empty($nominee_ids) ? (string) $nominee_ids[0] : '',
                    'primary_label' => $fallback_name,
                    'nominee_count' => max(count($nominee_ids), count($nominee_labels)),
                    'has_detail' => !empty($row['detail']) ? 1 : 0,
                    'has_note' => !empty($row['note']) ? 1 : 0,
                    'has_citation' => !empty($row['citation']) ? 1 : 0,
                );
            }
        }

        // Guarded inserts: clamp every string to its column width, retry once
        // with invalid-UTF8 stripped, and record anything that STILL fails so
        // the Data Integrity audit can name the exact row and SQL error.
        // Nothing is ever allowed to go silently missing from the derivation.
        $insert_failures = array();
        $insert_failures_total = 0;
        $safe_insert = function($table, $data, $clamps = array()) use ($wpdb, &$insert_failures, &$insert_failures_total) {
            foreach ($clamps as $field => $max_chars) {
                if (!isset($data[$field]) || !is_string($data[$field])) {
                    continue;
                }

                $length = function_exists('mb_strlen') ? mb_strlen($data[$field]) : strlen($data[$field]);
                if ($length > $max_chars) {
                    $data[$field] = function_exists('mb_substr')
                        ? mb_substr($data[$field], 0, $max_chars)
                        : substr($data[$field], 0, $max_chars);
                }
            }

            if ($wpdb->insert($table, $data) !== false) {
                return true;
            }

            $retry = $data;
            foreach ($retry as $field => $value) {
                if (is_string($value)) {
                    $retry[$field] = wp_check_invalid_utf8($value, true);
                }
            }
            if ($wpdb->insert($table, $retry) !== false) {
                return true;
            }

            $insert_failures_total++;
            if (count($insert_failures) < 50) {
                $insert_failures[] = array(
                    'table' => (string) $table,
                    'error' => (string) $wpdb->last_error,
                    'row' => array(
                        'source_award_id' => isset($data['source_award_id']) ? intval($data['source_award_id']) : 0,
                        'ceremony' => isset($data['ceremony']) ? intval($data['ceremony']) : 0,
                        'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : '',
                        'film_entity_id' => isset($data['film_entity_id']) ? (string) $data['film_entity_id'] : '',
                        'primary_entity_id' => isset($data['primary_entity_id']) ? (string) $data['primary_entity_id'] : '',
                        'category_slug' => isset($data['category_slug']) ? (string) $data['category_slug'] : '',
                    ),
                );
            }
            return false;
        };

        foreach ($ceremonies as $ceremony_row) {
            $safe_insert($ceremonies_table, $ceremony_row, array('year_label' => 20, 'ceremony_label' => 32));
        }

        foreach ($categories as $category_row) {
            $safe_insert($categories_table, $category_row, array('category_slug' => 191, 'canonical_category' => 255, 'display_category' => 255, 'award_class' => 50));
        }

        foreach ($entities as $entity_row) {
            $safe_insert($entities_table, $entity_row, array('entity_id' => 64, 'entity_type' => 20, 'label' => 500, 'sort_label' => 500));
        }

        foreach ($facts as $fact_row) {
            $safe_insert($facts_table, $fact_row, array('year_label' => 20, 'category_slug' => 191, 'film_entity_id' => 64, 'primary_entity_id' => 64, 'primary_label' => 500));
        }

        foreach ($nominees as $nominee_row) {
            $safe_insert($nominees_table, $nominee_row, array('category_slug' => 191, 'entity_id' => 64, 'entity_type' => 20, 'entity_label' => 500));
        }

        foreach ($category_stats as $category_slug => $stat_row) {
            $safe_insert($category_stats_table, array(
                'category_slug' => $category_slug,
                'canonical_category' => $stat_row['canonical_category'],
                'nominations' => intval($stat_row['nominations']),
                'wins' => intval($stat_row['wins']),
                'ceremonies' => count($stat_row['ceremonies_map']),
                'first_ceremony' => intval($stat_row['first_ceremony']),
                'last_ceremony' => intval($stat_row['last_ceremony']),
            ), array('category_slug' => 191, 'canonical_category' => 255));
        }

        foreach ($entity_stats as $entity_id => $stat_row) {
            $safe_insert($entity_stats_table, array(
                'entity_id' => $entity_id,
                'entity_type' => $stat_row['entity_type'],
                'label' => $stat_row['label'],
                'nominations' => intval($stat_row['nominations']),
                'wins' => intval($stat_row['wins']),
                'ceremonies' => count($stat_row['ceremonies_map']),
                'first_ceremony' => intval($stat_row['first_ceremony']),
                'last_ceremony' => intval($stat_row['last_ceremony']),
            ), array('entity_id' => 64, 'entity_type' => 20, 'label' => 500));
        }

        foreach ($ceremony_stats as $ceremony_key => $stat_row) {
            $top_title_entity_id = '';
            $top_title_label = '';
            $top_title_mentions = 0;
            $top_title_wins = 0;
            $winning_titles_count = 0;

            foreach ($stat_row['title_mentions'] as $title_entity_id => $mentions) {
                $wins = isset($stat_row['title_wins'][$title_entity_id]) ? intval($stat_row['title_wins'][$title_entity_id]) : 0;
                if ($wins > 0) {
                    $winning_titles_count++;
                }

                if (
                    $mentions > $top_title_mentions ||
                    ($mentions === $top_title_mentions && $wins > $top_title_wins) ||
                    ($mentions === $top_title_mentions && $wins === $top_title_wins && strcmp($title_entity_id, $top_title_entity_id) < 0)
                ) {
                    $top_title_entity_id = $title_entity_id;
                    $top_title_label = isset($stat_row['title_labels'][$title_entity_id]) ? (string) $stat_row['title_labels'][$title_entity_id] : '';
                    $top_title_mentions = intval($mentions);
                    $top_title_wins = $wins;
                }
            }

            $safe_insert($ceremony_stats_table, array(
                'ceremony' => intval($ceremony_key),
                'year_label' => $stat_row['year_label'],
                'nominations' => intval($stat_row['nominations']),
                'wins' => intval($stat_row['wins']),
                'categories_total' => count($stat_row['categories_map']),
                'winner_categories' => count($stat_row['winner_categories_map']),
                'winning_titles_count' => $winning_titles_count,
                'top_title_entity_id' => $top_title_entity_id,
                'top_title_label' => $top_title_label,
                'top_title_mentions' => $top_title_mentions,
                'top_title_wins' => $top_title_wins,
            ), array('year_label' => 20, 'top_title_entity_id' => 64, 'top_title_label' => 500));
        }

        // Persist the failure log for the Data Integrity audit; a clean run
        // clears it so stale errors never linger.
        if ($insert_failures_total > 0) {
            update_option('aat_reporting_insert_failures', array(
                'total' => $insert_failures_total,
                'failures' => $insert_failures,
                'generated_at' => current_time('mysql'),
            ), false);
        } else {
            delete_option('aat_reporting_insert_failures');
        }

        return array(
            'ceremonies' => count($ceremonies),
            'categories' => count($categories),
            'entities' => count($entities),
            'facts' => count($facts),
            'nominees' => count($nominees),
            'ceremony_stats' => count($ceremony_stats),
            'category_stats' => count($category_stats),
            'entity_stats' => count($entity_stats),
            'insert_failures' => $insert_failures_total,
        );
    }

    private function get_projection_total_counts() {
        global $wpdb;
        $this->ensure_projection_data_available();

        $facts_table = $this->get_award_facts_table_name();
        $categories_table = $this->get_categories_table_name();
        $ceremonies_table = $this->get_ceremonies_table_name();

        $records = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table"));
        $winners = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table WHERE winner = 1"));
        $categories = intval($wpdb->get_var("SELECT COUNT(*) FROM $categories_table"));
        $ceremonies = intval($wpdb->get_var("SELECT COUNT(*) FROM $ceremonies_table"));

        if ($records === 0) {
            $source_table = $this->get_table_name();
            $records = intval($wpdb->get_var("SELECT COUNT(*) FROM $source_table"));
            $winners = intval($wpdb->get_var("SELECT COUNT(*) FROM $source_table WHERE winner = 1"));
            $categories = intval($wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $source_table WHERE canonical_category != ''"));
            $ceremonies = intval($wpdb->get_var("SELECT COUNT(DISTINCT ceremony) FROM $source_table"));
        }

        return array(
            'records' => $records,
            'winners' => $winners,
            'categories' => $categories,
            'ceremonies' => $ceremonies,
        );
    }

    private function get_projection_categories_list() {
        global $wpdb;

        $this->ensure_projection_data_available();

        $categories_table = $this->get_categories_table_name();
        $rows = $wpdb->get_col("SELECT canonical_category FROM $categories_table ORDER BY canonical_category ASC");
        if (empty($rows)) {
            $source_table = $this->get_table_name();
            $rows = $wpdb->get_col("SELECT DISTINCT canonical_category FROM $source_table WHERE canonical_category != '' ORDER BY canonical_category ASC");
        }
        return is_array($rows) ? $rows : array();
    }

    private function get_projection_classes_list() {
        global $wpdb;

        $this->ensure_projection_data_available();

        $categories_table = $this->get_categories_table_name();
        $rows = $wpdb->get_col("SELECT DISTINCT award_class FROM $categories_table WHERE award_class != '' ORDER BY award_class ASC");
        if (empty($rows)) {
            $source_table = $this->get_table_name();
            $rows = $wpdb->get_col("SELECT DISTINCT class FROM $source_table WHERE class != '' ORDER BY class ASC");
        }
        return is_array($rows) ? $rows : array();
    }

    private function get_projection_years_list() {
        global $wpdb;

        $this->ensure_projection_data_available();

        $ceremonies_table = $this->get_ceremonies_table_name();
        $rows = $wpdb->get_col("SELECT year_label FROM $ceremonies_table ORDER BY ceremony DESC");
        if (empty($rows)) {
            $source_table = $this->get_table_name();
            $rows = $wpdb->get_col("SELECT DISTINCT year FROM $source_table ORDER BY ceremony DESC");
        }
        return is_array($rows) ? $rows : array();
    }

    private function get_projection_ceremonies_list() {
        global $wpdb;

        $this->ensure_projection_data_available();

        $ceremonies_table = $this->get_ceremonies_table_name();
        $rows = $wpdb->get_col("SELECT ceremony FROM $ceremonies_table ORDER BY ceremony DESC");
        if (empty($rows)) {
            $source_table = $this->get_table_name();
            $rows = $wpdb->get_col("SELECT DISTINCT ceremony FROM $source_table ORDER BY ceremony DESC");
        }
        return is_array($rows) ? array_map('intval', $rows) : array();
    }

    private function get_projected_entity_label($entity_id) {
        global $wpdb;

        $this->ensure_projection_data_available();

        $entity_id = strtolower(trim((string) $entity_id));
        if ($entity_id === '') {
            return '';
        }

        $entities_table = $this->get_entities_table_name();

        return (string) $wpdb->get_var(
            $wpdb->prepare("SELECT label FROM $entities_table WHERE entity_id = %s LIMIT 1", $entity_id)
        );
    }

    private function ensure_projection_data_available() {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        global $wpdb;
        $source_table = $this->get_table_name();
        $facts_table = $this->get_award_facts_table_name();

        $source_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $source_table"));
        $facts_count = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table"));

        if ($source_count > 0 && $facts_count === 0) {
            $this->rebuild_reporting_tables();
            delete_transient('aat_total_stats_v2');
            delete_transient('aat_awards_meta_v1');
            delete_transient('aat_max_ceremony_v1');
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('academy-awards-table', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // One-time rewrite refresh when the plugin updates (keeps Film/Person pages working without manual permalinks flush).
        $rewrite_version = (string) get_option('aat_rewrite_version', '');
        if ($rewrite_version !== AAT_VERSION) {
            // Refresh cached hub page detection when the plugin updates.
            delete_transient('aat_hub_page_ids_' . $this->get_entity_base_slug() . '_v1');

            $this->register_rewrite_rules();
            flush_rewrite_rules();
            update_option('aat_rewrite_version', AAT_VERSION, false);
        }
    }

    /**
     * Register query vars for entity pages.
     */
    public function register_query_vars($vars) {
        $vars[] = 'aat_entity';
        $vars[] = 'aat_entity_id';
        $vars[] = 'aat_hub';
        $vars[] = 'aat_hub_id';
        return $vars;
    }

    /**
     * Entity base slug, filterable.
     */
    public function get_entity_base_slug() {
        $slug = apply_filters('aat_entity_base_slug', 'oscars');
        $slug = sanitize_title($slug);
        return $slug ? $slug : 'oscars';
    }

    /**
     * Build the base URL for entity pages.
     */
    public function get_entity_base_url() {
        return trailingslashit(home_url('/' . $this->get_entity_base_slug() . '/'));
    }

    /**
     * Determine whether an id is a supported title id.
     */
    private function is_title_entity_id($id) {
        $id = strtolower(trim((string) $id));
        return (bool) preg_match('/^tt\d+$/', $id);
    }

    /**
     * Determine whether an id is a supported IMDb person id.
     */
    private function is_imdb_name_entity_id($id) {
        $id = strtolower(trim((string) $id));
        return (bool) preg_match('/^nm\d+$/', $id);
    }

    /**
     * Determine whether an id is a Lunara-local fallback person id.
     */
    private function is_local_name_entity_id($id) {
        $id = strtolower(trim((string) $id));
        return (bool) preg_match('/^lnm-[a-z0-9]+(?:-[a-z0-9]+)*$/', $id);
    }

    /**
     * Determine whether an id is a supported person id.
     */
    private function is_name_entity_id($id) {
        return $this->is_imdb_name_entity_id($id) || $this->is_local_name_entity_id($id);
    }

    /**
     * Determine whether an id is a supported company id.
     */
    private function is_company_entity_id($id) {
        $id = strtolower(trim((string) $id));
        return (bool) preg_match('/^co\d+$/', $id);
    }

    /**
     * Infer the route bucket for a supported entity id.
     */
    private function infer_entity_type_from_id($id) {
        $id = strtolower(trim((string) $id));
        if ($this->is_title_entity_id($id)) {
            return 'title';
        }
        if ($this->is_name_entity_id($id)) {
            return 'name';
        }
        if ($this->is_company_entity_id($id)) {
            return 'company';
        }
        return '';
    }

    /**
     * URL to the main database page that contains the [academy_awards] shortcode.
     *
     * Themes can override this via:
     *   add_filter('aat_database_url', fn() => home_url('/oscars-database/'));
     */
    public function get_database_url() {
        $url = apply_filters('aat_database_url', '');
        $url = is_string($url) ? trim($url) : '';
        if (!empty($url)) {
            return esc_url_raw($url);
        }

        // Canonical Lunara installs use the block/theme-owned Oscars portal as the
        // database home. Older shortcode helper pages can still exist, but they
        // should not become the primary route for Ledger navigation.
        $detect_shortcode_page = (bool) apply_filters('aat_detect_shortcode_database_url', false);
        if (!$detect_shortcode_page) {
            return esc_url_raw($this->get_entity_base_url());
        }

        // Optional legacy fallback: auto-detect a published page that contains
        // the [academy_awards] shortcode when a site explicitly opts into it.
        $cache_key = 'aat_database_url_autodetect_v1';
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return esc_url_raw($cached);
        }

        global $wpdb;
        if ($wpdb instanceof wpdb) {
            $like = '%' . $wpdb->esc_like('[academy_awards') . '%';
            $page_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE %s ORDER BY menu_order ASC, ID ASC LIMIT 1",
                    $like
                )
            );

            if (empty($page_id)) {
                // Fallback: some sites might place the shortcode in a post.
                $page_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_content LIKE %s ORDER BY post_type DESC, ID ASC LIMIT 1",
                        $like
                    )
                );
            }

            $page_id = intval($page_id);
            if ($page_id > 0) {
                $permalink = get_permalink($page_id);
                if (is_string($permalink) && $permalink !== '') {
                    set_transient($cache_key, $permalink, 12 * HOUR_IN_SECONDS);
                    return esc_url_raw($permalink);
                }
            }
        }

        // Final fallback: the entity base URL (typically /oscars/).
        return esc_url_raw($this->get_entity_base_url());
    }


    /**
     * Detect optional, user-created WordPress pages for hub indexes under the base slug page.
     *
     * Why this exists:
     * - The plugin ships canonical hub routes like /oscars/categories/ and /oscars/ceremonies/.
     * - But many sites prefer custom slugs (e.g. /oscars/categories-page/) for menu or editorial reasons.
     *
     * If we can detect those pages, we:
     * 1) Add rewrite aliases so those URLs render the hub pages
     * 2) Use those permalinks in footer links and hub navigation
     * 3) Pull the page editor content as intro copy, so the site can control tone/voice
     *
     * This is cached and will refresh when the plugin version updates (rewrite flush).
     */
    public function get_detected_hub_page_ids() {
        $base_slug = $this->get_entity_base_slug();
        $cache_key = 'aat_hub_page_ids_' . $base_slug . '_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $ids = array(
            'ceremonies' => 0,
            'categories' => 0,
            'about'      => 0,
        );

        // Prefer the actual /{base}/ page as the parent, if it exists.
        $parent = get_page_by_path($base_slug);
        $parent_id = ($parent instanceof WP_Post) ? intval($parent->ID) : 0;

        $args = array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );

        if ($parent_id > 0) {
            $args['post_parent'] = $parent_id;
        }

        $pages = get_posts($args);
        if (is_array($pages)) {
            foreach ($pages as $p) {
                if (!($p instanceof WP_Post)) continue;

                $title = (string) $p->post_title;
                $slug  = (string) $p->post_name;

                $t = strtolower($title);
                $s = strtolower($slug);

                if ($ids['ceremonies'] === 0 && (strpos($t, 'ceremon') !== false || strpos($s, 'ceremon') !== false)) {
                    $ids['ceremonies'] = intval($p->ID);
                    continue;
                }

                if ($ids['categories'] === 0 && (strpos($t, 'categor') !== false || strpos($s, 'categor') !== false)) {
                    $ids['categories'] = intval($p->ID);
                    continue;
                }

                if ($ids['about'] === 0 && (strpos($t, 'about') !== false || strpos($s, 'about') !== false)) {
                    $ids['about'] = intval($p->ID);
                    continue;
                }
            }
        }

        set_transient($cache_key, $ids, 12 * HOUR_IN_SECONDS);
        return $ids;
    }

    public function get_hub_page_post($hub) {
        $hub = sanitize_text_field((string) $hub);
        $ids = $this->get_detected_hub_page_ids();
        $id = isset($ids[$hub]) ? intval($ids[$hub]) : 0;
        if ($id > 0) {
            $p = get_post($id);
            if ($p instanceof WP_Post) {
                return $p;
            }
        }
        return null;
    }

    public function get_hub_page_slug($hub) {
        $p = $this->get_hub_page_post($hub);
        if ($p instanceof WP_Post) {
            return sanitize_title((string) $p->post_name);
        }
        return '';
    }

    public function get_hub_page_url($hub) {
        $p = $this->get_hub_page_post($hub);
        if ($p instanceof WP_Post) {
            $url = get_permalink($p);
            if (is_string($url) && $url !== '') {
                return esc_url_raw($url);
            }
        }
        return '';
    }

    /**
     * Hub URLs
     */
    public function get_ceremonies_index_url() {
        $prefer_editor_page = (bool) apply_filters('aat_prefer_editor_hub_page_urls', false, 'ceremonies');
        if ($prefer_editor_page) {
            $url = $this->get_hub_page_url('ceremonies');
            if (!empty($url)) return $url;
        }
        return esc_url_raw($this->get_entity_base_url() . 'ceremonies/');
    }

    public function get_categories_index_url() {
        $prefer_editor_page = (bool) apply_filters('aat_prefer_editor_hub_page_urls', false, 'categories');
        if ($prefer_editor_page) {
            $url = $this->get_hub_page_url('categories');
            if (!empty($url)) return $url;
        }
        return esc_url_raw($this->get_entity_base_url() . 'categories/');
    }

    public function get_about_url() {
        $prefer_editor_page = (bool) apply_filters('aat_prefer_editor_hub_page_urls', false, 'about');
        if ($prefer_editor_page) {
            $url = $this->get_hub_page_url('about');
            if (!empty($url)) return $url;
        }
        return esc_url_raw($this->get_entity_base_url() . 'about/');
    }

    public function get_ceremony_url($ceremony) {
        $n = intval($ceremony);
        if ($n <= 0) return '';
        return esc_url_raw($this->get_entity_base_url() . 'ceremony/' . $n . '/');
    }

    public function get_category_url($canonical_category) {
        $cat = (string) $canonical_category;
        if ($cat === '') return '';
        return esc_url_raw($this->get_entity_base_url() . 'category/' . sanitize_title($cat) . '/');
    }

    /**
     * Register rewrite rules for entity pages.
     */
    public function register_rewrite_rules() {
        $base = $this->get_entity_base_slug();
        // Entity pages
        add_rewrite_rule('^' . preg_quote($base, '/') . '/title/(tt\d+)/?$', 'index.php?aat_entity=title&aat_entity_id=$matches[1]', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/name/((?:nm\d+)|(?:lnm-[a-z0-9-]+))/?$', 'index.php?aat_entity=name&aat_entity_id=$matches[1]', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/company/(co\d+)/?$', 'index.php?aat_entity=company&aat_entity_id=$matches[1]', 'top');

        // Hub pages
        add_rewrite_rule('^' . preg_quote($base, '/') . '/ceremony/(\d{1,3})/?$', 'index.php?aat_hub=ceremony&aat_hub_id=$matches[1]', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/category/([^/]+)/?$', 'index.php?aat_hub=category&aat_hub_id=$matches[1]', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/ceremonies/?$', 'index.php?aat_hub=ceremonies', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/categories/?$', 'index.php?aat_hub=categories', 'top');
        add_rewrite_rule('^' . preg_quote($base, '/') . '/about/?$', 'index.php?aat_hub=about', 'top');

        // Optional hub page aliases (lets the site use custom slugs like /oscars/categories-page/).
        $hub_ids = $this->get_detected_hub_page_ids();

        if (!empty($hub_ids['ceremonies'])) {
            $slug = $this->get_hub_page_slug('ceremonies');
            if (!empty($slug) && $slug !== 'ceremonies') {
                add_rewrite_rule('^' . preg_quote($base, '/') . '/' . preg_quote($slug, '/') . '/?$', 'index.php?aat_hub=ceremonies', 'top');
            }
        }

        if (!empty($hub_ids['categories'])) {
            $slug = $this->get_hub_page_slug('categories');
            if (!empty($slug) && $slug !== 'categories') {
                add_rewrite_rule('^' . preg_quote($base, '/') . '/' . preg_quote($slug, '/') . '/?$', 'index.php?aat_hub=categories', 'top');
            }
        }

        if (!empty($hub_ids['about'])) {
            $slug = $this->get_hub_page_slug('about');
            if (!empty($slug) && $slug !== 'about') {
                add_rewrite_rule('^' . preg_quote($base, '/') . '/' . preg_quote($slug, '/') . '/?$', 'index.php?aat_hub=about', 'top');
            }
        }
    }

    /**
     * True when the current request is one of our entity pages.
     */
    public function is_entity_request() {
        $entity = get_query_var('aat_entity');
        $id = get_query_var('aat_entity_id');
        return (!empty($entity) && !empty($id));
    }

    /**
     * True when the current request is one of our hub pages
     * (Ceremony / Category / Ceremonies index / Categories index / About).
     */
    public function is_hub_request() {
        $hub = get_query_var('aat_hub');
        return !empty($hub);
    }

    /**
     * Add route-level body classes so theme/layout overrides can be targeted safely.
     */
    public function filter_body_classes($classes) {
        $classes = is_array($classes) ? $classes : array();

        if ($this->is_entity_request() || $this->is_hub_request()) {
            $classes[] = 'aat-shell-page';
        }

        if ($this->is_entity_request()) {
            $classes[] = 'aat-shell-entity';
        }

        if ($this->is_hub_request()) {
            $classes[] = 'aat-shell-hub';
        }

        return array_values(array_unique($classes));
    }

    /**
     * Keep the Oscars table shell out of WP-Optimize minification.
     *
     * The Data Explorer must fail visibly when DataTables is blocked; stale
     * minified bundles can leave it in a permanent loading state.
     */
    public function exclude_wp_optimize_minify_assets($exclusions) {
        $exclusions = is_array($exclusions) ? $exclusions : array();
        $exclusions[] = '/wp-content/plugins/academy-awards-table-optimized/assets/js/academy-awards-table.js';
        $exclusions[] = '/wp-content/plugins/academy-awards-table-optimized/assets/css/academy-awards-table.css';
        $exclusions[] = '/wp-content/themes/blocksy/static/bundle/';

        return array_values(array_unique($exclusions));
    }

    /**
     * Intercept and render Film/Person/Company pages.
     */

    /**
     * Collect a ceremony-aware set of title nominees/winners for poster grids.
     */
    public function get_ceremony_title_highlights($ceremony, $limit = 18) {
        global $wpdb;
        $facts_table = $this->get_award_facts_table_name();
        $categories_table = $this->get_categories_table_name();
        $entities_table = $this->get_entities_table_name();
        $ceremony = intval($ceremony);
        $limit = max(0, intval($limit));
        if ($ceremony <= 0) {
            return array();
        }

        $cache_key = 'aat_ceremony_title_highlights_v2_' . $ceremony . '_' . $limit;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.category_slug, c.canonical_category, e.entity_id AS film_id, e.label AS film, f.winner
             FROM $facts_table f
             INNER JOIN $categories_table c ON c.category_slug = f.category_slug
             INNER JOIN $entities_table e ON e.entity_id = f.film_entity_id
             WHERE f.ceremony = %d AND f.film_entity_id <> ''
             ORDER BY f.winner DESC, c.canonical_category ASC, e.label ASC",
            $ceremony
        ), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $best_picture = array();
        $other_winners = array();
        $other_nominees = array();
        $seen = array();

        foreach ($rows as $r) {
            $fid = strtolower(trim((string) ($r['film_id'] ?? '')));
            if (!preg_match('/^tt\d+$/', $fid) || isset($seen[$fid])) {
                continue;
            }
            $entry = array(
                'film_id' => $fid,
                'film' => (string) ($r['film'] ?? ''),
                'winner' => !empty($r['winner']) ? 1 : 0,
                'canonical_category' => (string) ($r['canonical_category'] ?? $r['category'] ?? ''),
            );
            $cat = strtoupper($entry['canonical_category']);
            if ($cat === 'BEST PICTURE') {
                $best_picture[] = $entry;
            } elseif ($entry['winner']) {
                $other_winners[] = $entry;
            } else {
                $other_nominees[] = $entry;
            }
            $seen[$fid] = true;
        }

        $ordered = array_merge($best_picture, $other_winners, $other_nominees);
        if ($limit > 0) {
            $ordered = array_slice($ordered, 0, $limit);
        }
        set_transient($cache_key, $ordered, 6 * HOUR_IN_SECONDS);
        return $ordered;
    }

    /**
     * Collect title highlights for a category page.
     */
    public function get_category_title_highlights($canonical_category, $limit = 18) {
        global $wpdb;
        $facts_table = $this->get_award_facts_table_name();
        $categories_table = $this->get_categories_table_name();
        $entities_table = $this->get_entities_table_name();
        $canonical_category = trim((string) $canonical_category);
        $limit = max(0, intval($limit));
        if ($canonical_category === '') {
            return array();
        }

        $cache_key = 'aat_category_title_highlights_v2_' . md5($canonical_category . '|' . $limit);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.ceremony, f.year_label AS year, e.label AS film, e.entity_id AS film_id, f.winner
             FROM $facts_table f
             INNER JOIN $categories_table c ON c.category_slug = f.category_slug
             INNER JOIN $entities_table e ON e.entity_id = f.film_entity_id
             WHERE c.canonical_category = %s AND f.film_entity_id <> ''
             ORDER BY f.ceremony DESC, f.winner DESC, e.label ASC",
            $canonical_category
        ), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $out = array();
        $seen = array();
        foreach ($rows as $r) {
            $fid = strtolower(trim((string) ($r['film_id'] ?? '')));
            if (!preg_match('/^tt\d+$/', $fid) || isset($seen[$fid])) {
                continue;
            }
            $out[] = array(
                'film_id' => $fid,
                'film' => (string) ($r['film'] ?? ''),
                'winner' => !empty($r['winner']) ? 1 : 0,
                'ceremony' => intval($r['ceremony'] ?? 0),
                'year' => (string) ($r['year'] ?? ''),
            );
            $seen[$fid] = true;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        set_transient($cache_key, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Return cached top-level hub stats from projection tables first.
     */
    public function get_hub_page_stats() {
        global $wpdb;

        $empty = array(
            'total_records'    => 0,
            'total_winners'    => 0,
            'total_categories' => 0,
            'total_ceremonies' => 0,
            'min_ceremony'     => 0,
            'max_ceremony'     => 0,
        );

        $cache_key = 'aat_hub_page_stats_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return array_merge($empty, $cached);
        }

        $this->ensure_projection_data_available();

        $facts_table = $this->get_award_facts_table_name();
        $category_stats_table = $this->get_category_stats_table_name();
        $ceremony_stats_table = $this->get_ceremony_stats_table_name();
        $categories_table = $this->get_categories_table_name();
        $ceremonies_table = $this->get_ceremonies_table_name();
        $source_table = $this->get_table_name();

        $stats = $empty;
        $stats['total_records'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table"));
        if ($stats['total_records'] > 0) {
            $stats['total_winners'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table WHERE winner = 1"));
        }

        $stats['total_categories'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $category_stats_table"));
        if ($stats['total_categories'] <= 0) {
            $stats['total_categories'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $categories_table"));
        }

        $stats['total_ceremonies'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $ceremony_stats_table"));
        if ($stats['total_ceremonies'] <= 0) {
            $stats['total_ceremonies'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $ceremonies_table"));
        }

        $bounds = $wpdb->get_row("SELECT MIN(ceremony) AS min_ceremony, MAX(ceremony) AS max_ceremony FROM $ceremonies_table", ARRAY_A);
        if (!is_array($bounds) || intval($bounds['max_ceremony'] ?? 0) <= 0) {
            $bounds = $wpdb->get_row("SELECT MIN(ceremony) AS min_ceremony, MAX(ceremony) AS max_ceremony FROM $ceremony_stats_table", ARRAY_A);
        }
        if (is_array($bounds)) {
            $stats['min_ceremony'] = intval($bounds['min_ceremony'] ?? 0);
            $stats['max_ceremony'] = intval($bounds['max_ceremony'] ?? 0);
        }

        if ($stats['total_records'] <= 0) {
            $stats['total_records'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $source_table"));
            $stats['total_winners'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $source_table WHERE winner = 1"));
        }

        if ($stats['total_categories'] <= 0) {
            $stats['total_categories'] = intval($wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $source_table WHERE canonical_category != ''"));
        }

        if ($stats['total_ceremonies'] <= 0) {
            $stats['total_ceremonies'] = intval($wpdb->get_var("SELECT COUNT(DISTINCT ceremony) FROM $source_table"));
        }

        if ($stats['min_ceremony'] <= 0 || $stats['max_ceremony'] <= 0) {
            $legacy_bounds = $wpdb->get_row("SELECT MIN(ceremony) AS min_ceremony, MAX(ceremony) AS max_ceremony FROM $source_table", ARRAY_A);
            if (is_array($legacy_bounds)) {
                $stats['min_ceremony'] = intval($legacy_bounds['min_ceremony'] ?? 0);
                $stats['max_ceremony'] = intval($legacy_bounds['max_ceremony'] ?? 0);
            }
        }

        set_transient($cache_key, $stats, 15 * MINUTE_IN_SECONDS);
        return $stats;
    }

    /**
     * Return ceremony page summary data from projection tables first.
     */
    public function get_ceremony_summary($ceremony) {
        global $wpdb;

        $ceremony = intval($ceremony);
        $empty = array(
            'year_label'       => '',
            'nominations'      => 0,
            'wins'             => 0,
            'categories_count' => 0,
            'winner_categories' => 0,
            'categories'       => array(),
            'newer_ceremony'   => 0,
            'older_ceremony'   => 0,
        );

        if ($ceremony <= 0) {
            return $empty;
        }

        $cache_key = 'aat_ceremony_summary_v1_' . $ceremony;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return array_merge($empty, $cached);
        }

        $this->ensure_projection_data_available();

        $ceremony_stats_table = $this->get_ceremony_stats_table_name();
        $facts_table = $this->get_award_facts_table_name();
        $categories_table = $this->get_categories_table_name();
        $ceremonies_table = $this->get_ceremonies_table_name();
        $source_table = $this->get_table_name();

        $summary = $empty;
        $summary['year_label'] = $this->get_ceremony_year($ceremony);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT year_label, nominations, wins, categories_total, winner_categories FROM $ceremony_stats_table WHERE ceremony = %d LIMIT 1",
                $ceremony
            ),
            ARRAY_A
        );

        if (is_array($row) && !empty($row)) {
            if ($summary['year_label'] === '') {
                $summary['year_label'] = (string) ($row['year_label'] ?? '');
            }
            $summary['nominations'] = intval($row['nominations'] ?? 0);
            $summary['wins'] = intval($row['wins'] ?? 0);
            $summary['categories_count'] = intval($row['categories_total'] ?? 0);
            $summary['winner_categories'] = intval($row['winner_categories'] ?? 0);
        }

        if ($summary['nominations'] <= 0) {
            $legacy_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS nominations, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS wins, COUNT(DISTINCT canonical_category) AS categories_count FROM $source_table WHERE ceremony = %d AND canonical_category != ''",
                    $ceremony
                ),
                ARRAY_A
            );
            if (is_array($legacy_row)) {
                $summary['nominations'] = intval($legacy_row['nominations'] ?? 0);
                $summary['wins'] = intval($legacy_row['wins'] ?? 0);
                $summary['categories_count'] = intval($legacy_row['categories_count'] ?? 0);
            }
        }

        $categories = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT c.canonical_category FROM $facts_table f INNER JOIN $categories_table c ON c.category_slug = f.category_slug WHERE f.ceremony = %d AND c.canonical_category != '' GROUP BY c.canonical_category ORDER BY c.canonical_category ASC",
                $ceremony
            )
        );

        if (empty($categories)) {
            $categories = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT canonical_category FROM $source_table WHERE ceremony = %d AND canonical_category != '' ORDER BY canonical_category ASC",
                    $ceremony
                )
            );
        }

        $summary['categories'] = is_array($categories) ? $categories : array();
        if ($summary['categories_count'] <= 0 && !empty($summary['categories'])) {
            $summary['categories_count'] = count($summary['categories']);
        }

        $summary['newer_ceremony'] = intval(
            $wpdb->get_var(
                $wpdb->prepare("SELECT MIN(ceremony) FROM $ceremonies_table WHERE ceremony > %d", $ceremony)
            )
        );
        if ($summary['newer_ceremony'] <= 0) {
            $summary['newer_ceremony'] = intval(
                $wpdb->get_var(
                    $wpdb->prepare("SELECT MIN(ceremony) FROM $source_table WHERE ceremony > %d", $ceremony)
                )
            );
        }

        $summary['older_ceremony'] = intval(
            $wpdb->get_var(
                $wpdb->prepare("SELECT MAX(ceremony) FROM $ceremonies_table WHERE ceremony < %d", $ceremony)
            )
        );
        if ($summary['older_ceremony'] <= 0) {
            $summary['older_ceremony'] = intval(
                $wpdb->get_var(
                    $wpdb->prepare("SELECT MAX(ceremony) FROM $source_table WHERE ceremony < %d", $ceremony)
                )
            );
        }

        set_transient($cache_key, $summary, 15 * MINUTE_IN_SECONDS);
        return $summary;
    }

    /**
     * Return cached summary stats for a category hub.
     */
    public function get_category_summary($canonical_category) {
        global $wpdb;

        $canonical_category = trim((string) $canonical_category);
        $empty = array(
            'nominations'     => 0,
            'wins'            => 0,
            'ceremonies'      => 0,
            'first_ceremony'  => 0,
            'last_ceremony'   => 0,
        );

        if ($canonical_category === '') {
            return $empty;
        }

        $cache_key = 'aat_category_summary_v1_' . md5($canonical_category);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['nominations'], $cached['wins'], $cached['ceremonies'])) {
            return array_merge($empty, $cached);
        }

        $this->ensure_projection_data_available();

        $category_stats_table = $this->get_category_stats_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT nominations, wins, ceremonies, first_ceremony, last_ceremony FROM $category_stats_table WHERE canonical_category = %s LIMIT 1",
                $canonical_category
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row)) {
            $source_table = $this->get_table_name();
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS nominations, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS wins, COUNT(DISTINCT ceremony) AS ceremonies, MIN(ceremony) AS first_ceremony, MAX(ceremony) AS last_ceremony FROM $source_table WHERE canonical_category = %s",
                    $canonical_category
                ),
                ARRAY_A
            );
        }

        if (!is_array($row)) {
            set_transient($cache_key, $empty, 30 * MINUTE_IN_SECONDS);
            return $empty;
        }

        $summary = array(
            'nominations'     => intval($row['nominations'] ?? 0),
            'wins'            => intval($row['wins'] ?? 0),
            'ceremonies'      => intval($row['ceremonies'] ?? 0),
            'first_ceremony'  => intval($row['first_ceremony'] ?? 0),
            'last_ceremony'   => intval($row['last_ceremony'] ?? 0),
        );

        set_transient($cache_key, $summary, 6 * HOUR_IN_SECONDS);
        return $summary;
    }

    /**
     * Backward-compatible helper for templates that need a simple entity URL.
     */
    public function get_entity_url($id) {
        return $this->build_entity_url_from_id($id);
    }

    /**
     * Build a ceremony-level dynamic rollup for winner-driven presentation modules.
     */
    public function get_ceremony_rollup($ceremony) {
        global $wpdb;

        $ceremony = intval($ceremony);
        if ($ceremony <= 0) {
            return array();
        }

        $cache_key = 'aat_ceremony_rollup_v2_' . $ceremony;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = array();
        $this->ensure_projection_data_available();

        $facts_table = $this->get_award_facts_table_name();
        $fact_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_award_id FROM $facts_table WHERE ceremony = %d AND category_slug != '' ORDER BY category_slug ASC, winner DESC, source_award_id ASC",
                $ceremony
            ),
            ARRAY_A
        );

        if (is_array($fact_rows) && !empty($fact_rows)) {
            $source_ids = array();
            foreach ($fact_rows as $fact_row) {
                $source_id = intval($fact_row['source_award_id'] ?? 0);
                if ($source_id > 0) {
                    $source_ids[] = $source_id;
                }
            }

            if (!empty($source_ids)) {
                $rows = $this->get_normalized_source_rows_by_ids(
                    $source_ids,
                    'ORDER BY ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC'
                );
            }
        }

        if (empty($rows)) {
            $table_name = $this->get_table_name();
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ceremony, year, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note FROM $table_name WHERE ceremony = %d AND canonical_category != '' ORDER BY canonical_category ASC, winner DESC, film ASC, name ASC",
                    $ceremony
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $seen_fingerprints = array();
        $deduped_rows = array();
        foreach ($rows as $row) {
            $fingerprint = $this->get_awards_row_fingerprint($row);
            if (isset($seen_fingerprints[$fingerprint])) {
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;
            $deduped_rows[] = $row;
        }
        $rows = $deduped_rows;

        $title_stats = array();
        $winner_rows = array();
        $categories = array();
        $best_picture = array();
        $best_picture_nominees = array();

        foreach ($rows as $row) {
            $row = $this->apply_row_hotfixes($row);
            $category = trim((string) ($row['canonical_category'] ?? $row['category'] ?? ''));
            if ($category === '') {
                continue;
            }

            $categories[$category] = true;
            $is_winner = !empty($row['winner']) ? 1 : 0;
            $film_ids = $this->extract_title_ids($row['film_id'] ?? '');
            $film_id = !empty($film_ids) ? (string) $film_ids[0] : '';
            $film_label = trim((string) ($row['film'] ?? ''));
            if ($film_label === '' && $film_id !== '') {
                $film_label = $this->lookup_title_label($film_id);
            }

            if ($category === 'BEST PICTURE') {
                $best_picture_key = $film_id !== '' ? $film_id : strtolower($film_label);
                if ($best_picture_key !== '') {
                    $best_picture_nominees[$best_picture_key] = array(
                        'film' => $film_label !== '' ? $film_label : strtoupper($best_picture_key),
                        'film_id' => $film_id,
                        'film_url' => $film_id !== '' ? $this->build_entity_url_from_id($film_id) : '',
                        'winner' => $is_winner,
                        'year' => trim((string) ($row['year'] ?? '')),
                    );
                }
            }

            if ($film_id !== '') {
                if (!isset($title_stats[$film_id])) {
                    $title_stats[$film_id] = array(
                        'film_id' => $film_id,
                        'film' => $film_label !== '' ? $film_label : strtoupper($film_id),
                        'film_url' => $this->build_entity_url_from_id($film_id),
                        'nominations' => 0,
                        'wins' => 0,
                        'winning_categories' => array(),
                    );
                }

                $title_stats[$film_id]['nominations']++;

                if ($is_winner) {
                    $title_stats[$film_id]['wins']++;
                    $title_stats[$film_id]['winning_categories'][$category] = $this->format_category_display($category);
                }
            }

            if ($is_winner) {
                $winner_entry = array(
                    'canonical_category' => $category,
                    'category_label' => $this->format_category_display($category),
                    'film' => $film_label,
                    'film_id' => $film_id,
                    'film_url' => $film_id !== '' ? $this->build_entity_url_from_id($film_id) : '',
                    'name' => trim((string) ($row['name'] ?? '')),
                    'nominees' => trim((string) ($row['nominees'] ?? '')),
                    'nominee_ids' => trim((string) ($row['nominee_ids'] ?? '')),
                    'detail' => trim((string) ($row['detail'] ?? '')),
                    'note' => trim((string) ($row['note'] ?? '')),
                    'year' => trim((string) ($row['year'] ?? '')),
                );

                $winner_rows[] = $winner_entry;

                if ($category === 'BEST PICTURE') {
                    $best_picture = $winner_entry;
                }
            }
        }

        if (!empty($winner_rows)) {
            $preferred_order = array_flip($this->get_ballot_category_order());
            usort($winner_rows, function($a, $b) use ($preferred_order) {
                $cat_a = (string) ($a['canonical_category'] ?? '');
                $cat_b = (string) ($b['canonical_category'] ?? '');
                $rank_a = isset($preferred_order[$cat_a]) ? $preferred_order[$cat_a] : 999;
                $rank_b = isset($preferred_order[$cat_b]) ? $preferred_order[$cat_b] : 999;

                if ($rank_a === $rank_b) {
                    return strcasecmp($cat_a, $cat_b);
                }

                return $rank_a <=> $rank_b;
            });
        }

        $titles = array_values($title_stats);
        $winning_titles = array_values(array_filter($titles, function($entry) {
            return !empty($entry['wins']);
        }));

        $sort_titles = function(&$entries, $primary, $secondary) {
            usort($entries, function($a, $b) use ($primary, $secondary) {
                $cmp = intval($b[$primary] ?? 0) <=> intval($a[$primary] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $cmp = intval($b[$secondary] ?? 0) <=> intval($a[$secondary] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['film'] ?? ''), (string) ($b['film'] ?? ''));
            });
        };

        $top_nominated = $titles;
        $sort_titles($top_nominated, 'nominations', 'wins');
        $top_winning = $winning_titles;
        $sort_titles($top_winning, 'wins', 'nominations');
        $best_picture_nominee_rows = array_values($best_picture_nominees);

        if (!empty($best_picture_nominee_rows)) {
            usort($best_picture_nominee_rows, function($a, $b) {
                $cmp = intval($b['winner'] ?? 0) <=> intval($a['winner'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['film'] ?? ''), (string) ($b['film'] ?? ''));
            });
        }

        $rollup = array(
            'ceremony' => $ceremony,
            'year' => !empty($rows[0]['year']) ? (string) $rows[0]['year'] : '',
            'categories_total' => count($categories),
            'winner_categories' => count($winner_rows),
            'has_full_winners' => count($categories) > 0 && count($winner_rows) === count($categories),
            'best_picture' => $best_picture,
            'best_picture_nominees' => $best_picture_nominee_rows,
            'winner_rows' => $winner_rows,
            'most_nominated' => !empty($top_nominated) ? $top_nominated[0] : array(),
            'most_wins' => !empty($top_winning) ? $top_winning[0] : array(),
            'winning_titles_count' => count($winning_titles),
            'top_titles' => array_slice($top_winning, 0, 5),
        );
        set_transient($cache_key, $rollup, HOUR_IN_SECONDS);
        return $rollup;
    }

    /**
     * Return a ceremony's category-grouped ballot for public ceremony pages.
     */
    public function get_ceremony_ballot_ledger($ceremony) {
        $ceremony = intval($ceremony);
        if ($ceremony <= 0) {
            return array(
                'categories' => array(),
                'review_map' => array(),
            );
        }

        $ballot = $this->get_ballot_category_groups($ceremony);
        if (empty($ballot['categories']) || !is_array($ballot['categories'])) {
            return array(
                'categories' => array(),
                'review_map' => array(),
            );
        }

        $categories = array();
        foreach ($ballot['categories'] as $group) {
            $category = trim((string) ($group['category'] ?? ''));
            if ($category === '') {
                continue;
            }

            $rows = !empty($group['rows']) && is_array($group['rows']) ? $group['rows'] : array();
            $winner_count = 0;
            foreach ($rows as $row) {
                if (!empty($row['winner'])) {
                    $winner_count++;
                }
            }

            $categories[] = array(
                'category' => $category,
                'label' => $this->format_category_display($category),
                'url' => $this->get_category_url($category),
                'rows' => $rows,
                'row_count' => count($rows),
                'winner_count' => $winner_count,
            );
        }

        return array(
            'categories' => $categories,
            'review_map' => !empty($ballot['review_map']) && is_array($ballot['review_map']) ? $ballot['review_map'] : array(),
        );
    }

    /**
     * Return a category's complete history grouped by decade for category pages.
     *
     * Each decade bucket contains a list of ceremonies (newest first). Each
     * ceremony entry contains its winner rows + its other-nominee rows for
     * this category. Mirrors get_ceremony_ballot_ledger() shape but inverted
     * (here we filter by category, group by ceremony, bucket by decade).
     *
     * Added 2026-05-24 for Round 2 Step 2 — Category Pages.
     *
     * @param string $canonical_category Canonical category name.
     * @return array {
     *     decades:       associative array of decade_key => decade bucket
     *     decade_order:  ordered array of decade_keys (newest first)
     *     decade_counts: decade_key => count of ceremonies in that decade
     *     totals:        ['ceremonies' => N, 'winners' => N, 'nominees' => N]
     *     review_map:    title_id => permalink (for any cross-linkable reviews)
     * }
     */
    public function get_category_decade_ledger($canonical_category) {
        global $wpdb;

        $canonical_category = trim((string) $canonical_category);
        $empty = array(
            'decades'       => array(),
            'decade_order'  => array(),
            'decade_counts' => array(),
            'totals'        => array('ceremonies' => 0, 'winners' => 0, 'nominees' => 0),
            'review_map'    => array(),
        );

        if ($canonical_category === '') {
            return $empty;
        }

        $cache_key = 'aat_category_decade_ledger_v2_' . md5($canonical_category);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['decades'], $cached['decade_order'], $cached['totals'])) {
            return $cached;
        }

        $rows = array();
        $this->ensure_projection_data_available();

        $facts_table = $this->get_award_facts_table_name();
        $category_slug = sanitize_title($canonical_category);
        if ($category_slug !== '') {
            $fact_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT source_award_id FROM $facts_table WHERE category_slug = %s ORDER BY ceremony DESC, winner DESC, source_award_id ASC",
                    $category_slug
                ),
                ARRAY_A
            );

            if (is_array($fact_rows) && !empty($fact_rows)) {
                $source_ids = array();
                foreach ($fact_rows as $fact_row) {
                    $source_id = intval($fact_row['source_award_id'] ?? 0);
                    if ($source_id > 0) {
                        $source_ids[] = $source_id;
                    }
                }

                if (!empty($source_ids)) {
                    $rows = $this->get_normalized_source_rows_by_ids(
                        $source_ids,
                        'ORDER BY ceremony DESC, winner DESC, film ASC, name ASC'
                    );
                }
            }
        }

        if (empty($rows)) {
            $table_name = $this->get_table_name();
            $fields = $this->get_awards_row_fields_sql();
            $sql = "SELECT DISTINCT $fields FROM $table_name WHERE canonical_category = %s ORDER BY ceremony DESC, winner DESC, film ASC, name ASC";
            $sql = $wpdb->prepare($sql, $canonical_category);
            $rows = $wpdb->get_results($sql, ARRAY_A);
        }

        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, $empty, 30 * MINUTE_IN_SECONDS);
            return $empty;
        }

        $seen_fingerprints = array();
        $deduped_rows = array();
        foreach ($rows as $row) {
            $fingerprint = $this->get_awards_row_fingerprint($row);
            if (isset($seen_fingerprints[$fingerprint])) {
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;
            $deduped_rows[] = $row;
        }
        $rows = $deduped_rows;

        $review_title_ids = array();
        $by_ceremony = array(); // ceremony_int => normalized bucket

        foreach ($rows as $row) {
            $row = $this->normalize_awards_row($row);
            $ceremony = intval($row['ceremony'] ?? 0);
            if ($ceremony <= 0) {
                continue;
            }

            if (!isset($by_ceremony[$ceremony])) {
                $by_ceremony[$ceremony] = array(
                    'ceremony'      => $ceremony,
                    'year'          => trim((string) ($row['year'] ?? '')),
                    'winner_rows'   => array(),
                    'nominee_rows'  => array(),
                );
            }

            if (!empty($row['winner'])) {
                $by_ceremony[$ceremony]['winner_rows'][] = $row;
            } else {
                $by_ceremony[$ceremony]['nominee_rows'][] = $row;
            }

            foreach ($this->extract_title_ids($row['film_id'] ?? '') as $tt_id) {
                $review_title_ids[$tt_id] = true;
            }
        }

        if (empty($by_ceremony)) {
            set_transient($cache_key, $empty, 30 * MINUTE_IN_SECONDS);
            return $empty;
        }

        // Bucket ceremonies by decade (uses the CEREMONY year, not the film year).
        // Ceremony year falls back to the film year on the row if ceremony lookup fails.
        $decades = array();
        foreach ($by_ceremony as $ceremony_int => $ceremony_data) {
            $ceremony_year = method_exists($this, 'get_ceremony_year')
                ? $this->get_ceremony_year($ceremony_int)
                : '';
            if (!$ceremony_year) {
                $ceremony_year = $ceremony_data['year']; // fallback
            }
            $year_int = intval($ceremony_year);

            if ($year_int <= 0) {
                $decade_key   = 'unknown';
                $decade_label = __('Undated', 'academy-awards-table');
                $decade_start = 0;
            } else {
                $decade_start = intval(floor($year_int / 10) * 10);
                $decade_key   = $decade_start . 's';
                $decade_label = $decade_start . 's';
            }

            if (!isset($decades[$decade_key])) {
                $decades[$decade_key] = array(
                    'key'         => $decade_key,
                    'label'       => $decade_label,
                    'start'       => $decade_start,
                    'ceremonies'  => array(),
                );
            }
            $decades[$decade_key]['ceremonies'][] = $ceremony_data;
        }

        // Decade order: newest first (2020s → 1920s → unknown bucket last).
        uasort($decades, function($a, $b) {
            $a_start = isset($a['start']) ? intval($a['start']) : 0;
            $b_start = isset($b['start']) ? intval($b['start']) : 0;
            return $b_start <=> $a_start;
        });

        $decade_order  = array_keys($decades);
        $decade_counts = array();
        $total_ceremonies = 0;
        $total_winners    = 0;
        $total_nominees   = 0;
        foreach ($decades as $key => $bucket) {
            $count = count($bucket['ceremonies']);
            $decade_counts[$key] = $count;
            $total_ceremonies   += $count;
            foreach ($bucket['ceremonies'] as $c) {
                $total_winners  += count($c['winner_rows']);
                $total_nominees += count($c['nominee_rows']);
            }
        }

        $review_map = !empty($review_title_ids)
            ? $this->get_review_permalink_map_for_title_ids(array_keys($review_title_ids))
            : array();

        $ledger = array(
            'decades'       => $decades,
            'decade_order'  => $decade_order,
            'decade_counts' => $decade_counts,
            'totals'        => array(
                'ceremonies' => $total_ceremonies,
                'winners'    => $total_winners,
                'nominees'   => $total_nominees,
            ),
            'review_map'    => $review_map,
        );
        set_transient($cache_key, $ledger, HOUR_IN_SECONDS);
        return $ledger;
    }

    /**
     * Get the most recent winner for a category.
     */
    public function get_category_latest_winner($canonical_category) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $canonical_category = trim((string) $canonical_category);
        if ($canonical_category === '') {
            return array();
        }

        $cache_key = 'aat_category_latest_winner_v2_' . md5($canonical_category);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ceremony, year, canonical_category, category, film, film_id, name, nominees, nominee_ids, detail, note FROM $table_name WHERE canonical_category = %s AND winner = 1 ORDER BY ceremony DESC, film ASC LIMIT 1",
                $canonical_category
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $film_ids = $this->extract_title_ids($row['film_id'] ?? '');
        $film_id = !empty($film_ids) ? (string) $film_ids[0] : '';

        $winner = array(
            'ceremony' => intval($row['ceremony'] ?? 0),
            'year' => trim((string) ($row['year'] ?? '')),
            'canonical_category' => trim((string) ($row['canonical_category'] ?? $row['category'] ?? '')),
            'film' => trim((string) ($row['film'] ?? '')),
            'film_id' => $film_id,
            'film_url' => $film_id !== '' ? $this->build_entity_url_from_id($film_id) : '',
            'name' => trim((string) ($row['name'] ?? '')),
            'nominees' => trim((string) ($row['nominees'] ?? '')),
            'nominee_ids' => trim((string) ($row['nominee_ids'] ?? '')),
            'detail' => trim((string) ($row['detail'] ?? '')),
            'note' => trim((string) ($row['note'] ?? '')),
        );
        set_transient($cache_key, $winner, HOUR_IN_SECONDS);
        return $winner;
    }

    /**
     * Fix 404 status for plugin-owned virtual pages.
     * WordPress marks unknown URLs as 404 before template_include fires.
     * This resets the status so entity and hub pages return 200.
     */
    public function fix_virtual_page_status() {
        if ($this->is_entity_request() || $this->is_hub_request()) {
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            status_header(200);
            nocache_headers();
        }
    }

    public function maybe_entity_template($template) {
        if (!$this->is_entity_request()) {
            return $template;
        }

        $entity = sanitize_text_field(get_query_var('aat_entity'));
        $id = strtolower(trim((string) sanitize_text_field(get_query_var('aat_entity_id'))));

        if ($entity === 'name' && $this->is_imdb_name_entity_id($id)) {
            $canonical_id = $this->resolve_canonical_name_entity_id($id);
            if ($canonical_id !== '' && $canonical_id !== $id) {
                set_query_var('aat_entity_id', $canonical_id);

                $canonical_url = $this->build_entity_url_from_id($canonical_id);
                if ($canonical_url !== '' && !headers_sent()) {
                    wp_safe_redirect($canonical_url, 301);
                    exit;
                }
            }
        }

        $entity_template = AAT_PLUGIN_DIR . 'templates/entity-page.php';
        if (file_exists($entity_template)) {
            return $entity_template;
        }

        return $template;
    }

    private function resolve_canonical_name_entity_id($id) {
        global $wpdb;

        $id = strtolower(trim((string) $id));
        if (!$this->is_imdb_name_entity_id($id)) {
            return $id;
        }

        $cache_key = 'aat_name_id_alias_v1_' . $id;
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // If this ID already resolves to ledger rows, it is already canonical.
        $rows = $this->get_entity_rows('name', $id);
        if (!empty($rows)) {
            set_transient($cache_key, $id, 12 * HOUR_IN_SECONDS);
            return $id;
        }

        $label = trim((string) $this->get_projected_entity_label($id));
        if ($label === '') {
            set_transient($cache_key, $id, 30 * MINUTE_IN_SECONDS);
            return $id;
        }

        $entities_table = $this->get_entities_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();

        $candidates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT e.entity_id
                 FROM $entities_table e
                 LEFT JOIN $entity_stats_table s ON s.entity_id = e.entity_id
                 WHERE e.entity_type = %s
                   AND e.label = %s
                   AND e.entity_id <> %s
                   AND e.entity_id REGEXP %s
                 ORDER BY COALESCE(s.nominations, 0) DESC, e.entity_id ASC
                 LIMIT 25",
                'name',
                $label,
                $id,
                '^nm[0-9]{7,9}$'
            )
        );

        if (is_array($candidates) && !empty($candidates)) {
            foreach ($candidates as $candidate_id) {
                $candidate_id = strtolower(trim((string) $candidate_id));
                if (!$this->is_imdb_name_entity_id($candidate_id)) {
                    continue;
                }

                $candidate_rows = $this->get_entity_rows('name', $candidate_id);
                if (!empty($candidate_rows)) {
                    set_transient($cache_key, $candidate_id, 12 * HOUR_IN_SECONDS);
                    return $candidate_id;
                }
            }
        }

        set_transient($cache_key, $id, 30 * MINUTE_IN_SECONDS);
        return $id;
    }

    /**
     * Intercept and render hub pages.
     */
    public function maybe_hub_template($template) {
        if (!$this->is_hub_request() || $this->is_entity_request()) {
            return $template;
        }

        $hub_template = AAT_PLUGIN_DIR . 'templates/hub-page.php';
        if (file_exists($hub_template)) {
            return $hub_template;
        }

        return $template;
    }

    /**
     * Load theme-owned Oscars styling when present.
     */
    public function enqueue_theme_route_assets($deps = array()) {
        $deps = is_array($deps) ? $deps : array();
        $styles = array(
            'assets/css/oscars.css',
            'oscars/oscars.css',
        );

        foreach ($styles as $relative_path) {
            $file = trailingslashit(get_stylesheet_directory()) . ltrim($relative_path, '/');
            if (file_exists($file)) {
                wp_enqueue_style(
                    'lunara-oscars-theme',
                    trailingslashit(get_stylesheet_directory_uri()) . ltrim($relative_path, '/'),
                    $deps,
                    (string) @filemtime($file)
                );
                break;
            }
        }
    }

    /**
     * Shared field list for Oscar data row queries.
     */
    private function get_awards_row_fields_sql() {
        return 'ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation';
    }

    /**
     * Fetch normalized source awards rows by source id list.
     */
    private function get_normalized_source_rows_by_ids($source_ids, $order_clause = '') {
        global $wpdb;

        $normalized_ids = array();
        foreach ((array) $source_ids as $source_id) {
            $source_id = intval($source_id);
            if ($source_id > 0) {
                $normalized_ids[$source_id] = true;
            }
        }

        if (empty($normalized_ids)) {
            return array();
        }

        $ids = array_keys($normalized_ids);
        $table_name = $this->get_table_name();
        $fields = $this->get_awards_row_fields_sql();
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, $fields FROM $table_name WHERE id IN ($placeholders)";
        if ($order_clause !== '') {
            $sql .= ' ' . $order_clause;
        }
        $sql = $wpdb->prepare($sql, $ids);

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        foreach ($rows as $index => $row) {
            $rows[$index] = $this->normalize_awards_row($row);
        }

        return $rows;
    }

    /**
     * Generate a stable fingerprint for a normalized awards row.
     */
    private function get_awards_row_fingerprint($row) {
        $fields = array('ceremony', 'year', 'class', 'canonical_category', 'category', 'film', 'film_id', 'name', 'nominees', 'nominee_ids', 'winner', 'detail', 'note', 'citation');
        $parts = array();

        foreach ($fields as $field) {
            $parts[] = isset($row[$field]) ? trim((string) $row[$field]) : '';
        }

        return md5(implode("\x1f", $parts));
    }

    /**
     * Convert pipe-delimited values into a display-ready human string.
     */
    private function humanize_pipe_list($value_list) {
        $values = array_values(array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen'));
        $count = count($values);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return (string) $values[0];
        }

        if ($count === 2) {
            return $values[0] . ' and ' . $values[1];
        }

        $last = array_pop($values);
        return implode(', ', $values) . ' and ' . $last;
    }

    /**
     * Detect rows where the nominated entity is the film title itself.
     */
    private function is_title_primary_nominee_row($row) {
        $class = strtoupper(trim((string) ($row['class'] ?? '')));
        $category = strtoupper(trim((string) ($row['canonical_category'] ?? $row['category'] ?? '')));

        if ($class === 'TITLE') {
            return true;
        }

        $prefixes = array(
            'INTERNATIONAL FEATURE FILM',
            'DOCUMENTARY',
            'SHORT FILM',
            'SHORT SUBJECT',
            'SPECIAL FOREIGN LANGUAGE FILM AWARD',
        );

        foreach ($prefixes as $prefix) {
            if (strpos($category, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect screenplay categories that should resolve to writer person pages.
     */
    private function is_screenplay_category($category) {
        $category = strtoupper(trim((string) $category));

        return in_array($category, array(
            'WRITING (ORIGINAL SCREENPLAY)',
            'WRITING (ADAPTED SCREENPLAY)',
        ), true);
    }

    /**
     * Remove screenplay-credit prefixes from imported nominee/name strings.
     */
    private function strip_screenplay_credit_prefixes($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $segments = preg_split('/\s*;\s*/', $value);
        if (!is_array($segments) || empty($segments)) {
            $segments = array($value);
        }

        $cleaned_segments = array();
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            $segment = preg_replace(
                '/^(screenplay\s*[-:]\s*|screenplay by|written by|written for the screen by|written for the Screen by|screen story and screenplay by|story and screenplay by|story by|script collaborators?\s*[-:]\s*)\s*/i',
                '',
                $segment
            );
            $segment = trim((string) $segment, " \t\n\r\0\x0B,;");

            if ($segment !== '') {
                $cleaned_segments[] = $segment;
            }
        }

        return implode('; ', $cleaned_segments);
    }

    /**
     * Convert screenplay credit text into a stable pipe-delimited people list.
     */
    private function screenplay_credit_to_pipe_list($value) {
        $value = $this->strip_screenplay_credit_prefixes($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+(?:and\s+)?in collaboration with\s+/i', '|', $value);
        $value = preg_replace('/\s+and\s+script collaborators?\s*[-:]\s*/i', '|', $value);
        $value = str_replace(array(';', ' & ', ' and ', ','), '|', $value);
        $value = preg_replace('/\s*\|\s*/', '|', (string) $value);

        $parts = array_values(array_filter(array_map(function($part) {
            $part = trim((string) $part);
            $part = preg_replace('/^(?:and\s+)?in collaboration with\s+/i', '', $part);
            $part = preg_replace('/^screenplay\s*[-:]\s*/i', '', $part);
            $part = preg_replace('/^script collaborators?\s*[-:]\s*/i', '', $part);
            $part = trim($part, " \t\n\r\0\x0B,;");
            return $part;
        }, explode('|', $value)), 'strlen'));

        if (empty($parts)) {
            return '';
        }

        $normalized = array();
        foreach ($parts as $part) {
            if (!in_array($part, $normalized, true)) {
                $normalized[] = $part;
            }
        }

        return implode('|', $normalized);
    }

    /**
     * Detect non-screenplay writing categories that still represent people pages.
     */
    private function is_non_screenplay_writing_person_category($category) {
        $category = strtoupper(trim((string) $category));
        return in_array($category, array(
            'WRITING (ORIGINAL STORY)',
            'WRITING (TITLE WRITING)',
        ), true);
    }

    /**
     * Build a stable Lunara-local fallback person id for historical rows without IMDb ids.
     */
    private function build_local_name_entity_id($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $name = remove_accents($name);
        }

        $slug = sanitize_title($name);
        if ($slug === '') {
            $slug = substr(md5(strtolower($name)), 0, 12);
        }

        return 'lnm-' . $slug;
    }

    /**
     * Resolve a pipe-delimited person list into existing ids or stable local fallback ids.
     */
    private function resolve_people_ids_with_fallback($value, $name_index) {
        $names = array_values(array_filter(array_map('trim', explode('|', (string) $value)), 'strlen'));
        if (empty($names)) {
            return '';
        }

        $resolved_ids = array();
        foreach ($names as $name) {
            $key = $this->normalize_entity_name_key($name);
            $resolved = ($key !== '' && array_key_exists($key, $name_index) && is_string($name_index[$key]) && $name_index[$key] !== '')
                ? (string) $name_index[$key]
                : '';

            if ($resolved === '') {
                $resolved = $this->build_local_name_entity_id($name);
            }

            if ($resolved === '') {
                return '';
            }

            $resolved_ids[] = $resolved;
        }

        return implode('|', $resolved_ids);
    }

    /**
     * Apply targeted display/data hotfixes for known problematic rows.
     */
    private function apply_row_hotfixes($row) {
        if (!is_array($row)) {
            return array();
        }

        if (!empty($row['nominee_ids'])) {
            $row['nominee_ids'] = $this->normalize_imdb_entity_ids($row['nominee_ids'], array('nm', 'co', 'tt'));
        }

        if (!empty($row['film_id'])) {
            $row['film_id'] = $this->normalize_imdb_entity_ids($row['film_id'], array('tt'));
        }

        $category = strtoupper(trim((string) ($row['canonical_category'] ?? $row['category'] ?? '')));
        $film = trim((string) ($row['film'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $nominees = trim((string) ($row['nominees'] ?? ''));

        $title_id_hotfixes = array(
            'Avatar: Fire and Ash' => array('tt13651794' => 'tt1757678'),
            'Diane Warren: Relentless' => array('tt21197266' => 'tt14588692'),
            'Elio' => array('tt11860228' => 'tt4900148'),
            'Jurassic World Rebirth' => array('tt5765780' => 'tt31036941'),
            'The Smashing Machine' => array('tt14857364' => 'tt11214558'),
            'Train Dreams' => array('tt16277242' => 'tt29768334'),
            'Zootopia 2' => array('tt14043526' => 'tt26443597'),
            'The Boy, the Mole, the Fox and the Horse' => array('tt14819332' => 'tt22667880'),
        );

        if (isset($title_id_hotfixes[$film])) {
            foreach ($title_id_hotfixes[$film] as $bad_id => $corrected_id) {
                $row['film_id'] = $this->replace_title_id_token($row['film_id'] ?? '', $bad_id, $corrected_id);
            }
        }

        if (trim((string) ($row['film_id'] ?? '')) === 'tt30224498' && $film === 'Kpop Demon Hunters') {
            $row['film'] = 'KPop Demon Hunters';
        }

        if ($category === 'BEST PICTURE' && $film !== '' && $name === '' && $nominees === '') {
            $best_picture_backfills = array(
                'Crouching Tiger, Hidden Dragon' => array(
                    'name' => 'Bill Kong, Hsu Li Kong and Ang Lee',
                    'nominees' => 'Bill Kong|Hsu Li Kong|Ang Lee',
                    'nominee_ids' => '',
                    'detail' => 'Producers',
                ),
                'Good Night, and Good Luck.' => array(
                    'name' => 'Grant Heslov',
                    'nominees' => 'Grant Heslov',
                    'nominee_ids' => 'nm0381416',
                    'detail' => 'Producer',
                ),
                'Three Billboards Outside Ebbing, Missouri' => array(
                    'name' => 'Graham Broadbent, Pete Czernin and Martin McDonagh',
                    'nominees' => 'Graham Broadbent|Pete Czernin|Martin McDonagh',
                    'nominee_ids' => '',
                    'detail' => 'Producers',
                ),
                'The Godfather Part III' => array(
                    'name' => 'Francis Ford Coppola',
                    'nominees' => 'Francis Ford Coppola',
                    'nominee_ids' => 'nm0000338',
                    'detail' => 'Producer',
                ),
                'Hello, Dolly!' => array(
                    'name' => 'Ernest Lehman',
                    'nominees' => 'Ernest Lehman',
                    'nominee_ids' => 'nm0500073',
                    'detail' => 'Producer',
                ),
                'Rachel, Rachel' => array(
                    'name' => 'Paul Newman',
                    'nominees' => 'Paul Newman',
                    'nominee_ids' => 'nm0000056',
                    'detail' => 'Producer',
                ),
                'All This, and Heaven Too' => array(
                    'name' => 'Warner Bros.',
                    'nominees' => 'Warner Bros.',
                    'nominee_ids' => '',
                    'detail' => 'Production company',
                ),
            );

            if (isset($best_picture_backfills[$film])) {
                foreach ($best_picture_backfills[$film] as $key => $value) {
                    $row[$key] = $value;
                }
            }
        }

        return $row;
    }

    /**
     * Normalize a pipe-delimited entity id list and drop placeholders or invalid tokens.
     */
    private function normalize_imdb_entity_ids($raw_ids, $allowed_prefixes = array('tt')) {
        $raw_ids = (string) $raw_ids;
        if ($raw_ids === '') {
            return '';
        }

        $normalized = array();
        $allowed_prefixes = array_values(array_unique(array_map('strtolower', (array) $allowed_prefixes)));
        $placeholder_values = array('?', 'n/a', 'na', 'none', 'unknown', 'null', 'nil', '0', '-', '--');

        $tokens = preg_split('/\s*[|,]\s*/', $raw_ids);
        foreach (array_filter(array_map('trim', (array) $tokens), 'strlen') as $part) {
            $part = strtolower(ltrim((string) $part, '/'));

            if ($part === '' || in_array($part, $placeholder_values, true)) {
                continue;
            }

            if ($this->is_valid_imdb_entity_id($part, $allowed_prefixes)) {
                $normalized[] = $part;
                continue;
            }

            if (in_array('nm', $allowed_prefixes, true) && $this->is_local_name_entity_id($part)) {
                $normalized[] = $part;
            }
        }

        return implode('|', array_values(array_unique($normalized)));
    }

    /**
     * Validate a single IMDb entity id against the allowed prefixes.
     */
    private function is_valid_imdb_entity_id($id, $allowed_prefixes = array('tt')) {
        $id = strtolower(trim((string) $id));
        if ($id === '') {
            return false;
        }

        foreach (array_values(array_unique(array_map('strtolower', (array) $allowed_prefixes))) as $prefix) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\d{7,9}$/', $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a database row for consistent display and import behavior.
     */
    private function normalize_awards_row($row) {
        if (!is_array($row)) {
            return array();
        }

        $row = $this->apply_row_hotfixes($row);

        $row['ceremony'] = isset($row['ceremony']) ? intval($row['ceremony']) : 0;
        $row['year'] = isset($row['year']) ? sanitize_text_field((string) $row['year']) : '';
        $row['class'] = isset($row['class']) ? sanitize_text_field((string) $row['class']) : '';
        $row['canonical_category'] = isset($row['canonical_category']) ? sanitize_text_field((string) $row['canonical_category']) : '';
        $row['category'] = isset($row['category']) ? sanitize_text_field((string) $row['category']) : '';
        $row['film'] = isset($row['film']) ? sanitize_text_field((string) $row['film']) : '';
        $row['film_id'] = isset($row['film_id']) ? sanitize_text_field((string) $row['film_id']) : '';
        $row['name'] = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
        $row['nominees'] = isset($row['nominees']) ? sanitize_textarea_field((string) $row['nominees']) : '';
        $row['nominee_ids'] = isset($row['nominee_ids']) ? sanitize_textarea_field((string) $row['nominee_ids']) : '';
        $row['winner'] = !empty($row['winner']) ? 1 : 0;
        $row['detail'] = isset($row['detail']) ? sanitize_textarea_field((string) $row['detail']) : '';
        $row['note'] = isset($row['note']) ? sanitize_textarea_field((string) $row['note']) : '';
        $row['citation'] = isset($row['citation']) ? sanitize_textarea_field((string) $row['citation']) : '';

        $normalized_category = $row['canonical_category'] !== '' ? $row['canonical_category'] : $row['category'];
        if ($this->is_screenplay_category($normalized_category)) {
            $raw_name = $row['name'];

            if ($row['nominees'] !== '') {
                $row['nominees'] = $this->screenplay_credit_to_pipe_list($row['nominees']);
            }

            if ($row['nominees'] === '' && $raw_name !== '') {
                $row['nominees'] = $this->screenplay_credit_to_pipe_list($raw_name);
            }
        }

        if ($row['name'] === '' && $row['nominees'] !== '') {
            $row['name'] = $this->humanize_pipe_list($row['nominees']);
        }

        if ($row['nominees'] === '' && $row['name'] !== '') {
            $row['nominees'] = $row['name'];
        }

        if ($this->is_title_primary_nominee_row($row) && $row['film'] !== '') {
            $film_label = $this->humanize_pipe_list($row['film']);
            $nominee_represents_film = (
                ($row['name'] === '' || $row['name'] === $film_label) &&
                ($row['nominees'] === '' || $row['nominees'] === $row['film'])
            );

            if ($row['name'] === '') {
                $row['name'] = $film_label;
            }
            if ($row['nominees'] === '') {
                $row['nominees'] = $row['film'];
            }
            if ($row['nominee_ids'] === '' && $row['film_id'] !== '' && $nominee_represents_film) {
                $row['nominee_ids'] = $row['film_id'];
            }
            if (
                $row['nominee_ids'] !== '' &&
                $row['film_id'] !== '' &&
                $row['nominee_ids'] === $row['film_id'] &&
                !$nominee_represents_film
            ) {
                $row['nominee_ids'] = '';
            }
        }

        return $row;
    }

    /**
     * Build a normalized database payload from imported CSV/JSON rows.
     */
    private function build_import_db_row($row) {
        $winner_raw = isset($row['Winner']) ? trim((string) $row['Winner']) : '';
        $winner = (!empty($winner_raw) && in_array(strtolower($winner_raw), array('1', 'true', 'yes'), true)) ? 1 : 0;

        return $this->normalize_awards_row(array(
            'ceremony' => isset($row['Ceremony']) ? intval($row['Ceremony']) : 0,
            'year' => isset($row['Year']) ? (string) $row['Year'] : '',
            'class' => isset($row['Class']) ? (string) $row['Class'] : '',
            'canonical_category' => isset($row['CanonicalCategory']) ? (string) $row['CanonicalCategory'] : '',
            'category' => isset($row['Category']) ? (string) $row['Category'] : '',
            'film' => isset($row['Film']) ? (string) $row['Film'] : '',
            'film_id' => isset($row['FilmId']) ? (string) $row['FilmId'] : '',
            'name' => isset($row['Name']) ? (string) $row['Name'] : '',
            'nominees' => isset($row['Nominees']) ? (string) $row['Nominees'] : '',
            'nominee_ids' => isset($row['NomineeIds']) ? (string) $row['NomineeIds'] : '',
            'winner' => $winner,
            'detail' => isset($row['Detail']) ? (string) $row['Detail'] : '',
            'note' => isset($row['Note']) ? (string) $row['Note'] : '',
            'citation' => isset($row['Citation']) ? (string) $row['Citation'] : '',
        ));
    }

    /**
     * Normalize entity names into a stable comparison key.
     */
    private function normalize_entity_name_key($value) {
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
    }

    /**
     * Build an exact writer/person lookup map from existing nominee rows.
     */
    private function build_nominee_name_index() {
        global $wpdb;
        $table_name = $this->get_table_name();

        $rows = $wpdb->get_results(
            "SELECT name, nominees, nominee_ids FROM $table_name WHERE nominee_ids != '' AND (nominees != '' OR name != '')",
            ARRAY_A
        );

        $index = array();

        foreach ((array) $rows as $row) {
            $names = array_values(array_filter(array_map('trim', explode('|', (string) ($row['nominees'] ?? ''))), 'strlen'));
            $ids = array_values(array_filter(array_map('trim', explode('|', (string) ($row['nominee_ids'] ?? ''))), 'strlen'));

            if (empty($names) || empty($ids) || count($names) !== count($ids)) {
                $fallback_name = trim((string) ($row['name'] ?? ''));
                $fallback_id = strtolower(trim((string) ($ids[0] ?? '')));

                if ($fallback_name !== '' && count($ids) === 1 && $this->is_name_entity_id($fallback_id)) {
                    $fallback_key = $this->normalize_entity_name_key($fallback_name);
                    if ($fallback_key !== '') {
                        if (!array_key_exists($fallback_key, $index)) {
                            $index[$fallback_key] = $fallback_id;
                        } elseif ($index[$fallback_key] !== $fallback_id) {
                            $index[$fallback_key] = false;
                        }
                    }
                }

                continue;
            }

            foreach ($names as $offset => $name) {
                $id = strtolower(trim((string) ($ids[$offset] ?? '')));
                if ($name === '' || $id === '' || strpos($id, 'nm') !== 0) {
                    continue;
                }

                $key = $this->normalize_entity_name_key($name);
                if ($key === '') {
                    continue;
                }

                if (!array_key_exists($key, $index)) {
                    $index[$key] = $id;
                } elseif ($index[$key] !== $id) {
                    $index[$key] = false;
                }
            }
        }

        return $index;
    }

    /**
     * Curated non-screenplay writing overrides for historical blank rows.
     */
    private function get_legacy_writing_nominee_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $rows = array(
            array('27', 'WRITING (Original Story)', 'Bread, Love and Dreams', 'Ettore Maria Margadonna', 'Ettore Maria Margadonna', 'nm0546572'),
            array('18', 'WRITING (Original Story)', 'Objective, Burma!', 'Alvah Bessie', 'Alvah Bessie', 'nm0078827'),
            array('13', 'WRITING (Original Story)', 'Arise, My Love', 'Benjamin Glazer and Hans Szekely', 'Benjamin Glazer|Hans Szekely', 'nm0322227|nm0844459'),
            array('13', 'WRITING (Original Story)', 'Edison, the Man', 'Hugo Butler and Dore Schary', 'Hugo Butler|Dore Schary', 'nm0124947|nm0770196'),
        );

        $overrides = array();
        foreach ($rows as $row) {
            $key = intval($row[0]) . '|' . strtoupper(trim((string) $row[1])) . '|' . strtolower(trim((string) $row[2]));
            $overrides[$key] = array(
                'name' => (string) $row[3],
                'nominees' => (string) $row[4],
                'nominee_ids' => (string) $row[5],
            );
        }

        return $overrides;
    }

    /**
     * Curated people-category nominee overrides keyed by awards row id.
     */
    private function get_people_nominee_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $rows = array(
            array(11461, 'Andrew Garfield'),
            array(10925, 'Denzel Washington'),
            array(10927, 'Woody Harrelson'),
            array(10930, 'Sam Rockwell'),
            array(10932, 'Frances McDormand'),
            array(10933, 'Margot Robbie'),
            array(10937, 'Allison Janney'),
            array(10505, 'Marion Cotillard'),
            array(9305, 'David Strathairn'),
            array(8547, 'Angelina Jolie'),
            array(7343, 'Andy Garcia'),
            array(7239, 'Anjelica Huston'),
            array(7240, 'Lena Olin'),
            array(6987, 'Robin Williams'),
            array(6633, 'Ralph Richardson'),
            array(6510, 'Tom Conti'),
            array(5923, 'Ellen Burstyn'),
            array(5687, 'Marie-Christine Barrault'),
            array(5565, 'James Whitmore'),
            array(5578, 'Sylvia Miles'),
            array(5352, 'Joanne Woodward'),
            array(5357, 'Sylvia Sidney'),
            array(5128, 'Vanessa Redgrave'),
            array(4893, "Peter O'Toole"),
            array(4900, 'Gig Young'),
            array(4902, 'Jane Fonda'),
            array(4910, 'Susannah York'),
            array(4929, 'Sydney Pollack'),
            array(4786, 'Joanne Woodward'),
            array(4791, 'Estelle Parsons'),
            array(4291, 'Agnes Moorehead'),
            array(4153, 'Bobby Darin'),
            array(3773, 'Peter Falk'),
            array(3654, 'Katharine Hepburn'),
            array(3656, 'Elizabeth Taylor'),
            array(3432, 'Deborah Kerr'),
            array(2760, 'Shirley Booth'),
            array(2768, 'Terry Moore'),
            array(2402, 'Deborah Kerr'),
            array(2289, 'Barbara Stanwyck'),
            array(1340, 'Bette Davis'),
            array(1345, 'Gladys Cooper'),
            array(996, "Barbara O'Neil"),
            array(827, 'Robert Donat'),
            array(840, 'Greer Garson'),
        );

        $overrides = array();
        foreach ($rows as $row) {
            $overrides[intval($row[0])] = array(
                'name' => (string) $row[1],
                'nominees' => (string) $row[1],
                'nominee_ids' => isset($row[2]) ? (string) $row[2] : '',
            );
        }

        return $overrides;
    }

    /**
     * Curated single-craft overrides for historical blank rows.
     */
    private function get_single_craft_nominee_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $overrides = array(
            5621 => array(
                'name' => 'Gerald Fried',
                'nominees' => 'Gerald Fried',
                'nominee_ids' => '',
            ),
            1941 => array(
                'name' => 'George Amy',
                'nominees' => 'George Amy',
                'nominee_ids' => '',
            ),
            1956 => array(
                'name' => 'Franz Waxman',
                'nominees' => 'Franz Waxman',
                'nominee_ids' => '',
            ),
            1563 => array(
                'name' => 'Charles G. Clarke and Allen M. Davey',
                'nominees' => 'Charles G. Clarke|Allen M. Davey',
                'nominee_ids' => '',
            ),
            1371 => array(
                'name' => 'John J. Mescall',
                'nominees' => 'John J. Mescall',
                'nominee_ids' => '',
            ),
        );

        return $overrides;
    }

    /**
     * Curated Best Picture producer overrides for rows with blank producer fields.
     */
    private function get_best_picture_nominee_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $overrides = array(
            11002 => array('name' => 'Graham Broadbent, Peter Czernin, and Martin McDonagh'),
            9377  => array('name' => 'Grant Heslov'),
            8731  => array('name' => 'Bill Kong, Hsu Li-kong, and Ang Lee'),
            7413  => array('name' => 'Francis Ford Coppola'),
            4968  => array('name' => 'Ernest Lehman'),
            4850  => array('name' => 'Paul Newman'),
            1076  => array('name' => 'Jack L. Warner, Hal B. Wallis, and David Lewis'),
            917   => array('name' => 'Victor Saville'),
        );

        return $overrides;
    }

    /**
     * Curated International Feature title overrides for archival shorthand rows.
     */
    private function get_international_feature_title_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $overrides = array(
            5734 => array(
                'film_id' => 'tt0071688',
                'nominees' => 'Jacob the Liar',
            ),
        );

        return $overrides;
    }

    /**
     * Resolve multiple person ids from a pipe list using local index, TMDB context, and local fallback.
     */
    private function resolve_people_ids_from_awards_context($value, $name_index, $film_id = '', $film = '', $year = '', $category = '') {
        $names = array_values(array_filter(array_map('trim', explode('|', (string) $value)), 'strlen'));
        if (empty($names)) {
            return array('names' => array(), 'ids' => array());
        }

        $resolved_names = array();
        $resolved_ids = array();
        foreach ($names as $name) {
            $resolved = $this->resolve_person_id_from_awards_context($name, $name_index, $film_id, $film, $year, $category);
            if (empty($resolved['id'])) {
                return array('names' => array(), 'ids' => array());
            }
            $resolved_names[] = !empty($resolved['name']) ? (string) $resolved['name'] : $name;
            $resolved_ids[] = (string) $resolved['id'];
        }

        return array('names' => $resolved_names, 'ids' => $resolved_ids);
    }

    /**
     * Resolve the preferred IMDb title id for an awards-row film context.
     */
    private function resolve_title_id_from_awards_context($film_id = '', $film = '', $year = '') {
        global $wpdb;

        foreach ($this->extract_title_ids($film_id) as $tt_id) {
            if ($this->is_title_entity_id($tt_id)) {
                return strtolower((string) $tt_id);
            }
        }

        $film = trim((string) $film);
        $year = trim((string) $year);
        if ($film !== '') {
            $table_name = $this->get_table_name();
            $known_film_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT film_id
                     FROM {$table_name}
                     WHERE film = %s
                       AND TRIM(COALESCE(film_id, '')) <> ''
                     ORDER BY CASE WHEN year = %s THEN 0 ELSE 1 END, id DESC
                     LIMIT 1",
                    $film,
                    $year
                )
            );

            foreach ($this->extract_title_ids($known_film_id) as $tt_id) {
                if ($this->is_title_entity_id($tt_id)) {
                    return strtolower((string) $tt_id);
                }
            }
        }

        $details = $this->get_tmdb_movie_details_for_awards_row($film_id, $film, $year);
        $tmdb_imdb_id = strtolower(trim((string) ($details['imdb_id'] ?? '')));
        if ($this->is_title_entity_id($tmdb_imdb_id)) {
            return $tmdb_imdb_id;
        }

        return '';
    }

    /**
     * Whether a category should resolve to a single person page.
     */
    private function is_people_award_person_category($category) {
        $category = strtoupper(trim((string) $category));
        return in_array($category, array(
            'DIRECTING',
            'ACTOR IN A LEADING ROLE',
            'ACTRESS IN A LEADING ROLE',
            'ACTOR IN A SUPPORTING ROLE',
            'ACTRESS IN A SUPPORTING ROLE',
        ), true);
    }

    /**
     * Normalize a person name for relaxed matching across suffix variants.
     */
    private function normalize_person_match_key($value) {
        $key = $this->normalize_entity_name_key($value);
        if ($key === '') {
            return '';
        }

        $key = preg_replace('/\b(jr|sr|ii|iii|iv|v)\b/', '', (string) $key);
        $key = preg_replace('/\s+/', ' ', (string) $key);
        return trim((string) $key);
    }

    /**
     * Resolve a single nominee id from the existing name index and TMDB context.
     */
    private function resolve_person_id_from_awards_context($name, $name_index, $film_id = '', $film = '', $year = '', $category = '') {
        $name = trim((string) $name);
        if ($name === '') {
            return array('name' => '', 'id' => '');
        }

        $exact_key = $this->normalize_entity_name_key($name);
        if ($exact_key !== '' && array_key_exists($exact_key, $name_index) && is_string($name_index[$exact_key]) && $name_index[$exact_key] !== '') {
            return array('name' => $name, 'id' => (string) $name_index[$exact_key]);
        }

        $details = $this->get_tmdb_movie_details_for_awards_row($film_id, $film, $year);
        $credits = is_array($details) ? ($details['credits'] ?? array()) : array();
        $cast = (!empty($credits['cast']) && is_array($credits['cast'])) ? $credits['cast'] : array();
        $crew = (!empty($credits['crew']) && is_array($credits['crew'])) ? $credits['crew'] : array();

        $needle = $this->normalize_person_match_key($name);
        if ($needle !== '') {
            $pool = array();
            foreach ($cast as $member) {
                $pool[] = array(
                    'name' => (string) ($member['name'] ?? ''),
                    'tmdb_person_id' => intval($member['id'] ?? 0),
                );
            }
            foreach ($crew as $member) {
                if ($category === 'DIRECTING' && strtoupper(trim((string) ($member['job'] ?? ''))) !== 'DIRECTOR') {
                    continue;
                }
                $pool[] = array(
                    'name' => (string) ($member['name'] ?? ''),
                    'tmdb_person_id' => intval($member['id'] ?? 0),
                );
            }

            foreach ($pool as $candidate) {
                $candidate_name = trim((string) ($candidate['name'] ?? ''));
                if ($candidate_name === '') {
                    continue;
                }

                if ($this->normalize_person_match_key($candidate_name) !== $needle) {
                    continue;
                }

                $imdb_id = $this->get_tmdb_person_imdb_id(intval($candidate['tmdb_person_id'] ?? 0));
                if ($imdb_id !== '') {
                    return array('name' => $candidate_name, 'id' => $imdb_id);
                }
            }
        }

        return array('name' => $name, 'id' => $this->build_local_name_entity_id($name));
    }

    /**
     * Resolve a directing nominee straight from TMDB movie context.
     */
    private function resolve_directing_nominee_from_tmdb($film_id = '', $film = '', $year = '') {
        $details = $this->get_tmdb_movie_details_for_awards_row($film_id, $film, $year);
        $credits = is_array($details) ? ($details['credits'] ?? array()) : array();
        $crew = (!empty($credits['crew']) && is_array($credits['crew'])) ? $credits['crew'] : array();

        foreach ($crew as $member) {
            if (strtoupper(trim((string) ($member['job'] ?? ''))) !== 'DIRECTOR') {
                continue;
            }

            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $imdb_id = $this->get_tmdb_person_imdb_id(intval($member['id'] ?? 0));
            if ($imdb_id !== '') {
                return array('name' => $name, 'id' => $imdb_id);
            }

            return array('name' => $name, 'id' => $this->build_local_name_entity_id($name));
        }

        return array('name' => '', 'id' => '');
    }

    /**
     * Whether a category is a single-person craft award we can resolve from TMDB crew.
     */
    private function is_single_craft_person_category($category) {
        $category = strtoupper(trim((string) $category));
        return in_array($category, array(
            'FILM EDITING',
            'CINEMATOGRAPHY',
            'CINEMATOGRAPHY (COLOR)',
            'CINEMATOGRAPHY (BLACK-AND-WHITE)',
            'MUSIC (ORIGINAL SCORE)',
        ), true);
    }

    /**
     * Map single-person craft categories to TMDB crew jobs.
     */
    private function get_single_craft_tmdb_jobs($category) {
        $category = strtoupper(trim((string) $category));
        if ($category === 'FILM EDITING') {
            return array('Editor');
        }
        if ($category === 'MUSIC (ORIGINAL SCORE)') {
            return array('Original Music Composer', 'Music');
        }
        if (strpos($category, 'CINEMATOGRAPHY') === 0) {
            return array('Director of Photography', 'Cinematographer');
        }

        return array();
    }

    /**
     * Resolve a single craft nominee from TMDB movie crew.
     */
    private function resolve_single_craft_nominee_from_tmdb($category, $film_id = '', $film = '', $year = '') {
        $jobs = $this->get_single_craft_tmdb_jobs($category);
        if (empty($jobs)) {
            return array('name' => '', 'id' => '');
        }

        $details = $this->get_tmdb_movie_details_for_awards_row($film_id, $film, $year);
        $credits = is_array($details) ? ($details['credits'] ?? array()) : array();
        $crew = (!empty($credits['crew']) && is_array($credits['crew'])) ? $credits['crew'] : array();

        foreach ($crew as $member) {
            $job = trim((string) ($member['job'] ?? ''));
            if ($job === '' || !in_array($job, $jobs, true)) {
                continue;
            }

            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $imdb_id = $this->get_tmdb_person_imdb_id(intval($member['id'] ?? 0));
            if ($imdb_id !== '') {
                return array('name' => $name, 'id' => $imdb_id);
            }

            return array('name' => $name, 'id' => $this->build_local_name_entity_id($name));
        }

        return array('name' => '', 'id' => '');
    }

    /**
     * Resolve an IMDb nm id from a TMDB person id.
     */
    private function get_tmdb_person_imdb_id($tmdb_person_id) {
        $tmdb_person_id = intval($tmdb_person_id);
        if ($tmdb_person_id <= 0) {
            return '';
        }

        $cache_key = 'aat_tmdb_person_imdb_v1_' . $tmdb_person_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return is_string($cached) ? $cached : '';
        }

        $key = $this->get_tmdb_api_key();
        if ($key === '') {
            return '';
        }

        $url = add_query_arg(
            array(
                'api_key' => $key,
            ),
            'https://api.themoviedb.org/3/person/' . $tmdb_person_id . '/external_ids'
        );

        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) {
            return '';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $imdb_id = strtolower(trim((string) ($data['imdb_id'] ?? '')));
        if (!preg_match('/^nm\d+$/', $imdb_id)) {
            $imdb_id = '';
        }

        set_transient($cache_key, $imdb_id, $imdb_id === '' ? 12 * HOUR_IN_SECONDS : 30 * DAY_IN_SECONDS);
        return $imdb_id;
    }

    /**
     * Fetch TMDB movie details with credits from an awards row film context.
     */
    private function get_tmdb_movie_details_for_awards_row($film_id, $film, $year = '') {
        foreach ($this->extract_title_ids($film_id) as $tt_id) {
            $details = $this->get_tmdb_data_for_imdb_id($tt_id);
            if (!empty($details['credits']) && is_array($details['credits'])) {
                return $details;
            }
        }

        $film = trim((string) $film);
        $year = preg_replace('/[^0-9]/', '', (string) $year);
        if ($film === '') {
            return array();
        }

        $cache_key = 'aat_tmdb_movie_context_v1_' . md5(strtolower($film . '|' . $year));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $key = $this->get_tmdb_api_key();
        if ($key === '') {
            return array();
        }

        $search_args = array(
            'api_key' => $key,
            'query' => $film,
            'include_adult' => 'false',
        );
        if ($year !== '') {
            $search_args['year'] = $year;
            $search_args['primary_release_year'] = $year;
        }

        $search_url = add_query_arg($search_args, 'https://api.themoviedb.org/3/search/movie');
        $search_response = wp_remote_get($search_url, array('timeout' => 15));
        if (is_wp_error($search_response)) {
            return array();
        }

        $search_data = json_decode(wp_remote_retrieve_body($search_response), true);
        if (empty($search_data['results']) || !is_array($search_data['results'])) {
            set_transient($cache_key, array(), 12 * HOUR_IN_SECONDS);
            return array();
        }

        $needle = strtolower(trim($film));
        $movie = array();
        foreach ($search_data['results'] as $candidate) {
            $candidate_title = strtolower(trim((string) ($candidate['title'] ?? '')));
            $candidate_year = substr((string) ($candidate['release_date'] ?? ''), 0, 4);
            if ($candidate_title === $needle && ($year === '' || $candidate_year === $year)) {
                $movie = $candidate;
                break;
            }
        }
        if (empty($movie['id'])) {
            $movie = $search_data['results'][0];
        }

        if (empty($movie['id'])) {
            set_transient($cache_key, array(), 12 * HOUR_IN_SECONDS);
            return array();
        }

        $details_url = add_query_arg(
            array(
                'api_key' => $key,
                'append_to_response' => 'credits',
            ),
            'https://api.themoviedb.org/3/movie/' . intval($movie['id'])
        );

        $details_response = wp_remote_get($details_url, array('timeout' => 15));
        if (is_wp_error($details_response)) {
            return array();
        }

        $details = json_decode(wp_remote_retrieve_body($details_response), true);
        if (!is_array($details)) {
            $details = array();
        }

        set_transient($cache_key, $details, empty($details) ? 12 * HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS);
        return $details;
    }

    /**
     * Resolve an IMDb nm id from a person name plus film context using TMDB person search.
     */
    private function resolve_tmdb_person_imdb_id_by_name($name, $film = '', $year = '') {
        $name = trim((string) $name);
        $film = trim((string) $film);
        $year = preg_replace('/[^0-9]/', '', (string) $year);
        if ($name === '') {
            return '';
        }

        $cache_key = 'aat_tmdb_person_search_v1_' . md5(strtolower($name . '|' . $film . '|' . $year));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return is_string($cached) ? $cached : '';
        }

        $key = $this->get_tmdb_api_key();
        if ($key === '') {
            return '';
        }

        $search_url = add_query_arg(
            array(
                'api_key' => $key,
                'query' => $name,
                'include_adult' => 'false',
            ),
            'https://api.themoviedb.org/3/search/person'
        );

        $search_response = wp_remote_get($search_url, array('timeout' => 15));
        if (is_wp_error($search_response)) {
            return '';
        }

        $search_data = json_decode(wp_remote_retrieve_body($search_response), true);
        if (empty($search_data['results']) || !is_array($search_data['results'])) {
            set_transient($cache_key, '', 12 * HOUR_IN_SECONDS);
            return '';
        }

        $needle = $this->normalize_entity_name_key($name);
        $film_key = $this->normalize_entity_name_key($film);
        $best_match = array();
        $best_score = -1;

        foreach ($search_data['results'] as $candidate) {
            $candidate_name = trim((string) ($candidate['name'] ?? ''));
            $candidate_id = intval($candidate['id'] ?? 0);
            if ($candidate_name === '' || $candidate_id <= 0) {
                continue;
            }

            $score = 0;
            $candidate_key = $this->normalize_entity_name_key($candidate_name);
            if ($candidate_key === $needle) {
                $score += 90;
            } elseif ($needle !== '' && (strpos($candidate_key, $needle) !== false || strpos($needle, $candidate_key) !== false)) {
                $score += 30;
            } else {
                continue;
            }

            $department = strtolower(trim((string) ($candidate['known_for_department'] ?? '')));
            if ($department === 'writing') {
                $score += 20;
            }

            if (!empty($candidate['known_for']) && is_array($candidate['known_for'])) {
                foreach ($candidate['known_for'] as $known_for) {
                    $known_title = trim((string) ($known_for['title'] ?? $known_for['name'] ?? ''));
                    $known_title_key = $this->normalize_entity_name_key($known_title);
                    if ($film_key !== '' && $known_title_key === $film_key) {
                        $score += 40;

                        $known_year = substr((string) ($known_for['release_date'] ?? $known_for['first_air_date'] ?? ''), 0, 4);
                        if ($year !== '' && $known_year === $year) {
                            $score += 10;
                        }
                        break;
                    }
                }
            }

            if (!empty($candidate['popularity'])) {
                $score += min(5, floatval($candidate['popularity']) / 20);
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $candidate;
            }
        }

        if (empty($best_match['id']) || $best_score < 90) {
            set_transient($cache_key, '', 12 * HOUR_IN_SECONDS);
            return '';
        }

        $imdb_id = $this->get_tmdb_person_imdb_id(intval($best_match['id']));
        if (!preg_match('/^nm\d+$/', $imdb_id)) {
            $imdb_id = '';
        }

        set_transient($cache_key, $imdb_id, $imdb_id === '' ? 12 * HOUR_IN_SECONDS : 30 * DAY_IN_SECONDS);
        return $imdb_id;
    }

    /**
     * Try to resolve screenplay nominee IMDb ids from TMDB credits using film context.
     */
    private function resolve_screenplay_nominee_ids_from_tmdb($nominees, $film_id = '', $film = '', $year = '') {
        $names = array_values(array_filter(array_map('trim', explode('|', (string) $nominees)), 'strlen'));
        if (empty($names)) {
            return '';
        }

        $details = $this->get_tmdb_movie_details_for_awards_row($film_id, $film, $year);
        if (empty($details['credits']['crew']) || !is_array($details['credits']['crew'])) {
            return '';
        }

        $credit_index = array();
        foreach ($details['credits']['crew'] as $credit) {
            $credit_name = trim((string) ($credit['name'] ?? ''));
            $tmdb_id = intval($credit['id'] ?? 0);
            if ($credit_name === '' || $tmdb_id <= 0) {
                continue;
            }

            $department = strtolower(trim((string) ($credit['department'] ?? '')));
            $job = strtolower(trim((string) ($credit['job'] ?? '')));
            $score = 0;
            if ($department === 'writing') {
                $score += 50;
            }
            if (preg_match('/screenplay|writer|writing|story|teleplay|author|written/', $job)) {
                $score += 50;
            }
            if ($score <= 0) {
                continue;
            }

            $key = $this->normalize_entity_name_key($credit_name);
            if ($key === '') {
                continue;
            }

            if (!isset($credit_index[$key]) || $score > $credit_index[$key]['score']) {
                $credit_index[$key] = array(
                    'tmdb_id' => $tmdb_id,
                    'score' => $score,
                );
            }
        }

        if (empty($credit_index)) {
            return '';
        }

        $resolved_ids = array();
        foreach ($names as $name) {
            $key = $this->normalize_entity_name_key($name);
            $imdb_id = '';

            if ($key !== '' && !empty($credit_index[$key]['tmdb_id'])) {
                $imdb_id = $this->get_tmdb_person_imdb_id(intval($credit_index[$key]['tmdb_id']));
            }

            if (!preg_match('/^nm\d+$/', $imdb_id)) {
                $imdb_id = $this->resolve_tmdb_person_imdb_id_by_name($name, $film, $year);
            }

            if (!preg_match('/^nm\d+$/', $imdb_id)) {
                return '';
            }

            $resolved_ids[] = $imdb_id;
        }

        return implode('|', $resolved_ids);
    }

    /**
     * Rewrite screenplay rows so writers resolve cleanly to person pages.
     */
    public function repair_screenplay_credit_rows($allow_remote = false) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category IN ('WRITING (Original Screenplay)', 'WRITING (Adapted Screenplay)')",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));
            if ($original_nominee_ids === '' && !empty($normalized['nominees'])) {
                $names = array_values(array_filter(array_map('trim', explode('|', (string) $normalized['nominees'])), 'strlen'));
                $matched_ids = array();
                $can_resolve_all = !empty($names);

                foreach ($names as $name) {
                    $key = $this->normalize_entity_name_key($name);
                    $resolved = ($key !== '' && array_key_exists($key, $name_index)) ? $name_index[$key] : false;
                    if (empty($resolved) || !is_string($resolved)) {
                        $can_resolve_all = false;
                        break;
                    }
                    $matched_ids[] = $resolved;
                }

                if ($can_resolve_all && !empty($matched_ids)) {
                    $normalized['nominee_ids'] = implode('|', $matched_ids);
                    $resolved_ids++;
                } elseif ($allow_remote) {
                    $tmdb_ids = $this->resolve_screenplay_nominee_ids_from_tmdb(
                        (string) ($normalized['nominees'] ?? ''),
                        (string) ($normalized['film_id'] ?? ''),
                        (string) ($normalized['film'] ?? ''),
                        (string) ($normalized['year'] ?? '')
                    );

                    if ($tmdb_ids !== '') {
                        $normalized['nominee_ids'] = $tmdb_ids;
                        $resolved_ids++;
                        $remote_resolved_ids++;
                    }
                }
            }

            $fields_to_update = array(
                'name' => (string) ($normalized['name'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        delete_transient('aat_records_total_v1');
        delete_transient('aat_records_total_v2');
        delete_transient('aat_total_stats_v2');
        delete_transient('aat_awards_meta_v1');
        delete_transient('aat_hub_page_stats_v1');

        $options_table = $wpdb->options;
        $wpdb->query("DELETE FROM $options_table WHERE option_name LIKE '_transient_aat_entity_label_%' OR option_name LIKE '_transient_timeout_aat_entity_label_%'");

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $remote_resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair non-screenplay writing categories so named writers still resolve to person pages.
     */
    public function repair_non_screenplay_writing_credit_rows() {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category IN ('WRITING (Original Story)', 'WRITING (Title Writing)')",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $legacy_overrides = $this->get_legacy_writing_nominee_overrides();
        $updated = 0;
        $resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));
            $category = strtoupper(trim((string) ($normalized['canonical_category'] ?? '')));

            if (!$this->is_non_screenplay_writing_person_category($category)) {
                continue;
            }

            $override_key = intval($normalized['ceremony'] ?? 0) . '|' . $category . '|' . strtolower(trim((string) ($normalized['film'] ?? '')));
            if (isset($legacy_overrides[$override_key])) {
                $override = $legacy_overrides[$override_key];
                $normalized['name'] = (string) ($override['name'] ?? $normalized['name'] ?? '');
                $normalized['nominees'] = (string) ($override['nominees'] ?? $normalized['nominees'] ?? '');
                $normalized['nominee_ids'] = (string) ($override['nominee_ids'] ?? $normalized['nominee_ids'] ?? '');
                if ($original_nominee_ids === '' && !empty($normalized['nominee_ids'])) {
                    $resolved_ids++;
                }
            }

            $name_value = trim((string) ($normalized['name'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));

            if ($nominees_value === '' && $name_value !== '') {
                $normalized['nominees'] = $this->screenplay_credit_to_pipe_list($name_value);
                $nominees_value = trim((string) $normalized['nominees']);
            }

            if ($original_nominee_ids === '' && $nominees_value !== '') {
                $resolved = $this->resolve_people_ids_with_fallback($nominees_value, $name_index);
                if ($resolved !== '') {
                    $normalized['nominee_ids'] = $resolved;
                    $resolved_ids++;
                }
            }

            $fields_to_update = array(
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_entity_label_' . md5('title:'));
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => 0,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair writing-category rows so person pages stay connected across the ledger.
     */
    public function repair_writing_credit_rows($allow_remote = false) {
        $screenplay = $this->repair_screenplay_credit_rows($allow_remote);
        $other_writing = $this->repair_non_screenplay_writing_credit_rows();

        return array(
            'updated' => intval($screenplay['updated'] ?? 0) + intval($other_writing['updated'] ?? 0),
            'resolved_ids' => intval($screenplay['resolved_ids'] ?? 0) + intval($other_writing['resolved_ids'] ?? 0),
            'remote_resolved_ids' => intval($screenplay['remote_resolved_ids'] ?? 0),
            'total_rows' => intval($screenplay['total_rows'] ?? 0) + intval($other_writing['total_rows'] ?? 0),
            'screenplay' => $screenplay,
            'other_writing' => $other_writing,
        );
    }

    /**
     * Repair person-led acting/directing rows so nominee pages stay connected.
     */
    public function repair_people_category_credit_rows($allow_remote = false) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category IN ('DIRECTING', 'ACTOR IN A LEADING ROLE', 'ACTRESS IN A LEADING ROLE', 'ACTOR IN A SUPPORTING ROLE', 'ACTRESS IN A SUPPORTING ROLE')
               AND TRIM(COALESCE(nominee_ids, '')) = ''",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $overrides = $this->get_people_nominee_overrides();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $category = strtoupper(trim((string) ($normalized['canonical_category'] ?? '')));
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));

            if (!$this->is_people_award_person_category($category)) {
                continue;
            }

            $row_id = intval($row['id'] ?? 0);
            if ($row_id > 0 && isset($overrides[$row_id])) {
                $override = $overrides[$row_id];
                $normalized['name'] = (string) ($override['name'] ?? $normalized['name'] ?? '');
                $normalized['nominees'] = (string) ($override['nominees'] ?? $normalized['nominees'] ?? '');
                if (!empty($override['nominee_ids'])) {
                    $normalized['nominee_ids'] = (string) $override['nominee_ids'];
                }
            }

            $name_value = trim((string) ($normalized['name'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));

            if ($name_value === '' && $allow_remote && $category === 'DIRECTING') {
                $resolved_director = $this->resolve_directing_nominee_from_tmdb(
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? '')
                );
                if (!empty($resolved_director['name'])) {
                    $normalized['name'] = (string) $resolved_director['name'];
                    $name_value = trim((string) $normalized['name']);
                    if (!empty($resolved_director['id']) && $original_nominee_ids === '') {
                        $normalized['nominee_ids'] = (string) $resolved_director['id'];
                        $resolved_ids++;
                        $remote_resolved_ids++;
                    }
                }
            }

            if ($nominees_value === '' && $name_value !== '') {
                $normalized['nominees'] = $name_value;
                $nominees_value = $name_value;
            }

            if ($original_nominee_ids === '' && $name_value !== '' && trim((string) ($normalized['nominee_ids'] ?? '')) === '') {
                $resolved = $this->resolve_person_id_from_awards_context(
                    $name_value,
                    $name_index,
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? ''),
                    $category
                );

                if (!empty($resolved['name'])) {
                    $normalized['name'] = (string) $resolved['name'];
                    $normalized['nominees'] = (string) $resolved['name'];
                }

                if (!empty($resolved['id'])) {
                    $normalized['nominee_ids'] = (string) $resolved['id'];
                    $resolved_ids++;
                    if (preg_match('/^nm\d+$/', (string) $resolved['id'])) {
                        $remote_resolved_ids++;
                    }
                }
            }

            $fields_to_update = array(
                'name' => (string) ($normalized['name'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $remote_resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair single-person craft rows so key crew pages stay connected.
     */
    public function repair_single_craft_credit_rows($allow_remote = false) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category IN ('FILM EDITING', 'CINEMATOGRAPHY', 'CINEMATOGRAPHY (Color)', 'CINEMATOGRAPHY (Black-and-White)', 'MUSIC (Original Score)')
               AND TRIM(COALESCE(nominee_ids, '')) = ''",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $overrides = $this->get_single_craft_nominee_overrides();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $category = strtoupper(trim((string) ($normalized['canonical_category'] ?? '')));
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));

            if (!$this->is_single_craft_person_category($category)) {
                continue;
            }

            $row_id = intval($row['id'] ?? 0);
            if ($row_id > 0 && isset($overrides[$row_id])) {
                $override = $overrides[$row_id];
                $normalized['name'] = (string) ($override['name'] ?? $normalized['name'] ?? '');
                $normalized['nominees'] = (string) ($override['nominees'] ?? $normalized['nominees'] ?? '');
                if (!empty($override['nominee_ids'])) {
                    $normalized['nominee_ids'] = (string) $override['nominee_ids'];
                }
            }

            $name_value = trim((string) ($normalized['name'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));

            if ($name_value === '' && $allow_remote) {
                $resolved_craft = $this->resolve_single_craft_nominee_from_tmdb(
                    $category,
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? '')
                );
                if (!empty($resolved_craft['name'])) {
                    $normalized['name'] = (string) $resolved_craft['name'];
                    $name_value = trim((string) $normalized['name']);
                    if (!empty($resolved_craft['id']) && $original_nominee_ids === '') {
                        $normalized['nominee_ids'] = (string) $resolved_craft['id'];
                        $resolved_ids++;
                        if (preg_match('/^nm\d+$/', (string) $resolved_craft['id'])) {
                            $remote_resolved_ids++;
                        }
                    }
                }
            }

            if ($nominees_value === '' && $name_value !== '') {
                $normalized['nominees'] = $name_value;
                $nominees_value = $name_value;
            }

            if ($original_nominee_ids === '' && trim((string) ($normalized['nominee_ids'] ?? '')) === '') {
                if (strpos($nominees_value, '|') !== false) {
                    $resolved = $this->resolve_people_ids_with_fallback($nominees_value, $name_index);
                    if ($resolved !== '') {
                        $normalized['nominee_ids'] = $resolved;
                        $resolved_ids++;
                    }
                } elseif ($name_value !== '') {
                    $resolved = $this->resolve_person_id_from_awards_context(
                        $name_value,
                        $name_index,
                        (string) ($normalized['film_id'] ?? ''),
                        (string) ($normalized['film'] ?? ''),
                        (string) ($normalized['year'] ?? ''),
                        $category
                    );

                    if (!empty($resolved['name'])) {
                        $normalized['name'] = (string) $resolved['name'];
                        $normalized['nominees'] = (string) $resolved['name'];
                    }

                    if (!empty($resolved['id'])) {
                        $normalized['nominee_ids'] = (string) $resolved['id'];
                        $resolved_ids++;
                        if (preg_match('/^nm\d+$/', (string) $resolved['id'])) {
                            $remote_resolved_ids++;
                        }
                    }
                }
            }

            $fields_to_update = array(
                'name' => (string) ($normalized['name'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $remote_resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair Best Picture producer rows so producer pages stay connected.
     */
    public function repair_best_picture_credit_rows($allow_remote = false) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category = 'BEST PICTURE'
               AND TRIM(COALESCE(nominee_ids, '')) = ''",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $overrides = $this->get_best_picture_nominee_overrides();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));
            $row_id = intval($row['id'] ?? 0);

            if ($row_id > 0 && isset($overrides[$row_id])) {
                $override = $overrides[$row_id];
                $normalized['name'] = (string) ($override['name'] ?? $normalized['name'] ?? '');
                $normalized['nominees'] = (string) ($override['nominees'] ?? $normalized['nominees'] ?? '');
                if (!empty($override['nominee_ids'])) {
                    $normalized['nominee_ids'] = (string) $override['nominee_ids'];
                }
            }

            $name_value = trim((string) ($normalized['name'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));

            if ($nominees_value === '' && $name_value !== '') {
                $normalized['nominees'] = $this->screenplay_credit_to_pipe_list($name_value);
                $nominees_value = trim((string) $normalized['nominees']);
            }

            if ($name_value === '' && $nominees_value !== '') {
                $normalized['name'] = $this->humanize_pipe_list($nominees_value);
                $name_value = trim((string) $normalized['name']);
            }

            if ($original_nominee_ids === '' && $nominees_value !== '' && trim((string) ($normalized['nominee_ids'] ?? '')) === '') {
                $resolved = $this->resolve_people_ids_from_awards_context(
                    $nominees_value,
                    $name_index,
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? ''),
                    'BEST PICTURE'
                );

                if (!empty($resolved['ids'])) {
                    $normalized['nominees'] = implode('|', array_map('strval', (array) $resolved['names']));
                    $normalized['name'] = $this->humanize_pipe_list($normalized['nominees']);
                    $normalized['nominee_ids'] = implode('|', array_map('strval', (array) $resolved['ids']));
                    $resolved_ids++;

                    foreach ((array) $resolved['ids'] as $resolved_id) {
                        if (preg_match('/^nm\d+$/', (string) $resolved_id)) {
                            $remote_resolved_ids++;
                            break;
                        }
                    }
                }
            }

            $fields_to_update = array(
                'name' => (string) ($normalized['name'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $remote_resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair International Feature Film rows so title pages stay connected.
     */
    public function repair_international_feature_credit_rows($allow_remote = false) {
        global $wpdb;

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category = 'INTERNATIONAL FEATURE FILM'
               AND TRIM(COALESCE(nominee_ids, '')) = ''",
            ARRAY_A
        );

        $updated = 0;
        $resolved_ids = 0;
        $overrides = $this->get_international_feature_title_overrides();

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $film_label = trim((string) ($normalized['film'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));
            $row_id = intval($row['id'] ?? 0);

            if ($row_id > 0 && isset($overrides[$row_id])) {
                $override = $overrides[$row_id];
                if (!empty($override['film_id'])) {
                    $normalized['film_id'] = (string) $override['film_id'];
                }
                if (!empty($override['nominees'])) {
                    $normalized['nominees'] = (string) $override['nominees'];
                    $nominees_value = trim((string) $normalized['nominees']);
                }
            }

            if ($nominees_value === '' && $film_label !== '') {
                $normalized['nominees'] = $film_label;
            }

            if (trim((string) ($normalized['nominee_ids'] ?? '')) === '') {
                $resolved_title_id = $this->resolve_title_id_from_awards_context(
                    (string) ($normalized['film_id'] ?? ''),
                    $film_label,
                    (string) ($normalized['year'] ?? '')
                );

                if ($resolved_title_id !== '') {
                    $normalized['film_id'] = $resolved_title_id;
                    $normalized['nominee_ids'] = $resolved_title_id;
                    $title_label = trim((string) $this->lookup_title_label($resolved_title_id));
                    if ($title_label !== '') {
                        $normalized['nominees'] = $title_label;
                    }
                    $resolved_ids++;
                }
            }

            $fields_to_update = array(
                'film_id' => (string) ($normalized['film_id'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Repair documentary and short-film rows so named creators resolve to person pages.
     */
    public function repair_documentary_and_short_credit_rows($allow_remote = false) {
        global $wpdb;

        $categories = array(
            'DOCUMENTARY (Feature)',
            'DOCUMENTARY (Short Subject)',
            'SHORT FILM (Animated)',
            'SHORT FILM (Live Action)',
            'ANIMATED FEATURE FILM',
        );

        $quoted_categories = "'" . implode("','", array_map('esc_sql', $categories)) . "'";
        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            "SELECT id, ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note, citation
             FROM $table_name
             WHERE canonical_category IN ($quoted_categories)
               AND TRIM(COALESCE(nominee_ids, '')) = ''",
            ARRAY_A
        );

        $name_index = $this->build_nominee_name_index();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $name_value = trim((string) ($normalized['name'] ?? ''));
            $nominees_value = trim((string) ($normalized['nominees'] ?? ''));

            if ($nominees_value === '' && $name_value !== '') {
                $normalized['nominees'] = $this->screenplay_credit_to_pipe_list($name_value);
                $nominees_value = trim((string) $normalized['nominees']);
            }

            if ($name_value === '' && $nominees_value !== '') {
                $normalized['name'] = $this->humanize_pipe_list($nominees_value);
                $name_value = trim((string) $normalized['name']);
            }

            if ($name_value === '' && trim((string) ($normalized['nominee_ids'] ?? '')) === '') {
                $resolved_title_id = $this->resolve_title_id_from_awards_context(
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? '')
                );

                if ($resolved_title_id !== '') {
                    $title_label = trim((string) $this->lookup_title_label($resolved_title_id));
                    $normalized['film_id'] = $resolved_title_id;
                    $normalized['nominee_ids'] = $resolved_title_id;
                    $normalized['nominees'] = $title_label !== '' ? $title_label : (string) ($normalized['film'] ?? '');
                    $resolved_ids++;
                    $remote_resolved_ids++;
                }
            }

            if ($name_value === '' || trim((string) ($normalized['nominee_ids'] ?? '')) !== '') {
                $fields_to_update = array(
                    'film_id' => (string) ($normalized['film_id'] ?? ''),
                    'nominees' => (string) ($normalized['nominees'] ?? ''),
                    'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
                );

                $has_changes = false;
                foreach ($fields_to_update as $field => $value) {
                    if ((string) ($row[$field] ?? '') !== $value) {
                        $has_changes = true;
                        break;
                    }
                }

                if ($has_changes) {
                    $result = $wpdb->update(
                        $table_name,
                        $fields_to_update,
                        array('id' => intval($row['id'])),
                        array('%s', '%s', '%s'),
                        array('%d')
                    );

                    if ($result !== false) {
                        $updated++;
                    }
                }

                continue;
            }

            if ($nominees_value === '') {
                $nominees_value = $name_value;
                $normalized['nominees'] = $name_value;
            }

            if (strpos($nominees_value, '|') !== false) {
                $resolved = $this->resolve_people_ids_from_awards_context(
                    $nominees_value,
                    $name_index,
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? ''),
                    (string) ($normalized['canonical_category'] ?? '')
                );

                if (!empty($resolved['ids'])) {
                    $normalized['nominees'] = implode('|', array_map('strval', (array) $resolved['names']));
                    $normalized['name'] = $this->humanize_pipe_list($normalized['nominees']);
                    $normalized['nominee_ids'] = implode('|', array_map('strval', (array) $resolved['ids']));
                    $resolved_ids++;

                    foreach ((array) $resolved['ids'] as $resolved_id) {
                        if (preg_match('/^nm\d+$/', (string) $resolved_id)) {
                            $remote_resolved_ids++;
                            break;
                        }
                    }
                }
            } else {
                $resolved = $this->resolve_person_id_from_awards_context(
                    $name_value,
                    $name_index,
                    (string) ($normalized['film_id'] ?? ''),
                    (string) ($normalized['film'] ?? ''),
                    (string) ($normalized['year'] ?? ''),
                    (string) ($normalized['canonical_category'] ?? '')
                );

                if (!empty($resolved['name'])) {
                    $normalized['name'] = (string) $resolved['name'];
                    $normalized['nominees'] = (string) $resolved['name'];
                }

                if (!empty($resolved['id'])) {
                    $normalized['nominee_ids'] = (string) $resolved['id'];
                    $resolved_ids++;
                    if (preg_match('/^nm\d+$/', (string) $resolved['id'])) {
                        $remote_resolved_ids++;
                    }
                }
            }

            $fields_to_update = array(
                'name' => (string) ($normalized['name'] ?? ''),
                'nominees' => (string) ($normalized['nominees'] ?? ''),
                'nominee_ids' => (string) ($normalized['nominee_ids'] ?? ''),
            );

            $has_changes = false;
            foreach ($fields_to_update as $field => $value) {
                if ((string) ($row[$field] ?? '') !== $value) {
                    $has_changes = true;
                    break;
                }
            }

            if (!$has_changes) {
                continue;
            }

            $result = $wpdb->update(
                $table_name,
                $fields_to_update,
                array('id' => intval($row['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('aat_hub_page_stats_v1');
            delete_transient('aat_hub_ceremony_grid_v2');
            delete_transient('aat_hub_category_grid_v2');
        }

        return array(
            'updated' => $updated,
            'resolved_ids' => $resolved_ids,
            'remote_resolved_ids' => $remote_resolved_ids,
            'total_rows' => count((array) $rows),
        );
    }

    /**
     * Adjust <title> on Film/Person pages for better UX/SEO.
     */
    public function filter_entity_document_title($title) {
        if (!$this->is_entity_request()) {
            return $title;
        }

        $entity = sanitize_text_field(get_query_var('aat_entity'));
        $id = sanitize_text_field(get_query_var('aat_entity_id'));
        $label = $this->get_entity_display_name($entity, $id);
        if ($label) {
            $prefix = ($entity === 'title') ? __('Oscar Title Profile', 'academy-awards-table') : (($entity === 'company') ? __('Oscar Company Profile', 'academy-awards-table') : __('Oscar Person Profile', 'academy-awards-table'));
            return $label . ' - ' . $prefix . ' - LUNARA FILM';
        }

        return $title;
    }

    /**
     * Adjust <title> on hub pages (Ceremony / Category / Index / About).
     */
    public function filter_hub_document_title($title) {
        if (!$this->is_hub_request() || $this->is_entity_request()) {
            return $title;
        }

        $hub = sanitize_text_field(get_query_var('aat_hub'));
        $hub_id = sanitize_text_field(get_query_var('aat_hub_id'));

        if ($hub === 'ceremony') {
            $ceremony = intval($hub_id);
            if ($ceremony > 0) {
                $year = $this->get_ceremony_year($ceremony);
                $label = $this->ordinal($ceremony) . ' Academy Awards';
                if (!empty($year)) {
                    return $label . ' (' . $year . ') - Oscar Ceremony - LUNARA FILM';
                }
                return $label . ' - Oscar Ceremony - LUNARA FILM';
            }
        }

        if ($hub === 'category') {
            $cat = $this->resolve_category_slug($hub_id);
            if (!empty($cat)) {
                return $this->format_category_display($cat) . ' - Oscar Category - LUNARA FILM';
            }
        }

        if ($hub === 'ceremonies') {
            return 'Oscar Ceremonies - LUNARA FILM';
        }

        if ($hub === 'categories') {
            return 'Oscar Categories - LUNARA FILM';
        }

        if ($hub === 'about') {
            return 'About the Oscar Ledger - LUNARA FILM';
        }

        return $title;
    }

    /**
     * Ordinal helper (97 => 97th)
     */
    public function ordinal($n) {
        $n = intval($n);
        if ($n <= 0) return '';
        $s = array('th', 'st', 'nd', 'rd');
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }

    /**
     * Get the ceremony year label for a ceremony number.
     */
    public function get_ceremony_year($ceremony) {
        global $wpdb;
        $ceremonies_table = $this->get_ceremonies_table_name();
        $source_table = $this->get_table_name();
        $this->ensure_projection_data_available();
        $ceremony = intval($ceremony);
        if ($ceremony <= 0) return '';
        static $runtime_cache = array();
        if (array_key_exists($ceremony, $runtime_cache)) {
            return $runtime_cache[$ceremony];
        }
        $cache_key = 'aat_ceremony_year_v1_' . $ceremony;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $runtime_cache[$ceremony] = is_string($cached) ? $cached : '';
            return $runtime_cache[$ceremony];
        }
        $sql = $wpdb->prepare("SELECT year_label FROM $ceremonies_table WHERE ceremony = %d LIMIT 1", $ceremony);
        $year = $wpdb->get_var($sql);
        if (!is_string($year) || $year === '') {
            $legacy_sql = $wpdb->prepare("SELECT MIN(year) FROM $source_table WHERE ceremony = %d", $ceremony);
            $year = $wpdb->get_var($legacy_sql);
        }
        $year = is_string($year) ? $year : '';
        $runtime_cache[$ceremony] = $year;
        set_transient($cache_key, $year, 6 * HOUR_IN_SECONDS);
        return $year;
    }


    /**
     * Get the latest ceremony number in the dataset.
     */
    public function get_max_ceremony() {
        global $wpdb;
        $ceremonies_table = $this->get_ceremonies_table_name();
        $source_table = $this->get_table_name();
        $this->ensure_projection_data_available();
        $cached = get_transient('aat_max_ceremony_v1');
        if ($cached !== false) {
            return intval($cached);
        }
        $max = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $ceremonies_table"));
        if ($max <= 0) {
            $max = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $source_table"));
        }
        set_transient('aat_max_ceremony_v1', $max, 6 * HOUR_IN_SECONDS);
        return $max;
    }

    /**
     * Get the latest year label in the dataset (based on max ceremony).
     */
    public function get_latest_year_label() {
        global $wpdb;
        $ceremonies_table = $this->get_ceremonies_table_name();
        $source_table = $this->get_table_name();
        $this->ensure_projection_data_available();
        $cached = get_transient('aat_latest_year_label_v1');
        if ($cached !== false) {
            return is_string($cached) ? $cached : '';
        }
        $year = (string) $wpdb->get_var("SELECT year_label FROM $ceremonies_table ORDER BY ceremony DESC LIMIT 1");
        if ($year === '') {
            $row = $wpdb->get_row("SELECT year FROM $source_table ORDER BY ceremony DESC, id DESC LIMIT 1", ARRAY_A);
            $year = is_array($row) ? (string) ($row['year'] ?? '') : '';
        }
        set_transient('aat_latest_year_label_v1', $year, 6 * HOUR_IN_SECONDS);
        return $year;
    }

    /**
     * Convert canonical categories to a friendlier display label (for common entries).
     */
    public function format_category_display($canonical_category) {
        $cat = (string) $canonical_category;
        if ($cat === '') return '';
        $map = array(
            'ACTOR IN A LEADING ROLE' => 'Best Actor',
            'ACTRESS IN A LEADING ROLE' => 'Best Actress',
            'ACTOR IN A SUPPORTING ROLE' => 'Best Supporting Actor',
            'ACTRESS IN A SUPPORTING ROLE' => 'Best Supporting Actress',
            'BEST PICTURE' => 'Best Picture',
            'DIRECTING' => 'Best Director',
        );
        if (isset($map[$cat])) {
            return $map[$cat];
        }
        return $cat;
    }

    /**
     * Resolve a category slug (sanitize_title(canonical_category)) back to canonical_category.
     */
    public function resolve_category_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if ($slug === '') return '';

        $cache_key = 'aat_category_slug_map_v1';
        $map = get_transient($cache_key);
        if (!is_array($map)) {
            $cats = $this->get_projection_categories_list();
            $map = array();
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    $cat = (string) $cat;
                    $s = sanitize_title($cat);
                    if ($s && !isset($map[$s])) {
                        $map[$s] = $cat;
                    }
                }
            }
            set_transient($cache_key, $map, 12 * HOUR_IN_SECONDS);
        }

        return isset($map[$slug]) ? (string) $map[$slug] : '';
    }

    /**
     * Helper: create a safe WHERE clause for pipe-delimited id fields.
     */
    private function build_pipe_match_where($field, $id, &$values) {
        $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
        $id = (string) $id;
        $values = array(
            $id,
            $id . '|%',
            '%|' . $id . '|%',
            '%|' . $id,
        );
        return "($field = %s OR $field LIKE %s OR $field LIKE %s OR $field LIKE %s)";
    }

    /**
     * Query rows for an entity.
     */
    public function get_entity_rows($entity, $id) {
        global $wpdb;
        $table_name = $this->get_table_name();
        $facts_table = $this->get_award_facts_table_name();
        $nominees_table = $this->get_award_nominees_table_name();

        $entity = sanitize_text_field($entity);
        $id = strtolower(trim((string) sanitize_text_field($id)));

        if (!in_array($entity, array('title', 'name', 'company'), true)) {
            return array();
        }

        if ($entity === 'title' && !$this->is_title_entity_id($id)) {
            return array();
        }
        if ($entity === 'name' && !$this->is_name_entity_id($id)) {
            return array();
        }
        if ($entity === 'company' && !$this->is_company_entity_id($id)) {
            return array();
        }

        $cache_key = 'aat_entity_rows_v2_' . md5($entity . ':' . $id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $fields = $this->get_awards_row_fields_sql();
        $qualified_fields = implode(', ', array_map(function($field) {
            return 'a.' . trim((string) $field);
        }, explode(',', $fields)));
        if ($entity === 'title') {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT $qualified_fields
                 FROM $table_name a
                 INNER JOIN $facts_table f ON f.source_award_id = a.id
                 WHERE f.film_entity_id = %s
                 ORDER BY a.ceremony DESC, a.canonical_category ASC, a.winner DESC, a.film ASC, a.name ASC",
                $id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT DISTINCT $qualified_fields
                 FROM $table_name a
                 INNER JOIN $nominees_table n ON n.source_award_id = a.id
                 WHERE n.entity_id = %s
                 ORDER BY a.ceremony DESC, a.canonical_category ASC, a.winner DESC, a.film ASC, a.name ASC",
                $id
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        // Projection-first reads are fastest, but source fallback keeps entity pages connected
        // if a projection table lags behind a recent import/update.
        if (empty($rows)) {
            $search_like = '%' . $wpdb->esc_like($id) . '%';

            if ($entity === 'title') {
                $source_sql = $wpdb->prepare(
                    "SELECT DISTINCT $fields
                     FROM $table_name
                     WHERE film_id LIKE %s
                     ORDER BY ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC",
                    $search_like
                );
            } else {
                $source_sql = $wpdb->prepare(
                    "SELECT DISTINCT $fields
                     FROM $table_name
                     WHERE nominee_ids LIKE %s
                     ORDER BY ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC",
                    $search_like
                );
            }

            $source_rows = $wpdb->get_results($source_sql, ARRAY_A);
            if (is_array($source_rows) && !empty($source_rows)) {
                foreach ($source_rows as $source_row) {
                    $normalized = $this->normalize_awards_row($source_row);
                    if ($this->normalized_row_contains_entity($normalized, $entity, $id)) {
                        $rows[] = $normalized;
                    }
                }
            }
        }

        foreach ($rows as $index => $row) {
            if (!isset($row['ceremony']) || !isset($row['canonical_category'])) {
                $row = $this->normalize_awards_row($row);
            }
            $rows[$index] = $row;
        }

        set_transient($cache_key, $rows, HOUR_IN_SECONDS);
        return $rows;
    }

    private function normalized_row_contains_entity($row, $entity, $id) {
        $row = is_array($row) ? $row : array();
        $entity = sanitize_text_field($entity);
        $id = strtolower(trim((string) $id));
        if ($id === '') {
            return false;
        }

        if ($entity === 'title') {
            $film_ids = $this->extract_entity_reference_ids((string) ($row['film_id'] ?? ''));
            return in_array($id, $film_ids, true);
        }

        if ($entity === 'name' || $entity === 'company') {
            $nominee_ids = $this->extract_entity_reference_ids((string) ($row['nominee_ids'] ?? ''));
            return in_array($id, $nominee_ids, true);
        }

        return false;
    }

    /**
     * Determine a display name for an entity using the dataset.
     */
    public function get_entity_display_name($entity, $id) {
        $entity = sanitize_text_field($entity);
        $id = strtolower(trim((string) sanitize_text_field($id)));

        $cache_key = 'aat_entity_label_' . md5($entity . ':' . $id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (string) $cached;
        }

        $label = $this->get_projected_entity_label($id);
        if ($label !== '') {
            set_transient($cache_key, $label, 12 * HOUR_IN_SECONDS);
            return $label;
        }

        $rows = $this->get_entity_rows($entity, $id);
        $label = '';

        if (!empty($rows)) {
            $first = $rows[0];
            if ($entity === 'title') {
                $label = $this->map_pipe_value_to_id($first['film'] ?? '', $first['film_id'] ?? '', $id);
            } else {
                $label = $this->map_pipe_value_to_id($first['nominees'] ?? '', $first['nominee_ids'] ?? '', $id);
                if (!$label) {
                    // Fallback: sometimes the Name field contains the person string.
                    $label = (string) ($first['name'] ?? '');
                }
            }
        }

        $label = trim((string) $label);
        set_transient($cache_key, $label, 12 * HOUR_IN_SECONDS);
        return $label;
    }

    /**
     * Map a pipe-delimited value list to the matching pipe-delimited id list.
     */
    public function map_pipe_value_to_id($value_list, $id_list, $target_id) {
        $values = array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen');
        $ids = array_filter(array_map('trim', explode('|', (string) $id_list)), 'strlen');
        $target_id = trim((string) $target_id);

        if (!empty($values) && count($values) === count($ids)) {
            foreach ($ids as $idx => $id) {
                if ($id === $target_id && isset($values[$idx])) {
                    return (string) $values[$idx];
                }
            }
        }

        // Do not guess. A mismatched list can easily return the wrong nominee label.
        return '';
    }

    /**
     * Detect whether a shortcode explicitly requests immediate table autoloading.
     */
    private function shortcode_requests_autoload($content, $tag) {
        $content = (string) $content;
        $tag = trim((string) $tag);

        if ($content === '' || $tag === '') {
            return false;
        }

        $pattern = '/\[' . preg_quote($tag, '/') . '\b[^\]]*autoload\s*=\s*(["\']?)(true|1)\1/i';
        return preg_match($pattern, $content) === 1;
    }

    /**
     * Detect whether a database/tracker block needs immediate table assets.
     */
    private function block_requests_autoload($content, $block_name) {
        $content = (string) $content;
        $block_name = trim((string) $block_name);

        if ($content === '' || $block_name === '' || !function_exists('parse_blocks')) {
            return false;
        }

        $check = function($blocks) use (&$check, $block_name) {
            foreach ((array) $blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                if (($block['blockName'] ?? '') === $block_name) {
                    $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
                    if (!empty($attrs['autoload'])) {
                        return true;
                    }
                    if (($attrs['layout'] ?? '') === 'embedded') {
                        return true;
                    }
                }

                if (!empty($block['innerBlocks']) && $check($block['innerBlocks'])) {
                    return true;
                }
            }

            return false;
        };

        return $check(parse_blocks($content));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Load assets only on:
        //  - pages that contain the [academy_awards] shortcode, OR
        //  - our internal Film/Person/Company pages, OR
        //  - our hub pages (Ceremony/Category/Index/About)
        $is_entity = $this->is_entity_request();
        $is_hub = $this->is_hub_request();
        $hub = $is_hub ? sanitize_text_field(get_query_var('aat_hub')) : '';
        $hub_needs_table = false;
        $table_view_requested = isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'table';
        if ($is_hub && in_array($hub, array('ceremony', 'category'), true)) {
            $hub_needs_table = $table_view_requested;
        }

        $is_table_page = false;
        $is_ballot_page = false;
        $is_tracker_v2_page = false;
        $table_shortcode_autoload = false;

        if (!$is_entity) {
            global $post;
            if ($post instanceof WP_Post) {
                $page_template = get_page_template_slug($post);
                $is_main_oscars_page = (
                    $page_template === 'page-oscars.php' ||
                    is_page('oscars') ||
                    (isset($post->post_name) && $post->post_name === 'oscars')
                );

                $is_table_page = (
                    $is_main_oscars_page ||
                    has_block('academy-awards/database', $post) ||
                    has_block('academy-awards/tracker', $post)
                );
                $has_tracker_table_shortcode = has_shortcode($post->post_content, 'lunara_awards_tracker') || has_block('academy-awards/tracker', $post);

                $is_tracker_v2_page = (
                    has_shortcode($post->post_content, 'lunara_awards_tracker_v2') ||
                    has_shortcode($post->post_content, 'academy_awards_tracker_v2') ||
                    has_block('academy-awards/tracker-v2', $post)
                );

                $is_ballot_page = (
                    has_shortcode($post->post_content, 'lunara_oscar_ballot') ||
                    has_shortcode($post->post_content, 'academy_awards_ballot') ||
                    has_block('academy-awards/ballot', $post)
                );

                $table_shortcode_autoload = $has_tracker_table_shortcode || (
                    $this->shortcode_requests_autoload($post->post_content, 'academy_awards') ||
                    $this->shortcode_requests_autoload($post->post_content, 'lunara_awards_tracker') ||
                    $this->block_requests_autoload($post->post_content, 'academy-awards/database') ||
                    $this->block_requests_autoload($post->post_content, 'academy-awards/tracker')
                );

                if ($is_main_oscars_page && $table_view_requested) {
                    $table_shortcode_autoload = true;
                }
            }
        }

        // Allow themes/site owners to force-load assets where needed.
        if (apply_filters('aat_force_enqueue_assets', false) === true) {
            $is_table_page = true;
            $table_shortcode_autoload = true;
        }

        if (!$is_entity && !$is_hub && !$is_table_page && !$is_tracker_v2_page && !$is_ballot_page) {
            return;
        }

        $aat_stylesheet_path = AAT_PLUGIN_DIR . 'assets/css/academy-awards-table.css';
        $aat_stylesheet_version = file_exists($aat_stylesheet_path) ? (string) filemtime($aat_stylesheet_path) : AAT_VERSION;

        // Always load plugin styles for the table, entity pages, and hub pages.
        // Only load DataTables and the plugin JS when we are actually rendering a table.
        if ($hub_needs_table || $table_view_requested || $table_shortcode_autoload) {
            // DataTables CSS
            wp_enqueue_style(
                'datatables-css',
                'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
                array(),
                '1.13.7'
            );

            wp_enqueue_style(
                'datatables-responsive-css',
                'https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css',
                array('datatables-css'),
                '2.5.0'
            );

            // Plugin CSS
            wp_enqueue_style(
                'aat-styles',
                AAT_PLUGIN_URL . 'assets/css/academy-awards-table.css',
                array('datatables-css'),
                $aat_stylesheet_version
            );
            $this->enqueue_theme_route_assets(array('aat-styles'));

            // jQuery (WordPress includes this)
            wp_enqueue_script('jquery');

            // DataTables JS
            wp_enqueue_script(
                'datatables-js',
                'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
                array('jquery'),
                '1.13.7',
                true
            );

            wp_enqueue_script(
                'datatables-responsive-js',
                'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js',
                array('datatables-js'),
                '2.5.0',
                true
            );

            // Plugin JS
            wp_enqueue_script(
                'aat-script',
                AAT_PLUGIN_URL . 'assets/js/academy-awards-table.js',
                array('jquery', 'datatables-js', 'datatables-responsive-js'),
                AAT_VERSION,
                true
            );

            // Localize script
            wp_localize_script('aat-script', 'aatData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aat_nonce'),
                'entityBase' => $this->get_entity_base_url(),
            ));
        } else {
            // Entity pages + hub pages (non-table): just the plugin styling (keeps pages light and fast).
            wp_enqueue_style(
                'aat-styles',
                AAT_PLUGIN_URL . 'assets/css/academy-awards-table.css',
                array(),
                $aat_stylesheet_version
            );
            $this->enqueue_theme_route_assets(array('aat-styles'));

            // Tracker V2 page (no DataTables required)
            if ($is_tracker_v2_page) {
                wp_enqueue_script(
                    'aat-tracker-v2',
                    AAT_PLUGIN_URL . 'assets/js/tracker-v2.js',
                    array('jquery'),
                    AAT_VERSION,
                    true
                );

                wp_localize_script('aat-tracker-v2', 'aatTracker', array(
                    'entityBase' => $this->get_entity_base_url(),
                    'databaseUrl' => $this->get_database_url(),
                ));
            }

            if ($is_ballot_page) {
                wp_enqueue_style(
                    'aat-ballot-styles',
                    AAT_PLUGIN_URL . 'assets/css/ballot.css',
                    array('aat-styles'),
                    AAT_VERSION
                );

                wp_enqueue_script(
                    'aat-ballot',
                    AAT_PLUGIN_URL . 'assets/js/ballot.js',
                    array(),
                    AAT_VERSION,
                    true
                );

                wp_localize_script('aat-ballot', 'aatBallot', array(
                    'copySuccess' => __('Ballot picks copied to clipboard.', 'academy-awards-table'),
                    'copyError' => __('Copy failed. You can still select and save picks on this device.', 'academy-awards-table'),
                    'resetConfirm' => __('Clear all saved Will Win and Should Win picks for this ballot?', 'academy-awards-table'),
                ));
            }
        }

        if ($is_hub) {
            wp_add_inline_style(
                'aat-styles',
                '.aat-hub-page .aat-category-latest-winner .aat-hub-inline-link,.aat-hub-page .aat-category-history .aat-hub-inline-link,.aat-hub-page .aat-category-history .aat-entity-link{align-items:center!important;display:inline-flex!important;line-height:1.25!important;min-height:32px!important;padding-block:3px!important;text-underline-offset:4px}.aat-hub-page .aat-category-latest-winner .aat-hub-inline-link-title,.aat-hub-page .aat-category-history .aat-hub-inline-link-title{min-height:34px!important}.aat-hub-page .aat-category-history .aat-timeline-link,.aat-hub-page .aat-category-history .aat-decade-pill,.aat-hub-page .aat-category-history .aat-nominee-trail-summary,.aat-hub-page .aat-category-history .aat-winner-circle-action,.aat-hub-page .aat-category-latest-winner .aat-hub-chip{line-height:1.25!important;min-height:34px!important}.aat-hub-page .aat-category-history .aat-decade-pill{padding-block:7px!important}.aat-hub-page .aat-category-history .aat-nominee-trail-actions .aat-winner-circle-action{min-height:34px!important;padding:7px 10px!important}'
            );
        }
    }

    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts($hook) {
        /**
         * IMPORTANT (WordPress.com / managed hosts):
         * The $hook suffix can vary depending on how the admin menu is registered.
         * If we rely only on $hook matching, our admin JS may never load, which makes
         * the import buttons appear to "do nothing".
         *
         * Safer: gate by the `page` query var (our menu slugs) instead.
         */
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $allowed_pages = array('academy-awards-table', 'academy-awards-tracker', 'academy-awards-posters', 'academy-awards-person-portraits', 'academy-awards-omdb-audit', 'academy-awards-ceremony-writeups');

        if (!in_array($page, $allowed_pages, true)) {
            return;
        }

        // Media library is needed on Poster Library screen
        // Media library is needed on Poster Library screen
        if ($page === 'academy-awards-posters') {
            wp_enqueue_media();
        }


        wp_enqueue_style(
            'aat-admin-styles',
            AAT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AAT_VERSION
        );

        wp_enqueue_script(
            'aat-admin-script',
            AAT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            AAT_VERSION,
            true
        );

        wp_localize_script('aat-admin-script', 'aatAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aat_admin_nonce'),
            'entityBase' => $this->get_entity_base_url(),
            'databaseUrl' => $this->get_database_url(),
        ));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Academy Awards Table', 'academy-awards-table'),
            __('Academy Awards', 'academy-awards-table'),
            'manage_options',
            'academy-awards-table',
            array($this, 'render_admin_page'),
            'dashicons-awards',
            30
        );

        add_submenu_page(
            'academy-awards-table',
            __('Awards Tracker (V2)', 'academy-awards-table'),
            __('Awards Tracker', 'academy-awards-table'),
            'manage_options',
            'academy-awards-tracker',
            array($this, 'render_tracker_admin_page')
        );

        add_submenu_page(
            'academy-awards-table',
            __('Poster Library', 'academy-awards-table'),
            __('Poster Library', 'academy-awards-table'),
            'manage_options',
            'academy-awards-posters',
            array($this, 'render_poster_admin_page')
        );

        add_submenu_page(
            'academy-awards-table',
            __('Person Portrait Queue', 'academy-awards-table'),
            __('Person Portrait Queue', 'academy-awards-table'),
            'manage_options',
            'academy-awards-person-portraits',
            array($this, 'render_person_portrait_import_admin_page')
        );

        add_submenu_page(
            'academy-awards-table',
            __('OMDb Integrity Audit', 'academy-awards-table'),
            __('OMDb Audit', 'academy-awards-table'),
            'manage_options',
            'academy-awards-omdb-audit',
            array($this, 'render_omdb_audit_admin_page')
        );

        add_submenu_page(
            'academy-awards-table',
            __('Ceremony Write-Ups', 'academy-awards-table'),
            __('Ceremony Write-Ups', 'academy-awards-table'),
            'manage_options',
            'academy-awards-ceremony-writeups',
            array($this, 'render_ceremony_writeups_admin_page')
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';
        $stats = $this->get_total_awards_stats($table_name);
        $total_records = $stats['records_total'];
        $total_winners = $stats['winners_total'];
        $projection_counts = $this->get_projection_total_counts();
        $categories = intval($projection_counts['categories']);
        $years = intval($projection_counts['ceremonies']);

        include AAT_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Render Tracker V2 admin page
     */
    public function render_tracker_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'academy-awards-table'));
        }

        global $wpdb;
        $awards_table = $wpdb->prefix . 'academy_awards';
        $tracker_table = $wpdb->prefix . 'aat_tracker';

        $ceremonies = $this->get_projection_ceremonies_list();
        $categories = $this->get_projection_categories_list();

        $selected_ceremony = isset($_GET['ceremony']) ? intval($_GET['ceremony']) : 0;
        if ($selected_ceremony <= 0) {
            $selected_ceremony = $this->get_max_ceremony();
        }

        $year_label = $this->get_ceremony_year($selected_ceremony);

        $picks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $tracker_table WHERE ceremony = %d ORDER BY canonical_category ASC, tier ASC, rank ASC, updated_at DESC",
                $selected_ceremony
            ),
            ARRAY_A
        );
        if (!is_array($picks)) $picks = array();

        include AAT_PLUGIN_DIR . 'templates/tracker-admin.php';
    }

    /**
     * Render Poster Library admin page
     */
    public function render_poster_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'academy-awards-table'));
        }

        $message = '';
        $message_type = 'success';
        $company_credit_preview_result = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_poster_api_settings_nonce'])) {
            check_admin_referer('aat_poster_api_settings', 'aat_poster_api_settings_nonce');

            if (!empty($_POST['aat_omdb_clear_key']) || !empty($_POST['aat_tmdb_clear_key'])) {
                if (!empty($_POST['aat_omdb_clear_key'])) {
                    delete_option('aat_omdb_api_key');
                }
                if (!empty($_POST['aat_tmdb_clear_key'])) {
                    delete_option('aat_tmdb_api_key');
                }
                $message = __('API key updates saved. Cleared selected keys from WordPress options.', 'academy-awards-table');
            } elseif (
                (isset($_POST['aat_omdb_api_key']) && trim((string) wp_unslash($_POST['aat_omdb_api_key'])) !== '') ||
                (isset($_POST['aat_tmdb_api_key']) && trim((string) wp_unslash($_POST['aat_tmdb_api_key'])) !== '')
            ) {
                if (isset($_POST['aat_omdb_api_key']) && trim((string) wp_unslash($_POST['aat_omdb_api_key'])) !== '') {
                    update_option('aat_omdb_api_key', sanitize_text_field(wp_unslash($_POST['aat_omdb_api_key'])), false);
                }
                if (isset($_POST['aat_tmdb_api_key']) && trim((string) wp_unslash($_POST['aat_tmdb_api_key'])) !== '') {
                    update_option('aat_tmdb_api_key', sanitize_text_field(wp_unslash($_POST['aat_tmdb_api_key'])), false);
                }
                $message = __('API keys saved. They are stored in WordPress options, not committed to the plugin repository.', 'academy-awards-table');
            } else {
                $message = __('No key change was made.', 'academy-awards-table');
                $message_type = 'warning';
            }
        }

        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';

        $total_posters = intval($wpdb->get_var("SELECT COUNT(*) FROM $poster_table"));
        $rows = $wpdb->get_results("SELECT * FROM $poster_table ORDER BY updated_at DESC LIMIT 500", ARRAY_A);
        if (!is_array($rows)) $rows = array();

        $omdb_key_configured = $this->get_omdb_api_key() !== '';
        $tmdb_key_configured = $this->get_tmdb_api_key() !== '';
        $person_profile_audit = $this->get_person_profile_attachment_audit(120);

        include AAT_PLUGIN_DIR . 'templates/poster-admin.php';
    }

    /**
     * Render the private person portrait import queue.
     */
    public function render_person_portrait_import_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'academy-awards-table'));
        }

        $message = '';
        $message_type = 'success';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_person_credit_review_nonce'])) {
            check_admin_referer('aat_person_credit_review', 'aat_person_credit_review_nonce');

            $review_result = $this->save_person_credit_review_record_from_request($_POST);

            if (is_wp_error($review_result)) {
                $message = $review_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Person credit review saved. Correction remains deferred until the source row is explicitly approved for repair.', 'academy-awards-table');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_person_credit_row_review_nonce'])) {
            check_admin_referer('aat_person_credit_row_review', 'aat_person_credit_row_review_nonce');

            $row_review_result = $this->save_person_credit_row_review_record_from_request($_POST);

            if (is_wp_error($row_review_result)) {
                $message = $row_review_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Full-row person-credit review saved. Source nominee_ids remain unchanged until the guarded apply step is confirmed.', 'academy-awards-table');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_company_credit_row_review_nonce'])) {
            check_admin_referer('aat_company_credit_row_review', 'aat_company_credit_row_review_nonce');

            $company_row_review_result = $this->save_company_credit_row_review_record_from_request($_POST);

            if (is_wp_error($company_row_review_result)) {
                $message = $company_row_review_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Company/studio credit review saved. Source nominee_ids remain unchanged; this lane is annotation-only.', 'academy-awards-table');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_company_credit_row_preview_nonce'])) {
            check_admin_referer('aat_company_credit_row_preview', 'aat_company_credit_row_preview_nonce');

            $company_credit_preview_result = $this->build_company_credit_row_preview_from_request($_POST);

            if (is_wp_error($company_credit_preview_result)) {
                $message = $company_credit_preview_result->get_error_message();
                $message_type = 'error';
                $company_credit_preview_result = array();
            } elseif (empty($company_credit_preview_result['ready'])) {
                $message = (string) ($company_credit_preview_result['message'] ?? __('Company/studio preview is not ready.', 'academy-awards-table'));
                $message_type = 'error';
            } else {
                $message = sprintf(
                    __('Company/studio preview validated for award row #%1$d. No source rows were changed.', 'academy-awards-table'),
                    intval($company_credit_preview_result['source_award_id'] ?? 0)
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_person_credit_source_correction_nonce'])) {
            check_admin_referer('aat_person_credit_source_correction', 'aat_person_credit_source_correction_nonce');

            $correction_result = $this->apply_person_credit_source_correction_from_request($_POST);

            if (is_wp_error($correction_result)) {
                $message = $correction_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    __('Corrected award row #%1$d with %2$s and marked the person-credit review resolved.', 'academy-awards-table'),
                    intval($correction_result['source_award_id'] ?? 0),
                    esc_html((string) ($correction_result['proposed_person_id'] ?? ''))
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_person_credit_row_apply_nonce'])) {
            check_admin_referer('aat_person_credit_row_apply', 'aat_person_credit_row_apply_nonce');

            $row_apply_result = $this->apply_person_credit_full_row_source_correction_from_request($_POST);

            if (is_wp_error($row_apply_result)) {
                $message = $row_apply_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    __('Applied full-row source correction to award row #%1$d and rebuilt Oscars reporting tables.', 'academy-awards-table'),
                    intval($row_apply_result['source_award_id'] ?? 0)
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_existing_person_portrait_duplicate_resolve_nonce'])) {
            check_admin_referer('aat_existing_person_portrait_duplicate_resolve', 'aat_existing_person_portrait_duplicate_resolve_nonce');

            $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            $person_id = isset($_POST['person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['person_id'])))) : '';
            $confirm_person_id = isset($_POST['duplicate_confirm_person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['duplicate_confirm_person_id'])))) : '';
            $adoption_note = isset($_POST['adoption_note']) ? sanitize_textarea_field(wp_unslash($_POST['adoption_note'])) : '';
            $adoption_result = $this->adopt_existing_person_portrait_attachment($attachment_id, $person_id, $adoption_note, array(
                'allow_duplicate' => true,
                'confirm_person_id' => $confirm_person_id,
            ));

            if (is_wp_error($adoption_result)) {
                $message = $adoption_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    __('Resolved duplicate PEOPLE attachment #%1$d for %2$s as a verified local portrait.', 'academy-awards-table'),
                    intval($adoption_result['attachment_id'] ?? 0),
                    esc_html((string) ($adoption_result['person_id'] ?? $person_id))
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_existing_person_portrait_review_nonce'])) {
            check_admin_referer('aat_existing_person_portrait_review', 'aat_existing_person_portrait_review_nonce');

            $existing_review_result = $this->save_person_portrait_existing_review_record_from_request($_POST);

            if (is_wp_error($existing_review_result)) {
                $message = $existing_review_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Existing PEOPLE portrait review saved. Adoption still requires Approved To Adopt plus exact typed IMDb confirmation.', 'academy-awards-table');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_existing_person_portrait_adopt_nonce'])) {
            check_admin_referer('aat_existing_person_portrait_adopt', 'aat_existing_person_portrait_adopt_nonce');

            $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            $person_id = isset($_POST['person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['person_id'])))) : '';
            $confirm_person_id = isset($_POST['existing_confirm_person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['existing_confirm_person_id'])))) : '';
            $adoption_note = isset($_POST['adoption_note']) ? sanitize_textarea_field(wp_unslash($_POST['adoption_note'])) : '';
            $adoption_result = $this->adopt_existing_person_portrait_attachment($attachment_id, $person_id, $adoption_note, array(
                'require_confirmation' => true,
                'confirm_person_id' => $confirm_person_id,
            ));

            if (is_wp_error($adoption_result)) {
                $message = $adoption_result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    __('Adopted existing PEOPLE attachment #%1$d for %2$s as a verified local portrait.', 'academy-awards-table'),
                    intval($adoption_result['attachment_id'] ?? 0),
                    esc_html((string) ($adoption_result['person_id'] ?? $person_id))
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_person_portrait_import_nonce'])) {
            check_admin_referer('aat_person_portrait_import', 'aat_person_portrait_import_nonce');

            $person_id = isset($_POST['person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($_POST['person_id'])))) : '';
            $import_result = $this->import_tmdb_person_profile_portrait($person_id);

            if (is_wp_error($import_result)) {
                $message = $import_result->get_error_message();
                $message_type = 'error';
            } else {
                $attachment_id = intval($import_result['attachment_id'] ?? 0);
                $status = (string) ($import_result['status'] ?? 'imported');
                if ($status === 'existing') {
                    $message = sprintf(__('Existing verified portrait found for %1$s. Attachment #%2$d is already connected.', 'academy-awards-table'), esc_html($person_id), $attachment_id);
                } else {
                    $message = sprintf(__('Verified portrait imported for %1$s as attachment #%2$d.', 'academy-awards-table'), esc_html($person_id), $attachment_id);
                }
            }
        }

        $allowed_states = array('all', 'candidate_external', 'ready', 'needs_attention');
        $selected_state = isset($_GET['state']) ? sanitize_key(wp_unslash($_GET['state'])) : 'candidate_external';
        if (!in_array($selected_state, $allowed_states, true)) {
            $selected_state = 'candidate_external';
        }

        $selected_limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $selected_limit = max(1, min(200, $selected_limit));
        $selected_offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $selected_offset = max(0, $selected_offset);
        $refresh_tmdb = !empty($_GET['refresh_tmdb']);
        $ids_raw = isset($_GET['person_ids']) ? sanitize_textarea_field(wp_unslash($_GET['person_ids'])) : '';

        $queue = $this->get_person_portrait_import_queue_rows(array(
            'state' => $selected_state,
            'limit' => $selected_limit,
            'offset' => $selected_offset,
            'refresh_tmdb' => $refresh_tmdb,
            'ids_raw' => $ids_raw,
        ));

        $queue_rows = is_array($queue['rows'] ?? null) ? $queue['rows'] : array();
        $queue_summary = is_array($queue['summary'] ?? null) ? $queue['summary'] : array();
        $allowed_adoption_views = array('all', 'hold_review', 'needs_review', 'needs_source', 'wrong_label', 'approved', 'ready', 'duplicates', 'duplicate_groups', 'manual');
        $adoption_view = isset($_GET['adoption_view']) ? sanitize_key(wp_unslash($_GET['adoption_view'])) : 'all';
        if (!in_array($adoption_view, $allowed_adoption_views, true)) {
            $adoption_view = 'all';
        }

        $adoption_limit = isset($_GET['adoption_limit']) ? intval($_GET['adoption_limit']) : 24;
        $adoption_limit = max(1, min(60, $adoption_limit));
        $adoption_offset = isset($_GET['adoption_offset']) ? intval($_GET['adoption_offset']) : 0;
        $adoption_offset = max(0, $adoption_offset);
        $adoption = $this->get_existing_person_portrait_adoption_rows(array(
            'limit' => $adoption_limit,
            'offset' => $adoption_offset,
            'view' => $adoption_view,
        ));
        $adoption_rows = is_array($adoption['rows'] ?? null) ? $adoption['rows'] : array();
        $adoption_summary = is_array($adoption['summary'] ?? null) ? $adoption['summary'] : array();
        $person_credit_category = isset($_GET['person_credit_category']) ? sanitize_title(wp_unslash($_GET['person_credit_category'])) : 'sound-mixing';
        $person_credit_review_state = isset($_GET['person_credit_review_state']) ? sanitize_key(wp_unslash($_GET['person_credit_review_state'])) : 'all';
        $person_credit_limit = isset($_GET['person_credit_limit']) ? intval($_GET['person_credit_limit']) : 25;
        $person_credit_limit = max(1, min(100, $person_credit_limit));
        $person_credit_offset = isset($_GET['person_credit_offset']) ? intval($_GET['person_credit_offset']) : 0;
        $person_credit_offset = max(0, $person_credit_offset);
        $company_credit_category = isset($_GET['company_credit_category']) ? sanitize_title(wp_unslash($_GET['company_credit_category'])) : 'sound-mixing';
        $company_credit_review_state = isset($_GET['company_credit_review_state']) ? sanitize_key(wp_unslash($_GET['company_credit_review_state'])) : 'all';
        $company_credit_entity_kind = isset($_GET['company_credit_entity_kind']) ? sanitize_key(wp_unslash($_GET['company_credit_entity_kind'])) : 'all';
        $company_credit_limit = isset($_GET['company_credit_limit']) ? intval($_GET['company_credit_limit']) : 25;
        $company_credit_limit = max(1, min(100, $company_credit_limit));
        $company_credit_offset = isset($_GET['company_credit_offset']) ? intval($_GET['company_credit_offset']) : 0;
        $company_credit_offset = max(0, $company_credit_offset);
        $person_credit_review_states = $this->get_person_credit_review_states();
        $person_credit_row_review_states = $this->get_person_credit_row_review_states();
        $person_credit_review_filter_labels = $this->get_person_credit_review_filter_labels();
        $person_portrait_existing_review_states = $this->get_person_portrait_existing_review_states();
        $person_portrait_existing_issue_types = $this->get_person_portrait_existing_issue_types();
        $company_credit_review_states = $this->get_company_credit_row_review_states();
        $company_credit_review_filter_labels = $this->get_company_credit_review_filter_labels();
        $company_credit_entity_kinds = $this->get_company_credit_entity_kinds();
        $company_credit_entity_filter_labels = $this->get_company_credit_entity_filter_labels();
        $person_credit_queue = $this->get_person_credit_review_queue_rows(array(
            'category' => $person_credit_category,
            'review_state' => $person_credit_review_state,
            'limit' => $person_credit_limit,
            'offset' => $person_credit_offset,
        ));
        $person_credit_rows = is_array($person_credit_queue['rows'] ?? null) ? $person_credit_queue['rows'] : array();
        $person_credit_summary = is_array($person_credit_queue['summary'] ?? null) ? $person_credit_queue['summary'] : array();
        $company_credit_queue = $this->get_company_credit_review_queue_rows(array(
            'category' => $company_credit_category,
            'review_state' => $company_credit_review_state,
            'entity_kind' => $company_credit_entity_kind,
            'limit' => $company_credit_limit,
            'offset' => $company_credit_offset,
        ));
        $company_credit_rows = is_array($company_credit_queue['rows'] ?? null) ? $company_credit_queue['rows'] : array();
        $company_credit_summary = is_array($company_credit_queue['summary'] ?? null) ? $company_credit_queue['summary'] : array();
        $tmdb_key_configured = $this->get_tmdb_api_key() !== '';

        include AAT_PLUGIN_DIR . 'templates/person-portrait-import-admin.php';
    }

    /**
     * Render the read-only OMDb integrity audit page.
     */
    public function render_omdb_audit_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'academy-awards-table'));
        }

        $message = '';
        $message_type = 'success';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_omdb_settings_nonce'])) {
            check_admin_referer('aat_omdb_settings', 'aat_omdb_settings_nonce');

            if (!empty($_POST['aat_omdb_clear_key']) || !empty($_POST['aat_tmdb_clear_key'])) {
                if (!empty($_POST['aat_omdb_clear_key'])) {
                    delete_option('aat_omdb_api_key');
                }
                if (!empty($_POST['aat_tmdb_clear_key'])) {
                    delete_option('aat_tmdb_api_key');
                }
                $message = __('API key updates saved. Cleared selected keys from WordPress options.', 'academy-awards-table');
            } elseif (
                (isset($_POST['aat_omdb_api_key']) && trim((string) wp_unslash($_POST['aat_omdb_api_key'])) !== '') ||
                (isset($_POST['aat_tmdb_api_key']) && trim((string) wp_unslash($_POST['aat_tmdb_api_key'])) !== '')
            ) {
                if (isset($_POST['aat_omdb_api_key']) && trim((string) wp_unslash($_POST['aat_omdb_api_key'])) !== '') {
                    $key = sanitize_text_field(wp_unslash($_POST['aat_omdb_api_key']));
                    update_option('aat_omdb_api_key', $key, false);
                }
                if (isset($_POST['aat_tmdb_api_key']) && trim((string) wp_unslash($_POST['aat_tmdb_api_key'])) !== '') {
                    $tmdb_key = sanitize_text_field(wp_unslash($_POST['aat_tmdb_api_key']));
                    update_option('aat_tmdb_api_key', $tmdb_key, false);
                }
                $message = __('API keys saved. They are stored in WordPress options, not committed to the plugin repository.', 'academy-awards-table');
            } else {
                $message = __('No key change was made.', 'academy-awards-table');
                $message_type = 'warning';
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_omdb_correction_nonce'])) {
            check_admin_referer('aat_omdb_correction', 'aat_omdb_correction_nonce');

            $result = $this->apply_omdb_verified_bad_id_correction_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    /* translators: 1: old IMDb ID, 2: new IMDb ID, 3: number of rows updated */
                    __('Applied OMDb candidate correction %1$s -> %2$s across %3$d Oscar rows. Review state is now Resolved.', 'academy-awards-table'),
                    (string) ($result['current_imdb_id'] ?? ''),
                    (string) ($result['candidate_imdb_id'] ?? ''),
                    (int) ($result['updated_rows'] ?? 0)
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_omdb_poster_review_nonce'])) {
            check_admin_referer('aat_omdb_poster_review', 'aat_omdb_poster_review_nonce');

            $result = $this->save_omdb_poster_review_record_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('OMDb poster review state saved.', 'academy-awards-table');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_omdb_poster_import_nonce'])) {
            check_admin_referer('aat_omdb_poster_import', 'aat_omdb_poster_import_nonce');

            $result = $this->import_omdb_poster_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    /* translators: 1: IMDb title ID, 2: attachment ID */
                    __('Imported OMDb poster for %1$s as attachment %2$d and mapped it in the Poster Library.', 'academy-awards-table'),
                    (string) ($result['imdb_id'] ?? ''),
                    (int) ($result['attachment_id'] ?? 0)
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_omdb_review_nonce'])) {
            check_admin_referer('aat_omdb_review', 'aat_omdb_review_nonce');

            $result = $this->save_omdb_review_record_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('OMDb review state saved.', 'academy-awards-table');
            }
        }

        $limit = isset($_GET['limit']) ? absint($_GET['limit']) : 25;
        if ($limit <= 0) {
            $limit = 25;
        }
        $limit = min(100, $limit);

        $offset = isset($_GET['offset']) ? absint($_GET['offset']) : 0;
        $force_refresh = isset($_GET['refresh']) && current_user_can('manage_options');
        $issue_filter = isset($_GET['issue']) ? sanitize_key(wp_unslash($_GET['issue'])) : 'all';
        $review_state_filter = isset($_GET['review_state']) ? sanitize_key(wp_unslash($_GET['review_state'])) : 'all';
        $scan_limit = isset($_GET['scan']) ? absint($_GET['scan']) : 250;
        if ($scan_limit <= 0) {
            $scan_limit = 250;
        }
        $scan_limit = min(1000, $scan_limit);

        $omdb_key_configured = $this->get_omdb_api_key() !== '';
        $tmdb_key_configured = $this->get_tmdb_api_key() !== '';
        $omdb_review_states = $this->get_omdb_review_states();
        $omdb_review_filter_labels = $this->get_omdb_review_filter_labels();
        $omdb_poster_review_states = $this->get_omdb_poster_review_states();
        $audit = $this->build_omdb_integrity_audit($limit, $offset, $force_refresh, $issue_filter, $scan_limit, $review_state_filter);

        include AAT_PLUGIN_DIR . 'templates/omdb-audit-admin.php';
    }

    public function render_ceremony_writeups_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'academy-awards-table'));
        }

        $this->maybe_create_ceremony_writeups_table();

        $message = '';
        $message_type = 'success';
        $preview = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_ceremony_writeups_upload_nonce'])) {
            check_admin_referer('aat_ceremony_writeups_upload', 'aat_ceremony_writeups_upload_nonce');

            $result = $this->handle_ceremony_writeups_upload_from_request();
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $preview = $result;
                $message = sprintf(
                    /* translators: %s: detected ceremony count */
                    __('Parsed %s ceremony write-ups. Review the preview, then stage them as private drafts.', 'academy-awards-table'),
                    number_format_i18n((int) ($preview['summary']['detected'] ?? 0))
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_ceremony_writeups_stage_nonce'])) {
            check_admin_referer('aat_ceremony_writeups_stage', 'aat_ceremony_writeups_stage_nonce');

            $result = $this->stage_ceremony_writeups_preview_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = sprintf(
                    /* translators: %s: staged row count */
                    __('Staged %s ceremony write-ups as private drafts.', 'academy-awards-table'),
                    number_format_i18n((int) ($result['count'] ?? 0))
                );
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aat_ceremony_writeup_save_nonce'])) {
            check_admin_referer('aat_ceremony_writeup_save', 'aat_ceremony_writeup_save_nonce');

            $result = $this->save_ceremony_writeup_record_from_request($_POST);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $message_type = 'error';
            } else {
                $message = __('Ceremony write-up saved.', 'academy-awards-table');
            }
        }

        $filter_state = $this->get_ceremony_writeup_filter_state();
        $status_filter = (string) $filter_state['status'];
        $search = (string) $filter_state['search'];
        $rows = $this->get_ceremony_writeups_admin_rows($status_filter, $search);
        $counts = $this->get_ceremony_writeups_admin_counts($search);
        $selected_ceremony = isset($_GET['ceremony']) ? absint($_GET['ceremony']) : 0;
        if ($selected_ceremony <= 0 && !empty($rows[0]['ceremony_number'])) {
            $selected_ceremony = (int) $rows[0]['ceremony_number'];
        }
        $selected_row = $selected_ceremony > 0 ? $this->get_ceremony_writeup_record($selected_ceremony) : array();
        $statuses = $this->get_ceremony_writeup_status_labels();
        $status_filter_labels = array_merge(array('all' => __('All', 'academy-awards-table')), $statuses);
        ?>
        <div class="wrap aat-admin-page aat-ceremony-writeups-admin">
            <h1><?php echo esc_html__('Ceremony Write-Ups', 'academy-awards-table'); ?></h1>
            <p class="description"><?php echo esc_html__('Upload Dalton-authored ceremony guide copy from Word, preview the 98 parsed entries, stage private drafts, then approve one ceremony at a time for public display.', 'academy-awards-table'); ?></p>

            <?php if ($message !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($message_type === 'error' ? 'error' : 'success'); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <section class="aat-admin-panel">
                <h2><?php echo esc_html__('Upload Ceremony Guide DOCX', 'academy-awards-table'); ?></h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('aat_ceremony_writeups_upload', 'aat_ceremony_writeups_upload_nonce'); ?>
                    <input type="file" name="aat_ceremony_writeups_docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required />
                    <?php submit_button(__('Preview DOCX', 'academy-awards-table'), 'secondary', 'submit', false); ?>
                </form>
            </section>

            <?php if (!empty($preview['summary'])) : ?>
                <?php
                $summary = $preview['summary'];
                $preview_records = !empty($preview['records']) && is_array($preview['records']) ? $preview['records'] : array();
                ?>
                <section class="aat-admin-panel aat-ceremony-writeups-preview">
                    <h2><?php echo esc_html__('Import Preview', 'academy-awards-table'); ?></h2>
                    <div class="aat-admin-stat-grid">
                        <div><span><?php echo esc_html__('Detected', 'academy-awards-table'); ?></span><strong><?php echo esc_html(number_format_i18n((int) ($summary['detected'] ?? 0))); ?></strong></div>
                        <div><span><?php echo esc_html__('First', 'academy-awards-table'); ?></span><strong><?php echo esc_html((string) ($summary['first'] ?? '')); ?></strong></div>
                        <div><span><?php echo esc_html__('Last', 'academy-awards-table'); ?></span><strong><?php echo esc_html((string) ($summary['last'] ?? '')); ?></strong></div>
                        <div><span><?php echo esc_html__('Missing', 'academy-awards-table'); ?></span><strong><?php echo esc_html(empty($summary['missing']) ? '0' : implode(', ', array_map('intval', (array) $summary['missing']))); ?></strong></div>
                    </div>
                    <p><code><?php echo esc_html((string) ($preview['source_hash'] ?? '')); ?></code></p>
                    <ol class="aat-ceremony-writeups-preview-list">
                        <?php foreach (array_slice($preview_records, 0, 6, true) as $preview_record) : ?>
                            <li>
                                <strong><?php echo esc_html((string) ($preview_record['ceremony_label'] ?? '')); ?></strong>
                                <span><?php echo esc_html(wp_trim_words((string) ($preview_record['body'] ?? ''), 28)); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <form method="post">
                        <?php wp_nonce_field('aat_ceremony_writeups_stage', 'aat_ceremony_writeups_stage_nonce'); ?>
                        <input type="hidden" name="aat_ceremony_writeups_source_hash" value="<?php echo esc_attr((string) ($preview['source_hash'] ?? '')); ?>" />
                        <?php submit_button(__('Stage 98 Drafts', 'academy-awards-table'), 'primary', 'submit', false); ?>
                    </form>
                </section>
            <?php endif; ?>

            <section class="aat-admin-panel aat-ceremony-writeups-grid">
                <div class="aat-ceremony-writeups-list">
                    <h2><?php echo esc_html__('Review Queue', 'academy-awards-table'); ?></h2>
                    <div class="aat-ceremony-writeups-toolbar">
                        <form class="aat-ceremony-writeups-filter-bar" method="get">
                            <input type="hidden" name="page" value="academy-awards-ceremony-writeups" />
                            <label for="aat_ceremony_writeup_status_filter">
                                <span><?php echo esc_html__('Status', 'academy-awards-table'); ?></span>
                                <select id="aat_ceremony_writeup_status_filter" name="aat_ceremony_writeup_status_filter">
                                    <?php foreach ($status_filter_labels as $status_key => $status_label) : ?>
                                        <option value="<?php echo esc_attr($status_key); ?>" <?php selected($status_filter, $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label for="aat_ceremony_writeup_search">
                                <span><?php echo esc_html__('Search', 'academy-awards-table'); ?></span>
                                <input id="aat_ceremony_writeup_search" name="aat_ceremony_writeup_search" type="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('ceremony, headline, note...', 'academy-awards-table'); ?>" />
                            </label>
                            <?php submit_button(__('Filter', 'academy-awards-table'), 'secondary', 'submit', false); ?>
                            <a class="button-link" href="<?php echo esc_url($this->get_ceremony_writeups_admin_url()); ?>"><?php echo esc_html__('Reset', 'academy-awards-table'); ?></a>
                        </form>
                        <div class="aat-ceremony-writeups-counts" aria-label="<?php echo esc_attr__('Ceremony write-up status counts', 'academy-awards-table'); ?>">
                            <?php foreach ($status_filter_labels as $status_key => $status_label) : ?>
                                <?php
                                $count_args = array('aat_ceremony_writeup_status_filter' => $status_key);
                                if ($search !== '') {
                                    $count_args['aat_ceremony_writeup_search'] = $search;
                                }
                                $count = (int) ($counts[$status_key] ?? 0);
                                ?>
                                <a class="aat-ceremony-writeups-count<?php echo $status_filter === $status_key ? ' is-active' : ''; ?>" href="<?php echo esc_url($this->get_ceremony_writeups_admin_url($count_args)); ?>">
                                    <span><?php echo esc_html($status_label); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($count)); ?></strong>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (empty($rows)) : ?>
                        <p><?php echo esc_html__('No ceremony write-ups match this queue view.', 'academy-awards-table'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr><th><?php echo esc_html__('Ceremony', 'academy-awards-table'); ?></th><th><?php echo esc_html__('Status', 'academy-awards-table'); ?></th><th><?php echo esc_html__('Updated', 'academy-awards-table'); ?></th><th><?php echo esc_html__('Links', 'academy-awards-table'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row) : ?>
                                    <?php $row_ceremony = (int) ($row['ceremony_number'] ?? 0); ?>
                                    <tr<?php echo $row_ceremony === $selected_ceremony ? ' class="is-selected"' : ''; ?>>
                                        <td>
                                            <a href="<?php echo esc_url($this->get_ceremony_writeups_admin_url(array('ceremony' => $row_ceremony, 'aat_ceremony_writeup_status_filter' => $status_filter, 'aat_ceremony_writeup_search' => $search))); ?>"><?php echo esc_html((string) ($row['ceremony_label'] ?? '')); ?></a>
                                            <?php if (!empty($row['headline'])) : ?>
                                                <span class="aat-ceremony-writeups-row-title"><?php echo esc_html((string) $row['headline']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="aat-ceremony-writeups-status is-<?php echo esc_attr(sanitize_html_class((string) ($row['status'] ?? 'draft'))); ?>"><?php echo esc_html($statuses[$row['status']] ?? (string) $row['status']); ?></span></td>
                                        <td><?php echo esc_html((string) ($row['updated_at'] ?? '')); ?></td>
                                        <td><a href="<?php echo esc_url($this->get_ceremony_url($row_ceremony)); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Preview route', 'academy-awards-table'); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="aat-ceremony-writeups-editor">
                    <h2><?php echo esc_html__('Edit Selected Ceremony', 'academy-awards-table'); ?></h2>
                    <?php if (empty($selected_row)) : ?>
                        <p><?php echo esc_html__('Select a staged ceremony write-up to edit.', 'academy-awards-table'); ?></p>
                    <?php else : ?>
                        <form method="post">
                            <?php wp_nonce_field('aat_ceremony_writeup_save', 'aat_ceremony_writeup_save_nonce'); ?>
                            <input type="hidden" name="aat_ceremony_writeup_ceremony" value="<?php echo esc_attr((string) $selected_ceremony); ?>" />
                            <input type="hidden" name="aat_ceremony_writeup_status_filter" value="<?php echo esc_attr($status_filter); ?>" />
                            <input type="hidden" name="aat_ceremony_writeup_search" value="<?php echo esc_attr($search); ?>" />
                            <p>
                                <label for="aat_ceremony_writeup_status"><strong><?php echo esc_html__('Status', 'academy-awards-table'); ?></strong></label><br />
                                <select id="aat_ceremony_writeup_status" name="aat_ceremony_writeup_status">
                                    <?php foreach ($statuses as $status_key => $status_label) : ?>
                                        <option value="<?php echo esc_attr($status_key); ?>" <?php selected((string) ($selected_row['status'] ?? ''), $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p>
                                <label for="aat_ceremony_writeup_headline"><strong><?php echo esc_html__('Headline', 'academy-awards-table'); ?></strong></label><br />
                                <input class="large-text" id="aat_ceremony_writeup_headline" name="aat_ceremony_writeup_headline" type="text" value="<?php echo esc_attr((string) ($selected_row['headline'] ?? '')); ?>" />
                            </p>
                            <p>
                                <label for="aat_ceremony_writeup_dek"><strong><?php echo esc_html__('Deck', 'academy-awards-table'); ?></strong></label><br />
                                <input class="large-text" id="aat_ceremony_writeup_dek" name="aat_ceremony_writeup_dek" type="text" value="<?php echo esc_attr((string) ($selected_row['dek'] ?? '')); ?>" />
                            </p>
                            <p>
                                <label for="aat_ceremony_writeup_body"><strong><?php echo esc_html__('Public Body', 'academy-awards-table'); ?></strong></label><br />
                                <textarea class="large-text" rows="12" id="aat_ceremony_writeup_body" name="aat_ceremony_writeup_body"><?php echo esc_textarea((string) ($selected_row['body'] ?? '')); ?></textarea>
                            </p>
                            <p>
                                <label for="aat_ceremony_writeup_source_notes"><strong><?php echo esc_html__('Private Source Notes', 'academy-awards-table'); ?></strong></label><br />
                                <textarea class="large-text" rows="6" id="aat_ceremony_writeup_source_notes" name="aat_ceremony_writeup_source_notes"><?php echo esc_textarea((string) ($selected_row['source_notes'] ?? '')); ?></textarea>
                            </p>
                            <?php submit_button(__('Save Ceremony Write-Up', 'academy-awards-table'), 'primary', 'submit', false); ?>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    private function get_ceremony_writeup_status_labels() {
        return array(
            AAT_Ceremony_Writeups::STATUS_DRAFT        => __('Draft', 'academy-awards-table'),
            AAT_Ceremony_Writeups::STATUS_NEEDS_REVIEW => __('Needs Review', 'academy-awards-table'),
            AAT_Ceremony_Writeups::STATUS_APPROVED     => __('Approved', 'academy-awards-table'),
            AAT_Ceremony_Writeups::STATUS_HIDDEN       => __('Hidden', 'academy-awards-table'),
        );
    }

    private function sanitize_ceremony_writeup_status_filter($status) {
        $status = sanitize_key((string) $status);
        if ($status === '' || $status === 'all') {
            return 'all';
        }

        $statuses = AAT_Ceremony_Writeups::get_statuses();
        return isset($statuses[$status]) ? $status : 'all';
    }

    private function normalize_ceremony_writeup_search($search) {
        $search = trim(sanitize_text_field((string) $search));
        if (function_exists('mb_substr')) {
            return mb_substr($search, 0, 120);
        }

        return substr($search, 0, 120);
    }

    private function get_ceremony_writeup_filter_state() {
        $status = isset($_GET['aat_ceremony_writeup_status_filter']) ? wp_unslash($_GET['aat_ceremony_writeup_status_filter']) : 'all';
        $search = isset($_GET['aat_ceremony_writeup_search']) ? wp_unslash($_GET['aat_ceremony_writeup_search']) : '';

        return array(
            'status' => $this->sanitize_ceremony_writeup_status_filter($status),
            'search' => $this->normalize_ceremony_writeup_search($search),
        );
    }

    private function get_ceremony_writeups_admin_url($args = array()) {
        $query = array('page' => 'academy-awards-ceremony-writeups');
        foreach ((array) $args as $key => $value) {
            if ($key === 'aat_ceremony_writeup_status_filter') {
                $value = $this->sanitize_ceremony_writeup_status_filter($value);
                if ($value === 'all') {
                    continue;
                }
            } elseif ($key === 'aat_ceremony_writeup_search') {
                $value = $this->normalize_ceremony_writeup_search($value);
                if ($value === '') {
                    continue;
                }
            } elseif ($key === 'ceremony') {
                $value = absint($value);
                if ($value <= 0) {
                    continue;
                }
            } else {
                continue;
            }

            $query[$key] = $value;
        }

        return add_query_arg($query, admin_url('admin.php'));
    }

    private function get_ceremony_writeups_preview_transient_key($source_hash) {
        $source_hash = preg_replace('/[^a-f0-9]/', '', strtolower((string) $source_hash));
        return 'aat_ceremony_writeups_preview_' . substr($source_hash, 0, 32);
    }

    private function handle_ceremony_writeups_upload_from_request() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_ceremony_writeups_forbidden', __('You do not have permission to import ceremony write-ups.', 'academy-awards-table'));
        }

        if (!AAT_Ceremony_Writeups::parser_is_available()) {
            return new WP_Error('aat_ceremony_writeups_parser_unavailable', __('This server needs ZipArchive or PharData available before it can preview DOCX ceremony guides.', 'academy-awards-table'));
        }

        if (empty($_FILES['aat_ceremony_writeups_docx']) || !is_array($_FILES['aat_ceremony_writeups_docx'])) {
            return new WP_Error('aat_ceremony_writeups_missing_file', __('Choose a DOCX file to preview.', 'academy-awards-table'));
        }

        $file = $_FILES['aat_ceremony_writeups_docx'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('aat_ceremony_writeups_upload_error', __('The DOCX upload did not complete.', 'academy-awards-table'));
        }

        $filename = sanitize_file_name((string) ($file['name'] ?? ''));
        $tmp_name = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'docx') {
            return new WP_Error('aat_ceremony_writeups_bad_extension', __('Upload a .docx file exported from Word.', 'academy-awards-table'));
        }
        if ($tmp_name === '' || !is_uploaded_file($tmp_name) || !is_readable($tmp_name)) {
            return new WP_Error('aat_ceremony_writeups_unreadable', __('The uploaded DOCX could not be read.', 'academy-awards-table'));
        }
        $filetype = wp_check_filetype_and_ext(
            $tmp_name,
            $filename,
            array('docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        );
        if (($filetype['ext'] ?? '') !== 'docx') {
            return new WP_Error('aat_ceremony_writeups_bad_type', __('The uploaded file does not look like a valid DOCX document.', 'academy-awards-table'));
        }
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            return new WP_Error('aat_ceremony_writeups_bad_size', __('The ceremony guide DOCX must be under 10 MB.', 'academy-awards-table'));
        }

        try {
            $parsed = AAT_Ceremony_Writeups::parse_docx($tmp_name);
        } catch (Exception $exception) {
            return new WP_Error('aat_ceremony_writeups_parse_failed', $exception->getMessage());
        }

        $summary = $parsed['summary'] ?? array();
        if ((int) ($summary['detected'] ?? 0) !== 98 || !empty($summary['missing']) || !empty($summary['duplicates'])) {
            return new WP_Error('aat_ceremony_writeups_incomplete', __('The DOCX must contain exactly 98 ceremony headings before staging.', 'academy-awards-table'));
        }

        $parsed['source_doc'] = $filename;
        foreach ($parsed['records'] as $number => $record) {
            $parsed['records'][$number]['source_doc'] = $filename;
        }

        set_transient($this->get_ceremony_writeups_preview_transient_key($parsed['source_hash']), $parsed, 2 * HOUR_IN_SECONDS);

        return $parsed;
    }

    private function stage_ceremony_writeups_preview_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_ceremony_writeups_forbidden', __('You do not have permission to stage ceremony write-ups.', 'academy-awards-table'));
        }

        $source_hash = isset($request['aat_ceremony_writeups_source_hash']) ? sanitize_text_field(wp_unslash($request['aat_ceremony_writeups_source_hash'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($source_hash))) {
            return new WP_Error('aat_ceremony_writeups_missing_hash', __('Upload and preview a complete DOCX before staging ceremony write-ups.', 'academy-awards-table'));
        }

        $preview = get_transient($this->get_ceremony_writeups_preview_transient_key($source_hash));
        if (empty($preview['records']) || !is_array($preview['records'])) {
            return new WP_Error('aat_ceremony_writeups_missing_preview', __('The import preview expired. Upload the DOCX again.', 'academy-awards-table'));
        }

        $summary = $preview['summary'] ?? array();
        if ((int) ($summary['detected'] ?? 0) !== 98 || !empty($summary['missing']) || !empty($summary['duplicates'])) {
            return new WP_Error('aat_ceremony_writeups_incomplete_preview', __('The import preview is incomplete and cannot be staged.', 'academy-awards-table'));
        }

        global $wpdb;
        $this->maybe_create_ceremony_writeups_table();
        $table = $this->get_ceremony_writeups_table_name();
        $now = current_time('mysql');
        $user_id = get_current_user_id();
        $count = 0;

        foreach ($preview['records'] as $record) {
            $ceremony = (int) ($record['ceremony_number'] ?? 0);
            if ($ceremony <= 0) {
                continue;
            }

            $saved = $wpdb->replace(
                $table,
                array(
                    'ceremony_number' => $ceremony,
                    'ceremony_label'  => sanitize_text_field((string) ($record['ceremony_label'] ?? '')),
                    'source_doc'      => sanitize_text_field((string) ($record['source_doc'] ?? ($preview['source_doc'] ?? ''))),
                    'source_hash'     => sanitize_text_field((string) ($record['source_hash'] ?? ($preview['source_hash'] ?? ''))),
                    'headline'        => sanitize_text_field((string) ($record['headline'] ?? '')),
                    'dek'             => sanitize_text_field((string) ($record['dek'] ?? '')),
                    'body'            => sanitize_textarea_field((string) ($record['body'] ?? '')),
                    'source_notes'    => sanitize_textarea_field((string) ($record['source_notes'] ?? '')),
                    'status'          => AAT_Ceremony_Writeups::STATUS_DRAFT,
                    'created_by'      => $user_id,
                    'updated_by'      => $user_id,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
            );

            if ($saved !== false) {
                $count++;
            }
        }

        if ($count !== 98) {
            return new WP_Error('aat_ceremony_writeups_stage_failed', __('Could not stage all 98 ceremony write-ups.', 'academy-awards-table'));
        }

        return array('count' => $count);
    }

    private function save_ceremony_writeup_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_ceremony_writeups_forbidden', __('You do not have permission to save ceremony write-ups.', 'academy-awards-table'));
        }

        $ceremony = isset($request['aat_ceremony_writeup_ceremony']) ? absint($request['aat_ceremony_writeup_ceremony']) : 0;
        if ($ceremony <= 0 || $ceremony > 999) {
            return new WP_Error('aat_ceremony_writeups_bad_ceremony', __('Invalid ceremony number.', 'academy-awards-table'));
        }

        global $wpdb;
        $this->maybe_create_ceremony_writeups_table();
        $table = $this->get_ceremony_writeups_table_name();
        $existing = $this->get_ceremony_writeup_record($ceremony);
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $data = array(
            'ceremony_number' => $ceremony,
            'ceremony_label'  => !empty($existing['ceremony_label']) ? (string) $existing['ceremony_label'] : sprintf('%s Academy Awards', $this->ordinal($ceremony)),
            'source_doc'      => !empty($existing['source_doc']) ? (string) $existing['source_doc'] : '',
            'source_hash'     => !empty($existing['source_hash']) ? (string) $existing['source_hash'] : '',
            'headline'        => isset($request['aat_ceremony_writeup_headline']) ? sanitize_text_field(wp_unslash($request['aat_ceremony_writeup_headline'])) : '',
            'dek'             => isset($request['aat_ceremony_writeup_dek']) ? sanitize_text_field(wp_unslash($request['aat_ceremony_writeup_dek'])) : '',
            'body'            => isset($request['aat_ceremony_writeup_body']) ? sanitize_textarea_field(wp_unslash($request['aat_ceremony_writeup_body'])) : '',
            'source_notes'    => isset($request['aat_ceremony_writeup_source_notes']) ? sanitize_textarea_field(wp_unslash($request['aat_ceremony_writeup_source_notes'])) : '',
            'status'          => AAT_Ceremony_Writeups::sanitize_status($request['aat_ceremony_writeup_status'] ?? ''),
            'updated_by'      => $user_id,
            'updated_at'      => $now,
        );

        if (!empty($existing)) {
            $updated = $wpdb->update(
                $table,
                $data,
                array('ceremony_number' => $ceremony),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );

            if ($updated === false) {
                return new WP_Error('aat_ceremony_writeups_save_failed', __('Could not save the ceremony write-up.', 'academy-awards-table'));
            }

            return array('ceremony' => $ceremony);
        }

        $data['created_by'] = $user_id;
        $data['created_at'] = $now;
        $inserted = $wpdb->insert(
            $table,
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s')
        );

        if ($inserted === false) {
            return new WP_Error('aat_ceremony_writeups_insert_failed', __('Could not create the ceremony write-up.', 'academy-awards-table'));
        }

        return array('ceremony' => $ceremony);
    }

    private function get_ceremony_writeups_search_sql($search, &$args) {
        global $wpdb;

        $search = $this->normalize_ceremony_writeup_search($search);
        if ($search === '') {
            return '';
        }

        $like = '%' . $wpdb->esc_like($search) . '%';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;

        return '(ceremony_label LIKE %s OR headline LIKE %s OR dek LIKE %s OR body LIKE %s OR source_notes LIKE %s)';
    }

    private function get_ceremony_writeups_admin_counts($search = '') {
        global $wpdb;
        $table = $this->get_ceremony_writeups_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $statuses = AAT_Ceremony_Writeups::get_statuses();
        $counts = array('all' => 0);
        foreach ($statuses as $status_key => $status_label) {
            $counts[$status_key] = 0;
        }

        if ($exists !== $table) {
            return $counts;
        }

        $args = array();
        $where = $this->get_ceremony_writeups_search_sql($search, $args);
        $sql = "SELECT status, COUNT(*) AS total FROM $table";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' GROUP BY status';

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, $args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            $status = AAT_Ceremony_Writeups::sanitize_status($row['status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            $counts[$status] = $total;
            $counts['all'] += $total;
        }

        return $counts;
    }

    private function get_ceremony_writeups_admin_rows($status_filter = 'all', $search = '') {
        global $wpdb;
        $table = $this->get_ceremony_writeups_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $status_filter = $this->sanitize_ceremony_writeup_status_filter($status_filter);
        $args = array();
        $clauses = array();
        if ($status_filter !== 'all') {
            $clauses[] = 'status = %s';
            $args[] = $status_filter;
        }

        $search_clause = $this->get_ceremony_writeups_search_sql($search, $args);
        if ($search_clause !== '') {
            $clauses[] = $search_clause;
        }

        $sql = "SELECT ceremony_number, ceremony_label, headline, status, source_hash, updated_at FROM $table";
        if (!empty($clauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY ceremony_number DESC';

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, $args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private function decode_ceremony_writeup_text_fields($row, $fields) {
        if (!is_array($row)) {
            return array();
        }

        foreach ($fields as $field) {
            $hex_field = $field . '_hex';
            $row[$field] = AAT_Ceremony_Writeups::decode_database_text($row[$field] ?? '', $row[$hex_field] ?? '');
            unset($row[$hex_field]);
        }

        return $row;
    }

    private function get_ceremony_writeup_record($ceremony) {
        global $wpdb;
        $ceremony = absint($ceremony);
        if ($ceremony <= 0) {
            return array();
        }

        $table = $this->get_ceremony_writeups_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *, HEX(ceremony_label) AS ceremony_label_hex, HEX(headline) AS headline_hex, HEX(dek) AS dek_hex, HEX(body) AS body_hex, HEX(source_notes) AS source_notes_hex FROM $table WHERE ceremony_number = %d LIMIT 1",
                $ceremony
            ),
            ARRAY_A
        );

        return $this->decode_ceremony_writeup_text_fields($row, array('ceremony_label', 'headline', 'dek', 'body', 'source_notes'));
    }

    public function get_approved_ceremony_writeup($ceremony) {
        global $wpdb;
        $ceremony = absint($ceremony);
        if ($ceremony <= 0) {
            return array();
        }

        $table = $this->get_ceremony_writeups_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ceremony_number, HEX(ceremony_label) AS ceremony_label_hex, HEX(headline) AS headline_hex, HEX(dek) AS dek_hex, HEX(body) AS body_hex FROM $table WHERE ceremony_number = %d AND status = %s LIMIT 1",
                $ceremony,
                AAT_Ceremony_Writeups::STATUS_APPROVED
            ),
            ARRAY_A
        );

        $writeup = $this->decode_ceremony_writeup_text_fields($row, array('ceremony_label', 'headline', 'dek', 'body'));

        // Render-time repair of punctuation lost during the original guide import
        // (see AAT_Ceremony_Writeups::repair_lost_punctuation). Display path only —
        // the stored row and the admin edit surface are left untouched.
        if (is_array($writeup)) {
            foreach (array('ceremony_label', 'headline', 'dek', 'body') as $repair_field) {
                if (isset($writeup[$repair_field]) && is_string($writeup[$repair_field])) {
                    $writeup[$repair_field] = AAT_Ceremony_Writeups::repair_lost_punctuation($writeup[$repair_field]);
                }
            }
        }

        return $writeup;
    }


    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'class' => '',
            'year' => '',
            'ceremony' => '',
            'winners_only' => 'false',
            // Layout variants: full (default) or embedded (used for hub pages)
            'layout' => 'full',
            'limit' => 0,
        ), $atts, 'academy_awards');

        // Convenience: allow ceremony="latest" / year="latest" so pages auto-update after new imports.
        $cer = strtolower(trim((string) ($atts['ceremony'] ?? '')));
        if ($cer === 'latest' || $cer === 'current') {
            $max = $this->get_max_ceremony();
            if ($max > 0) {
                $atts['ceremony'] = (string) $max;
            }
        }

        $yr = strtolower(trim((string) ($atts['year'] ?? '')));
        if ($yr === 'latest' || $yr === 'current') {
            $latest_year = $this->get_latest_year_label();
            if (!empty($latest_year)) {
                $atts['year'] = $latest_year;
            }
        }

        ob_start();
        include AAT_PLUGIN_DIR . 'templates/table-display.php';
        return ob_get_clean();
    }


    /**
     * Shortcode: Lunara Awards Tracker (Oscar season surface)
     * Usage: [lunara_awards_tracker] or [lunara_awards_tracker ceremony="latest"]
     *
     * This is a curated wrapper around the main database table, intended for your
     * Awards Tracker page. It defaults to the latest ceremony, and uses the embedded
     * layout to fit neatly into your site design.
     */
    public function render_tracker_shortcode($atts) {
        $atts = shortcode_atts(array(
            'ceremony'      => 'latest',
            'year'          => '',
            'layout'        => 'embedded',
            'winners_only'  => 'false',
            'category'      => '',
            'class'         => '',
        ), $atts, 'lunara_awards_tracker');

        // Reuse the main shortcode renderer so we keep one code path.
        return $this->render_shortcode($atts);
    }


    /**
     * Tracker V2 shortcode (Predictions / Locks / Watchlist / Longshots)
     * Usage: [lunara_awards_tracker_v2 ceremony="latest"]
     */
    public function render_tracker_v2_shortcode($atts) {
        $atts = shortcode_atts(array(
            'ceremony' => 'latest',
            'show_selector' => 'true',
            'show_posters' => 'true',
            'show_imdb' => 'true',
            'show_review_links' => 'true',
        ), $atts, 'lunara_awards_tracker_v2');

        $cer = strtolower(trim((string) ($atts['ceremony'] ?? '')));
        $ceremony = 0;
        if ($cer === 'latest' || $cer === 'current' || $cer === '') {
            $ceremony = $this->get_max_ceremony();
        } else {
            $ceremony = intval($atts['ceremony']);
        }
        if ($ceremony <= 0) {
            $ceremony = $this->get_max_ceremony();
        }

        // Allow URL override for easy season switching: ?ceremony=97
        if (isset($_GET['ceremony'])) {
            $q = intval($_GET['ceremony']);
            if ($q > 0) $ceremony = $q;
        }

        $show_selector = ($atts['show_selector'] === 'true' || $atts['show_selector'] === true || $atts['show_selector'] === '1');
        $show_posters = ($atts['show_posters'] === 'true' || $atts['show_posters'] === true || $atts['show_posters'] === '1');
        $show_imdb = ($atts['show_imdb'] === 'true' || $atts['show_imdb'] === true || $atts['show_imdb'] === '1');
        $show_review_links = ($atts['show_review_links'] === 'true' || $atts['show_review_links'] === true || $atts['show_review_links'] === '1');

        $season_label = $this->ordinal($ceremony) . ' Academy Awards';
        $year_label = $this->get_ceremony_year($ceremony);

        $picks = $this->get_tracker_picks($ceremony);

        // Ceremony dropdown options (for selector)
        $ceremonies = $this->get_projection_ceremonies_list();
        if (!is_array($ceremonies)) $ceremonies = array();

        ob_start();
        include AAT_PLUGIN_DIR . 'templates/tracker-v2.php';
        return ob_get_clean();
    }

    /**
     * Interactive ballot shortcode.
     * Usage: [lunara_oscar_ballot ceremony="latest" headline="2026 Oscars Ballot"]
     */
    public function render_ballot_shortcode($atts) {
        $atts = shortcode_atts(array(
            'ceremony' => 'latest',
            'headline' => '',
            'intro' => '',
            'show_selector' => 'true',
            'categories' => '',
        ), $atts, 'lunara_oscar_ballot');

        $cer = strtolower(trim((string) ($atts['ceremony'] ?? '')));
        $ceremony = 0;
        if ($cer === 'latest' || $cer === 'current' || $cer === '') {
            $ceremony = $this->get_max_ceremony();
        } else {
            $ceremony = intval($atts['ceremony']);
        }
        if ($ceremony <= 0) {
            $ceremony = $this->get_max_ceremony();
        }

        if (isset($_GET['ceremony'])) {
            $q = intval($_GET['ceremony']);
            if ($q > 0) {
                $ceremony = $q;
            }
        }

        $show_selector = ($atts['show_selector'] === 'true' || $atts['show_selector'] === true || $atts['show_selector'] === '1');
        $year_label = $this->get_ceremony_year($ceremony);
        $season_label = $this->ordinal($ceremony) . ' Academy Awards';

        $headline = trim((string) ($atts['headline'] ?? ''));
        if ($headline === '') {
            if (preg_match('/^\d{4}$/', (string) $year_label)) {
                $headline = (string) ((int) $year_label + 1) . ' Oscars Ballot';
            } else {
                $headline = 'Oscars Ballot';
            }
        }

        $intro = trim((string) ($atts['intro'] ?? ''));
        if ($intro === '') {
            $intro = 'Pick one Will Win and one Should Win selection in every category. Your ballot saves automatically on this device.';
        }

        $categories_filter = $this->parse_ballot_categories((string) ($atts['categories'] ?? ''));
        $ballot_groups = $this->get_ballot_category_groups($ceremony, $categories_filter);

        $ceremonies = $this->get_projection_ceremonies_list();
        if (!is_array($ceremonies)) {
            $ceremonies = array();
        }

        $state_key = 'aat-ballot-' . $ceremony;

        ob_start();
        include AAT_PLUGIN_DIR . 'templates/ballot.php';
        return ob_get_clean();
    }

    /**
     * Get tracker picks for a ceremony, grouped by tier and category.
     */
    public function get_tracker_picks($ceremony) {
        global $wpdb;
        $tracker_table = $wpdb->prefix . 'aat_tracker';

        $ceremony = intval($ceremony);
        if ($ceremony <= 0) return array();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $tracker_table WHERE ceremony = %d ORDER BY canonical_category ASC, FIELD(tier,'prediction','lock','watch','longshot') ASC, rank ASC, updated_at DESC",
                $ceremony
            ),
            ARRAY_A
        );
        if (!is_array($rows)) $rows = array();

        $grouped = array(
            'prediction' => array(),
            'lock' => array(),
            'watch' => array(),
            'longshot' => array(),
        );

        foreach ($rows as $r) {
            $tier = isset($r['tier']) ? (string) $r['tier'] : 'watch';
            if (!isset($grouped[$tier])) $tier = 'watch';
            $cat = isset($r['canonical_category']) ? (string) $r['canonical_category'] : '';
            if ($cat === '') continue;
            if (!isset($grouped[$tier][$cat])) $grouped[$tier][$cat] = array();
            $grouped[$tier][$cat][] = $r;
        }

        return $grouped;
    }

    /**
     * Parse a comma-separated ballot category filter into canonical categories.
     */
    private function parse_ballot_categories($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        $categories = array();

        foreach ($parts as $part) {
            $resolved = $this->resolve_category_slug($part);
            if ($resolved !== '') {
                $categories[] = $resolved;
                continue;
            }

            $categories[] = $part;
        }

        return array_values(array_unique($categories));
    }

    /**
     * Preferred editorial order for the interactive ballot.
     */
    private function get_ballot_category_order() {
        return array(
            'BEST PICTURE',
            'DIRECTING',
            'ACTRESS IN A LEADING ROLE',
            'ACTOR IN A LEADING ROLE',
            'ACTRESS IN A SUPPORTING ROLE',
            'ACTOR IN A SUPPORTING ROLE',
            'CASTING',
            'WRITING (Original Screenplay)',
            'WRITING (Adapted Screenplay)',
            'ANIMATED FEATURE FILM',
            'DOCUMENTARY FEATURE FILM',
            'INTERNATIONAL FEATURE FILM',
            'MUSIC (Original Score)',
            'MUSIC (Original Song)',
            'SOUND',
            'MAKEUP AND HAIRSTYLING',
            'COSTUME DESIGN',
            'CINEMATOGRAPHY',
            'FILM EDITING',
            'PRODUCTION DESIGN',
            'VISUAL EFFECTS',
        );
    }

    /**
     * Return ballot-ready groups for a ceremony.
     */
    private function get_ballot_category_groups($ceremony, $allowed_categories = array()) {
        global $wpdb;

        $ceremony = intval($ceremony);
        if ($ceremony <= 0) {
            return array();
        }

        $allowed_categories = array_values(array_filter(array_map('strval', (array) $allowed_categories), 'strlen'));
        sort($allowed_categories);
        $cache_key = 'aat_ballot_category_groups_v2_' . md5($ceremony . '|' . implode('|', $allowed_categories));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $table_name = $this->get_table_name();
        $fields = $this->get_awards_row_fields_sql();
        $where_sql = 'ceremony = %d AND canonical_category != %s AND class NOT IN (%s, %s)';
        $values = array($ceremony, '', 'Special', 'SciTech');

        if (!empty($allowed_categories)) {
            $placeholders = implode(', ', array_fill(0, count($allowed_categories), '%s'));
            $where_sql .= " AND canonical_category IN ($placeholders)";
            foreach ($allowed_categories as $category) {
                $values[] = (string) $category;
            }
        }

        $sql = "SELECT DISTINCT $fields FROM $table_name WHERE $where_sql ORDER BY canonical_category ASC, winner DESC, film ASC, name ASC";
        $sql = $wpdb->prepare($sql, $values);

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $review_title_ids = array();
        $grouped = array();

        foreach ($rows as $row) {
            $row = $this->normalize_awards_row($row);
            $category = trim((string) ($row['canonical_category'] ?? ''));
            if ($category === '') {
                continue;
            }

            if (!isset($grouped[$category])) {
                $grouped[$category] = array();
            }

            $grouped[$category][] = $row;

            foreach ($this->extract_title_ids($row['film_id'] ?? '') as $tt_id) {
                $review_title_ids[$tt_id] = true;
            }
        }

        if (empty($grouped)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $review_map = !empty($review_title_ids) ? $this->get_review_permalink_map_for_title_ids(array_keys($review_title_ids)) : array();
        $ordered_categories = array_keys($grouped);
        $preferred_order = array_flip($this->get_ballot_category_order());

        usort($ordered_categories, function($a, $b) use ($preferred_order) {
            $rank_a = isset($preferred_order[$a]) ? $preferred_order[$a] : 999;
            $rank_b = isset($preferred_order[$b]) ? $preferred_order[$b] : 999;

            if ($rank_a === $rank_b) {
                return strcasecmp((string) $a, (string) $b);
            }

            return $rank_a <=> $rank_b;
        });

        $out = array();
        foreach ($ordered_categories as $category) {
            $out[] = array(
                'category' => $category,
                'rows' => $grouped[$category],
            );
        }

        $groups = array(
            'categories' => $out,
            'review_map' => $review_map,
        );
        set_transient($cache_key, $groups, HOUR_IN_SECONDS);
        return $groups;
    }

    /**
     * Build IMDb URL for an IMDb entity ID.
     */
    public function build_imdb_url($id) {
        $id = trim((string) $id);
        if ($this->is_title_entity_id($id)) return 'https://www.imdb.com/title/' . $id . '/';
        if ($this->is_imdb_name_entity_id($id)) return 'https://www.imdb.com/name/' . $id . '/';
        if ($this->is_company_entity_id($id)) return 'https://www.imdb.com/company/' . $id . '/';
        return '';
    }

    /**
     * Build internal Lunara entity URL from an IMDb entity ID.
     */
    public function build_entity_url_from_id($id) {
        $id = trim((string) $id);
        if ($id === '') return '';
        $base = $this->get_entity_base_url();
        $entity_type = $this->infer_entity_type_from_id($id);
        if ($entity_type !== '') return esc_url_raw($base . $entity_type . '/' . strtolower($id) . '/');
        return '';
    }

    /**
     * Resolve a visible person label to an internal name entity link.
     */
    public function get_name_entity_link_by_label($label) {
        global $wpdb;

        $label = trim((string) wp_strip_all_tags($label));
        $empty = array(
            'label' => $label,
            'id'    => '',
            'url'   => '',
        );

        if ($label === '') {
            return $empty;
        }

        $cache_key = 'aat_name_entity_link_by_label_' . md5($label);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $normalized_label = $this->normalize_entity_name_key($label);
        if ($normalized_label === '') {
            set_transient($cache_key, $empty, HOUR_IN_SECONDS);
            return $empty;
        }

        $split_visible_credit_labels = function($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return array();
            }

            $parts = strpos($value, '|') !== false
                ? explode('|', $value)
                : preg_split('/\s*(?:,|\s+and\s+)\s*/i', $value);

            return array_values(array_filter(array_map(function($part) {
                return trim((string) wp_strip_all_tags($part));
            }, (array) $parts), 'strlen'));
        };

        $entities_table = $this->get_entities_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT e.entity_id, e.label
                 FROM $entities_table e
                 LEFT JOIN $entity_stats_table s ON s.entity_id = e.entity_id
                 WHERE e.entity_type = %s
                   AND e.sort_label = %s
                 ORDER BY CASE WHEN e.entity_id REGEXP %s THEN 0 ELSE 1 END, COALESCE(s.nominations, 0) DESC, e.entity_id ASC
                 LIMIT 1",
                'name',
                $normalized_label,
                '^nm[0-9]{7,9}$'
            ),
            ARRAY_A
        );

        if (is_array($row) && !empty($row['entity_id']) && $this->is_name_entity_id((string) $row['entity_id'])) {
            $result = array(
                'label' => trim((string) ($row['label'] ?? $label)) ?: $label,
                'id'    => strtolower(trim((string) $row['entity_id'])),
                'url'   => $this->build_entity_url_from_id((string) $row['entity_id']),
            );
            set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
            return $result;
        }

        $table_name = $this->get_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT name, nominees, nominee_ids
                 FROM $table_name
                 WHERE TRIM(COALESCE(nominee_ids, '')) <> ''
                   AND (nominees LIKE %s OR name = %s)
                 LIMIT 60",
                '%' . $wpdb->esc_like($label) . '%',
                $label
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $candidate_row) {
            $candidate_labels = $split_visible_credit_labels($candidate_row['nominees'] ?? '');
            if (empty($candidate_labels) && !empty($candidate_row['name'])) {
                $candidate_labels = $split_visible_credit_labels($candidate_row['name']);
            }

            $candidate_ids = $this->extract_entity_reference_ids($candidate_row['nominee_ids'] ?? '');
            foreach ($candidate_labels as $index => $candidate_label) {
                $candidate_label = trim((string) $candidate_label);
                if ($candidate_label === '' || $this->normalize_entity_name_key($candidate_label) !== $normalized_label) {
                    continue;
                }

                $candidate_id = isset($candidate_ids[$index]) ? strtolower(trim((string) $candidate_ids[$index])) : '';
                if ($candidate_id === '' || !$this->is_name_entity_id($candidate_id)) {
                    continue;
                }

                $canonical_id = $this->canonicalize_name_entity_id_for_label($candidate_id, $candidate_label);
                $result = array(
                    'label' => $candidate_label,
                    'id'    => $canonical_id,
                    'url'   => $this->build_entity_url_from_id($canonical_id),
                );
                set_transient($cache_key, $result, 12 * HOUR_IN_SECONDS);
                return $result;
            }
        }

        set_transient($cache_key, $empty, HOUR_IN_SECONDS);
        return $empty;
    }

    /**
     * Resolve a canonical IMDb person id for a visible nominee label.
     *
     * If legacy data links a name to a stale nm id, this method picks the
     * label-matching nm id that actually has ledger rows.
     */
    public function canonicalize_name_entity_id_for_label($id, $label) {
        global $wpdb;

        $id = strtolower(trim((string) $id));
        $label = trim((string) $label);

        if (!$this->is_name_entity_id($id) || $label === '' || $this->is_local_name_entity_id($id)) {
            return $id;
        }

        $requested_label = trim((string) $this->get_projected_entity_label($id));
        if ($requested_label !== '' && $this->normalize_entity_name_key($requested_label) === $this->normalize_entity_name_key($label)) {
            return $id;
        }

        $entities_table = $this->get_entities_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();

        $candidates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT e.entity_id
                 FROM $entities_table e
                 LEFT JOIN $entity_stats_table s ON s.entity_id = e.entity_id
                 WHERE e.entity_type = %s
                   AND e.label = %s
                   AND e.entity_id REGEXP %s
                 ORDER BY COALESCE(s.nominations, 0) DESC, e.entity_id ASC
                 LIMIT 25",
                'name',
                $label,
                '^nm[0-9]{7,9}$'
            )
        );

        if (!is_array($candidates) || empty($candidates)) {
            return $id;
        }

        foreach ($candidates as $candidate_id) {
            $candidate_id = strtolower(trim((string) $candidate_id));
            if (!$this->is_imdb_name_entity_id($candidate_id)) {
                continue;
            }

            if (!empty($this->get_entity_rows('name', $candidate_id))) {
                return $candidate_id;
            }
        }

        return $id;
    }

    /**
     * Resolve a stable display label for an IMDb title id.
     */
    public function lookup_title_label($id) {
        $id = strtolower(trim((string) $id));
        if (!preg_match('/^tt\d+$/', $id)) {
            return '';
        }

        $label = $this->get_entity_display_name('title', $id);
        if ($label !== '') {
            return $label;
        }

        $context = $this->get_title_context_for_imdb_id($id);
        if (!empty($context['title'])) {
            return trim((string) $context['title']);
        }

        return '';
    }

    /**
     * Poster Library: get attachment ID for a title.
     *
     * Strategy:
     *  1) Prefer the featured image from a linked Lunara review (if present)
     *  2) Fall back to the Poster Library mapping table
     */

    /**
     * Retrieve the hard-coded TMDB API key.
     */
    public function get_tmdb_api_key() {
        if ( defined('AAT_TMDB_API_KEY') && AAT_TMDB_API_KEY ) {
            return (string) AAT_TMDB_API_KEY;
        }

        $key = get_option('aat_tmdb_api_key', '');
        return trim((string) $key);
    }

    /**
     * Retrieve the OMDb API key without committing secrets to the repository.
     */
    public function get_omdb_api_key() {
        if (defined('AAT_OMDB_API_KEY') && AAT_OMDB_API_KEY) {
            return trim((string) AAT_OMDB_API_KEY);
        }

        $key = get_option('aat_omdb_api_key', '');
        return trim((string) $key);
    }

    /**
     * Build the patron Poster API URL for a title.
     */
    public function get_omdb_poster_api_url($imdb_id) {
        $imdb_id = strtolower(trim((string) $imdb_id));
        if (!preg_match('/^tt\d{7,8}$/', $imdb_id)) {
            return '';
        }

        $key = $this->get_omdb_api_key();
        if ($key === '') {
            return '';
        }

        return add_query_arg(
            array(
                'apikey' => $key,
                'i' => $imdb_id,
            ),
            'https://img.omdbapi.com/'
        );
    }

    private function get_omdb_review_states() {
        return array(
            'needs_review' => __('Needs Review', 'academy-awards-table'),
            'verified_bad_id' => __('Verified Bad ID', 'academy-awards-table'),
            'omdb_source_gap' => __('OMDb Source Gap', 'academy-awards-table'),
            'poster_gap_only' => __('Poster Gap Only', 'academy-awards-table'),
            'resolved' => __('Resolved', 'academy-awards-table'),
            'ignore_accept' => __('Ignore / Accept', 'academy-awards-table'),
        );
    }

    private function get_omdb_review_filter_labels() {
        return array_merge(
            array(
                'all' => __('All Review States', 'academy-awards-table'),
                'unreviewed' => __('Unreviewed', 'academy-awards-table'),
            ),
            $this->get_omdb_review_states()
        );
    }

    private function get_omdb_poster_review_states() {
        return array(
            'needs_review' => __('Needs Poster Review', 'academy-awards-table'),
            'accepted' => __('Accepted', 'academy-awards-table'),
            'source_failed' => __('Source Failed', 'academy-awards-table'),
            'needs_manual' => __('Needs Manual Poster', 'academy-awards-table'),
            'manual_replacement' => __('Manual Replacement', 'academy-awards-table'),
            'ignore_accept' => __('Ignore / Accept', 'academy-awards-table'),
        );
    }

    private function sanitize_omdb_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_omdb_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function sanitize_omdb_poster_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_omdb_poster_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function sanitize_omdb_review_filter($filter) {
        $filter = sanitize_key((string) $filter);
        $labels = $this->get_omdb_review_filter_labels();
        return isset($labels[$filter]) ? $filter : 'all';
    }

    private function sanitize_omdb_review_issue_type($issue_type) {
        $issue_type = sanitize_key((string) $issue_type);
        $allowed = array('match', 'mismatch', 'omdb_missing', 'poster_missing', 'unchecked');
        return in_array($issue_type, $allowed, true) ? $issue_type : '';
    }

    private function save_omdb_review_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_omdb_review_forbidden', __('You do not have permission to save OMDb review states.', 'academy-awards-table'));
        }

        $imdb_id = isset($request['aat_omdb_review_imdb_id']) ? strtolower(trim((string) wp_unslash($request['aat_omdb_review_imdb_id']))) : '';
        if (!$this->is_title_entity_id($imdb_id)) {
            return new WP_Error('aat_omdb_review_bad_id', __('Invalid IMDb title ID.', 'academy-awards-table'));
        }

        global $wpdb;
        $table = $this->get_omdb_reviews_table_name();
        $this->maybe_create_omdb_reviews_table();

        $state = $this->sanitize_omdb_review_state($request['aat_omdb_review_state'] ?? '');
        $issue_type = $this->sanitize_omdb_review_issue_type($request['aat_omdb_review_issue_type'] ?? '');
        $note = isset($request['aat_omdb_review_note']) ? sanitize_textarea_field(wp_unslash($request['aat_omdb_review_note'])) : '';
        $now = current_time('mysql');

        $result = $wpdb->replace(
            $table,
            array(
                'imdb_id' => $imdb_id,
                'review_state' => $state,
                'issue_type' => $issue_type,
                'correction_note' => $note,
                'reviewer_user_id' => get_current_user_id(),
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_omdb_review_save_failed', __('Could not save the OMDb review state.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_omdb_poster_review_record($imdb_id, $state, $note) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_omdb_poster_review_forbidden', __('You do not have permission to save OMDb poster review states.', 'academy-awards-table'));
        }

        $imdb_id = strtolower(trim((string) $imdb_id));
        if (!$this->is_title_entity_id($imdb_id)) {
            return new WP_Error('aat_omdb_poster_review_bad_id', __('Invalid IMDb title ID for poster review.', 'academy-awards-table'));
        }

        global $wpdb;
        $table = $this->get_omdb_poster_reviews_table_name();
        $this->maybe_create_omdb_poster_reviews_table();

        $state = $this->sanitize_omdb_poster_review_state($state);
        $note = sanitize_textarea_field((string) $note);
        $now = current_time('mysql');

        $result = $wpdb->replace(
            $table,
            array(
                'imdb_id' => $imdb_id,
                'poster_state' => $state,
                'poster_note' => $note,
                'reviewer_user_id' => get_current_user_id(),
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_omdb_poster_review_save_failed', __('Could not save the OMDb poster review state.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_omdb_poster_review_record_from_request($request) {
        $imdb_id = isset($request['aat_omdb_poster_review_imdb_id']) ? strtolower(trim((string) wp_unslash($request['aat_omdb_poster_review_imdb_id']))) : '';
        $state = $request['aat_omdb_poster_review_state'] ?? '';
        $note = isset($request['aat_omdb_poster_review_note']) ? wp_unslash($request['aat_omdb_poster_review_note']) : '';

        return $this->save_omdb_poster_review_record($imdb_id, $state, $note);
    }

    private function get_omdb_review_records_for_ids($imdb_ids) {
        global $wpdb;

        $ids = array();
        foreach ((array) $imdb_ids as $imdb_id) {
            $imdb_id = strtolower(trim((string) $imdb_id));
            if ($this->is_title_entity_id($imdb_id)) {
                $ids[$imdb_id] = true;
            }
        }

        if (empty($ids)) {
            return array();
        }

        $table = $this->get_omdb_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $ids = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
        $sql = $wpdb->prepare(
            "SELECT imdb_id, review_state, issue_type, correction_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE imdb_id IN ($placeholders)",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_omdb_review_states();
        $out = array();
        foreach ($rows as $row) {
            $imdb_id = strtolower(trim((string) ($row['imdb_id'] ?? '')));
            if (!$this->is_title_entity_id($imdb_id)) {
                continue;
            }

            $state = $this->sanitize_omdb_review_state($row['review_state'] ?? '');
            $out[$imdb_id] = array(
                'imdb_id' => $imdb_id,
                'review_state' => $state,
                'review_state_label' => $states[$state] ?? $states['needs_review'],
                'issue_type' => $this->sanitize_omdb_review_issue_type($row['issue_type'] ?? ''),
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
            );
        }

        return $out;
    }

    private function get_omdb_poster_review_records_for_ids($imdb_ids) {
        global $wpdb;

        $ids = array();
        foreach ((array) $imdb_ids as $imdb_id) {
            $imdb_id = strtolower(trim((string) $imdb_id));
            if ($this->is_title_entity_id($imdb_id)) {
                $ids[$imdb_id] = true;
            }
        }

        if (empty($ids)) {
            return array();
        }

        $table = $this->get_omdb_poster_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $ids = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
        $sql = $wpdb->prepare(
            "SELECT imdb_id, poster_state, poster_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE imdb_id IN ($placeholders)",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_omdb_poster_review_states();
        $out = array();
        foreach ($rows as $row) {
            $imdb_id = strtolower(trim((string) ($row['imdb_id'] ?? '')));
            if (!$this->is_title_entity_id($imdb_id)) {
                continue;
            }

            $state = $this->sanitize_omdb_poster_review_state($row['poster_state'] ?? '');
            $out[$imdb_id] = array(
                'imdb_id' => $imdb_id,
                'poster_state' => $state,
                'poster_state_label' => $states[$state] ?? $states['needs_review'],
                'poster_note' => (string) ($row['poster_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
            );
        }

        return $out;
    }

    private function get_default_omdb_review_record($imdb_id) {
        $states = $this->get_omdb_review_states();
        return array(
            'imdb_id' => strtolower(trim((string) $imdb_id)),
            'review_state' => 'needs_review',
            'review_state_label' => $states['needs_review'],
            'issue_type' => '',
            'correction_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
        );
    }

    private function get_default_omdb_poster_review_record($imdb_id) {
        $states = $this->get_omdb_poster_review_states();
        return array(
            'imdb_id' => strtolower(trim((string) $imdb_id)),
            'poster_state' => 'needs_review',
            'poster_state_label' => $states['needs_review'],
            'poster_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
        );
    }

    private function extract_omdb_correction_candidate_id($note, $current_imdb_id = '') {
        $note = (string) $note;
        $current_imdb_id = strtolower(trim((string) $current_imdb_id));
        if ($note === '') {
            return '';
        }

        if (preg_match('/candidate[^t]*(tt\d{7,8})/i', $note, $match)) {
            $candidate = strtolower(trim((string) $match[1]));
            if ($candidate !== $current_imdb_id && $this->is_title_entity_id($candidate)) {
                return $candidate;
            }
        }

        if (preg_match_all('/tt\d{7,8}/i', $note, $matches)) {
            foreach ($matches[0] as $match) {
                $candidate = strtolower(trim((string) $match));
                if ($candidate !== $current_imdb_id && $this->is_title_entity_id($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function build_omdb_correction_preview_for_row($row, $force_refresh = false) {
        $review = is_array($row['review'] ?? null) ? $row['review'] : array();
        if ((string) ($review['review_state'] ?? '') !== 'verified_bad_id') {
            return array();
        }

        $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
        $current_imdb_id = strtolower(trim((string) ($dataset['imdb_id'] ?? '')));
        $candidate_imdb_id = $this->extract_omdb_correction_candidate_id(
            (string) ($review['correction_note'] ?? ''),
            $current_imdb_id
        );

        if (!$this->is_title_entity_id($candidate_imdb_id)) {
            return array(
                'state' => 'missing_candidate',
                'candidate_imdb_id' => '',
                'message' => __('No candidate IMDb title ID was found in the private correction note.', 'academy-awards-table'),
                'warnings' => array(__('Add a candidate tt... ID to the private note before any correction preview.', 'academy-awards-table')),
            );
        }

        $candidate_omdb = $this->get_omdb_data_for_imdb_id($candidate_imdb_id, $force_refresh);
        if (!empty($candidate_omdb['error'])) {
            return array(
                'state' => 'candidate_error',
                'candidate_imdb_id' => $candidate_imdb_id,
                'candidate_imdb_url' => $this->build_imdb_url($candidate_imdb_id),
                'message' => (string) $candidate_omdb['error'],
                'warnings' => array(__('OMDb could not resolve the saved candidate ID.', 'academy-awards-table')),
            );
        }

        $dataset_title = trim((string) ($dataset['film'] ?? ''));
        $dataset_year = preg_replace('/[^0-9]/', '', (string) ($dataset['year'] ?? ''));
        $candidate_title = trim((string) ($candidate_omdb['title'] ?? ''));
        $candidate_year = '';
        if (preg_match('/\d{4}/', (string) ($candidate_omdb['year'] ?? ''), $year_match)) {
            $candidate_year = $year_match[0];
        }

        $title_match = $dataset_title !== ''
            && $candidate_title !== ''
            && $this->normalize_title_compare_key($dataset_title) === $this->normalize_title_compare_key($candidate_title);
        $year_match = $dataset_year !== '' && $candidate_year !== '' && $dataset_year === $candidate_year;
        $type = strtolower(trim((string) ($candidate_omdb['type'] ?? '')));
        $type_ok = $type === '' || $type === 'movie';
        $poster = trim((string) ($candidate_omdb['poster'] ?? ''));
        $poster_present = $poster !== '' && strtoupper($poster) !== 'N/A';

        $warnings = array();
        if (!$title_match) {
            $warnings[] = __('Candidate title still differs from the Oscar dataset title.', 'academy-awards-table');
        }
        if (!$year_match) {
            $warnings[] = __('Candidate year still differs from the Oscar dataset year.', 'academy-awards-table');
        }
        if (!$type_ok) {
            $warnings[] = __('Candidate is not marked as a movie by OMDb.', 'academy-awards-table');
        }
        if (!$poster_present) {
            $warnings[] = __('Candidate has no OMDb poster.', 'academy-awards-table');
        }

        $state = empty($warnings) ? 'ready_preview' : 'needs_human_check';

        return array(
            'state' => $state,
            'candidate_imdb_id' => $candidate_imdb_id,
            'candidate_imdb_url' => $this->build_imdb_url($candidate_imdb_id),
            'candidate' => array(
                'imdb_id' => strtolower(trim((string) ($candidate_omdb['imdb_id'] ?? $candidate_imdb_id))),
                'title' => $candidate_title,
                'year' => trim((string) ($candidate_omdb['year'] ?? '')),
                'type' => trim((string) ($candidate_omdb['type'] ?? '')),
                'runtime' => trim((string) ($candidate_omdb['runtime'] ?? '')),
                'director' => trim((string) ($candidate_omdb['director'] ?? '')),
                'poster_present' => $poster_present,
            ),
            'checks' => array(
                'title_match' => $title_match,
                'year_match' => $year_match,
                'type_ok' => $type_ok,
                'poster_present' => $poster_present,
            ),
            'warnings' => $warnings,
        );
    }

    private function apply_omdb_verified_bad_id_correction_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_omdb_correction_forbidden', __('You do not have permission to apply OMDb corrections.', 'academy-awards-table'));
        }

        if (empty($request['aat_omdb_correction_confirm'])) {
            return new WP_Error('aat_omdb_correction_unconfirmed', __('Confirm the one-row correction before applying it.', 'academy-awards-table'));
        }

        $current_imdb_id = isset($request['aat_omdb_correction_current_id']) ? strtolower(trim((string) wp_unslash($request['aat_omdb_correction_current_id']))) : '';
        $candidate_imdb_id = isset($request['aat_omdb_correction_candidate_id']) ? strtolower(trim((string) wp_unslash($request['aat_omdb_correction_candidate_id']))) : '';
        $expected_title_key = isset($request['aat_omdb_correction_dataset_title'])
            ? $this->normalize_title_compare_key((string) wp_unslash($request['aat_omdb_correction_dataset_title']))
            : '';
        $expected_year = isset($request['aat_omdb_correction_dataset_year'])
            ? preg_replace('/[^0-9]/', '', (string) wp_unslash($request['aat_omdb_correction_dataset_year']))
            : '';

        if (!$this->is_title_entity_id($current_imdb_id) || !$this->is_title_entity_id($candidate_imdb_id) || $current_imdb_id === $candidate_imdb_id) {
            return new WP_Error('aat_omdb_correction_bad_ids', __('Invalid current/candidate IMDb title IDs.', 'academy-awards-table'));
        }

        $review_records = $this->get_omdb_review_records_for_ids(array($current_imdb_id));
        $review = $review_records[$current_imdb_id] ?? array();
        if (empty($review['is_reviewed']) || (string) ($review['review_state'] ?? '') !== 'verified_bad_id') {
            return new WP_Error('aat_omdb_correction_bad_state', __('Only rows marked Verified Bad ID can be corrected by this action.', 'academy-awards-table'));
        }

        $saved_candidate_id = $this->extract_omdb_correction_candidate_id((string) ($review['correction_note'] ?? ''), $current_imdb_id);
        if ($saved_candidate_id !== $candidate_imdb_id) {
            return new WP_Error('aat_omdb_correction_candidate_mismatch', __('The submitted candidate does not match the candidate saved in the private correction note.', 'academy-awards-table'));
        }

        global $wpdb;
        $table_name = $this->get_table_name();
        $like = '%' . $wpdb->esc_like($current_imdb_id) . '%';
        $matches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, ceremony, year, film, film_id FROM $table_name WHERE film_id = %s OR film_id LIKE %s ORDER BY ceremony DESC, id ASC",
                $current_imdb_id,
                $like
            ),
            ARRAY_A
        );

        $rows_to_update = array();
        foreach (is_array($matches) ? $matches : array() as $match) {
            $ids = $this->extract_title_ids($match['film_id'] ?? '');
            if (!in_array($current_imdb_id, $ids, true)) {
                continue;
            }

            if ($expected_title_key !== '' && $this->normalize_title_compare_key((string) ($match['film'] ?? '')) !== $expected_title_key) {
                continue;
            }

            if ($expected_year !== '' && preg_replace('/[^0-9]/', '', (string) ($match['year'] ?? '')) !== $expected_year) {
                continue;
            }

            $new_film_id = $this->replace_title_id_token((string) ($match['film_id'] ?? ''), $current_imdb_id, $candidate_imdb_id);
            if ($new_film_id === (string) ($match['film_id'] ?? '')) {
                continue;
            }

            $match['new_film_id'] = $new_film_id;
            $rows_to_update[] = $match;
        }

        if (empty($rows_to_update)) {
            return new WP_Error('aat_omdb_correction_no_rows', __('No Oscar rows contain this exact bad IMDb ID token for the submitted title/year context.', 'academy-awards-table'));
        }

        $context_keys = array();
        foreach ($rows_to_update as $row) {
            $context_key = $this->normalize_title_compare_key((string) ($row['film'] ?? '')) . '|' . preg_replace('/[^0-9]/', '', (string) ($row['year'] ?? ''));
            $context_keys[$context_key] = true;
        }

        if (count($context_keys) > 1) {
            return new WP_Error('aat_omdb_correction_mixed_context', __('This bad IMDb ID appears on multiple distinct title/year contexts. Split it manually before applying a one-row correction.', 'academy-awards-table'));
        }

        $dataset = array(
            'imdb_id' => $current_imdb_id,
            'film' => trim((string) ($rows_to_update[0]['film'] ?? '')),
            'year' => preg_replace('/[^0-9]/', '', (string) ($rows_to_update[0]['year'] ?? '')),
            'ceremony' => (int) ($rows_to_update[0]['ceremony'] ?? 0),
        );
        $current_omdb = $this->get_omdb_data_for_imdb_id($current_imdb_id, false);
        $preview = $this->build_omdb_correction_preview_for_row(
            array(
                'dataset' => $dataset,
                'omdb' => $current_omdb,
                'review' => $review,
            ),
            true
        );

        if ((string) ($preview['state'] ?? '') !== 'ready_preview') {
            return new WP_Error('aat_omdb_correction_not_ready', __('The candidate preview is not clean enough to apply automatically. Recheck the saved note and candidate first.', 'academy-awards-table'));
        }

        $updated = 0;
        foreach ($rows_to_update as $row) {
            $result = $wpdb->update(
                $table_name,
                array('film_id' => (string) ($row['new_film_id'] ?? '')),
                array('id' => (int) ($row['id'] ?? 0)),
                array('%s'),
                array('%d')
            );

            if ($result === false) {
                return new WP_Error('aat_omdb_correction_update_failed', __('A database update failed while applying the candidate correction.', 'academy-awards-table'));
            }

            if ($result > 0) {
                $updated++;
            }
        }

        if ($updated <= 0) {
            return new WP_Error('aat_omdb_correction_no_change', __('The correction did not change any Oscar rows.', 'academy-awards-table'));
        }

        $reviews_table = $this->get_omdb_reviews_table_name();
        $now = current_time('mysql');
        $existing_note = trim((string) ($review['correction_note'] ?? ''));
        $resolved_note = sprintf(
            /* translators: 1: old IMDb ID, 2: new IMDb ID, 3: row count, 4: datetime */
            __('Resolved %1$s -> %2$s across %3$d Oscar rows on %4$s after OMDb candidate revalidation.', 'academy-awards-table'),
            $current_imdb_id,
            $candidate_imdb_id,
            $updated,
            $now
        );
        if ($existing_note !== '') {
            $resolved_note .= "\n\n" . __('Previous note:', 'academy-awards-table') . ' ' . $existing_note;
        }

        $wpdb->replace(
            $reviews_table,
            array(
                'imdb_id' => $current_imdb_id,
                'review_state' => 'resolved',
                'issue_type' => 'mismatch',
                'correction_note' => $resolved_note,
                'reviewer_user_id' => get_current_user_id(),
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        $this->clear_awards_runtime_caches(array($current_imdb_id, $candidate_imdb_id));

        return array(
            'current_imdb_id' => $current_imdb_id,
            'candidate_imdb_id' => $candidate_imdb_id,
            'updated_rows' => $updated,
        );
    }

    private function clear_awards_runtime_caches($entity_ids = array()) {
        delete_transient('aat_records_total_v1');
        delete_transient('aat_records_total_v2');
        delete_transient('aat_total_stats_v2');
        delete_transient('aat_awards_meta_v1');
        delete_transient('aat_hub_page_stats_v1');
        delete_transient('aat_hub_ceremony_grid_v2');
        delete_transient('aat_hub_category_grid_v2');

        foreach ((array) $entity_ids as $entity_id) {
            $entity_id = strtolower(trim((string) $entity_id));
            if ($entity_id !== '') {
                delete_transient('aat_entity_label_' . md5('title:' . $entity_id));
                delete_transient('aat_title_context_v1_' . $entity_id);
            }
        }

        do_action('aat_after_data_import', 'omdb_correction', 0);
    }

    /**
     * Fetch and cache OMDb title data by IMDb title ID.
     */
    public function get_omdb_data_for_imdb_id($imdb_id, $force_refresh = false) {
        $imdb_id = strtolower(trim((string) $imdb_id));
        if (!preg_match('/^tt\d{7,8}$/', $imdb_id)) {
            return array('error' => __('Invalid IMDb title ID.', 'academy-awards-table'));
        }

        $key = $this->get_omdb_api_key();
        if ($key === '') {
            return array('error' => __('OMDb API key is not configured.', 'academy-awards-table'));
        }

        $cache_key = 'aat_omdb_title_v1_' . $imdb_id;
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = add_query_arg(
            array(
                'apikey' => $key,
                'i' => $imdb_id,
                'r' => 'json',
                'plot' => 'short',
            ),
            'https://www.omdbapi.com/'
        );

        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) {
            $out = array('error' => $response->get_error_message());
            set_transient($cache_key, $out, HOUR_IN_SECONDS);
            return $out;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $out = array(
                'error' => sprintf(
                    /* translators: %d: HTTP status code */
                    __('OMDb returned HTTP %d.', 'academy-awards-table'),
                    $code
                ),
            );
            set_transient($cache_key, $out, HOUR_IN_SECONDS);
            return $out;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            $out = array('error' => __('OMDb response was not valid JSON.', 'academy-awards-table'));
            set_transient($cache_key, $out, HOUR_IN_SECONDS);
            return $out;
        }

        if (isset($data['Response']) && strtolower((string) $data['Response']) === 'false') {
            $out = array('error' => trim((string) ($data['Error'] ?? __('OMDb lookup failed.', 'academy-awards-table'))));
            set_transient($cache_key, $out, HOUR_IN_SECONDS);
            return $out;
        }

        $out = array(
            'imdb_id' => strtolower(trim((string) ($data['imdbID'] ?? $imdb_id))),
            'title' => trim((string) ($data['Title'] ?? '')),
            'year' => trim((string) ($data['Year'] ?? '')),
            'type' => trim((string) ($data['Type'] ?? '')),
            'poster' => trim((string) ($data['Poster'] ?? '')),
            'runtime' => trim((string) ($data['Runtime'] ?? '')),
            'director' => trim((string) ($data['Director'] ?? '')),
            'released' => trim((string) ($data['Released'] ?? '')),
            'raw' => $data,
        );

        set_transient($cache_key, $out, 7 * DAY_IN_SECONDS);
        return $out;
    }

    /**
     * Build read-only OMDb comparison rows for the admin integrity screen.
     */
    public function build_omdb_integrity_audit($limit = 25, $offset = 0, $force_refresh = false, $issue_filter = 'all', $scan_limit = 250, $review_state_filter = 'all') {
        global $wpdb;

        $limit = max(1, min(100, absint($limit)));
        $offset = max(0, absint($offset));
        $issue_filter = sanitize_key((string) $issue_filter);
        $allowed_filters = array('all', 'actionable', 'mismatch', 'omdb_missing', 'poster_missing', 'match');
        if (!in_array($issue_filter, $allowed_filters, true)) {
            $issue_filter = 'all';
        }
        $scan_limit = max($limit, min(1000, absint($scan_limit)));
        $review_state_filter = $this->sanitize_omdb_review_filter($review_state_filter);
        $table_name = $this->get_table_name();

        $rows = $wpdb->get_results(
            "SELECT ceremony, year, film, film_id, COUNT(*) AS mentions, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS wins
             FROM $table_name
             WHERE TRIM(COALESCE(film_id, '')) <> ''
             GROUP BY ceremony, year, film, film_id
             ORDER BY ceremony DESC, wins DESC, film ASC",
            ARRAY_A
        );

        $titles = array();
        foreach ((array) $rows as $row) {
            foreach ($this->extract_title_ids($row['film_id'] ?? '') as $tt_id) {
                if (isset($titles[$tt_id])) {
                    $titles[$tt_id]['mentions'] += (int) ($row['mentions'] ?? 0);
                    $titles[$tt_id]['wins'] += (int) ($row['wins'] ?? 0);
                    continue;
                }

                $titles[$tt_id] = array(
                    'imdb_id' => $tt_id,
                    'film' => trim((string) ($row['film'] ?? '')),
                    'year' => preg_replace('/[^0-9]/', '', (string) ($row['year'] ?? '')),
                    'ceremony' => (int) ($row['ceremony'] ?? 0),
                    'mentions' => (int) ($row['mentions'] ?? 0),
                    'wins' => (int) ($row['wins'] ?? 0),
                );
            }
        }

        $total_titles = count($titles);
        $candidates = array_values($titles);
        if ($issue_filter === 'all' && $review_state_filter === 'all') {
            $candidates = array_slice($candidates, $offset, $limit);
        } else {
            $candidates = array_slice($candidates, 0, $scan_limit);
        }

        $evaluated_rows = array();
        $counts = array(
            'match' => 0,
            'warning' => 0,
            'error' => 0,
            'unchecked' => 0,
        );
        $issue_counts = array(
            'match' => 0,
            'mismatch' => 0,
            'omdb_missing' => 0,
            'poster_missing' => 0,
            'unchecked' => 0,
        );

        foreach ($candidates as $item) {
            $omdb = $this->get_omdb_data_for_imdb_id($item['imdb_id'], $force_refresh);
            $warnings = array();
            $issue_types = array();
            $status = 'match';

            if (!empty($omdb['error'])) {
                $status = $this->get_omdb_api_key() === '' ? 'unchecked' : 'error';
                $warnings[] = (string) $omdb['error'];
                $issue_types[] = $status === 'unchecked' ? 'unchecked' : 'omdb_missing';
            } else {
                $dataset_title_key = $this->normalize_title_compare_key($item['film']);
                $omdb_title_key = $this->normalize_title_compare_key($omdb['title'] ?? '');
                if ($dataset_title_key !== '' && $omdb_title_key !== '' && $dataset_title_key !== $omdb_title_key) {
                    $warnings[] = sprintf(
                        /* translators: 1: dataset title, 2: OMDb title */
                        __('Title differs: dataset "%1$s" vs OMDb "%2$s".', 'academy-awards-table'),
                        $item['film'],
                        (string) ($omdb['title'] ?? '')
                    );
                    $issue_types[] = 'mismatch';
                }

                $omdb_year = '';
                if (preg_match('/\d{4}/', (string) ($omdb['year'] ?? ''), $m)) {
                    $omdb_year = $m[0];
                }

                if ($item['year'] !== '' && $omdb_year !== '' && $item['year'] !== $omdb_year) {
                    $warnings[] = sprintf(
                        /* translators: 1: dataset year, 2: OMDb year */
                        __('Year differs: dataset %1$s vs OMDb %2$s.', 'academy-awards-table'),
                        $item['year'],
                        $omdb_year
                    );
                    $issue_types[] = 'mismatch';
                }

                if (empty($omdb['poster']) || strtoupper((string) $omdb['poster']) === 'N/A') {
                    $warnings[] = __('OMDb has no poster URL for this title.', 'academy-awards-table');
                    $issue_types[] = 'poster_missing';
                }

                if (!empty($warnings)) {
                    $status = 'warning';
                }
            }

            $issue_types = array_values(array_unique($issue_types));
            if (empty($issue_types) && $status === 'match') {
                $issue_types[] = 'match';
            }

            foreach ($issue_types as $issue_type) {
                if (!isset($issue_counts[$issue_type])) {
                    $issue_counts[$issue_type] = 0;
                }
                $issue_counts[$issue_type]++;
            }

            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;

            $primary_issue = $this->get_omdb_primary_issue_type($issue_types, $status);
            $recommended_action = $this->get_omdb_recommended_action($primary_issue);

            $evaluated_rows[] = array(
                'status' => $status,
                'issue_type' => $primary_issue,
                'issue_types' => $issue_types,
                'recommended_action' => $recommended_action,
                'warnings' => $warnings,
                'dataset' => $item,
                'omdb' => $omdb,
                'poster_api_url' => $this->get_omdb_poster_api_url($item['imdb_id']),
                'entity_url' => $this->build_entity_url_from_id($item['imdb_id']),
                'imdb_url' => $this->build_imdb_url($item['imdb_id']),
            );
        }

        $review_ids = array();
        foreach ($evaluated_rows as $row) {
            $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
            $review_ids[] = (string) ($dataset['imdb_id'] ?? '');
        }
        $review_records = $this->get_omdb_review_records_for_ids($review_ids);
        foreach ($evaluated_rows as $idx => $row) {
            $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
            $imdb_id = strtolower(trim((string) ($dataset['imdb_id'] ?? '')));
            $evaluated_rows[$idx]['review'] = $review_records[$imdb_id] ?? $this->get_default_omdb_review_record($imdb_id);
        }

        if ($issue_filter === 'all') {
            $issue_filtered_rows = $evaluated_rows;
        } else {
            $issue_filtered_rows = array();
            foreach ($evaluated_rows as $row) {
                $row_issue_types = (array) ($row['issue_types'] ?? array());
                $include = false;
                if ($issue_filter === 'actionable') {
                    $include = !empty(array_intersect($row_issue_types, array('mismatch', 'poster_missing')));
                } elseif ($issue_filter === 'match') {
                    $include = in_array('match', $row_issue_types, true);
                } else {
                    $include = in_array($issue_filter, $row_issue_types, true);
                }

                if ($include) {
                    $issue_filtered_rows[] = $row;
                }
            }
        }

        if ($review_state_filter === 'all') {
            $filtered_rows = $issue_filtered_rows;
        } else {
            $filtered_rows = array();
            foreach ($issue_filtered_rows as $row) {
                $review = is_array($row['review'] ?? null) ? $row['review'] : array();
                $include = false;
                if ($review_state_filter === 'unreviewed') {
                    $include = empty($review['is_reviewed']);
                } else {
                    $include = !empty($review['is_reviewed']) && (string) ($review['review_state'] ?? '') === $review_state_filter;
                }

                if ($include) {
                    $filtered_rows[] = $row;
                }
            }
        }

        $filtered_total = $issue_filter === 'all' && $review_state_filter === 'all' ? $total_titles : count($filtered_rows);
        $audit_rows = $issue_filter === 'all' && $review_state_filter === 'all' ? $filtered_rows : array_slice($filtered_rows, $offset, $limit);
        $poster_review_ids = array();
        foreach ($audit_rows as $row) {
            $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
            $poster_review_ids[] = (string) ($dataset['imdb_id'] ?? '');
        }
        $poster_review_records = $this->get_omdb_poster_review_records_for_ids($poster_review_ids);
        foreach ($audit_rows as $idx => $row) {
            $dataset = is_array($row['dataset'] ?? null) ? $row['dataset'] : array();
            $imdb_id = strtolower(trim((string) ($dataset['imdb_id'] ?? '')));
            $audit_rows[$idx]['correction_preview'] = $this->build_omdb_correction_preview_for_row($row, $force_refresh);
            $audit_rows[$idx]['local_poster'] = $this->get_omdb_audit_local_poster_summary($imdb_id);
            $audit_rows[$idx]['poster_review'] = $poster_review_records[$imdb_id] ?? $this->get_default_omdb_poster_review_record($imdb_id);
        }

        return array(
            'total' => $filtered_total,
            'total_titles' => $total_titles,
            'limit' => $limit,
            'offset' => $offset,
            'issue_filter' => $issue_filter,
            'review_state_filter' => $review_state_filter,
            'scan_limit' => $scan_limit,
            'scanned' => count($candidates),
            'rows' => $audit_rows,
            'counts' => $counts,
            'issue_counts' => $issue_counts,
            'has_key' => $this->get_omdb_api_key() !== '',
        );
    }

    /**
     * Choose the single most useful queue label for a row.
     */
    private function get_omdb_primary_issue_type($issue_types, $status) {
        $issue_types = array_values(array_unique(array_map('sanitize_key', (array) $issue_types)));
        foreach (array('mismatch', 'poster_missing', 'omdb_missing', 'unchecked', 'match') as $candidate) {
            if (in_array($candidate, $issue_types, true)) {
                return $candidate;
            }
        }

        return $status === 'match' ? 'match' : 'omdb_missing';
    }

    /**
     * Human recommendation for the read-only correction queue.
     */
    private function get_omdb_recommended_action($issue_type) {
        switch ((string) $issue_type) {
            case 'mismatch':
                return __('Verify IMDb ID before changing any Oscar row.', 'academy-awards-table');
            case 'poster_missing':
                return __('Keep title ID, source poster elsewhere if needed.', 'academy-awards-table');
            case 'omdb_missing':
                return __('Treat as OMDb gap unless another source contradicts it.', 'academy-awards-table');
            case 'unchecked':
                return __('Configure OMDb key or refresh the slice.', 'academy-awards-table');
            case 'match':
            default:
                return __('No correction needed.', 'academy-awards-table');
        }
    }

    /**
     * Normalize title strings for OMDb comparison without over-cleaning display data.
     */
    private function normalize_title_compare_key($title) {
        $title = trim((string) $title);
        if ($title === '') {
            return '';
        }
        if (function_exists('remove_accents')) {
            $title = remove_accents($title);
        }
        $title = strtolower($title);
        $title = preg_replace('/^(the|a|an)\s+/', '', $title);
        $title = str_replace('&', 'and', $title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', (string) $title);
        $title = preg_replace('/\s+/', ' ', (string) $title);
        return trim((string) $title);
    }


/**
 * Build title context from the Oscar dataset for TMDB fallback searches.
 */
public function get_title_context_for_imdb_id($imdb_id) {
    global $wpdb;
    $imdb_id = strtolower(trim((string) $imdb_id));
    if (!preg_match('/^tt\d+$/', $imdb_id)) {
        return array('title' => '', 'year' => '');
    }

    $cache_key = 'aat_title_context_v1_' . $imdb_id;
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['title'], $cached['year'])) {
        return $cached;
    }

    $table_name = $this->get_table_name();
    $like = '%' . $wpdb->esc_like($imdb_id) . '%';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT film, year FROM $table_name WHERE film_id = %s OR film_id LIKE %s ORDER BY ceremony DESC, winner DESC, id DESC LIMIT 1",
        $imdb_id,
        $like
    ), ARRAY_A);
    if (!is_array($row)) {
        return array('title' => '', 'year' => '');
    }

    $context = array(
        'title' => trim((string)($row['film'] ?? '')),
        'year'  => preg_replace('/[^0-9]/', '', (string)($row['year'] ?? '')),
    );
    set_transient($cache_key, $context, 12 * HOUR_IN_SECONDS);
    return $context;
}

/**
 * Build person context from the Oscar dataset for TMDB fallback searches.
 */
public function get_person_context_for_imdb_id($imdb_id) {
    $imdb_id = strtolower(trim((string) $imdb_id));
    if (!preg_match('/^nm\d+$/', $imdb_id)) {
        return array(
            'name' => '',
            'latest_year' => '',
            'film_ids' => array(),
            'known_titles' => array(),
        );
    }

    $cache_key = 'aat_person_context_v1_' . $imdb_id;
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['name'], $cached['latest_year'], $cached['film_ids'], $cached['known_titles'])) {
        return $cached;
    }

    $rows = $this->get_entity_rows('name', $imdb_id);
    if (!is_array($rows) || empty($rows)) {
        return array(
            'name' => '',
            'latest_year' => '',
            'film_ids' => array(),
            'known_titles' => array(),
        );
    }

    $name = trim((string) $this->get_entity_display_name('name', $imdb_id));
    $latest_year = '';
    $film_ids = array();
    $known_titles = array();

    foreach ($rows as $row) {
        $year = preg_replace('/[^0-9]/', '', (string) ($row['year'] ?? ''));
        if ($year !== '' && ($latest_year === '' || intval($year) > intval($latest_year))) {
            $latest_year = $year;
        }

        $row_film_ids = array_values(array_filter(array_map('trim', explode('|', (string) ($row['film_id'] ?? ''))), 'strlen'));
        $row_films = array_values(array_filter(array_map('trim', explode('|', (string) ($row['film'] ?? ''))), 'strlen'));

        foreach ($row_film_ids as $index => $fid) {
            $fid = strtolower((string) $fid);
            if (!preg_match('/^tt\d+$/', $fid) || isset($film_ids[$fid])) {
                continue;
            }

            $film_ids[$fid] = $fid;

            $film_label = isset($row_films[$index]) ? trim((string) $row_films[$index]) : '';
            if ($film_label === '') {
                $film_label = $this->lookup_title_label($fid);
            }
            if ($film_label !== '') {
                $known_titles[$film_label] = $film_label;
            }
        }
    }

    if ($name === '' && !empty($rows[0]['name'])) {
        $name = trim((string) $rows[0]['name']);
    }

    $context = array(
        'name' => $name,
        'latest_year' => $latest_year,
        'film_ids' => array_values($film_ids),
        'known_titles' => array_values($known_titles),
    );

    set_transient($cache_key, $context, 12 * HOUR_IN_SECONDS);
    return $context;
}

/**
 * Fetch and cache TMDB data for an IMDb title id.
 * Falls back to a TMDB title search when direct IMDb lookup fails.
 */
public function get_tmdb_data_for_imdb_id( $imdb_id, $allow_remote = true ) {
    $imdb_id = strtolower( trim( (string) $imdb_id ) );
    if ( ! preg_match('/^tt\d+$/', $imdb_id) ) {
        return array();
    }

    $cache_key = 'aat_tmdb_v243_' . $imdb_id;
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    // On public render paths ($allow_remote = false) never issue a blocking
    // remote lookup: a cold cache returns empty so the caller falls back to its
    // local poster or premium fallback plate instantly. Remote enrichment is
    // reserved for the admin importers, which pass $allow_remote = true.
    if ( ! $allow_remote ) {
        return array();
    }

    $key = $this->get_tmdb_api_key();
    if ( $key === '' ) {
        return array();
    }

    $movie = array();
    $lookup_cache_key = 'aat_tmdb_lookup_v1_' . $imdb_id;
    $lookup_cached = get_transient( $lookup_cache_key );
    if ( is_array( $lookup_cached ) ) {
        $movie = $lookup_cached;
    } else {
        $find_url = add_query_arg(
            array(
                'api_key' => $key,
                'external_source' => 'imdb_id',
            ),
            'https://api.themoviedb.org/3/find/' . rawurlencode( $imdb_id )
        );
        $response = wp_remote_get( $find_url, array( 'timeout' => 15 ) );
        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['movie_results'][0] ) ) {
                $movie = $data['movie_results'][0];
            }
        }
    }

    if ( empty( $movie['id'] ) ) {
        $ctx = $this->get_title_context_for_imdb_id($imdb_id);
        $title = trim((string)($ctx['title'] ?? ''));
        $year = trim((string)($ctx['year'] ?? ''));
        if ($title !== '') {
            $search_args = array(
                'api_key' => $key,
                'query' => $title,
                'include_adult' => 'false',
            );
            if ($year !== '') {
                $search_args['year'] = $year;
                $search_args['primary_release_year'] = $year;
            }
            $search_url = add_query_arg($search_args, 'https://api.themoviedb.org/3/search/movie');
            $search_response = wp_remote_get($search_url, array('timeout' => 15));
            if (!is_wp_error($search_response)) {
                $search_data = json_decode(wp_remote_retrieve_body($search_response), true);
                if (!empty($search_data['results']) && is_array($search_data['results'])) {
                    $needle = strtolower($title);
                    foreach ($search_data['results'] as $candidate) {
                        $cand_title = strtolower(trim((string)($candidate['title'] ?? '')));
                        $cand_year = substr((string)($candidate['release_date'] ?? ''), 0, 4);
                        if ($cand_title === $needle && ($year === '' || $cand_year === $year)) {
                            $movie = $candidate;
                            break;
                        }
                    }
                    if (empty($movie['id'])) {
                        $movie = $search_data['results'][0];
                    }
                }
            }
        }
    }

    if ( empty( $movie['id'] ) ) {
        set_transient( $lookup_cache_key, array(), 30 * MINUTE_IN_SECONDS );
        set_transient( $cache_key, array(), 30 * MINUTE_IN_SECONDS );
        return array();
    }

    set_transient( $lookup_cache_key, $movie, 30 * DAY_IN_SECONDS );

    $details_url = add_query_arg(
        array(
            'api_key' => $key,
            'append_to_response' => 'credits',
        ),
        'https://api.themoviedb.org/3/movie/' . intval( $movie['id'] )
    );
    $details_response = wp_remote_get( $details_url, array( 'timeout' => 15 ) );
    if ( is_wp_error( $details_response ) ) {
        return array();
    }

    $details = json_decode( wp_remote_retrieve_body( $details_response ), true );
    if ( ! is_array( $details ) ) {
        $details = array();
    }
    if ( ! empty( $details['poster_path'] ) ) {
        $details['poster_full'] = 'https://image.tmdb.org/t/p/w500' . $details['poster_path'];
    }
    if ( ! empty( $details['backdrop_path'] ) ) {
        $details['backdrop_full'] = 'https://image.tmdb.org/t/p/w780' . $details['backdrop_path'];
    }
    set_transient( $cache_key, $details, 7 * DAY_IN_SECONDS );
    return $details;
}

/**
 * Fetch and cache TMDB data for an IMDb person id using the Oscar dataset as context.
 */
public function get_tmdb_person_data_for_imdb_id($imdb_id, $allow_remote = true) {
    $imdb_id = strtolower(trim((string) $imdb_id));
    if (!preg_match('/^nm\d+$/', $imdb_id)) {
        return array();
    }

    $cache_key = 'aat_tmdb_person_v2_' . $imdb_id;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    // Public render paths pass $allow_remote = false so a cold cache resolves
    // instantly to the local portrait or premium fallback plate instead of
    // blocking on a remote person lookup. The portrait queue importer passes true.
    if (!$allow_remote) {
        return array();
    }

    $key = $this->get_tmdb_api_key();
    if ($key === '') {
        return array();
    }

    $context = $this->get_person_context_for_imdb_id($imdb_id);
    $name = trim((string) ($context['name'] ?? ''));
    if ($name === '') {
        return array();
    }

    $normalize = function($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    };

    $known_titles = array();
    foreach ((array) ($context['known_titles'] ?? array()) as $known_title) {
        $normalized = $normalize($known_title);
        if ($normalized !== '') {
            $known_titles[$normalized] = true;
        }
    }

    $search_url = add_query_arg(
        array(
            'api_key' => $key,
            'query' => $name,
            'include_adult' => 'false',
        ),
        'https://api.themoviedb.org/3/search/person'
    );

    $search_response = wp_remote_get($search_url, array('timeout' => 15));
    if (is_wp_error($search_response)) {
        return array();
    }

    $search_data = json_decode(wp_remote_retrieve_body($search_response), true);
    if (empty($search_data['results']) || !is_array($search_data['results'])) {
        set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
        return array();
    }

    $needle = $normalize($name);
    $best_match = array();
    $best_score = -1;

    foreach ($search_data['results'] as $candidate) {
        if (empty($candidate['id']) || empty($candidate['name'])) {
            continue;
        }

        $score = 0;
        $candidate_name = $normalize($candidate['name']);

        if ($candidate_name === $needle) {
            $score += 80;
        } elseif ($needle !== '' && (strpos($candidate_name, $needle) !== false || strpos($needle, $candidate_name) !== false)) {
            $score += 35;
        }

        if (!empty($candidate['known_for']) && is_array($candidate['known_for'])) {
            foreach ($candidate['known_for'] as $known_item) {
                $known_title = trim((string) ($known_item['title'] ?? $known_item['name'] ?? ''));
                if ($known_title === '') {
                    continue;
                }

                if (isset($known_titles[$normalize($known_title)])) {
                    $score += 25;
                    break;
                }
            }
        }

        if (!empty($candidate['known_for_department'])) {
            $score += 5;
        }

        if (!empty($candidate['popularity'])) {
            $score += min(10, floatval($candidate['popularity']) / 10);
        }

        if ($score > $best_score) {
            $best_score = $score;
            $best_match = $candidate;
        }
    }

    if (empty($best_match['id'])) {
        set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
        return array();
    }

    $details_url = add_query_arg(
        array(
            'api_key' => $key,
            'append_to_response' => 'combined_credits,images',
        ),
        'https://api.themoviedb.org/3/person/' . intval($best_match['id'])
    );

    $details_response = wp_remote_get($details_url, array('timeout' => 15));
    if (is_wp_error($details_response)) {
        return array();
    }

    $details = json_decode(wp_remote_retrieve_body($details_response), true);
    if (!is_array($details)) {
        $details = array();
    }

    if (!empty($details['profile_path'])) {
        $details['profile_full'] = 'https://image.tmdb.org/t/p/w342' . $details['profile_path'];
    }

    if (!empty($details['combined_credits']['cast']) && is_array($details['combined_credits']['cast'])) {
        foreach ($details['combined_credits']['cast'] as $credit) {
            if (!empty($credit['backdrop_path'])) {
                $details['backdrop_full'] = 'https://image.tmdb.org/t/p/w780' . $credit['backdrop_path'];
                break;
            }
        }
    }
    if (empty($details['backdrop_full']) && !empty($details['combined_credits']['crew']) && is_array($details['combined_credits']['crew'])) {
        foreach ($details['combined_credits']['crew'] as $credit) {
            if (!empty($credit['backdrop_path'])) {
                $details['backdrop_full'] = 'https://image.tmdb.org/t/p/w780' . $credit['backdrop_path'];
                break;
            }
        }
    }

    set_transient($cache_key, $details, 7 * DAY_IN_SECONDS);
    return $details;
}

/**
 * Resolve the preferred visual package for a title.

     * Prefers a mapped local poster, then TMDB poster/backdrop metadata.
     */

public function get_title_visual_package($tt, $size = 'large', $allow_remote = false) {
    $tt = strtolower(trim((string) $tt));
    if (!preg_match('/^tt\d+$/', $tt)) {
        return array();
    }

    $ctx = $this->get_title_context_for_imdb_id($tt);
    $dataset_title = trim((string)($ctx['title'] ?? ''));
    $dataset_year  = trim((string)($ctx['year'] ?? ''));

    $out = array(
        'poster_html'        => '',
        'poster_url'         => '',
        'backdrop_url'       => '',
        'title'              => $dataset_title,
        'release_year'       => $dataset_year,
        'runtime'            => '',
        'overview'           => '',
        'director'           => '',
        'tmdb'               => array(),
        'fallback_html'      => '',
        'card_fallback_html' => '',
    );

    $poster_html = $this->get_poster_img_html_for_title($tt, $size, array('class' => 'aat-entity-poster'));
    if (!empty($poster_html)) {
        $out['poster_html'] = $poster_html;
    }

    $tmdb = $this->get_tmdb_data_for_imdb_id($tt, $allow_remote);
    if (is_array($tmdb) && !empty($tmdb)) {
        $out['tmdb'] = $tmdb;
        if (empty($out['poster_html']) && !empty($tmdb['poster_full'])) {
            $out['poster_url'] = $tmdb['poster_full'];
        }
        if (!empty($tmdb['backdrop_full'])) {
            $out['backdrop_url'] = $tmdb['backdrop_full'];
        }
        if (!empty($tmdb['title'])) {
            $out['title'] = (string) $tmdb['title'];
        }
        if (!empty($tmdb['release_date'])) {
            $out['release_year'] = substr((string) $tmdb['release_date'], 0, 4);
        }
        if (!empty($tmdb['runtime'])) {
            $out['runtime'] = (string) intval($tmdb['runtime']);
        }
        if (!empty($tmdb['overview'])) {
            $out['overview'] = (string) $tmdb['overview'];
        }
        if (!empty($tmdb['credits']['crew']) && is_array($tmdb['credits']['crew'])) {
            foreach ($tmdb['credits']['crew'] as $crew) {
                if (($crew['job'] ?? '') === 'Director' && !empty($crew['name'])) {
                    $out['director'] = (string) $crew['name'];
                    break;
                }
            }
        }
    }

    $display_title = $out['title'] !== '' ? $out['title'] : strtoupper($tt);
    $display_year  = $out['release_year'] !== '' ? $out['release_year'] : '';
    $meta_bits = array();
    if ($display_year !== '') $meta_bits[] = esc_html($display_year);
    if ($out['director'] !== '') $meta_bits[] = esc_html($out['director']);
    $meta_line = !empty($meta_bits) ? '<div class="aat-fallback-meta">' . implode('<span class="aat-fallback-dot">•</span>', $meta_bits) . '</div>' : '';
    $backdrop_style = !empty($out['backdrop_url']) ? ' style="background-image: linear-gradient(180deg, rgba(4,11,22,.28), rgba(4,11,22,.82)), url(' . esc_url($out['backdrop_url']) . '); background-size: cover; background-position: center;"' : '';
    $out['fallback_html'] = '<div class="aat-entity-poster-fallback"' . $backdrop_style . '><div class="aat-fallback-inner"><div class="aat-fallback-kicker">LUNARA FILM</div><div class="aat-fallback-title">' . esc_html($display_title) . '</div>' . $meta_line . '</div></div>';
    $out['card_fallback_html'] = '<div class="aat-filmography-poster-placeholder"' . $backdrop_style . '><div class="aat-fallback-inner"><div class="aat-fallback-kicker">Oscar Profile</div><div class="aat-fallback-title small">' . esc_html($display_title) . '</div>' . $meta_line . '</div></div>';

    return $out;
}

/**
 * Resolve the preferred visual package for a person profile.
 */
public function get_person_visual_package($nm_id, $size = 'large', $allow_remote = false) {
    $nm_id = strtolower(trim((string) $nm_id));
    if (!preg_match('/^nm\d+$/', $nm_id)) {
        return array();
    }

    $context = $this->get_person_context_for_imdb_id($nm_id);
    $dataset_name = trim((string) ($context['name'] ?? ''));

    $out = array(
        'portrait_url' => '',
        'backdrop_url' => '',
        'name' => $dataset_name,
        'biography' => '',
        'meta_bits' => array(),
        'tmdb' => array(),
        'fallback_html' => '',
        'visual_source' => 'none',
        'visual_state' => 'no-portrait',
        'portrait_attachment_id' => 0,
        'portrait_match_strategy' => '',
        'portrait_verified' => false,
    );

    $local_profile = $this->resolve_profile_attachment_for_person($nm_id, $dataset_name);
    $local_attachment_id = intval($local_profile['attachment_id'] ?? 0);
    $out['portrait_attachment_id'] = $local_attachment_id;
    $out['portrait_match_strategy'] = (string) ($local_profile['match_strategy'] ?? '');
    if ($local_attachment_id > 0) {
        $local_portrait_url = wp_get_attachment_image_url($local_attachment_id, $size);
        if (is_string($local_portrait_url) && $local_portrait_url !== '') {
            $out['portrait_url'] = $local_portrait_url;
            $out['visual_source'] = 'local-media-library';
            $out['visual_state'] = 'local-portrait';
            $out['portrait_verified'] = true;
        }
    }

    $tmdb = $this->get_tmdb_person_data_for_imdb_id($nm_id, $allow_remote);
    if (is_array($tmdb) && !empty($tmdb)) {
        $out['tmdb'] = $tmdb;
        if (empty($out['portrait_url']) && !empty($tmdb['profile_full'])) {
            $out['portrait_url'] = (string) $tmdb['profile_full'];
            $out['visual_source'] = 'tmdb-person-profile';
            $out['visual_state'] = 'tmdb-portrait';
        }
        if (!empty($tmdb['backdrop_full'])) {
            $out['backdrop_url'] = (string) $tmdb['backdrop_full'];
        }
        if (!empty($tmdb['name'])) {
            $out['name'] = (string) $tmdb['name'];
        }
        if (!empty($tmdb['biography'])) {
            $out['biography'] = (string) $tmdb['biography'];
        }
        if (!empty($tmdb['known_for_department'])) {
            $out['meta_bits'][] = (string) $tmdb['known_for_department'];
        }
        if (!empty($tmdb['birthday'])) {
            $out['meta_bits'][] = 'Born ' . substr((string) $tmdb['birthday'], 0, 4);
        }
        if (!empty($tmdb['place_of_birth'])) {
            $out['meta_bits'][] = (string) $tmdb['place_of_birth'];
        }
    }

    if (empty($out['meta_bits']) && !empty($context['latest_year'])) {
        $out['meta_bits'][] = 'Latest Oscar year ' . (string) $context['latest_year'];
    }

    $display_name = $out['name'] !== '' ? $out['name'] : strtoupper($nm_id);
    $meta_bits = array_map('esc_html', array_values(array_filter($out['meta_bits'], 'strlen')));
    $meta_line = !empty($meta_bits) ? '<div class="aat-fallback-meta">' . implode('<span class="aat-fallback-dot">&bull;</span>', $meta_bits) . '</div>' : '';
    // A title/backdrop image can support page atmosphere, but it must never occupy a person portrait chamber.
    $out['fallback_html'] = '<div class="aat-entity-poster-fallback aat-person-portrait-fallback"><div class="aat-fallback-inner"><div class="aat-fallback-kicker">LUNARA FILM</div><div class="aat-fallback-title">' . esc_html($display_name) . '</div>' . $meta_line . '</div></div>';

    return $out;
}

    private function resolve_profile_attachment_for_person($nm_id, $person_name = '') {
        global $wpdb;

        $nm_id = strtolower(trim((string) $nm_id));
        if (!preg_match('/^nm\d{7,9}$/', $nm_id)) {
            return array(
                'attachment_id' => 0,
                'match_strategy' => '',
                'attached_file' => '',
            );
        }

        $explicit_attachment_id = $this->find_existing_person_portrait_attachment($nm_id, '');
        if ($explicit_attachment_id > 0) {
            $resolved = array(
                'attachment_id' => $explicit_attachment_id,
                'match_strategy' => 'aat-person-meta',
                'attached_file' => (string) get_post_meta($explicit_attachment_id, '_wp_attached_file', true),
            );
            set_transient('aat_person_profile_attachment_v2_' . $nm_id, $resolved, 12 * HOUR_IN_SECONDS);
            return $resolved;
        }

        $person_name = trim((string) $person_name);
        if ($person_name === '') {
            $context = $this->get_person_context_for_imdb_id($nm_id);
            $person_name = trim((string) ($context['name'] ?? ''));
        }

        static $runtime_cache = array();
        if (array_key_exists($nm_id, $runtime_cache)) {
            $cached_runtime = $runtime_cache[$nm_id];
            return is_array($cached_runtime) ? $cached_runtime : array(
                'attachment_id' => intval($cached_runtime),
                'match_strategy' => '',
                'attached_file' => '',
            );
        }

        $cache_key = 'aat_person_profile_attachment_v2_' . $nm_id;
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['attachment_id'])) {
            $runtime_cache[$nm_id] = $cached;
            return $cached;
        }

        $like_file = '%' . $wpdb->esc_like($nm_id . '-profile.') . '%';
        $like_id = '%' . $wpdb->esc_like($nm_id) . '%';
        $like_title = $wpdb->esc_like($nm_id) . '%';
        $resolved = array(
            'attachment_id' => 0,
            'match_strategy' => '',
            'attached_file' => '',
        );

        $pick_attachment = function($sql, $params, $strategy, $require_unique) use ($wpdb, &$resolved) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
            if (!is_array($rows) || empty($rows)) {
                return false;
            }

            if ($require_unique && count($rows) !== 1) {
                return false;
            }

            $resolved = array(
                'attachment_id' => intval($rows[0]['ID'] ?? 0),
                'match_strategy' => $strategy,
                'attached_file' => (string) ($rows[0]['attached_file'] ?? ''),
            );

            return $resolved['attachment_id'] > 0;
        };

        $image_where = "p.post_type = %s AND p.post_mime_type LIKE %s";
        $image_params = array('attachment', 'image/%');

        if ($pick_attachment(
            "SELECT p.ID, pm_file.meta_value AS attached_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = %s
             WHERE {$image_where}
               AND pm_file.meta_value LIKE %s
             ORDER BY p.post_date DESC, p.ID DESC
             LIMIT 2",
            array_merge(array('_wp_attached_file'), $image_params, array($like_file)),
            'imdb-file',
            false
        )) {
            $runtime_cache[$nm_id] = $resolved;
            set_transient($cache_key, $resolved, 12 * HOUR_IN_SECONDS);
            return $resolved;
        }

        if ($pick_attachment(
            "SELECT p.ID, pm_file.meta_value AS attached_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_alt ON pm_alt.post_id = p.ID AND pm_alt.meta_key = %s
             WHERE {$image_where}
               AND (
                   p.post_name LIKE %s
                   OR p.post_title LIKE %s
                   OR pm_alt.meta_value LIKE %s
                   OR pm_file.meta_value LIKE %s
               )
             ORDER BY p.post_date DESC, p.ID DESC
             LIMIT 2",
            array_merge(array('_wp_attached_file', '_wp_attachment_image_alt'), $image_params, array($like_title, $like_title, $like_id, $like_id)),
            'imdb-meta',
            false
        )) {
            $runtime_cache[$nm_id] = $resolved;
            set_transient($cache_key, $resolved, 12 * HOUR_IN_SECONDS);
            return $resolved;
        }

        if ($person_name !== '') {
            $person_slug = sanitize_title($person_name);

            if ($pick_attachment(
                "SELECT p.ID, pm_file.meta_value AS attached_file
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} pm_alt ON pm_alt.post_id = p.ID AND pm_alt.meta_key = %s
                 WHERE {$image_where}
                   AND (
                       p.post_title = %s
                       OR pm_alt.meta_value = %s
                       OR p.post_name = %s
                   )
                 ORDER BY p.post_date DESC, p.ID DESC
                 LIMIT 2",
                array_merge(array('_wp_attached_file', '_wp_attachment_image_alt'), $image_params, array($person_name, $person_name, $person_slug)),
                'name-exact',
                true
            )) {
                $runtime_cache[$nm_id] = $resolved;
                set_transient($cache_key, $resolved, 12 * HOUR_IN_SECONDS);
                return $resolved;
            }

            if ($person_slug !== '' && $pick_attachment(
                "SELECT p.ID, pm_file.meta_value AS attached_file
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = %s
                 WHERE {$image_where}
                   AND (
                       p.post_name = %s
                       OR pm_file.meta_value LIKE %s
                   )
                 ORDER BY p.post_date DESC, p.ID DESC
                 LIMIT 2",
                array_merge(array('_wp_attached_file'), $image_params, array($person_slug, '%' . $wpdb->esc_like('/' . $person_slug . '.') . '%')),
                'name-slug',
                true
            )) {
                $runtime_cache[$nm_id] = $resolved;
                set_transient($cache_key, $resolved, 12 * HOUR_IN_SECONDS);
                return $resolved;
            }
        }

        $runtime_cache[$nm_id] = $resolved;
        set_transient($cache_key, $resolved, 12 * HOUR_IN_SECONDS);

        return $resolved;
    }

    private function get_profile_attachment_id_for_person($nm_id) {
        $resolved = $this->resolve_profile_attachment_for_person($nm_id);
        return intval($resolved['attachment_id'] ?? 0);
    }

    private function get_person_profile_attachment_audit($limit = 100) {
        global $wpdb;

        $limit = max(1, min(500, intval($limit)));
        $entities_table = $this->get_entities_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entity_id AS person_id, label
                 FROM {$entities_table}
                 WHERE entity_type = %s
                 ORDER BY label ASC, entity_id ASC
                 LIMIT %d",
                'name',
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $audit = array();
        foreach ($rows as $row) {
            $person_id = strtolower(trim((string) ($row['person_id'] ?? '')));
            if (!preg_match('/^nm\d{7,9}$/', $person_id)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) $this->get_entity_display_name('name', $person_id));
            }

            $entity_rows = $this->get_entity_rows('name', $person_id);
            $resolved = $this->resolve_profile_attachment_for_person($person_id, $label);
            $attachment_id = intval($resolved['attachment_id'] ?? 0);
            $thumb_url = $attachment_id > 0 ? wp_get_attachment_image_url($attachment_id, array(60, 60)) : '';
            $visual_source = 'none';
            $visual_state = 'no-portrait';
            $portrait_verified = false;
            $portrait_state_label = __('No portrait', 'academy-awards-table');

            if ($attachment_id > 0) {
                $visual_source = 'local-media-library';
                $visual_state = 'local-portrait';
                $portrait_verified = true;
                $portrait_state_label = __('Local portrait', 'academy-awards-table');
            } else {
                $cached_tmdb = get_transient('aat_tmdb_person_v2_' . $person_id);
                if (is_array($cached_tmdb) && !empty($cached_tmdb['profile_full'])) {
                    $visual_source = 'tmdb-person-profile';
                    $visual_state = 'tmdb-portrait';
                    $portrait_state_label = __('TMDb portrait', 'academy-awards-table');
                    $thumb_url = (string) $cached_tmdb['profile_full'];
                }
            }

            $audit[] = array(
                'person_id' => $person_id,
                'label' => $label,
                'attachment_id' => $attachment_id,
                'attached_file' => (string) ($resolved['attached_file'] ?? ''),
                'thumb_url' => is_string($thumb_url) ? $thumb_url : '',
                'matched' => $attachment_id > 0,
                'match_strategy' => (string) ($resolved['match_strategy'] ?? ''),
                'visual_source' => $visual_source,
                'visual_state' => $visual_state,
                'portrait_verified' => $portrait_verified,
                'portrait_state_label' => $portrait_state_label,
                'nomination_count' => is_array($entity_rows) ? count($entity_rows) : 0,
                'profile_url' => $this->build_entity_url_from_id($person_id),
            );
        }

        return $audit;
    }

    private function parse_person_ids_from_text($ids_raw) {
        $ids_raw = trim((string) $ids_raw);
        if ($ids_raw === '') {
            return array();
        }

        preg_match_all('/nm\d{7,9}/i', $ids_raw, $matches);
        $ids = array();
        foreach ((array) ($matches[0] ?? array()) as $id) {
            $id = strtolower(trim((string) $id));
            if ($this->is_imdb_name_entity_id($id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * WP-CLI command for Dalton-supplied person portrait batches.
     *
     * Usage:
     * wp aat profile-images dry-run --source=/private/oscars-profile-images --results-csv=/private/tmdb_profile_results.csv --missing-csv=/private/profiles_missing.csv
     * wp aat profile-images import --source=/private/oscars-profile-images --results-csv=/private/tmdb_profile_results.csv --missing-csv=/private/profiles_missing.csv --limit=100 --offset=0 --batch=oscars-profile-images-20260625
     * wp aat profile-images coverage --results-csv=/private/tmdb_profile_results.csv --batch=oscars-profile-images-20260625 --sample=25
     * wp aat profile-images existing-media-audit --folder=PEOPLE --sample=25 --output-csv=/private/people-media-reconciliation.csv
     * wp aat profile-images person-credit-audit --category=sound-mixing --state=unresolved --sample=50 --output-csv=/private/person-credit-reconciliation.csv
     * wp aat profile-images company-credit-audit --category=sound-mixing --state=all --sample=80 --output-csv=/private/company-credit-reconciliation.csv
     * wp aat profile-images person-credit-stage --input-csv=/private/person-credit-batch-01.csv
     * wp aat profile-images person-credit-stage --input-csv=/private/person-credit-batch-01.csv --commit
     */
    public function handle_profile_image_batch_cli($args, $assoc_args) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        $mode = isset($args[0]) ? sanitize_key((string) $args[0]) : 'dry-run';
        if (!in_array($mode, array('dry-run', 'import', 'coverage', 'existing-media-audit', 'person-credit-audit', 'company-credit-audit', 'person-credit-stage'), true)) {
            WP_CLI::error('Mode must be dry-run, import, coverage, existing-media-audit, person-credit-audit, company-credit-audit, or person-credit-stage.');
        }

        if ($mode === 'company-credit-audit') {
            $options = $this->normalize_profile_image_company_credit_audit_cli_args(is_array($assoc_args) ? $assoc_args : array());
            if (is_wp_error($options)) {
                WP_CLI::error($options->get_error_message());
            }

            $audit = $this->build_profile_image_company_credit_audit($options);
            if (is_wp_error($audit)) {
                WP_CLI::error($audit->get_error_message());
            }

            $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
            $samples = is_array($audit['samples'] ?? null) ? $audit['samples'] : array();
            $this->profile_image_batch_cli_report($summary);
            $this->profile_image_company_credit_audit_cli_samples($samples);
            WP_CLI::success('Company and studio credit classifier audit complete.');
            return;
        }

        if ($mode === 'person-credit-stage') {
            $options = $this->normalize_profile_image_person_credit_stage_cli_args(is_array($assoc_args) ? $assoc_args : array());
            if (is_wp_error($options)) {
                WP_CLI::error($options->get_error_message());
            }

            $stage = $this->stage_profile_image_person_credit_reviews_from_csv($options);
            if (is_wp_error($stage)) {
                WP_CLI::error($stage->get_error_message());
            }

            $summary = is_array($stage['summary'] ?? null) ? $stage['summary'] : array();
            $samples = is_array($stage['samples'] ?? null) ? $stage['samples'] : array();
            $this->profile_image_batch_cli_report($summary);
            $this->profile_image_person_credit_stage_cli_samples($samples);

            if (!empty($options['commit'])) {
                WP_CLI::success('Person credit review annotations staged.');
            } else {
                WP_CLI::success('Person credit review staging dry-run complete. Re-run with --commit to write annotations.');
            }
            return;
        }

        if ($mode === 'person-credit-audit') {
            $options = $this->normalize_profile_image_person_credit_audit_cli_args(is_array($assoc_args) ? $assoc_args : array());
            if (is_wp_error($options)) {
                WP_CLI::error($options->get_error_message());
            }

            $audit = $this->build_profile_image_person_credit_audit($options);
            if (is_wp_error($audit)) {
                WP_CLI::error($audit->get_error_message());
            }

            $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
            $samples = is_array($audit['samples'] ?? null) ? $audit['samples'] : array();
            $this->profile_image_batch_cli_report($summary);
            $this->profile_image_person_credit_audit_cli_samples($samples);
            WP_CLI::success('Person credit reconciliation audit complete.');
            return;
        }

        if ($mode === 'existing-media-audit') {
            $options = $this->normalize_profile_image_existing_media_audit_cli_args(is_array($assoc_args) ? $assoc_args : array());
            if (is_wp_error($options)) {
                WP_CLI::error($options->get_error_message());
            }

            $audit = $this->build_profile_image_existing_media_audit($options);
            if (is_wp_error($audit)) {
                WP_CLI::error($audit->get_error_message());
            }

            $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
            $samples = is_array($audit['samples'] ?? null) ? $audit['samples'] : array();
            $this->profile_image_batch_cli_report($summary);
            $this->profile_image_existing_media_cli_samples($samples);

            if (empty($summary['folder_found'])) {
                WP_CLI::warning('No matching media folder was found. Re-run with --all-media to scan every image attachment.');
            }

            WP_CLI::success('Existing people media audit complete.');
            return;
        }

        if ($mode === 'coverage') {
            $options = $this->normalize_profile_image_coverage_cli_args(is_array($assoc_args) ? $assoc_args : array());
            if (is_wp_error($options)) {
                WP_CLI::error($options->get_error_message());
            }

            $audit = $this->build_profile_image_coverage_audit($options);
            if (is_wp_error($audit)) {
                WP_CLI::error($audit->get_error_message());
            }

            $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
            $samples = is_array($audit['samples'] ?? null) ? $audit['samples'] : array();
            $this->profile_image_batch_cli_report($summary);
            $this->profile_image_coverage_cli_samples($samples);
            WP_CLI::success('Manual profile image coverage audit complete.');
            return;
        }

        $options = $this->normalize_profile_image_batch_cli_args($mode, is_array($assoc_args) ? $assoc_args : array());
        if (is_wp_error($options)) {
            WP_CLI::error($options->get_error_message());
        }

        $plan = $this->build_profile_image_batch_plan($options);
        if (is_wp_error($plan)) {
            WP_CLI::error($plan->get_error_message());
        }

        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : array();
        $rows = is_array($plan['rows'] ?? null) ? $plan['rows'] : array();

        if ($mode === 'import') {
            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $errors = array();

            foreach ($rows as $row) {
                $processed++;
                $result = $this->import_manual_person_profile_image($row, (string) $options['batch']);
                if (is_wp_error($result)) {
                    $errors[] = sprintf('%s: %s', (string) ($row['person_id'] ?? ''), $result->get_error_message());
                    continue;
                }

                $status = (string) ($result['status'] ?? '');
                if ($status === 'imported') {
                    $imported++;
                } else {
                    $skipped++;
                }
            }

            $summary['processed'] = $processed;
            $summary['imported'] = $imported;
            $summary['skipped'] = intval($summary['skipped'] ?? 0) + $skipped;
            $summary['errors'] = count($errors);
            $this->profile_image_batch_cli_report($summary);

            foreach ($errors as $error) {
                WP_CLI::warning($error);
            }

            WP_CLI::success('Manual profile image batch import complete.');
            return;
        }

        $summary['processed'] = 0;
        $summary['imported'] = 0;
        $summary['skipped'] = intval($summary['skipped'] ?? 0);
        $summary['errors'] = intval($summary['errors'] ?? 0);
        $this->profile_image_batch_cli_report($summary);
        WP_CLI::success('Manual profile image batch dry-run complete.');
    }

    private function profile_image_cli_flag_is_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'yes', 'y', 'on'), true);
    }

    private function normalize_profile_image_person_credit_stage_cli_args($assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $input_csv = isset($assoc_args['input-csv']) ? $clean_path($assoc_args['input-csv']) : '';
        $state = isset($assoc_args['state']) ? sanitize_key((string) $assoc_args['state']) : 'candidate_found';
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 0;
        $commit = isset($assoc_args['commit']) ? $this->profile_image_cli_flag_is_truthy($assoc_args['commit']) : false;
        $overwrite = isset($assoc_args['overwrite']) ? $this->profile_image_cli_flag_is_truthy($assoc_args['overwrite']) : false;
        $note_prefix = isset($assoc_args['note-prefix']) ? sanitize_text_field((string) $assoc_args['note-prefix']) : 'Private exact-ID candidate. Source row correction remains deferred.';

        if ($input_csv === '') {
            return new WP_Error('aat_person_credit_stage_missing_input', 'Pass --input-csv pointing at a private person-credit review batch CSV.');
        }
        if (!is_readable($input_csv)) {
            return new WP_Error('aat_person_credit_stage_input_unreadable', 'The --input-csv file is not readable.');
        }

        $states = $this->get_person_credit_review_states();
        if (!isset($states[$state])) {
            return new WP_Error('aat_person_credit_stage_bad_state', 'Pass --state as one of: ' . implode(', ', array_keys($states)) . '.');
        }

        return array(
            'mode' => 'person-credit-stage',
            'input_csv' => $input_csv,
            'state' => $state,
            'limit' => max(0, min(1000, $limit)),
            'commit' => $commit,
            'overwrite' => $overwrite,
            'note_prefix' => $note_prefix,
        );
    }

    private function normalize_person_credit_stage_csv_key($key) {
        $key = preg_replace('/^\xEF\xBB\xBF/', '', (string) $key);
        $key = strtolower(trim($key));
        $key = str_replace(array(' ', '-'), '_', $key);
        return sanitize_key($key);
    }

    private function map_person_credit_stage_state($suggested_state, $fallback_state) {
        $suggested_state = sanitize_key((string) $suggested_state);
        if (in_array($suggested_state, array('candidate_found_with_portrait', 'candidate_found_id_only'), true)) {
            return 'candidate_found';
        }
        if ($suggested_state === 'source_gap_or_department_credit') {
            return 'source_gap';
        }
        if (in_array($suggested_state, array('manual_label_id_mismatch', 'manual_duplicate_name'), true)) {
            return 'needs_review';
        }

        return $this->sanitize_person_credit_review_state($fallback_state);
    }

    private function build_person_credit_stage_note($row, $options) {
        $parts = array();
        $note_prefix = trim((string) ($options['note_prefix'] ?? ''));
        if ($note_prefix !== '') {
            $parts[] = $note_prefix;
        }

        $parts[] = 'Private batch file: ' . basename((string) ($options['input_csv'] ?? ''));

        $context = array(
            'Film' => $row['film'] ?? '',
            'Ceremony' => $row['ceremony'] ?? '',
            'Year' => $row['year'] ?? '',
            'Suggested state' => $row['suggested_state'] ?? '',
            'TMDb status' => $row['tmdb_status'] ?? '',
            'TMDb person id' => $row['tmdb_person_id'] ?? '',
            'TMDb image file' => $row['tmdb_image_file'] ?? '',
            'Match confidence' => $row['match_confidence'] ?? '',
            'Match note' => $row['match_note'] ?? '',
        );

        foreach ($context as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $parts);
    }

    private function read_person_credit_stage_csv_rows($options) {
        $input_csv = (string) ($options['input_csv'] ?? '');
        $limit = max(0, min(1000, intval($options['limit'] ?? 0)));
        $handle = fopen($input_csv, 'r');
        if (!$handle) {
            return new WP_Error('aat_person_credit_stage_csv_open_failed', 'Could not open --input-csv for reading.');
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            return new WP_Error('aat_person_credit_stage_csv_missing_header', 'The --input-csv file must include a header row.');
        }

        $keys = array();
        foreach ($headers as $index => $header) {
            $key = $this->normalize_person_credit_stage_csv_key($header);
            if ($key !== '') {
                $keys[$index] = $key;
            }
        }

        $required = array('review_key', 'source_award_id', 'credit_label', 'proposed_person_id');
        foreach ($required as $field) {
            if (!in_array($field, $keys, true)) {
                fclose($handle);
                return new WP_Error('aat_person_credit_stage_csv_missing_column', 'The --input-csv file must include a ' . $field . ' column.');
            }
        }

        $rows = array();
        $errors = array();
        $seen = array();
        $row_number = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $row_number++;
            $raw = array();
            foreach ($keys as $index => $key) {
                $raw[$key] = isset($values[$index]) && is_scalar($values[$index]) ? trim((string) $values[$index]) : '';
            }

            if (implode('', $raw) === '') {
                continue;
            }

            $review_key = $this->sanitize_person_credit_review_key($raw['review_key'] ?? '');
            $source_award_id = absint($raw['source_award_id'] ?? 0);
            $credit_label = sanitize_text_field((string) ($raw['credit_label'] ?? ''));
            $proposed_person_id = strtolower(trim(sanitize_text_field((string) ($raw['proposed_person_id'] ?? ''))));
            $category_slug = sanitize_title((string) ($raw['category_slug'] ?? ($raw['category'] ?? '')));
            $state = $this->map_person_credit_stage_state($raw['suggested_state'] ?? '', (string) ($options['state'] ?? 'candidate_found'));

            if ($review_key === '') {
                $errors[] = 'Row ' . $row_number . ' has an invalid review_key.';
                continue;
            }
            if (isset($seen[$review_key])) {
                $errors[] = 'Row ' . $row_number . ' repeats review_key ' . $review_key . '.';
                continue;
            }
            if ($source_award_id <= 0) {
                $errors[] = 'Row ' . $row_number . ' has an invalid source_award_id.';
                continue;
            }
            if ($credit_label === '') {
                $errors[] = 'Row ' . $row_number . ' has an empty credit_label.';
                continue;
            }
            if (!$this->is_imdb_name_entity_id($proposed_person_id)) {
                $errors[] = 'Row ' . $row_number . ' has an invalid proposed_person_id.';
                continue;
            }

            $seen[$review_key] = true;
            $raw['review_key'] = $review_key;
            $raw['source_award_id'] = $source_award_id;
            $raw['credit_label'] = $credit_label;
            $raw['proposed_person_id'] = $proposed_person_id;
            $raw['category_slug'] = $category_slug;
            $raw['review_state'] = $state;
            $raw['correction_note'] = $this->build_person_credit_stage_note($raw, $options);
            $rows[] = $raw;

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        fclose($handle);

        if (!empty($errors)) {
            return new WP_Error('aat_person_credit_stage_csv_errors', implode(' ', array_slice($errors, 0, 10)));
        }
        if (empty($rows)) {
            return new WP_Error('aat_person_credit_stage_csv_empty', 'The --input-csv file did not contain any stageable rows.');
        }

        return $rows;
    }

    private function stage_profile_image_person_credit_reviews_from_csv($options) {
        $rows = $this->read_person_credit_stage_csv_rows($options);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $review_keys = array();
        foreach ($rows as $row) {
            $review_keys[] = (string) ($row['review_key'] ?? '');
        }
        $existing = $this->get_person_credit_review_records_for_keys($review_keys);

        $summary = array(
            'mode' => 'person-credit-stage',
            'input_csv' => basename((string) ($options['input_csv'] ?? '')),
            'state' => (string) ($options['state'] ?? 'candidate_found'),
            'limit' => intval($options['limit'] ?? 0),
            'commit' => !empty($options['commit']) ? 'yes' : 'no',
            'overwrite' => !empty($options['overwrite']) ? 'yes' : 'no',
            'parsed_rows' => count($rows),
            'stageable' => 0,
            'would_stage' => 0,
            'staged' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        );

        $samples = array();
        $errors = array();
        foreach ($rows as $row) {
            $review_key = (string) ($row['review_key'] ?? '');
            if (isset($existing[$review_key]) && empty($options['overwrite'])) {
                $summary['skipped_existing']++;
                continue;
            }

            $summary['stageable']++;
            if (empty($options['commit'])) {
                $summary['would_stage']++;
                $samples[] = $row;
                continue;
            }

            $result = $this->replace_person_credit_review_record(array(
                'review_key' => $review_key,
                'review_state' => (string) ($row['review_state'] ?? 'candidate_found'),
                'proposed_person_id' => (string) ($row['proposed_person_id'] ?? ''),
                'category_slug' => (string) ($row['category_slug'] ?? ''),
                'credit_label' => (string) ($row['credit_label'] ?? ''),
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => 0,
            ));

            if (is_wp_error($result)) {
                $summary['errors']++;
                $errors[] = $review_key . ': ' . $result->get_error_message();
                continue;
            }

            $summary['staged']++;
            $samples[] = $row;
        }

        if (!empty($errors)) {
            foreach (array_slice($errors, 0, 10) as $error) {
                WP_CLI::warning($error);
            }
        }

        return array(
            'summary' => $summary,
            'samples' => array_slice($samples, 0, 10),
        );
    }

    private function profile_image_person_credit_stage_cli_samples($samples) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') || empty($samples)) {
            return;
        }

        WP_CLI::line('person_credit_stage_samples:');
        foreach ($samples as $row) {
            WP_CLI::line(sprintf(
                '  - %s | #%d | %s | %s | %s',
                (string) ($row['review_key'] ?? ''),
                intval($row['source_award_id'] ?? 0),
                (string) ($row['review_state'] ?? ''),
                (string) ($row['credit_label'] ?? ''),
                (string) ($row['proposed_person_id'] ?? '')
            ));
        }
    }

    private function normalize_profile_image_person_credit_audit_cli_args($assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $category = isset($assoc_args['category']) ? sanitize_title((string) $assoc_args['category']) : '';
        $sample = isset($assoc_args['sample']) ? intval($assoc_args['sample']) : 50;
        $state = isset($assoc_args['state']) ? sanitize_key((string) $assoc_args['state']) : 'unresolved';
        $output_csv = isset($assoc_args['output-csv']) ? $clean_path($assoc_args['output-csv']) : '';

        if (!in_array($state, array('all', 'linked', 'unresolved'), true)) {
            return new WP_Error('aat_person_credit_audit_bad_state', 'Pass --state=all, --state=linked, or --state=unresolved.');
        }

        if ($output_csv !== '') {
            $dir = dirname($output_csv);
            if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
                return new WP_Error('person_credit_audit_output_not_private', 'The --output-csv directory must exist and be writable.');
            }
        }

        return array(
            'mode' => 'person-credit-audit',
            'category' => $category,
            'sample' => max(0, min(500, $sample)),
            'state' => $state,
            'output_csv' => $output_csv,
        );
    }

    private function build_profile_image_person_credit_audit($options) {
        global $wpdb;

        $category = sanitize_title((string) ($options['category'] ?? ''));
        $state_filter = sanitize_key((string) ($options['state'] ?? 'unresolved'));
        if (!in_array($state_filter, array('all', 'linked', 'unresolved'), true)) {
            $state_filter = 'unresolved';
        }
        $sample_limit = max(0, min(500, intval($options['sample'] ?? 50)));
        $output_csv = (string) ($options['output_csv'] ?? '');

        $table_name = $this->get_table_name();
        $where = 'WHERE (TRIM(COALESCE(nominees, \'\')) <> \'\' OR TRIM(COALESCE(name, \'\')) <> \'\')';
        $params = array();
        $sql = "SELECT id, ceremony, year, canonical_category, category, film, name, nominees, nominee_ids, winner
                FROM {$table_name}
                {$where}
                ORDER BY ceremony DESC, canonical_category ASC, film ASC, id ASC";
        $rows = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        if (!is_array($rows)) {
            $rows = array();
        }

        $audit_rows = array();
        $summary = array(
            'mode' => 'person-credit-audit',
            'category' => $category,
            'state' => $state_filter,
            'source_rows' => 0,
            'credit_labels' => 0,
            'person_credit_linked' => 0,
            'person_credit_unresolved' => 0,
            'missing_source_nominee_ids' => 0,
            'label_id_mismatch' => 0,
            'samples' => $sample_limit,
            'output_csv' => $output_csv,
        );

        foreach ($rows as $source_row) {
            $row_category = (string) ($source_row['canonical_category'] ?: ($source_row['category'] ?? ''));
            if ($category !== '' && sanitize_title($row_category) !== $category) {
                continue;
            }
            $summary['source_rows']++;

            $source_value = trim((string) ($source_row['nominees'] ?? ''));
            if ($source_value === '') {
                $source_value = trim((string) ($source_row['name'] ?? ''));
            }

            $labels = $this->split_visible_person_credit_labels($source_value);
            if (empty($labels)) {
                continue;
            }

            $source_nominee_ids = $this->extract_entity_reference_ids((string) ($source_row['nominee_ids'] ?? ''));
            foreach ($labels as $label_index => $credit_label) {
                $credit_label = trim((string) $credit_label);
                if ($credit_label === '') {
                    continue;
                }

                $summary['credit_labels']++;
                $link = $this->get_name_entity_link_by_label($credit_label);
                $resolved_id = strtolower(trim((string) ($link['id'] ?? '')));
                $has_link = $this->is_name_entity_id($resolved_id);
                $row_state = $has_link ? 'person_credit_linked' : 'person_credit_unresolved';
                $source_id_for_position = isset($source_nominee_ids[$label_index]) ? strtolower(trim((string) $source_nominee_ids[$label_index])) : '';
                $has_source_ids = !empty($source_nominee_ids);
                $mismatch = $has_source_ids && (!$this->is_name_entity_id($source_id_for_position) || ($has_link && $source_id_for_position !== $resolved_id));

                if ($has_link) {
                    $summary['person_credit_linked']++;
                } else {
                    $summary['person_credit_unresolved']++;
                    if (!$has_source_ids) {
                        $summary['missing_source_nominee_ids']++;
                    }
                }
                if ($mismatch) {
                    $summary['label_id_mismatch']++;
                }

                if ($state_filter === 'linked' && !$has_link) {
                    continue;
                }
                if ($state_filter === 'unresolved' && $has_link) {
                    continue;
                }

                $source_award_id = intval($source_row['id'] ?? 0);
                $review_key = $this->get_person_credit_review_key($source_award_id, $label_index);
                $audit_rows[] = array(
                    'review_key' => $review_key,
                    'source_award_id' => $source_award_id,
                    'ceremony' => intval($source_row['ceremony'] ?? 0),
                    'year' => (string) ($source_row['year'] ?? ''),
                    'category' => $row_category,
                    'category_slug' => sanitize_title($row_category),
                    'film' => (string) ($source_row['film'] ?? ''),
                    'source_credit' => $source_value,
                    'credit_label' => $credit_label,
                    'label_index' => intval($label_index) + 1,
                    'state' => $row_state,
                    'resolved_id' => $resolved_id,
                    'resolved_url' => $has_link ? (string) ($link['url'] ?? '') : '',
                    'source_nominee_ids' => implode('|', $source_nominee_ids),
                    'source_id_for_position' => $source_id_for_position,
                    'missing_source_nominee_ids' => !$has_source_ids ? 1 : 0,
                    'label_id_mismatch' => $mismatch ? 1 : 0,
                    'winner' => !empty($source_row['winner']) ? 1 : 0,
                );
            }
        }

        if ($output_csv !== '') {
            $csv_result = $this->write_profile_image_person_credit_audit_csv($output_csv, $audit_rows);
            if (is_wp_error($csv_result)) {
                return $csv_result;
            }
        }

        return array(
            'summary' => $summary,
            'rows' => $audit_rows,
            'samples' => $this->profile_image_person_credit_audit_sample_rows($audit_rows, $sample_limit),
        );
    }

    private function profile_image_person_credit_audit_sample_rows($rows, $limit) {
        $limit = max(0, min(500, intval($limit)));
        if ($limit < 1 || empty($rows)) {
            return array();
        }

        return array_slice(array_values($rows), 0, $limit);
    }

    private function profile_image_person_credit_audit_cli_samples($samples) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') || empty($samples)) {
            return;
        }

        WP_CLI::line('person_credit_samples:');
        foreach ($samples as $row) {
            WP_CLI::line(sprintf(
                '  - #%d | %s | %s | %s | %s | %s',
                intval($row['source_award_id'] ?? 0),
                (string) ($row['state'] ?? ''),
                (string) ($row['category'] ?? ''),
                (string) ($row['credit_label'] ?? ''),
                (string) ($row['resolved_id'] ?? ''),
                (string) ($row['source_nominee_ids'] ?? '')
            ));
        }
    }

    private function write_profile_image_person_credit_audit_csv($path, $rows) {
        $path = trim((string) $path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return true;
        }

        $handle = fopen($path, 'w');
        if (!$handle) {
            return new WP_Error('aat_person_credit_audit_csv_open_failed', 'Could not open --output-csv for writing.');
        }

        $fields = array(
            'review_key',
            'source_award_id',
            'ceremony',
            'year',
            'category',
            'category_slug',
            'film',
            'source_credit',
            'credit_label',
            'label_index',
            'state',
            'resolved_id',
            'resolved_url',
            'source_nominee_ids',
            'source_id_for_position',
            'missing_source_nominee_ids',
            'label_id_mismatch',
            'winner',
        );

        fputcsv($handle, $fields);
        foreach ($rows as $row) {
            $line = array();
            foreach ($fields as $field) {
                $line[] = isset($row[$field]) && is_scalar($row[$field]) ? (string) $row[$field] : '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
        return true;
    }

    private function normalize_profile_image_company_credit_audit_cli_args($assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $category = isset($assoc_args['category']) ? sanitize_title((string) $assoc_args['category']) : '';
        $sample = isset($assoc_args['sample']) ? intval($assoc_args['sample']) : 80;
        $state = isset($assoc_args['state']) ? sanitize_key((string) $assoc_args['state']) : 'all';
        $output_csv = isset($assoc_args['output-csv']) ? $clean_path($assoc_args['output-csv']) : '';
        $allowed_states = array('all', 'company', 'department', 'mixed', 'source_gap', 'person', 'slot_mismatch');

        if (!in_array($state, $allowed_states, true)) {
            return new WP_Error('aat_company_credit_audit_bad_state', 'Pass --state as one of: ' . implode(', ', $allowed_states) . '.');
        }

        if ($output_csv !== '') {
            $dir = dirname($output_csv);
            if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
                return new WP_Error('company_credit_audit_output_not_private', 'The --output-csv directory must exist and be writable.');
            }
        }

        return array(
            'mode' => 'company-credit-audit',
            'category' => $category,
            'sample' => max(0, min(500, $sample)),
            'state' => $state,
            'output_csv' => $output_csv,
        );
    }

    private function parse_company_credit_row_nominee_id_slots($raw_ids, $preserve_empty = true) {
        $raw_ids = str_replace(array("\r\n", "\r"), "\n", (string) $raw_ids);
        $raw_ids = trim($raw_ids);
        if ($raw_ids === '') {
            return array();
        }

        if (strpos($raw_ids, "\n") !== false) {
            $parts = explode("\n", $raw_ids);
        } elseif (strpos($raw_ids, '|') !== false) {
            $parts = explode('|', $raw_ids);
        } else {
            $parts = preg_split('/\s*,\s*/', $raw_ids);
        }

        $slots = array();
        foreach ((array) $parts as $part) {
            $part = strtolower(trim(sanitize_text_field((string) $part)));
            if ($part === '') {
                if ($preserve_empty) {
                    $slots[] = '';
                }
                continue;
            }

            $slots[] = $part;
        }

        return $slots;
    }

    private function validate_company_credit_row_nominee_id_slots($nominee_ids, $allow_blank = true) {
        foreach ((array) $nominee_ids as $index => $nominee_id) {
            $nominee_id = strtolower(trim((string) $nominee_id));
            if ($nominee_id === '' && $allow_blank) {
                continue;
            }

            if (!$this->is_company_entity_id($nominee_id)) {
                return new WP_Error(
                    'aat_company_credit_row_bad_company_id',
                    sprintf(
                        __('Nominee ID slot %1$d must be a valid IMDb co ID%2$s.', 'academy-awards-table'),
                        intval($index) + 1,
                        $allow_blank ? __(' or blank', 'academy-awards-table') : ''
                    )
                );
            }
        }

        return true;
    }

    private function get_company_credit_row_review_states() {
        return array(
            'needs_review' => __('Needs Review', 'academy-awards-table'),
            'ready_to_apply' => __('Ready To Apply', 'academy-awards-table'),
            'department_label_only' => __('Department Label Only', 'academy-awards-table'),
            'source_gap' => __('Source Gap', 'academy-awards-table'),
            'applied' => __('Applied', 'academy-awards-table'),
            'ignore_accept' => __('Ignore / Accept', 'academy-awards-table'),
        );
    }

    private function sanitize_company_credit_row_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_company_credit_row_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function get_company_credit_entity_kinds() {
        return array(
            'company' => __('Company / Studio', 'academy-awards-table'),
            'department' => __('Department Label Only', 'academy-awards-table'),
            'mixed' => __('Mixed Company / Department', 'academy-awards-table'),
            'source_gap' => __('Source Gap', 'academy-awards-table'),
            'person' => __('Person Credit', 'academy-awards-table'),
            'slot_mismatch' => __('Slot Mismatch', 'academy-awards-table'),
        );
    }

    private function sanitize_company_credit_entity_kind($kind) {
        $kind = sanitize_key((string) $kind);
        $kinds = $this->get_company_credit_entity_kinds();
        return isset($kinds[$kind]) ? $kind : 'source_gap';
    }

    private function company_credit_label_has_department_signal($label) {
        $label = strtolower((string) $label);
        return (bool) preg_match('/\b(?:department|dept|sound department|recording department|studio sound department|sound staff|sound unit)\b/', $label);
    }

    private function company_credit_label_has_company_signal($label) {
        $label = strtolower((string) $label);
        return (bool) preg_match('/\b(?:company|companies|corp|corporation|inc|ltd|limited|llc|studio|studios|pictures|picture|production|productions|entertainment|films|cinema|columbia|fox|metro-goldwyn-mayer|mgm|universal|warner bros|warner brothers|paramount|rko|united artists|seven arts|shepperton|goldwyn|rca|republic)\b/', $label);
    }

    private function classify_company_credit_source_row($source_row) {
        $labels = $this->get_person_credit_source_row_visible_labels($source_row);
        $source_nominee_id_slots = $this->parse_company_credit_row_nominee_id_slots($source_row['nominee_ids'] ?? '', false);
        $source_ids = array_values(array_filter(array_map('trim', $source_nominee_id_slots), 'strlen'));

        $department_labels = 0;
        $company_labels = 0;
        $person_link_labels = 0;
        foreach ($labels as $label) {
            if ($this->company_credit_label_has_department_signal($label)) {
                $department_labels++;
            }
            if ($this->company_credit_label_has_company_signal($label)) {
                $company_labels++;
            }

            $link = $this->get_name_entity_link_by_label($label);
            if ($this->is_name_entity_id(strtolower(trim((string) ($link['id'] ?? ''))))) {
                $person_link_labels++;
            }
        }

        $company_ids = array();
        $person_ids = array();
        $title_ids = array();
        $other_ids = array();
        foreach ($source_ids as $source_id) {
            $source_id = strtolower(trim((string) $source_id));
            if ($this->is_company_entity_id($source_id)) {
                $company_ids[] = $source_id;
            } elseif ($this->is_name_entity_id($source_id)) {
                $person_ids[] = $source_id;
            } elseif ($this->is_title_entity_id($source_id)) {
                $title_ids[] = $source_id;
            } else {
                $other_ids[] = $source_id;
            }
        }

        $label_count = count($labels);
        $source_id_count = count($source_ids);
        $has_department = $department_labels > 0;
        $has_company = $company_labels > 0 || !empty($company_ids);
        $slot_mismatch = ($has_company || $has_department) && $source_id_count > 0 && $label_count !== $source_id_count;

        if ($has_department && $department_labels === $label_count) {
            $entity_kind = 'department';
        } elseif ($has_department && $has_company) {
            $entity_kind = 'mixed';
        } elseif ($has_department) {
            $entity_kind = 'department';
        } elseif ($has_company) {
            $entity_kind = 'company';
        } elseif (!empty($person_ids) || $person_link_labels > 0) {
            $entity_kind = 'person';
        } else {
            $entity_kind = 'source_gap';
        }

        if ($entity_kind === 'company' && (!empty($person_ids) || !empty($title_ids) || !empty($other_ids))) {
            $entity_kind = 'mixed';
        }

        return array(
            'entity_kind' => $entity_kind,
            'labels' => $labels,
            'label_count' => $label_count,
            'source_nominee_ids' => implode('|', $source_ids),
            'source_id_count' => $source_id_count,
            'company_id_count' => count($company_ids),
            'person_id_count' => count($person_ids),
            'title_id_count' => count($title_ids),
            'department_label_count' => $department_labels,
            'company_label_count' => $company_labels,
            'person_link_label_count' => $person_link_labels,
            'slot_mismatch' => $slot_mismatch ? 1 : 0,
        );
    }

    private function build_profile_image_company_credit_audit($options) {
        global $wpdb;

        $category = sanitize_title((string) ($options['category'] ?? ''));
        $state_filter = sanitize_key((string) ($options['state'] ?? 'all'));
        $allowed_states = array('all', 'company', 'department', 'mixed', 'source_gap', 'person', 'slot_mismatch');
        if (!in_array($state_filter, $allowed_states, true)) {
            $state_filter = 'all';
        }
        $sample_limit = max(0, min(500, intval($options['sample'] ?? 80)));
        $output_csv = (string) ($options['output_csv'] ?? '');

        $table_name = $this->get_table_name();
        $sql = "SELECT id, ceremony, year, canonical_category, category, film, name, nominees, nominee_ids, winner
                FROM {$table_name}
                WHERE (TRIM(COALESCE(nominees, '')) <> '' OR TRIM(COALESCE(name, '')) <> '')
                ORDER BY ceremony DESC, canonical_category ASC, film ASC, id ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        $summary = array(
            'mode' => 'company-credit-audit',
            'category' => $category,
            'state' => $state_filter,
            'source_rows' => 0,
            'candidate_rows' => 0,
            'company' => 0,
            'department' => 0,
            'mixed' => 0,
            'source_gap' => 0,
            'person' => 0,
            'slot_mismatch' => 0,
            'missing_source_nominee_ids' => 0,
            'label_id_mismatch' => 0,
            'samples' => $sample_limit,
            'output_csv' => $output_csv,
        );
        $audit_rows = array();

        foreach ($rows as $source_row) {
            $row_category = (string) ($source_row['canonical_category'] ?: ($source_row['category'] ?? ''));
            if ($category !== '' && sanitize_title($row_category) !== $category) {
                continue;
            }

            $summary['source_rows']++;
            $classification = $this->classify_company_credit_source_row($source_row);
            if (empty($classification['labels'])) {
                continue;
            }

            $summary['candidate_rows']++;
            $entity_kind = (string) ($classification['entity_kind'] ?? 'source_gap');
            $slot_mismatch = !empty($classification['slot_mismatch']);
            if (isset($summary[$entity_kind])) {
                $summary[$entity_kind]++;
            }
            if ($slot_mismatch) {
                $summary['slot_mismatch']++;
                $summary['label_id_mismatch']++;
            }
            if (intval($classification['source_id_count'] ?? 0) < 1) {
                $summary['missing_source_nominee_ids']++;
            }

            if ($state_filter !== 'all' && $state_filter !== $entity_kind && !($state_filter === 'slot_mismatch' && $slot_mismatch)) {
                continue;
            }

            $audit_rows[] = array(
                'source_award_id' => intval($source_row['id'] ?? 0),
                'ceremony' => intval($source_row['ceremony'] ?? 0),
                'year' => (string) ($source_row['year'] ?? ''),
                'category' => $row_category,
                'category_slug' => sanitize_title($row_category),
                'film' => (string) ($source_row['film'] ?? ''),
                'source_credit' => trim((string) (($source_row['nominees'] ?? '') ?: ($source_row['name'] ?? ''))),
                'credit_labels' => implode('|', (array) ($classification['labels'] ?? array())),
                'entity_kind' => $entity_kind,
                'source_nominee_ids' => (string) ($classification['source_nominee_ids'] ?? ''),
                'label_count' => intval($classification['label_count'] ?? 0),
                'source_id_count' => intval($classification['source_id_count'] ?? 0),
                'company_id_count' => intval($classification['company_id_count'] ?? 0),
                'person_id_count' => intval($classification['person_id_count'] ?? 0),
                'department_label_count' => intval($classification['department_label_count'] ?? 0),
                'company_label_count' => intval($classification['company_label_count'] ?? 0),
                'slot_mismatch' => $slot_mismatch ? 1 : 0,
                'winner' => !empty($source_row['winner']) ? 1 : 0,
            );
        }

        if ($output_csv !== '') {
            $csv_result = $this->write_profile_image_company_credit_audit_csv($output_csv, $audit_rows);
            if (is_wp_error($csv_result)) {
                return $csv_result;
            }
        }

        return array(
            'summary' => $summary,
            'rows' => $audit_rows,
            'samples' => array_slice(array_values($audit_rows), 0, $sample_limit),
        );
    }

    private function profile_image_company_credit_audit_cli_samples($samples) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') || empty($samples)) {
            return;
        }

        WP_CLI::line('company_credit_samples:');
        foreach ($samples as $row) {
            WP_CLI::line(sprintf(
                '  - #%d | %s | labels=%d ids=%d mismatch=%d | %s | %s',
                intval($row['source_award_id'] ?? 0),
                (string) ($row['entity_kind'] ?? ''),
                intval($row['label_count'] ?? 0),
                intval($row['source_id_count'] ?? 0),
                intval($row['slot_mismatch'] ?? 0),
                (string) ($row['credit_labels'] ?? ''),
                (string) ($row['source_nominee_ids'] ?? '')
            ));
        }
    }

    private function write_profile_image_company_credit_audit_csv($path, $rows) {
        $path = trim((string) $path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return true;
        }

        $handle = fopen($path, 'w');
        if (!$handle) {
            return new WP_Error('aat_company_credit_audit_csv_open_failed', 'Could not open --output-csv for writing.');
        }

        $fields = array(
            'source_award_id',
            'ceremony',
            'year',
            'category',
            'category_slug',
            'film',
            'source_credit',
            'credit_labels',
            'entity_kind',
            'source_nominee_ids',
            'label_count',
            'source_id_count',
            'company_id_count',
            'person_id_count',
            'department_label_count',
            'company_label_count',
            'slot_mismatch',
            'winner',
        );

        fputcsv($handle, $fields);
        foreach ($rows as $row) {
            $line = array();
            foreach ($fields as $field) {
                $line[] = isset($row[$field]) && is_scalar($row[$field]) ? (string) $row[$field] : '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
        return true;
    }

    private function get_company_credit_review_filter_labels() {
        return array_merge(
            array(
                'all' => __('All Review States', 'academy-awards-table'),
                'unreviewed' => __('Unreviewed', 'academy-awards-table'),
            ),
            $this->get_company_credit_row_review_states()
        );
    }

    private function sanitize_company_credit_review_filter($filter) {
        $filter = sanitize_key((string) $filter);
        $labels = $this->get_company_credit_review_filter_labels();
        return isset($labels[$filter]) ? $filter : 'all';
    }

    private function get_company_credit_entity_filter_labels() {
        return array_merge(
            array(
                'all' => __('All Entity Kinds', 'academy-awards-table'),
            ),
            $this->get_company_credit_entity_kinds()
        );
    }

    private function sanitize_company_credit_entity_filter($filter) {
        $filter = sanitize_key((string) $filter);
        $labels = $this->get_company_credit_entity_filter_labels();
        return isset($labels[$filter]) ? $filter : 'all';
    }

    private function pack_company_credit_row_labels($labels) {
        return $this->pack_person_credit_row_labels($labels);
    }

    private function unpack_company_credit_row_labels($labels) {
        return $this->unpack_person_credit_row_labels($labels);
    }

    private function replace_company_credit_row_review_record($args) {
        $source_award_id = absint($args['source_award_id'] ?? 0);
        if ($source_award_id <= 0) {
            return new WP_Error('aat_company_credit_row_review_bad_source', __('Invalid source award row for company/studio review.', 'academy-awards-table'));
        }

        $state = $this->sanitize_company_credit_row_review_state($args['review_state'] ?? '');
        $entity_kind = $this->sanitize_company_credit_entity_kind($args['entity_kind'] ?? '');
        $category_slug = sanitize_title((string) ($args['category_slug'] ?? ''));
        $credit_labels = isset($args['credit_labels']) && is_array($args['credit_labels'])
            ? $this->pack_company_credit_row_labels($args['credit_labels'])
            : sanitize_textarea_field((string) ($args['credit_labels'] ?? ''));
        $proposed_slots = isset($args['proposed_nominee_ids']) && is_array($args['proposed_nominee_ids'])
            ? array_map('strval', $args['proposed_nominee_ids'])
            : $this->parse_company_credit_row_nominee_id_slots($args['proposed_nominee_ids'] ?? '', true);
        $validation = $this->validate_company_credit_row_nominee_id_slots($proposed_slots, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $display_label_override = sanitize_textarea_field((string) ($args['display_label_override'] ?? ''));
        $note = sanitize_textarea_field((string) ($args['correction_note'] ?? ''));
        $reviewer_user_id = absint($args['reviewer_user_id'] ?? get_current_user_id());
        $now = current_time('mysql');

        global $wpdb;
        $table = $this->get_company_credit_row_reviews_table_name();
        $this->maybe_create_company_credit_row_reviews_table();

        $result = $wpdb->replace(
            $table,
            array(
                'source_award_id' => $source_award_id,
                'category_slug' => $category_slug,
                'credit_labels' => $credit_labels,
                'review_state' => $state,
                'entity_kind' => $entity_kind,
                'proposed_nominee_ids' => implode('|', $proposed_slots),
                'display_label_override' => $display_label_override,
                'correction_note' => $note,
                'reviewer_user_id' => $reviewer_user_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_company_credit_row_review_save_failed', __('Could not save the company/studio credit review.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_company_credit_row_review_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_company_credit_row_review_forbidden', __('You do not have permission to save company/studio credit reviews.', 'academy-awards-table'));
        }

        $source_award_id = absint($request['aat_company_credit_row_source_award_id'] ?? 0);
        $source_row = $this->get_person_credit_source_award_row($source_award_id);
        if (empty($source_row)) {
            return new WP_Error('aat_company_credit_row_review_missing_source', __('The source award row could not be reloaded.', 'academy-awards-table'));
        }

        $labels = $this->get_person_credit_source_row_visible_labels($source_row);
        if (empty($labels)) {
            return new WP_Error('aat_company_credit_row_review_missing_labels', __('The source award row no longer has visible company/studio credit labels.', 'academy-awards-table'));
        }

        $classification = $this->classify_company_credit_source_row($source_row);
        $proposed_nominee_ids = isset($request['aat_company_credit_row_proposed_nominee_ids'])
            ? wp_unslash($request['aat_company_credit_row_proposed_nominee_ids'])
            : '';
        $display_label_override = isset($request['aat_company_credit_row_display_label_override'])
            ? sanitize_textarea_field(wp_unslash($request['aat_company_credit_row_display_label_override']))
            : '';
        $note = isset($request['aat_company_credit_row_review_note'])
            ? sanitize_textarea_field(wp_unslash($request['aat_company_credit_row_review_note']))
            : '';

        return $this->replace_company_credit_row_review_record(array(
            'source_award_id' => $source_award_id,
            'category_slug' => $this->get_person_credit_source_row_category_slug($source_row),
            'credit_labels' => $labels,
            'review_state' => $request['aat_company_credit_row_review_state'] ?? '',
            'entity_kind' => $request['aat_company_credit_row_entity_kind'] ?? ($classification['entity_kind'] ?? ''),
            'proposed_nominee_ids' => $proposed_nominee_ids,
            'display_label_override' => $display_label_override,
            'correction_note' => $note,
            'reviewer_user_id' => get_current_user_id(),
        ));
    }

    private function company_credit_company_id_has_public_profile($company_id) {
        $company_id = strtolower(trim((string) $company_id));
        if (!$this->is_company_entity_id($company_id)) {
            return false;
        }

        $label = trim((string) $this->get_entity_display_name('company', $company_id));
        if ($label !== '') {
            return true;
        }

        $rows = $this->get_entity_rows('company', $company_id);
        return is_array($rows) && !empty($rows);
    }

    private function build_company_credit_row_preview_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_company_credit_row_preview_forbidden', __('You do not have permission to preview company/studio credit rows.', 'academy-awards-table'));
        }

        $source_award_id = absint($request['aat_company_credit_row_preview_source_award_id'] ?? 0);
        $typed_confirmation = isset($request['aat_company_credit_row_preview_confirm_source_id'])
            ? sanitize_text_field(wp_unslash($request['aat_company_credit_row_preview_confirm_source_id']))
            : '';
        $records = $this->get_company_credit_row_review_records_for_source_ids(array($source_award_id));
        $review = isset($records[$source_award_id])
            ? $records[$source_award_id]
            : $this->get_default_company_credit_row_review_record($source_award_id, 'source_gap');

        return $this->build_company_credit_row_preview($source_award_id, $review, $typed_confirmation);
    }

    private function build_company_credit_row_preview($source_award_id, $review, $typed_confirmation = '') {
        $source_award_id = absint($source_award_id);
        $preview = array(
            'ready' => false,
            'state' => 'blocked',
            'message' => __('Save a company/studio review before previewing a source-row change.', 'academy-awards-table'),
            'source_award_id' => $source_award_id,
            'labels' => array(),
            'current_nominee_ids' => '',
            'new_nominee_ids' => '',
            'visible_label_count' => 0,
            'review_state' => '',
            'entity_kind' => '',
            'proposed_slots' => array(),
            'slot_previews' => array(),
            'display_label_override' => '',
            'required_confirmation' => $source_award_id > 0 ? (string) $source_award_id : '',
            'typed_confirmation' => trim((string) $typed_confirmation),
        );

        if ($source_award_id <= 0) {
            $preview['state'] = 'bad_source';
            $preview['message'] = __('Invalid source award row.', 'academy-awards-table');
            return $preview;
        }

        $source_row = $this->get_person_credit_source_award_row($source_award_id);
        if (empty($source_row)) {
            $preview['state'] = 'missing_source';
            $preview['message'] = __('The source award row could not be reloaded; no company/studio preview is available.', 'academy-awards-table');
            return $preview;
        }

        $labels = $this->get_person_credit_source_row_visible_labels($source_row);
        $preview['labels'] = $labels;
        $preview['visible_label_count'] = count($labels);
        if (empty($labels)) {
            $preview['state'] = 'missing_labels';
            $preview['message'] = __('The source award row no longer has visible company/studio credit labels.', 'academy-awards-table');
            return $preview;
        }

        $current_nominee_ids = $this->parse_company_credit_row_nominee_id_slots($source_row['nominee_ids'] ?? '', false);
        $preview['current_nominee_ids'] = implode('|', $current_nominee_ids);
        $preview['display_label_override'] = (string) ($review['display_label_override'] ?? '');

        if (empty($review['is_reviewed'])) {
            return $preview;
        }

        $stored_category = sanitize_title((string) ($review['category_slug'] ?? ''));
        $current_category = $this->get_person_credit_source_row_category_slug($source_row);
        if ($stored_category !== '' && $stored_category !== $current_category) {
            $preview['state'] = 'category_mismatch';
            $preview['message'] = __('The source category changed after this company/studio review was saved; refresh before previewing.', 'academy-awards-table');
            return $preview;
        }

        $stored_labels = isset($review['credit_label_list']) && is_array($review['credit_label_list'])
            ? $review['credit_label_list']
            : $this->unpack_company_credit_row_labels($review['credit_labels'] ?? '');
        if (!empty($stored_labels) && $this->normalize_person_credit_label_list_for_compare($stored_labels) !== $this->normalize_person_credit_label_list_for_compare($labels)) {
            $preview['state'] = 'label_mismatch';
            $preview['message'] = __('The visible source credit labels changed after this company/studio review was saved; refresh before previewing.', 'academy-awards-table');
            return $preview;
        }

        $review_state = $this->sanitize_company_credit_row_review_state($review['review_state'] ?? '');
        $entity_kind = $this->sanitize_company_credit_entity_kind($review['entity_kind'] ?? '');
        $preview['review_state'] = $review_state;
        $preview['entity_kind'] = $entity_kind;

        if ($review_state === 'applied') {
            $preview['state'] = 'applied';
            $preview['message'] = __('This company/studio review is already marked applied.', 'academy-awards-table');
            return $preview;
        }

        if ($entity_kind !== 'company') {
            $preview['state'] = 'not_company';
            $preview['message'] = __('Preview validation is enabled only for true company/studio rows. Department, mixed, source-gap, person, and slot-mismatch rows stay review-only.', 'academy-awards-table');
            return $preview;
        }

        if ($review_state !== 'ready_to_apply') {
            $preview['state'] = 'not_ready_state';
            $preview['message'] = __('Set the company/studio review state to Ready To Apply before running preview validation.', 'academy-awards-table');
            return $preview;
        }

        $proposed_slots = isset($review['proposed_nominee_id_slots']) && is_array($review['proposed_nominee_id_slots'])
            ? array_map('strval', $review['proposed_nominee_id_slots'])
            : $this->parse_company_credit_row_nominee_id_slots($review['proposed_nominee_ids'] ?? '', true);
        $preview['proposed_slots'] = $proposed_slots;
        if (empty($proposed_slots)) {
            $preview['state'] = 'missing_proposal';
            $preview['message'] = __('Add ordered IMDb co IDs before previewing a company/studio row.', 'academy-awards-table');
            return $preview;
        }

        $validation = $this->validate_company_credit_row_nominee_id_slots($proposed_slots, false);
        if (is_wp_error($validation)) {
            $preview['state'] = 'bad_proposal';
            $preview['message'] = $validation->get_error_message();
            return $preview;
        }

        if (count($proposed_slots) !== count($labels)) {
            $preview['state'] = 'count_mismatch';
            $preview['message'] = sprintf(
                __('The proposed company ID count (%1$d) must match the visible credit label count (%2$d). This protects slot-by-slot public links.', 'academy-awards-table'),
                count($proposed_slots),
                count($labels)
            );
            return $preview;
        }

        $slot_previews = array();
        foreach ($proposed_slots as $index => $company_id) {
            $company_id = strtolower(trim((string) $company_id));
            $company_label = trim((string) $this->get_entity_display_name('company', $company_id));
            if ($company_label === '') {
                $company_label = strtoupper($company_id);
            }
            if (!$this->company_credit_company_id_has_public_profile($company_id)) {
                $preview['state'] = 'unknown_company';
                $preview['message'] = sprintf(
                    __('Company ID slot %1$d (%2$s) does not resolve to a route-backed public Oscars company profile yet.', 'academy-awards-table'),
                    intval($index) + 1,
                    $company_id
                );
                return $preview;
            }

            $slot_previews[] = array(
                'label' => (string) ($labels[$index] ?? ''),
                'current_id' => (string) ($current_nominee_ids[$index] ?? ''),
                'proposed_id' => $company_id,
                'company_label' => $company_label,
                'company_url' => $this->build_entity_url_from_id($company_id),
            );
        }

        $new_nominee_ids = implode('|', $proposed_slots);
        $preview['new_nominee_ids'] = $new_nominee_ids;
        $preview['slot_previews'] = $slot_previews;
        if ($new_nominee_ids === $preview['current_nominee_ids']) {
            $preview['state'] = 'unchanged';
            $preview['message'] = __('This source row already has the proposed ordered company nominee_ids.', 'academy-awards-table');
            return $preview;
        }

        if ((string) $preview['typed_confirmation'] !== (string) $preview['required_confirmation']) {
            $preview['state'] = 'confirmation_missing';
            $preview['message'] = sprintf(
                __('Type source award row ID %s to run preview validation for this company/studio row.', 'academy-awards-table'),
                (string) $preview['required_confirmation']
            );
            return $preview;
        }

        $preview['ready'] = true;
        $preview['state'] = 'ready';
        $preview['message'] = __('Preview validated. This would update only the ordered nominee_ids string, but no source rows were changed in this preview-only gate.', 'academy-awards-table');
        return $preview;
    }

    private function get_company_credit_row_review_records_for_source_ids($source_award_ids) {
        global $wpdb;

        $ids = array();
        foreach ((array) $source_award_ids as $source_award_id) {
            $source_award_id = absint($source_award_id);
            if ($source_award_id > 0) {
                $ids[$source_award_id] = true;
            }
        }

        if (empty($ids)) {
            return array();
        }

        $table = $this->get_company_credit_row_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $ids = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT source_award_id, category_slug, credit_labels, review_state, entity_kind, proposed_nominee_ids, display_label_override, correction_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE source_award_id IN ($placeholders)",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_company_credit_row_review_states();
        $entity_kinds = $this->get_company_credit_entity_kinds();
        $out = array();
        foreach ($rows as $row) {
            $source_award_id = absint($row['source_award_id'] ?? 0);
            if ($source_award_id <= 0) {
                continue;
            }

            $state = $this->sanitize_company_credit_row_review_state($row['review_state'] ?? '');
            $entity_kind = $this->sanitize_company_credit_entity_kind($row['entity_kind'] ?? '');
            $out[$source_award_id] = array(
                'source_award_id' => $source_award_id,
                'category_slug' => sanitize_title((string) ($row['category_slug'] ?? '')),
                'credit_labels' => (string) ($row['credit_labels'] ?? ''),
                'credit_label_list' => $this->unpack_company_credit_row_labels($row['credit_labels'] ?? ''),
                'review_state' => $state,
                'review_state_label' => $states[$state] ?? $states['needs_review'],
                'entity_kind' => $entity_kind,
                'entity_kind_label' => $entity_kinds[$entity_kind] ?? $entity_kinds['source_gap'],
                'proposed_nominee_ids' => (string) ($row['proposed_nominee_ids'] ?? ''),
                'proposed_nominee_id_slots' => $this->parse_company_credit_row_nominee_id_slots($row['proposed_nominee_ids'] ?? '', true),
                'display_label_override' => (string) ($row['display_label_override'] ?? ''),
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
            );
        }

        return $out;
    }

    private function get_default_company_credit_row_review_record($source_award_id, $entity_kind = 'source_gap') {
        $states = $this->get_company_credit_row_review_states();
        $entity_kinds = $this->get_company_credit_entity_kinds();
        $entity_kind = $this->sanitize_company_credit_entity_kind($entity_kind);

        return array(
            'source_award_id' => absint($source_award_id),
            'category_slug' => '',
            'credit_labels' => '',
            'credit_label_list' => array(),
            'review_state' => 'needs_review',
            'review_state_label' => $states['needs_review'],
            'entity_kind' => $entity_kind,
            'entity_kind_label' => $entity_kinds[$entity_kind] ?? $entity_kinds['source_gap'],
            'proposed_nominee_ids' => '',
            'proposed_nominee_id_slots' => array(),
            'display_label_override' => '',
            'correction_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
        );
    }

    private function get_company_credit_review_queue_rows($options = array()) {
        $category = isset($options['category']) ? sanitize_title((string) $options['category']) : 'sound-mixing';
        $review_filter = $this->sanitize_company_credit_review_filter($options['review_state'] ?? 'all');
        $entity_filter = $this->sanitize_company_credit_entity_filter($options['entity_kind'] ?? 'all');
        $limit = isset($options['limit']) ? intval($options['limit']) : 25;
        $limit = max(1, min(100, $limit));
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        $offset = max(0, $offset);

        $audit = $this->build_profile_image_company_credit_audit(array(
            'category' => $category,
            'state' => 'all',
            'sample' => 0,
            'output_csv' => '',
        ));

        if (is_wp_error($audit)) {
            return array(
                'rows' => array(),
                'summary' => array(
                    'error' => $audit->get_error_message(),
                    'category' => $category,
                    'company_credit_review_filter' => $review_filter,
                    'company_credit_entity_filter' => $entity_filter,
                    'limit' => $limit,
                    'offset' => $offset,
                ),
            );
        }

        $audit_rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
        $source_award_ids = array();
        foreach ($audit_rows as $audit_row) {
            $source_award_id = absint($audit_row['source_award_id'] ?? 0);
            if ($source_award_id > 0) {
                $source_award_ids[] = $source_award_id;
            }
        }

        $review_records = $this->get_company_credit_row_review_records_for_source_ids($source_award_ids);
        $entity_labels = $this->get_company_credit_entity_kinds();
        $filtered_rows = array();
        foreach ($audit_rows as $audit_row) {
            $source_award_id = absint($audit_row['source_award_id'] ?? 0);
            $classifier_kind = $this->sanitize_company_credit_entity_kind($audit_row['entity_kind'] ?? '');
            if ($entity_filter !== 'all' && $entity_filter !== $classifier_kind && !($entity_filter === 'slot_mismatch' && !empty($audit_row['slot_mismatch']))) {
                continue;
            }

            $review = isset($review_records[$source_award_id])
                ? $review_records[$source_award_id]
                : $this->get_default_company_credit_row_review_record($source_award_id, $classifier_kind);

            if ($review_filter === 'unreviewed' && !empty($review['is_reviewed'])) {
                continue;
            }
            if ($review_filter !== 'all' && $review_filter !== 'unreviewed' && (string) ($review['review_state'] ?? '') !== $review_filter) {
                continue;
            }

            $label_list = $this->unpack_company_credit_row_labels((string) ($audit_row['credit_labels'] ?? ''));
            $audit_row['credit_label_list'] = $label_list;
            $audit_row['review'] = $review;
            $audit_row['review_state'] = (string) ($review['review_state'] ?? 'needs_review');
            $audit_row['review_state_label'] = (string) ($review['review_state_label'] ?? '');
            $audit_row['stored_entity_kind'] = (string) ($review['entity_kind'] ?? $classifier_kind);
            $audit_row['stored_entity_kind_label'] = (string) ($review['entity_kind_label'] ?? ($entity_labels[$classifier_kind] ?? ''));
            $audit_row['entity_kind_label'] = (string) ($entity_labels[$classifier_kind] ?? '');
            $audit_row['proposed_nominee_ids'] = (string) ($review['proposed_nominee_ids'] ?? '');
            $audit_row['display_label_override'] = (string) ($review['display_label_override'] ?? '');
            $audit_row['correction_note'] = (string) ($review['correction_note'] ?? '');
            $audit_row['is_reviewed'] = !empty($review['is_reviewed']);
            $filtered_rows[] = $audit_row;
        }

        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
        $summary['category'] = $category;
        $summary['company_credit_review_filter'] = $review_filter;
        $summary['company_credit_entity_filter'] = $entity_filter;
        $summary['reviewed_total'] = count($review_records);
        $summary['filtered_total'] = count($filtered_rows);
        $summary['returned'] = min($limit, max(0, count($filtered_rows) - $offset));
        $summary['limit'] = $limit;
        $summary['offset'] = $offset;

        return array(
            'rows' => array_slice($filtered_rows, $offset, $limit),
            'summary' => $summary,
        );
    }

    private function get_person_credit_review_states() {
        return array(
            'needs_review' => __('Needs Review', 'academy-awards-table'),
            'candidate_found' => __('Candidate Found', 'academy-awards-table'),
            'ready_to_correct' => __('Ready To Correct', 'academy-awards-table'),
            'source_gap' => __('Source Gap', 'academy-awards-table'),
            'resolved' => __('Resolved', 'academy-awards-table'),
            'ignore_accept' => __('Ignore / Accept', 'academy-awards-table'),
        );
    }

    private function sanitize_person_credit_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_person_credit_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function get_person_credit_review_filter_labels() {
        return array_merge(
            array(
                'all' => __('All Review States', 'academy-awards-table'),
                'unreviewed' => __('Unreviewed', 'academy-awards-table'),
            ),
            $this->get_person_credit_review_states()
        );
    }

    private function sanitize_person_credit_review_filter($filter) {
        $filter = sanitize_key((string) $filter);
        $labels = $this->get_person_credit_review_filter_labels();
        return isset($labels[$filter]) ? $filter : 'all';
    }

    private function get_person_credit_row_review_states() {
        return array(
            'needs_review' => __('Needs Review', 'academy-awards-table'),
            'ready_to_apply' => __('Ready To Apply', 'academy-awards-table'),
            'source_gap' => __('Source Gap', 'academy-awards-table'),
            'applied' => __('Applied', 'academy-awards-table'),
            'ignore_accept' => __('Ignore / Accept', 'academy-awards-table'),
        );
    }

    private function sanitize_person_credit_row_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_person_credit_row_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function get_person_credit_review_key($source_award_id, $label_index) {
        $source_award_id = absint($source_award_id);
        $label_index = max(0, intval($label_index));
        if ($source_award_id <= 0) {
            return '';
        }
        return $source_award_id . ':' . $label_index;
    }

    private function sanitize_person_credit_review_key($review_key) {
        $review_key = trim((string) $review_key);
        if (!preg_match('/^(\d+):(\d+)$/', $review_key, $match)) {
            return '';
        }
        return $this->get_person_credit_review_key($match[1], $match[2]);
    }

    private function replace_person_credit_review_record($args) {
        $review_key = $this->sanitize_person_credit_review_key($args['review_key'] ?? '');
        if ($review_key === '') {
            return new WP_Error('aat_person_credit_review_bad_key', __('Invalid person-credit review key.', 'academy-awards-table'));
        }

        list($source_award_id, $label_index) = array_map('intval', explode(':', $review_key, 2));
        $state = $this->sanitize_person_credit_review_state($args['review_state'] ?? '');
        $proposed_person_id = strtolower(trim(sanitize_text_field((string) ($args['proposed_person_id'] ?? ''))));
        if ($proposed_person_id !== '' && !$this->is_imdb_name_entity_id($proposed_person_id)) {
            return new WP_Error('aat_person_credit_review_bad_person_id', __('Proposed person ID must be a valid IMDb nm ID or blank.', 'academy-awards-table'));
        }

        $category_slug = sanitize_title((string) ($args['category_slug'] ?? ''));
        $credit_label = sanitize_text_field((string) ($args['credit_label'] ?? ''));
        $note = sanitize_textarea_field((string) ($args['correction_note'] ?? ''));
        $reviewer_user_id = absint($args['reviewer_user_id'] ?? get_current_user_id());
        $now = current_time('mysql');

        global $wpdb;
        $table = $this->get_person_credit_reviews_table_name();
        $this->maybe_create_person_credit_reviews_table();

        $result = $wpdb->replace(
            $table,
            array(
                'review_key' => $review_key,
                'source_award_id' => $source_award_id,
                'label_index' => $label_index,
                'category_slug' => $category_slug,
                'credit_label' => $credit_label,
                'review_state' => $state,
                'proposed_person_id' => $proposed_person_id,
                'correction_note' => $note,
                'reviewer_user_id' => $reviewer_user_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_person_credit_review_save_failed', __('Could not save the person-credit review state.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_person_credit_review_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_person_credit_review_forbidden', __('You do not have permission to save person-credit review states.', 'academy-awards-table'));
        }

        $review_key = $this->sanitize_person_credit_review_key($request['aat_person_credit_review_key'] ?? '');
        if ($review_key === '') {
            return new WP_Error('aat_person_credit_review_bad_key', __('Invalid person-credit review key.', 'academy-awards-table'));
        }

        $state = $this->sanitize_person_credit_review_state($request['aat_person_credit_review_state'] ?? '');
        $proposed_person_id = isset($request['aat_person_credit_proposed_person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($request['aat_person_credit_proposed_person_id'])))) : '';
        if ($proposed_person_id !== '' && !$this->is_imdb_name_entity_id($proposed_person_id)) {
            return new WP_Error('aat_person_credit_review_bad_person_id', __('Proposed person ID must be a valid IMDb nm ID or blank.', 'academy-awards-table'));
        }

        $category_slug = isset($request['aat_person_credit_category_slug']) ? sanitize_title(wp_unslash($request['aat_person_credit_category_slug'])) : '';
        $credit_label = isset($request['aat_person_credit_label']) ? sanitize_text_field(wp_unslash($request['aat_person_credit_label'])) : '';
        $note = isset($request['aat_person_credit_review_note']) ? sanitize_textarea_field(wp_unslash($request['aat_person_credit_review_note'])) : '';
        return $this->replace_person_credit_review_record(array(
            'review_key' => $review_key,
            'review_state' => $state,
            'proposed_person_id' => $proposed_person_id,
            'category_slug' => $category_slug,
            'credit_label' => $credit_label,
            'correction_note' => $note,
            'reviewer_user_id' => get_current_user_id(),
        ));
    }

    private function get_person_credit_source_row_category_slug($source_row) {
        $row_category = (string) (($source_row['canonical_category'] ?? '') ?: ($source_row['category'] ?? ''));
        return sanitize_title($row_category);
    }

    private function get_person_credit_source_row_visible_labels($source_row) {
        $source_value = trim((string) ($source_row['nominees'] ?? ''));
        if ($source_value === '') {
            $source_value = trim((string) ($source_row['name'] ?? ''));
        }

        return $this->split_visible_person_credit_labels($source_value);
    }

    private function pack_person_credit_row_labels($labels) {
        $packed = array();
        foreach ((array) $labels as $label) {
            $label = $this->clean_visible_person_credit_label($label);
            if ($label !== '') {
                $packed[] = $label;
            }
        }

        return implode("\n", $packed);
    }

    private function unpack_person_credit_row_labels($labels) {
        $labels = (string) $labels;
        if (trim($labels) === '') {
            return array();
        }

        $parts = preg_split('/\r\n|\r|\n|\|/', $labels);
        return array_values(array_filter(array_map(array($this, 'clean_visible_person_credit_label'), (array) $parts), 'strlen'));
    }

    private function normalize_person_credit_label_list_for_compare($labels) {
        $normalized = array();
        foreach ((array) $labels as $label) {
            $label = $this->normalize_person_credit_label_for_compare($label);
            if ($label !== '') {
                $normalized[] = $label;
            }
        }

        return $normalized;
    }

    private function parse_person_credit_row_nominee_id_slots($raw_ids, $preserve_empty = true) {
        $raw_ids = str_replace(array("\r\n", "\r"), "\n", (string) $raw_ids);
        $raw_ids = trim($raw_ids);
        if ($raw_ids === '') {
            return array();
        }

        if (strpos($raw_ids, "\n") !== false) {
            $parts = explode("\n", $raw_ids);
        } elseif (strpos($raw_ids, '|') !== false) {
            $parts = explode('|', $raw_ids);
        } else {
            $parts = preg_split('/\s*,\s*/', $raw_ids);
        }

        $slots = array();
        foreach ((array) $parts as $part) {
            $part = strtolower(trim(sanitize_text_field((string) $part)));
            if ($part === '') {
                if ($preserve_empty) {
                    $slots[] = '';
                }
                continue;
            }

            $slots[] = $part;
        }

        return $slots;
    }

    private function validate_person_credit_row_nominee_id_slots($nominee_ids, $allow_blank = true) {
        foreach ((array) $nominee_ids as $index => $nominee_id) {
            $nominee_id = strtolower(trim((string) $nominee_id));
            if ($nominee_id === '' && $allow_blank) {
                continue;
            }

            if (!$this->is_imdb_name_entity_id($nominee_id)) {
                return new WP_Error(
                    'aat_person_credit_row_bad_person_id',
                    sprintf(
                        __('Nominee ID slot %1$d must be a valid IMDb nm ID%2$s.', 'academy-awards-table'),
                        intval($index) + 1,
                        $allow_blank ? __(' or blank', 'academy-awards-table') : ''
                    )
                );
            }
        }

        return true;
    }

    private function replace_person_credit_row_review_record($args) {
        $source_award_id = absint($args['source_award_id'] ?? 0);
        if ($source_award_id <= 0) {
            return new WP_Error('aat_person_credit_row_review_bad_source', __('Invalid source award row for full-row review.', 'academy-awards-table'));
        }

        $state = $this->sanitize_person_credit_row_review_state($args['review_state'] ?? '');
        $category_slug = sanitize_title((string) ($args['category_slug'] ?? ''));
        $credit_labels = isset($args['credit_labels']) && is_array($args['credit_labels'])
            ? $this->pack_person_credit_row_labels($args['credit_labels'])
            : sanitize_textarea_field((string) ($args['credit_labels'] ?? ''));
        $proposed_slots = isset($args['proposed_nominee_ids']) && is_array($args['proposed_nominee_ids'])
            ? array_map('strval', $args['proposed_nominee_ids'])
            : $this->parse_person_credit_row_nominee_id_slots($args['proposed_nominee_ids'] ?? '', true);
        $validation = $this->validate_person_credit_row_nominee_id_slots($proposed_slots, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $note = sanitize_textarea_field((string) ($args['correction_note'] ?? ''));
        $reviewer_user_id = absint($args['reviewer_user_id'] ?? get_current_user_id());
        $now = current_time('mysql');

        global $wpdb;
        $table = $this->get_person_credit_row_reviews_table_name();
        $this->maybe_create_person_credit_row_reviews_table();

        $result = $wpdb->replace(
            $table,
            array(
                'source_award_id' => $source_award_id,
                'category_slug' => $category_slug,
                'credit_labels' => $credit_labels,
                'review_state' => $state,
                'proposed_nominee_ids' => implode('|', $proposed_slots),
                'correction_note' => $note,
                'reviewer_user_id' => $reviewer_user_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_person_credit_row_review_save_failed', __('Could not save the full-row person-credit review.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_person_credit_row_review_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_person_credit_row_review_forbidden', __('You do not have permission to save full-row person-credit reviews.', 'academy-awards-table'));
        }

        $source_award_id = absint($request['aat_person_credit_row_source_award_id'] ?? 0);
        $source_row = $this->get_person_credit_source_award_row($source_award_id);
        if (empty($source_row)) {
            return new WP_Error('aat_person_credit_row_review_missing_source', __('The source award row could not be reloaded.', 'academy-awards-table'));
        }

        $labels = $this->get_person_credit_source_row_visible_labels($source_row);
        if (empty($labels)) {
            return new WP_Error('aat_person_credit_row_review_missing_labels', __('The source award row no longer has visible person-credit labels.', 'academy-awards-table'));
        }

        $proposed_nominee_ids = isset($request['aat_person_credit_row_proposed_nominee_ids'])
            ? wp_unslash($request['aat_person_credit_row_proposed_nominee_ids'])
            : '';
        $note = isset($request['aat_person_credit_row_review_note'])
            ? sanitize_textarea_field(wp_unslash($request['aat_person_credit_row_review_note']))
            : '';

        return $this->replace_person_credit_row_review_record(array(
            'source_award_id' => $source_award_id,
            'category_slug' => $this->get_person_credit_source_row_category_slug($source_row),
            'credit_labels' => $labels,
            'review_state' => $request['aat_person_credit_row_review_state'] ?? '',
            'proposed_nominee_ids' => $proposed_nominee_ids,
            'correction_note' => $note,
            'reviewer_user_id' => get_current_user_id(),
        ));
    }

    private function get_person_credit_source_award_row($source_award_id) {
        global $wpdb;

        $source_award_id = absint($source_award_id);
        if ($source_award_id <= 0) {
            return array();
        }

        $table = $this->get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, ceremony, year, canonical_category, category, film, name, nominees, nominee_ids, winner FROM $table WHERE id = %d LIMIT 1",
                $source_award_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : array();
    }

    private function get_person_credit_row_review_records_for_source_ids($source_award_ids) {
        global $wpdb;

        $ids = array();
        foreach ((array) $source_award_ids as $source_award_id) {
            $source_award_id = absint($source_award_id);
            if ($source_award_id > 0) {
                $ids[$source_award_id] = true;
            }
        }

        if (empty($ids)) {
            return array();
        }

        $table = $this->get_person_credit_row_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $ids = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT source_award_id, category_slug, credit_labels, review_state, proposed_nominee_ids, correction_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE source_award_id IN ($placeholders)",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_person_credit_row_review_states();
        $out = array();
        foreach ($rows as $row) {
            $source_award_id = absint($row['source_award_id'] ?? 0);
            if ($source_award_id <= 0) {
                continue;
            }

            $state = $this->sanitize_person_credit_row_review_state($row['review_state'] ?? '');
            $out[$source_award_id] = array(
                'source_award_id' => $source_award_id,
                'category_slug' => sanitize_title((string) ($row['category_slug'] ?? '')),
                'credit_labels' => (string) ($row['credit_labels'] ?? ''),
                'credit_label_list' => $this->unpack_person_credit_row_labels($row['credit_labels'] ?? ''),
                'review_state' => $state,
                'review_state_label' => $states[$state] ?? $states['needs_review'],
                'proposed_nominee_ids' => (string) ($row['proposed_nominee_ids'] ?? ''),
                'proposed_nominee_id_slots' => $this->parse_person_credit_row_nominee_id_slots($row['proposed_nominee_ids'] ?? '', true),
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
            );
        }

        return $out;
    }

    private function get_default_person_credit_row_review_record($source_award_id) {
        $states = $this->get_person_credit_row_review_states();
        return array(
            'source_award_id' => absint($source_award_id),
            'category_slug' => '',
            'credit_labels' => '',
            'credit_label_list' => array(),
            'review_state' => 'needs_review',
            'review_state_label' => $states['needs_review'],
            'proposed_nominee_ids' => '',
            'proposed_nominee_id_slots' => array(),
            'correction_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
        );
    }

    private function normalize_person_credit_label_for_compare($label) {
        $label = $this->clean_visible_person_credit_label($label);
        $label = strtolower((string) preg_replace('/\s+/', ' ', $label));
        return trim($label);
    }

    private function build_person_credit_full_row_review_preview($source_award_id, $review) {
        $source_award_id = absint($source_award_id);
        $preview = array(
            'ready' => false,
            'state' => 'blocked',
            'message' => __('Save a full-row review before correcting this source row.', 'academy-awards-table'),
            'source_award_id' => $source_award_id,
            'labels' => array(),
            'current_nominee_ids' => '',
            'new_nominee_ids' => '',
            'visible_label_count' => 0,
        );

        if ($source_award_id <= 0) {
            $preview['state'] = 'bad_source';
            $preview['message'] = __('Invalid source award row.', 'academy-awards-table');
            return $preview;
        }

        $source_row = $this->get_person_credit_source_award_row($source_award_id);
        if (empty($source_row)) {
            $preview['state'] = 'missing_source';
            $preview['message'] = __('The source award row could not be reloaded; no full-row correction is available.', 'academy-awards-table');
            return $preview;
        }

        $labels = $this->get_person_credit_source_row_visible_labels($source_row);
        $preview['labels'] = $labels;
        $preview['visible_label_count'] = count($labels);
        if (empty($labels)) {
            $preview['state'] = 'missing_labels';
            $preview['message'] = __('The source award row no longer has visible person-credit labels.', 'academy-awards-table');
            return $preview;
        }

        $current_nominee_ids = $this->parse_person_credit_row_nominee_id_slots($source_row['nominee_ids'] ?? '', false);
        $preview['current_nominee_ids'] = implode('|', $current_nominee_ids);

        $stored_category = sanitize_title((string) ($review['category_slug'] ?? ''));
        $current_category = $this->get_person_credit_source_row_category_slug($source_row);
        if ($stored_category !== '' && $stored_category !== $current_category) {
            $preview['state'] = 'category_mismatch';
            $preview['message'] = __('The source category changed after this full-row review was saved; refresh before applying.', 'academy-awards-table');
            return $preview;
        }

        $stored_labels = isset($review['credit_label_list']) && is_array($review['credit_label_list'])
            ? $review['credit_label_list']
            : $this->unpack_person_credit_row_labels($review['credit_labels'] ?? '');
        if (!empty($stored_labels) && $this->normalize_person_credit_label_list_for_compare($stored_labels) !== $this->normalize_person_credit_label_list_for_compare($labels)) {
            $preview['state'] = 'label_mismatch';
            $preview['message'] = __('The visible source credit labels changed after this full-row review was saved; refresh before applying.', 'academy-awards-table');
            return $preview;
        }

        $state = $this->sanitize_person_credit_row_review_state($review['review_state'] ?? '');
        if ($state === 'source_gap') {
            $preview['state'] = 'source_gap';
            $preview['message'] = __('This row is marked Source Gap. Keep it private until every visible credit has a trustworthy IMDb nm ID.', 'academy-awards-table');
            return $preview;
        }
        if ($state === 'applied') {
            $preview['state'] = 'applied';
            $preview['message'] = __('This full-row source correction has already been marked applied.', 'academy-awards-table');
            return $preview;
        }
        if (empty($review['is_reviewed'])) {
            return $preview;
        }

        $proposed_slots = isset($review['proposed_nominee_id_slots']) && is_array($review['proposed_nominee_id_slots'])
            ? $review['proposed_nominee_id_slots']
            : $this->parse_person_credit_row_nominee_id_slots($review['proposed_nominee_ids'] ?? '', true);
        if (empty($proposed_slots)) {
            $preview['state'] = 'missing_proposal';
            $preview['message'] = __('Add the ordered nominee_ids proposal before applying a full-row correction.', 'academy-awards-table');
            return $preview;
        }

        $validation = $this->validate_person_credit_row_nominee_id_slots($proposed_slots, true);
        if (is_wp_error($validation)) {
            $preview['state'] = 'bad_proposal';
            $preview['message'] = $validation->get_error_message();
            return $preview;
        }

        if (count($proposed_slots) !== count($labels)) {
            $preview['state'] = 'count_mismatch';
            $preview['message'] = sprintf(
                __('The proposed nominee_ids count (%1$d) must match the visible credit label count (%2$d).', 'academy-awards-table'),
                count($proposed_slots),
                count($labels)
            );
            return $preview;
        }

        if ($state !== 'ready_to_apply') {
            $preview['state'] = 'not_ready_state';
            $preview['message'] = __('Set the full-row review state to Ready To Apply before correcting nominee_ids.', 'academy-awards-table');
            return $preview;
        }

        if (in_array('', $proposed_slots, true)) {
            $preview['state'] = 'blank_slots';
            $preview['message'] = __('Ready To Apply requires every visible credit slot to have a valid IMDb nm ID.', 'academy-awards-table');
            return $preview;
        }

        $new_nominee_ids = implode('|', $proposed_slots);
        $preview['new_nominee_ids'] = $new_nominee_ids;
        if ($new_nominee_ids === $preview['current_nominee_ids']) {
            $preview['state'] = 'already_applied';
            $preview['message'] = __('This source row already has the proposed ordered nominee_ids.', 'academy-awards-table');
            return $preview;
        }

        $preview['ready'] = true;
        $preview['state'] = 'ready';
        $preview['message'] = __('Ready for a guarded full-row source correction. This will update only this award row and rebuild Oscars reporting tables.', 'academy-awards-table');
        return $preview;
    }

    private function build_person_credit_source_correction_preview($audit_row, $review) {
        $review_key = $this->sanitize_person_credit_review_key($review['review_key'] ?? ($audit_row['review_key'] ?? ''));
        $preview = array(
            'ready' => false,
            'state' => 'blocked',
            'message' => __('Save a reviewed candidate before correcting the source row.', 'academy-awards-table'),
            'review_key' => $review_key,
            'source_award_id' => 0,
            'credit_label' => '',
            'proposed_person_id' => '',
            'current_nominee_ids' => '',
            'new_nominee_ids' => '',
            'visible_label_count' => 0,
        );

        if ($review_key === '') {
            $preview['state'] = 'bad_key';
            $preview['message'] = __('Invalid review key; this row cannot be corrected safely.', 'academy-awards-table');
            return $preview;
        }

        list($source_award_id, $zero_based_label_index) = array_map('intval', explode(':', $review_key, 2));
        $preview['source_award_id'] = $source_award_id;

        $review_state = $this->sanitize_person_credit_review_state($review['review_state'] ?? '');
        if (empty($review['is_reviewed']) || !in_array($review_state, array('candidate_found', 'ready_to_correct'), true)) {
            $preview['state'] = 'not_ready_state';
            $preview['message'] = __('Source correction unlocks only after this row is reviewed as Candidate Found or Ready To Correct.', 'academy-awards-table');
            return $preview;
        }

        $proposed_person_id = strtolower(trim(sanitize_text_field((string) ($review['proposed_person_id'] ?? ''))));
        $preview['proposed_person_id'] = $proposed_person_id;
        if (!$this->is_imdb_name_entity_id($proposed_person_id)) {
            $preview['state'] = 'missing_candidate';
            $preview['message'] = __('Add a valid proposed IMDb nm ID before correcting the source row.', 'academy-awards-table');
            return $preview;
        }

        $source_row = $this->get_person_credit_source_award_row($source_award_id);
        if (empty($source_row)) {
            $preview['state'] = 'missing_source';
            $preview['message'] = __('The source award row could not be reloaded; no correction is available.', 'academy-awards-table');
            return $preview;
        }

        $source_value = trim((string) ($source_row['nominees'] ?? ''));
        if ($source_value === '') {
            $source_value = trim((string) ($source_row['name'] ?? ''));
        }

        $labels = $this->split_visible_person_credit_labels($source_value);
        $preview['visible_label_count'] = count($labels);
        if (!isset($labels[$zero_based_label_index])) {
            $preview['state'] = 'label_index_missing';
            $preview['message'] = __('The source credit label position no longer exists; refresh the audit before correcting.', 'academy-awards-table');
            return $preview;
        }

        $current_credit_label = (string) $labels[$zero_based_label_index];
        $stored_credit_label = trim((string) ($review['credit_label'] ?? ''));
        if ($stored_credit_label === '') {
            $stored_credit_label = trim((string) ($audit_row['credit_label'] ?? ''));
        }
        $preview['credit_label'] = $current_credit_label;

        if (
            $stored_credit_label !== '' &&
            $this->normalize_person_credit_label_for_compare($stored_credit_label) !== $this->normalize_person_credit_label_for_compare($current_credit_label)
        ) {
            $preview['state'] = 'label_mismatch';
            $preview['message'] = __('The current source label no longer matches the saved review label; refresh the row before correcting.', 'academy-awards-table');
            return $preview;
        }

        $source_nominee_ids = $this->extract_entity_reference_ids((string) ($source_row['nominee_ids'] ?? ''));
        $preview['current_nominee_ids'] = implode('|', $source_nominee_ids);

        if (count($labels) !== 1) {
            $preview['state'] = 'multi_credit_needs_full_row';
            $preview['message'] = __('This source row has multiple visible credits. Use a full-row resolver before writing nominee_ids.', 'academy-awards-table');
            return $preview;
        }

        if (!empty($source_nominee_ids)) {
            if (count($source_nominee_ids) === 1 && strtolower((string) $source_nominee_ids[0]) === $proposed_person_id) {
                $preview['state'] = 'already_applied';
                $preview['message'] = __('This source row already contains the proposed IMDb person ID.', 'academy-awards-table');
                return $preview;
            }

            $preview['state'] = 'existing_ids_present';
            $preview['message'] = __('This source row already has nominee_ids. Manual review is required before replacing them.', 'academy-awards-table');
            return $preview;
        }

        $preview['ready'] = true;
        $preview['state'] = 'ready';
        $preview['new_nominee_ids'] = $proposed_person_id;
        $preview['message'] = __('Ready for a one-row source correction. This will update only this award row and then rebuild Oscars reporting tables.', 'academy-awards-table');
        return $preview;
    }

    private function apply_person_credit_source_correction_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_person_credit_source_correction_forbidden', __('You do not have permission to correct person-credit source rows.', 'academy-awards-table'));
        }

        if (empty($request['aat_person_credit_source_confirm'])) {
            return new WP_Error('aat_person_credit_source_correction_unconfirmed', __('Confirm the one-row source correction before applying it.', 'academy-awards-table'));
        }

        $review_key = $this->sanitize_person_credit_review_key($request['aat_person_credit_source_review_key'] ?? '');
        if ($review_key === '') {
            return new WP_Error('aat_person_credit_source_correction_bad_key', __('Invalid person-credit review key.', 'academy-awards-table'));
        }

        $proposed_person_id = isset($request['aat_person_credit_source_proposed_person_id'])
            ? strtolower(trim(sanitize_text_field(wp_unslash($request['aat_person_credit_source_proposed_person_id']))))
            : '';
        $confirmed_person_id = isset($request['aat_person_credit_source_confirm_person_id'])
            ? strtolower(trim(sanitize_text_field(wp_unslash($request['aat_person_credit_source_confirm_person_id']))))
            : '';

        if (!$this->is_imdb_name_entity_id($proposed_person_id)) {
            return new WP_Error('aat_person_credit_source_correction_bad_person_id', __('The proposed person ID must be a valid IMDb nm ID.', 'academy-awards-table'));
        }
        if ($confirmed_person_id !== $proposed_person_id) {
            return new WP_Error('aat_person_credit_source_correction_confirm_id', __('Type the exact proposed IMDb person ID to apply the source correction.', 'academy-awards-table'));
        }

        $records = $this->get_person_credit_review_records_for_keys(array($review_key));
        if (empty($records[$review_key])) {
            return new WP_Error('aat_person_credit_source_correction_missing_review', __('Save a person-credit review before applying a source correction.', 'academy-awards-table'));
        }

        $review = $records[$review_key];
        if ((string) ($review['proposed_person_id'] ?? '') !== $proposed_person_id) {
            return new WP_Error('aat_person_credit_source_correction_stale_id', __('The proposed ID changed after this form rendered. Refresh and try again.', 'academy-awards-table'));
        }

        $preview = $this->build_person_credit_source_correction_preview(array('review_key' => $review_key), $review);
        if (empty($preview['ready'])) {
            return new WP_Error('aat_person_credit_source_correction_not_ready', (string) ($preview['message'] ?? __('This source row is not ready for correction.', 'academy-awards-table')));
        }

        global $wpdb;
        $table = $this->get_table_name();
        $source_award_id = absint($preview['source_award_id'] ?? 0);
        $new_nominee_ids = (string) ($preview['new_nominee_ids'] ?? '');
        $current_nominee_ids = (string) ($preview['current_nominee_ids'] ?? '');
        $credit_label = (string) ($preview['credit_label'] ?? ($review['credit_label'] ?? ''));

        $wpdb->query('START TRANSACTION');
        $updated = $wpdb->update(
            $table,
            array('nominee_ids' => $new_nominee_ids),
            array('id' => $source_award_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('aat_person_credit_source_correction_update_failed', __('Could not update nominee_ids on the source award row.', 'academy-awards-table'));
        }

        $previous_note = trim((string) ($review['correction_note'] ?? ''));
        $resolved_note = sprintf(
            'Source row corrected: award row #%1$d, "%2$s", nominee_ids "%3$s" -> "%4$s".',
            $source_award_id,
            $credit_label,
            $current_nominee_ids,
            $new_nominee_ids
        );
        if ($previous_note !== '') {
            $resolved_note .= "\n\nPrevious private note:\n" . $previous_note;
        }

        $review_update = $this->replace_person_credit_review_record(array(
            'review_key' => $review_key,
            'review_state' => 'resolved',
            'proposed_person_id' => $proposed_person_id,
            'category_slug' => (string) ($review['category_slug'] ?? ''),
            'credit_label' => $credit_label,
            'correction_note' => $resolved_note,
            'reviewer_user_id' => get_current_user_id(),
        ));

        if (is_wp_error($review_update)) {
            $wpdb->query('ROLLBACK');
            return $review_update;
        }

        $wpdb->query('COMMIT');

        $reporting_rebuild = $this->rebuild_reporting_tables();
        $this->clear_awards_runtime_caches(array($proposed_person_id));

        return array(
            'review_key' => $review_key,
            'source_award_id' => $source_award_id,
            'proposed_person_id' => $proposed_person_id,
            'updated_rows' => intval($updated),
            'reporting_rebuild' => $reporting_rebuild,
        );
    }

    private function apply_person_credit_full_row_source_correction_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_person_credit_row_apply_forbidden', __('You do not have permission to apply full-row person-credit corrections.', 'academy-awards-table'));
        }

        if (empty($request['aat_person_credit_row_apply_confirm'])) {
            return new WP_Error('aat_person_credit_row_apply_unconfirmed', __('Confirm the full-row source correction before applying it.', 'academy-awards-table'));
        }

        $source_award_id = absint($request['aat_person_credit_row_apply_source_award_id'] ?? 0);
        if ($source_award_id <= 0) {
            return new WP_Error('aat_person_credit_row_apply_bad_source', __('Invalid source award row.', 'academy-awards-table'));
        }

        $confirmed_source_award_id = isset($request['aat_person_credit_row_apply_confirm_source_award_id'])
            ? trim(sanitize_text_field(wp_unslash($request['aat_person_credit_row_apply_confirm_source_award_id'])))
            : '';
        if ($confirmed_source_award_id !== (string) $source_award_id) {
            return new WP_Error('aat_person_credit_row_apply_confirm_source', __('Type the exact source award row number to apply this full-row correction.', 'academy-awards-table'));
        }

        $records = $this->get_person_credit_row_review_records_for_source_ids(array($source_award_id));
        if (empty($records[$source_award_id])) {
            return new WP_Error('aat_person_credit_row_apply_missing_review', __('Save a full-row person-credit review before applying a source correction.', 'academy-awards-table'));
        }

        $review = $records[$source_award_id];
        $preview = $this->build_person_credit_full_row_review_preview($source_award_id, $review);
        if (empty($preview['ready'])) {
            return new WP_Error('aat_person_credit_row_apply_not_ready', (string) ($preview['message'] ?? __('This full-row source correction is not ready.', 'academy-awards-table')));
        }

        global $wpdb;
        $table = $this->get_table_name();
        $new_nominee_ids = (string) ($preview['new_nominee_ids'] ?? '');
        $current_nominee_ids = (string) ($preview['current_nominee_ids'] ?? '');
        $labels = is_array($preview['labels'] ?? null) ? $preview['labels'] : array();

        $wpdb->query('START TRANSACTION');
        $updated = $wpdb->update(
            $table,
            array('nominee_ids' => $new_nominee_ids),
            array('id' => $source_award_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('aat_person_credit_row_apply_update_failed', __('Could not update nominee_ids on the source award row.', 'academy-awards-table'));
        }

        $previous_note = trim((string) ($review['correction_note'] ?? ''));
        $resolved_note = sprintf(
            'Full-row source correction applied: award row #%1$d, %2$d visible credits, nominee_ids "%3$s" -> "%4$s".',
            $source_award_id,
            count($labels),
            $current_nominee_ids,
            $new_nominee_ids
        );
        if ($previous_note !== '') {
            $resolved_note .= "\n\nPrevious private note:\n" . $previous_note;
        }

        $review_update = $this->replace_person_credit_row_review_record(array(
            'source_award_id' => $source_award_id,
            'category_slug' => (string) ($review['category_slug'] ?? ''),
            'credit_labels' => !empty($labels) ? $labels : (string) ($review['credit_labels'] ?? ''),
            'review_state' => 'applied',
            'proposed_nominee_ids' => $new_nominee_ids,
            'correction_note' => $resolved_note,
            'reviewer_user_id' => get_current_user_id(),
        ));

        if (is_wp_error($review_update)) {
            $wpdb->query('ROLLBACK');
            return $review_update;
        }

        $wpdb->query('COMMIT');

        $reporting_rebuild = $this->rebuild_reporting_tables();
        $this->clear_awards_runtime_caches($this->parse_person_credit_row_nominee_id_slots($new_nominee_ids, false));

        return array(
            'source_award_id' => $source_award_id,
            'new_nominee_ids' => $new_nominee_ids,
            'updated_rows' => intval($updated),
            'reporting_rebuild' => $reporting_rebuild,
        );
    }

    private function get_person_credit_review_records_for_keys($review_keys) {
        global $wpdb;

        $keys = array();
        foreach ((array) $review_keys as $review_key) {
            $review_key = $this->sanitize_person_credit_review_key($review_key);
            if ($review_key !== '') {
                $keys[$review_key] = true;
            }
        }

        if (empty($keys)) {
            return array();
        }

        $table = $this->get_person_credit_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $keys = array_keys($keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT review_key, source_award_id, label_index, category_slug, credit_label, review_state, proposed_person_id, correction_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE review_key IN ($placeholders)",
            $keys
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_person_credit_review_states();
        $out = array();
        foreach ($rows as $row) {
            $review_key = $this->sanitize_person_credit_review_key($row['review_key'] ?? '');
            if ($review_key === '') {
                continue;
            }

            $state = $this->sanitize_person_credit_review_state($row['review_state'] ?? '');
            $out[$review_key] = array(
                'review_key' => $review_key,
                'source_award_id' => (int) ($row['source_award_id'] ?? 0),
                'label_index' => (int) ($row['label_index'] ?? 0),
                'category_slug' => sanitize_title((string) ($row['category_slug'] ?? '')),
                'credit_label' => (string) ($row['credit_label'] ?? ''),
                'review_state' => $state,
                'review_state_label' => $states[$state] ?? $states['needs_review'],
                'proposed_person_id' => strtolower(trim((string) ($row['proposed_person_id'] ?? ''))),
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
            );
        }

        return $out;
    }

    private function get_default_person_credit_review_record($review_key) {
        $states = $this->get_person_credit_review_states();
        return array(
            'review_key' => $this->sanitize_person_credit_review_key($review_key),
            'review_state' => 'needs_review',
            'review_state_label' => $states['needs_review'],
            'proposed_person_id' => '',
            'correction_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
        );
    }

    private function get_person_credit_review_queue_rows($options = array()) {
        $category = isset($options['category']) ? sanitize_title((string) $options['category']) : 'sound-mixing';
        $person_credit_review_filter = $this->sanitize_person_credit_review_filter($options['review_state'] ?? 'all');
        $limit = isset($options['limit']) ? intval($options['limit']) : 25;
        $limit = max(1, min(100, $limit));
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        $offset = max(0, $offset);

        $audit = $this->build_profile_image_person_credit_audit(array(
            'category' => $category,
            'state' => 'unresolved',
            'sample' => 0,
            'output_csv' => '',
        ));

        if (is_wp_error($audit)) {
            return array(
                'rows' => array(),
                'summary' => array(
                    'error' => $audit->get_error_message(),
                    'category' => $category,
                    'person_credit_review_filter' => $person_credit_review_filter,
                    'limit' => $limit,
                    'offset' => $offset,
                ),
            );
        }

        $audit_rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
        $review_keys = array();
        $source_award_ids = array();
        foreach ($audit_rows as $audit_row) {
            $review_key = (string) ($audit_row['review_key'] ?? '');
            if ($review_key !== '') {
                $review_keys[] = $review_key;
            }

            $source_award_id = absint($audit_row['source_award_id'] ?? 0);
            if ($source_award_id > 0) {
                $source_award_ids[] = $source_award_id;
            }
        }

        $review_records = $this->get_person_credit_review_records_for_keys($review_keys);
        $row_review_records = $this->get_person_credit_row_review_records_for_source_ids($source_award_ids);
        $filtered_rows = array();
        foreach ($audit_rows as $audit_row) {
            $review_key = (string) ($audit_row['review_key'] ?? '');
            $review = isset($review_records[$review_key])
                ? $review_records[$review_key]
                : $this->get_default_person_credit_review_record($review_key);
            $source_award_id = absint($audit_row['source_award_id'] ?? 0);
            $row_review = isset($row_review_records[$source_award_id])
                ? $row_review_records[$source_award_id]
                : $this->get_default_person_credit_row_review_record($source_award_id);

            if ($person_credit_review_filter === 'unreviewed' && !empty($review['is_reviewed'])) {
                continue;
            }
            if ($person_credit_review_filter !== 'all' && $person_credit_review_filter !== 'unreviewed' && (string) ($review['review_state'] ?? '') !== $person_credit_review_filter) {
                continue;
            }

            $audit_row['review'] = $review;
            $audit_row['review_state'] = (string) ($review['review_state'] ?? 'needs_review');
            $audit_row['review_state_label'] = (string) ($review['review_state_label'] ?? '');
            $audit_row['proposed_person_id'] = (string) ($review['proposed_person_id'] ?? '');
            $audit_row['correction_note'] = (string) ($review['correction_note'] ?? '');
            $audit_row['is_reviewed'] = !empty($review['is_reviewed']);
            $audit_row['source_correction_preview'] = $this->build_person_credit_source_correction_preview($audit_row, $review);
            $audit_row['full_row_review'] = $row_review;
            $audit_row['full_row_preview'] = $this->build_person_credit_full_row_review_preview($source_award_id, $row_review);
            $filtered_rows[] = $audit_row;
        }

        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
        $summary['category'] = $category;
        $summary['person_credit_review_filter'] = $person_credit_review_filter;
        $summary['reviewed_total'] = count($review_records);
        $summary['row_reviewed_total'] = count($row_review_records);
        $summary['filtered_total'] = count($filtered_rows);
        $summary['returned'] = min($limit, max(0, count($filtered_rows) - $offset));
        $summary['limit'] = $limit;
        $summary['offset'] = $offset;

        return array(
            'rows' => array_slice($filtered_rows, $offset, $limit),
            'summary' => $summary,
        );
    }

    private function normalize_profile_image_coverage_cli_args($assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $results_csv = isset($assoc_args['results-csv']) ? $clean_path($assoc_args['results-csv']) : '';
        $batch = isset($assoc_args['batch']) ? sanitize_key((string) $assoc_args['batch']) : '';
        $sample = isset($assoc_args['sample']) ? intval($assoc_args['sample']) : 25;

        if ($results_csv === '') {
            return new WP_Error('aat_profile_coverage_missing_results_csv', 'Pass --results-csv pointing at tmdb_profile_results.csv.');
        }
        if (!is_readable($results_csv)) {
            return new WP_Error('aat_profile_coverage_results_unreadable', 'The --results-csv file is not readable.');
        }

        return array(
            'mode' => 'coverage',
            'results-csv' => $results_csv,
            'batch' => $batch,
            'sample' => max(0, min(100, $sample)),
        );
    }

    private function build_profile_image_coverage_audit($options) {
        $results_csv = (string) ($options['results-csv'] ?? '');
        $batch = sanitize_key((string) ($options['batch'] ?? ''));
        $sample_limit = max(0, min(100, intval($options['sample'] ?? 25)));

        $approved_rows = $this->read_profile_image_batch_results_csv($results_csv);
        if (is_wp_error($approved_rows)) {
            return $approved_rows;
        }

        $approved_set = array_fill_keys(array_keys($approved_rows), true);
        $people = $this->get_profile_image_coverage_id_set('people');
        $entities = $this->get_profile_image_coverage_id_set('entities');
        $imported = $this->get_profile_image_coverage_id_set('imported', array('batch' => $batch));

        $people_set = is_array($people['ids'] ?? null) ? $people['ids'] : array();
        $entity_set = is_array($entities['ids'] ?? null) ? $entities['ids'] : array();
        $imported_set = is_array($imported['ids'] ?? null) ? $imported['ids'] : array();
        $people_table_available = !empty($people['available']);

        $route_backed_approved = array_intersect_key($approved_set, $entity_set);
        $approved_without_people = $people_table_available ? array_diff_key($approved_set, $people_set) : $approved_set;
        $approved_without_entity = array_diff_key($approved_set, $entity_set);
        $approved_in_people_without_entity = array_diff_key(array_intersect_key($approved_set, $people_set), $entity_set);
        $imported_without_entity = array_diff_key($imported_set, $entity_set);
        $route_backed_imported = array_intersect_key($imported_set, $entity_set);

        $samples = array(
            'approved_without_people' => $this->profile_image_coverage_sample_rows($approved_without_people, $approved_rows, $sample_limit),
            'approved_without_entity' => $this->profile_image_coverage_sample_rows($approved_without_entity, $approved_rows, $sample_limit),
            'approved_in_people_without_entity' => $this->profile_image_coverage_sample_rows($approved_in_people_without_entity, $approved_rows, $sample_limit),
            'imported_without_entity' => $this->profile_image_coverage_sample_rows($imported_without_entity, $approved_rows, $sample_limit),
        );

        return array(
            'summary' => array(
                'mode' => 'coverage',
                'results_csv' => $results_csv,
                'batch' => $batch,
                'sample' => $sample_limit,
                'approved_ids' => count($approved_set),
                'people_table_available' => $people_table_available ? 1 : 0,
                'people_ids' => count($people_set),
                'entity_ids' => count($entity_set),
                'imported_ids' => count($imported_set),
                'route_backed_approved' => count($route_backed_approved),
                'approved_without_people' => count($approved_without_people),
                'approved_without_entity' => count($approved_without_entity),
                'approved_in_people_without_entity' => count($approved_in_people_without_entity),
                'imported_without_entity' => count($imported_without_entity),
                'route_backed_imported' => count($route_backed_imported),
                'samples' => $sample_limit,
            ),
            'samples' => $samples,
        );
    }

    private function get_profile_image_coverage_id_set($source, $args = array()) {
        global $wpdb;

        $source = sanitize_key((string) $source);
        $ids = array();
        $available = true;

        if ($source === 'people') {
            $people_table = 'people';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($people_table)));
            if (!$exists) {
                return array(
                    'ids' => array(),
                    'available' => false,
                );
            }

            $rows = $wpdb->get_col(
                "SELECT LOWER(TRIM(imdb_id))
                 FROM {$people_table}
                 WHERE imdb_id REGEXP '^nm[0-9]{7,9}$'"
            );
        } elseif ($source === 'entities') {
            $entities_table = $this->get_entities_table_name();
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT LOWER(TRIM(entity_id))
                     FROM {$entities_table}
                     WHERE entity_type = %s
                       AND entity_id REGEXP %s",
                    'name',
                    '^nm[0-9]{7,9}$'
                )
            );
        } elseif ($source === 'imported') {
            $batch = sanitize_key((string) ($args['batch'] ?? ''));
            $source_values = array('manual-batch-upload', 'tmdb-person-profile');
            $source_placeholders = implode(', ', array_fill(0, count($source_values), '%s'));
            $batch_join = '';
            $batch_where = '';
            $params = array(
                '_aat_person_imdb_id',
                '^nm[0-9]{7,9}$',
                '_aat_person_portrait_source',
            );
            $params = array_merge($params, $source_values);

            if ($batch !== '') {
                $batch_join = "INNER JOIN {$wpdb->postmeta} pm_batch ON pm_batch.post_id = p.ID";
                $batch_where = ' AND pm_batch.meta_key = %s AND pm_batch.meta_value = %s';
                $params[] = '_aat_person_portrait_batch';
                $params[] = $batch;
            }

            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT LOWER(TRIM(pm_person.meta_value))
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm_person ON pm_person.post_id = p.ID
                     INNER JOIN {$wpdb->postmeta} pm_source ON pm_source.post_id = p.ID
                     {$batch_join}
                     WHERE p.post_type = 'attachment'
                       AND p.post_mime_type LIKE 'image/%%'
                       AND pm_person.meta_key = %s
                       AND pm_person.meta_value REGEXP %s
                       AND pm_source.meta_key = %s
                       AND pm_source.meta_value IN ({$source_placeholders})
                       {$batch_where}",
                    $params
                )
            );
        } else {
            $rows = array();
            $available = false;
        }

        foreach ((array) $rows as $id) {
            $id = strtolower(trim((string) $id));
            if ($this->is_imdb_name_entity_id($id)) {
                $ids[$id] = true;
            }
        }

        return array(
            'ids' => $ids,
            'available' => $available,
        );
    }

    private function profile_image_coverage_sample_rows($id_set, $approved_rows, $limit) {
        $limit = max(0, min(100, intval($limit)));
        if ($limit < 1 || empty($id_set)) {
            return array();
        }

        $ids = array_keys((array) $id_set);
        sort($ids, SORT_NATURAL);
        $ids = array_slice($ids, 0, $limit);

        $samples = array();
        foreach ($ids as $id) {
            $approved = is_array($approved_rows[$id] ?? null) ? $approved_rows[$id] : array();
            $samples[] = array(
                'person_id' => $id,
                'expected_name' => (string) ($approved['expected_name'] ?? ''),
                'tmdb_name' => (string) ($approved['tmdb_name'] ?? ''),
                'image_file' => (string) ($approved['image_file'] ?? ''),
            );
        }

        return $samples;
    }

    private function profile_image_coverage_cli_samples($samples) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') || empty($samples)) {
            return;
        }

        foreach ($samples as $bucket => $rows) {
            if (empty($rows) || !is_array($rows)) {
                continue;
            }

            WP_CLI::line($bucket . '_samples:');
            foreach ($rows as $row) {
                WP_CLI::line(sprintf(
                    '  - %s | %s | %s | %s',
                    (string) ($row['person_id'] ?? ''),
                    (string) ($row['expected_name'] ?? ''),
                    (string) ($row['tmdb_name'] ?? ''),
                    (string) ($row['image_file'] ?? '')
                ));
            }
        }
    }

    private function normalize_profile_image_existing_media_audit_cli_args($assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $folder = isset($assoc_args['folder']) ? trim((string) $assoc_args['folder']) : 'PEOPLE';
        $sample = isset($assoc_args['sample']) ? intval($assoc_args['sample']) : 25;
        $all_media = !empty($assoc_args['all-media']);
        $output_csv = isset($assoc_args['output-csv']) ? $clean_path($assoc_args['output-csv']) : '';

        if (!$all_media && $folder === '') {
            return new WP_Error('aat_profile_existing_media_missing_folder', 'Pass --folder=PEOPLE or use --all-media.');
        }

        if ($output_csv !== '') {
            $dir = dirname($output_csv);
            if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
                return new WP_Error('aat_profile_existing_media_csv_unwritable', 'The --output-csv directory is not writable.');
            }
        }

        return array(
            'mode' => 'existing-media-audit',
            'folder' => sanitize_text_field($folder),
            'sample' => max(0, min(200, $sample)),
            'all_media' => $all_media ? 1 : 0,
            'output_csv' => $output_csv,
        );
    }

    private function build_profile_image_existing_media_audit($options) {
        $folder_data = $this->get_profile_image_existing_media_attachment_ids($options);
        if (is_wp_error($folder_data)) {
            return $folder_data;
        }

        $attachment_ids = is_array($folder_data['attachment_ids'] ?? null) ? $folder_data['attachment_ids'] : array();
        $entity_set = $this->get_profile_image_coverage_id_set('entities');
        $entity_ids = is_array($entity_set['ids'] ?? null) ? $entity_set['ids'] : array();
        $label_index = $this->get_profile_image_person_label_index();
        $attachment_rows = $this->get_profile_image_existing_media_attachment_rows($attachment_ids);

        $rows = array();
        $candidate_counts = array();
        $summary = array(
            'mode' => 'existing-media-audit',
            'folder' => (string) ($options['folder'] ?? 'PEOPLE'),
            'folder_strategy' => (string) ($folder_data['strategy'] ?? ''),
            'folder_found' => !empty($folder_data['found']) ? 1 : 0,
            'all_media' => !empty($options['all_media']) ? 1 : 0,
            'folder_attachments' => count($attachment_ids),
            'scanned' => 0,
            'already_route_backed' => 0,
            'mapped_no_route' => 0,
            'reusable_nm_filename' => 0,
            'likely_name_match' => 0,
            'ambiguous_name_match' => 0,
            'needs_manual_review' => 0,
            'adoption_candidates' => 0,
            'duplicate_person_id_rows' => 0,
            'samples' => max(0, min(200, intval($options['sample'] ?? 25))),
            'output_csv' => (string) ($options['output_csv'] ?? ''),
        );

        foreach ($attachment_rows as $attachment) {
            $row = $this->build_profile_image_existing_media_audit_row($attachment, $entity_ids, $label_index);
            $rows[] = $row;

            $summary['scanned']++;
            $state = (string) ($row['state'] ?? 'needs_manual_review');
            if (isset($summary[$state])) {
                $summary[$state]++;
            }
            if (!empty($row['adoption_candidate'])) {
                $summary['adoption_candidates']++;
            }

            $candidate_id = (string) ($row['candidate_person_id'] ?? '');
            if ($candidate_id !== '' && $this->is_imdb_name_entity_id($candidate_id)) {
                if (!isset($candidate_counts[$candidate_id])) {
                    $candidate_counts[$candidate_id] = 0;
                }
                $candidate_counts[$candidate_id]++;
            }
        }

        foreach ($rows as &$row) {
            $candidate_id = (string) ($row['candidate_person_id'] ?? '');
            $duplicate_count = ($candidate_id !== '' && isset($candidate_counts[$candidate_id])) ? intval($candidate_counts[$candidate_id]) : 0;
            $row['duplicate_person_id'] = $duplicate_count > 1 ? 1 : 0;
            $row['duplicate_count'] = $duplicate_count;
            if ($duplicate_count > 1) {
                $summary['duplicate_person_id_rows']++;
            }
        }
        unset($row);

        $output_csv = (string) ($options['output_csv'] ?? '');
        if ($output_csv !== '') {
            $csv_result = $this->write_profile_image_existing_media_audit_csv($output_csv, $rows);
            if (is_wp_error($csv_result)) {
                return $csv_result;
            }
        }

        return array(
            'summary' => $summary,
            'rows' => $rows,
            'samples' => $this->profile_image_existing_media_sample_rows($rows, $summary['samples']),
        );
    }

    private function get_profile_image_existing_media_attachment_ids($options) {
        global $wpdb;

        $all_media = !empty($options['all_media']);
        $folder = trim((string) ($options['folder'] ?? 'PEOPLE'));

        if ($all_media) {
            $ids = $wpdb->get_col(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_mime_type LIKE 'image/%'
                 ORDER BY ID ASC"
            );

            return array(
                'attachment_ids' => array_values(array_unique(array_map('intval', (array) $ids))),
                'strategy' => 'all-media',
                'found' => true,
            );
        }

        $matches = $this->find_profile_image_media_folder_attachment_ids($folder);
        $ids = array();
        $strategies = array();

        foreach ($matches as $strategy => $strategy_ids) {
            $strategies[] = (string) $strategy;
            foreach ((array) $strategy_ids as $id) {
                $id = intval($id);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array(
            'attachment_ids' => array_keys($ids),
            'strategy' => empty($strategies) ? 'not-found' : implode('+', $strategies),
            'found' => !empty($ids),
        );
    }

    private function find_profile_image_media_folder_attachment_ids($folder) {
        global $wpdb;

        $folder = trim((string) $folder);
        if ($folder === '') {
            return array();
        }

        $matches = array();

        $taxonomy_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id, tt.taxonomy
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE LOWER(t.name) = LOWER(%s)
                   AND p.post_type = 'attachment'
                   AND p.post_mime_type LIKE 'image/%%'",
                $folder
            ),
            ARRAY_A
        );

        foreach ((array) $taxonomy_rows as $row) {
            $strategy = 'taxonomy:' . sanitize_key((string) ($row['taxonomy'] ?? 'unknown'));
            if (!isset($matches[$strategy])) {
                $matches[$strategy] = array();
            }
            $matches[$strategy][] = intval($row['object_id'] ?? 0);
        }

        $filebird_folders = $wpdb->prefix . 'fbv';
        $filebird_links = $wpdb->prefix . 'fbv_attachment_folder';
        if ($this->profile_image_existing_media_table_exists($filebird_folders) && $this->profile_image_existing_media_table_exists($filebird_links)) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT af.attachment_id
                     FROM {$filebird_folders} f
                     INNER JOIN {$filebird_links} af ON af.folder_id = f.id
                     INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id
                     WHERE LOWER(f.name) = LOWER(%s)
                       AND p.post_type = 'attachment'
                       AND p.post_mime_type LIKE 'image/%%'",
                    $folder
                )
            );
            if (!empty($ids)) {
                $matches['filebird'] = array_map('intval', (array) $ids);
            }
        }

        $rml_folders = $wpdb->prefix . 'realmedialibrary';
        $rml_links = $wpdb->prefix . 'realmedialibrary_posts';
        if ($this->profile_image_existing_media_table_exists($rml_folders) && $this->profile_image_existing_media_table_exists($rml_links)) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT rp.attachment
                     FROM {$rml_folders} r
                     INNER JOIN {$rml_links} rp ON rp.fid = r.id
                     INNER JOIN {$wpdb->posts} p ON p.ID = rp.attachment
                     WHERE LOWER(r.name) = LOWER(%s)
                       AND p.post_type = 'attachment'
                       AND p.post_mime_type LIKE 'image/%%'",
                    $folder
                )
            );
            if (!empty($ids)) {
                $matches['real-media-library'] = array_map('intval', (array) $ids);
            }
        }

        return $matches;
    }

    private function profile_image_existing_media_table_exists($table_name) {
        global $wpdb;

        $table_name = (string) $table_name;
        if ($table_name === '' || preg_match('/[^A-Za-z0-9_]/', $table_name)) {
            return false;
        }

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return (string) $exists === $table_name;
    }

    private function get_profile_image_existing_media_attachment_rows($attachment_ids) {
        global $wpdb;

        $attachment_ids = array_values(array_unique(array_filter(array_map('intval', (array) $attachment_ids))));
        if (empty($attachment_ids)) {
            return array();
        }

        $rows = array();
        foreach (array_chunk($attachment_ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $chunk_rows = $wpdb->get_results(
                "SELECT p.ID,
                        p.post_title,
                        p.post_name,
                        p.post_excerpt,
                        p.post_date,
                        pm_file.meta_value AS attached_file,
                        pm_alt.meta_value AS alt_text,
                        pm_person.meta_value AS explicit_person_id,
                        pm_source.meta_value AS portrait_source,
                        pm_verified.meta_value AS portrait_verified
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key = '_wp_attached_file'
                 LEFT JOIN {$wpdb->postmeta} pm_alt ON pm_alt.post_id = p.ID AND pm_alt.meta_key = '_wp_attachment_image_alt'
                 LEFT JOIN {$wpdb->postmeta} pm_person ON pm_person.post_id = p.ID AND pm_person.meta_key = '_aat_person_imdb_id'
                 LEFT JOIN {$wpdb->postmeta} pm_source ON pm_source.post_id = p.ID AND pm_source.meta_key = '_aat_person_portrait_source'
                 LEFT JOIN {$wpdb->postmeta} pm_verified ON pm_verified.post_id = p.ID AND pm_verified.meta_key = '_aat_person_portrait_verified'
                 WHERE p.ID IN ({$in})
                   AND p.post_type = 'attachment'
                   AND p.post_mime_type LIKE 'image/%'
                 ORDER BY p.post_title ASC, p.ID ASC",
                ARRAY_A
            );
            $rows = array_merge($rows, is_array($chunk_rows) ? $chunk_rows : array());
        }

        return $rows;
    }

    private function build_profile_image_existing_media_audit_row($attachment, $entity_ids, $label_index) {
        $attachment_id = intval($attachment['ID'] ?? 0);
        $attached_file = (string) ($attachment['attached_file'] ?? '');
        $post_title = (string) ($attachment['post_title'] ?? '');
        $post_name = (string) ($attachment['post_name'] ?? '');
        $alt_text = (string) ($attachment['alt_text'] ?? '');
        $explicit_person_id = strtolower(trim((string) ($attachment['explicit_person_id'] ?? '')));
        $portrait_source = (string) ($attachment['portrait_source'] ?? '');
        $portrait_verified = (string) ($attachment['portrait_verified'] ?? '');
        $detected_id = $this->extract_imdb_name_id_from_profile_media_text(array($attached_file, $post_title, $post_name, $alt_text));
        $candidate_person_id = '';
        $candidate_label = '';
        $match_strategy = 'none';
        $state = 'needs_manual_review';
        $adoption_candidate = 0;

        if ($this->is_imdb_name_entity_id($explicit_person_id)) {
            $candidate_person_id = $explicit_person_id;
            $match_strategy = 'aat-person-meta';
            $state = isset($entity_ids[$candidate_person_id]) ? 'already_route_backed' : 'mapped_no_route';
        } elseif ($this->is_imdb_name_entity_id($detected_id)) {
            $candidate_person_id = $detected_id;
            $match_strategy = 'imdb-text';
            if (isset($entity_ids[$candidate_person_id])) {
                $state = 'reusable_nm_filename';
                $adoption_candidate = 1;
            }
        } else {
            $name_match = $this->match_profile_image_existing_media_name($post_title, $alt_text, $attached_file, $label_index);
            $name_state = (string) ($name_match['state'] ?? '');
            if ($name_state === 'unique') {
                $candidate_person_id = (string) ($name_match['person_id'] ?? '');
                $candidate_label = (string) ($name_match['label'] ?? '');
                $match_strategy = 'name-exact';
                if (isset($entity_ids[$candidate_person_id])) {
                    $state = 'likely_name_match';
                    $adoption_candidate = 1;
                }
            } elseif ($name_state === 'ambiguous') {
                $match_strategy = 'name-ambiguous';
                $state = 'ambiguous_name_match';
                $candidate_label = (string) ($name_match['label'] ?? '');
            }
        }

        if ($candidate_label === '' && $candidate_person_id !== '') {
            $context = $this->get_person_context_for_imdb_id($candidate_person_id);
            $candidate_label = trim((string) ($context['name'] ?? ''));
        }

        return array(
            'attachment_id' => $attachment_id,
            'attached_file' => $attached_file,
            'post_title' => $post_title,
            'alt_text' => $alt_text,
            'explicit_person_id' => $explicit_person_id,
            'detected_person_id' => $detected_id,
            'candidate_person_id' => $candidate_person_id,
            'candidate_label' => $candidate_label,
            'match_strategy' => $match_strategy,
            'state' => $state,
            'adoption_candidate' => $adoption_candidate,
            'duplicate_person_id' => 0,
            'duplicate_count' => 0,
            'portrait_source' => $portrait_source,
            'portrait_verified' => $portrait_verified,
        );
    }

    private function extract_imdb_name_id_from_profile_media_text($texts) {
        foreach ((array) $texts as $text) {
            if (preg_match('/\bnm\d{7,9}\b/i', (string) $text, $matches)) {
                return strtolower((string) $matches[0]);
            }
        }

        return '';
    }

    private function get_profile_image_person_label_index() {
        global $wpdb;

        $entities_table = $this->get_entities_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entity_id AS person_id, label
                 FROM {$entities_table}
                 WHERE entity_type = %s
                   AND entity_id REGEXP %s",
                'name',
                '^nm[0-9]{7,9}$'
            ),
            ARRAY_A
        );

        $index = array();
        foreach ((array) $rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $person_id = strtolower(trim((string) ($row['person_id'] ?? '')));
            $normalized = $this->normalize_profile_image_existing_media_person_name($label);
            if ($label === '' || !$this->is_imdb_name_entity_id($person_id) || $normalized === '') {
                continue;
            }
            if (!isset($index[$normalized])) {
                $index[$normalized] = array();
            }
            $index[$normalized][] = array(
                'person_id' => $person_id,
                'label' => $label,
            );
        }

        return $index;
    }

    private function match_profile_image_existing_media_name($post_title, $alt_text, $attached_file, $label_index) {
        $candidates = array($post_title, $alt_text, wp_basename((string) $attached_file));
        foreach ($candidates as $candidate) {
            $normalized = $this->normalize_profile_image_existing_media_person_name($candidate);
            if ($normalized === '' || empty($label_index[$normalized])) {
                continue;
            }

            $matches = $label_index[$normalized];
            if (count($matches) === 1) {
                return array(
                    'state' => 'unique',
                    'person_id' => (string) ($matches[0]['person_id'] ?? ''),
                    'label' => (string) ($matches[0]['label'] ?? ''),
                );
            }

            return array(
                'state' => 'ambiguous',
                'label' => $candidate,
            );
        }

        return array('state' => 'none');
    }

    private function normalize_profile_image_existing_media_person_name($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = wp_basename(str_replace('\\', '/', $value));
        $value = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $value);
        $value = preg_replace('/\bnm\d{7,9}\b/i', ' ', $value);
        $value = str_replace(array('_', '-'), ' ', $value);
        $value = preg_replace('/\b(person\s*profile|profile|portrait|headshot|photo|image)\b/i', ' ', $value);
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function profile_image_existing_media_sample_rows($rows, $limit) {
        $limit = max(0, min(200, intval($limit)));
        if ($limit < 1 || empty($rows)) {
            return array();
        }

        $samples = array();
        foreach ($rows as $row) {
            $samples[] = $row;
            if (count($samples) >= $limit) {
                break;
            }
        }

        return $samples;
    }

    private function write_profile_image_existing_media_audit_csv($path, $rows) {
        $path = trim((string) $path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return true;
        }

        $handle = fopen($path, 'w');
        if (!$handle) {
            return new WP_Error('aat_profile_existing_media_csv_open_failed', 'Could not open --output-csv for writing.');
        }

        $fields = array(
            'attachment_id',
            'state',
            'candidate_person_id',
            'candidate_label',
            'match_strategy',
            'adoption_candidate',
            'duplicate_person_id',
            'duplicate_count',
            'explicit_person_id',
            'detected_person_id',
            'portrait_source',
            'portrait_verified',
            'post_title',
            'alt_text',
            'attached_file',
        );
        fputcsv($handle, $fields);
        foreach ($rows as $row) {
            $line = array();
            foreach ($fields as $field) {
                $line[] = isset($row[$field]) && is_scalar($row[$field]) ? (string) $row[$field] : '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
        return true;
    }

    private function profile_image_existing_media_cli_samples($samples) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI') || empty($samples)) {
            return;
        }

        WP_CLI::line('existing_media_samples:');
        foreach ($samples as $row) {
            WP_CLI::line(sprintf(
                '  - #%d | %s | %s | %s | %s | %s',
                intval($row['attachment_id'] ?? 0),
                (string) ($row['state'] ?? ''),
                (string) ($row['candidate_person_id'] ?? ''),
                (string) ($row['candidate_label'] ?? ''),
                (string) ($row['match_strategy'] ?? ''),
                (string) ($row['attached_file'] ?? '')
            ));
        }
    }

    private function profile_image_batch_cli_report($summary) {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        $ordered = array(
            'mode',
            'source',
            'input_csv',
            'results_csv',
            'missing_csv',
            'batch',
            'folder',
            'folder_strategy',
            'folder_found',
            'all_media',
            'category',
            'state',
            'sample',
            'limit',
            'offset',
            'commit',
            'overwrite',
            'parsed_rows',
            'stageable',
            'would_stage',
            'staged',
            'skipped_existing',
            'source_rows',
            'credit_labels',
            'person_credit_linked',
            'person_credit_unresolved',
            'missing_source_nominee_ids',
            'label_id_mismatch',
            'folder_attachments',
            'source_images',
            'csv_approved',
            'approved_ids',
            'people_table_available',
            'people_ids',
            'entity_ids',
            'imported_ids',
            'route_backed_approved',
            'approved_without_people',
            'approved_without_entity',
            'approved_in_people_without_entity',
            'imported_without_entity',
            'route_backed_imported',
            'samples',
            'already_route_backed',
            'mapped_no_route',
            'reusable_nm_filename',
            'likely_name_match',
            'ambiguous_name_match',
            'needs_manual_review',
            'adoption_candidates',
            'duplicate_person_id_rows',
            'output_csv',
            'missing_ids',
            'source_in_approved',
            'source_in_missing',
            'source_unknown',
            'already_imported',
            'importable',
            'queued',
            'processed',
            'imported',
            'skipped',
            'errors',
        );

        foreach ($ordered as $key) {
            if (array_key_exists($key, $summary)) {
                WP_CLI::line($key . ': ' . (is_scalar($summary[$key]) ? (string) $summary[$key] : wp_json_encode($summary[$key])));
            }
        }
    }

    private function normalize_profile_image_batch_cli_args($mode, $assoc_args) {
        $clean_path = function($value) {
            return trim((string) $value, " \t\n\r\0\x0B\"'");
        };

        $source = isset($assoc_args['source']) ? $clean_path($assoc_args['source']) : '';
        $results_csv = isset($assoc_args['results-csv']) ? $clean_path($assoc_args['results-csv']) : '';
        $missing_csv = isset($assoc_args['missing-csv']) ? $clean_path($assoc_args['missing-csv']) : '';
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 100;
        $offset = isset($assoc_args['offset']) ? intval($assoc_args['offset']) : 0;
        $batch = isset($assoc_args['batch']) ? sanitize_key((string) $assoc_args['batch']) : '';

        if ($source === '') {
            return new WP_Error('aat_profile_batch_missing_source', 'Pass --source with an extracted image folder or the oscars-profile-images zip.');
        }
        if ($results_csv === '') {
            return new WP_Error('aat_profile_batch_missing_results_csv', 'Pass --results-csv pointing at tmdb_profile_results.csv.');
        }
        if ($missing_csv === '') {
            return new WP_Error('aat_profile_batch_missing_missing_csv', 'Pass --missing-csv pointing at profiles_missing.csv.');
        }
        if (!is_readable($results_csv)) {
            return new WP_Error('aat_profile_batch_results_unreadable', 'The --results-csv file is not readable.');
        }
        if (!is_readable($missing_csv)) {
            return new WP_Error('aat_profile_batch_missing_unreadable', 'The --missing-csv file is not readable.');
        }
        if (!is_readable($source)) {
            return new WP_Error('aat_profile_batch_source_unreadable', 'The --source folder or zip is not readable.');
        }

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        if ($batch === '') {
            $batch = 'manual-profile-batch-' . gmdate('Ymd-His');
        }

        return array(
            'mode' => $mode,
            'source' => $source,
            'results-csv' => $results_csv,
            'missing-csv' => $missing_csv,
            'limit' => $limit,
            'offset' => $offset,
            'batch' => $batch,
        );
    }

    private function build_profile_image_batch_plan($options) {
        $source = (string) ($options['source'] ?? '');
        $results_csv = (string) ($options['results-csv'] ?? '');
        $missing_csv = (string) ($options['missing-csv'] ?? '');
        $limit = intval($options['limit'] ?? 100);
        $offset = intval($options['offset'] ?? 0);

        $results = $this->read_profile_image_batch_results_csv($results_csv);
        if (is_wp_error($results)) {
            return $results;
        }

        $profiles_missing = $this->read_profile_image_batch_missing_csv($missing_csv);
        if (is_wp_error($profiles_missing)) {
            return $profiles_missing;
        }

        $source_manifest = $this->read_profile_image_batch_source_manifest($source);
        if (is_wp_error($source_manifest)) {
            return $source_manifest;
        }

        ksort($source_manifest, SORT_NATURAL);

        $summary = array(
            'mode' => (string) ($options['mode'] ?? 'dry-run'),
            'source' => $source,
            'results_csv' => $results_csv,
            'missing_csv' => $missing_csv,
            'batch' => (string) ($options['batch'] ?? ''),
            'limit' => $limit,
            'offset' => $offset,
            'source_images' => count($source_manifest),
            'csv_approved' => count($results),
            'missing_ids' => count($profiles_missing),
            'source_in_approved' => 0,
            'source_in_missing' => 0,
            'source_unknown' => 0,
            'already_imported' => 0,
            'importable' => 0,
            'queued' => 0,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
        );

        $rows = array();
        foreach ($source_manifest as $person_id => $image) {
            if (isset($profiles_missing[$person_id])) {
                $summary['source_in_missing']++;
                $summary['skipped']++;
                continue;
            }

            if (!isset($results[$person_id])) {
                $summary['source_unknown']++;
                $summary['errors']++;
                continue;
            }

            $summary['source_in_approved']++;
            $approved = $results[$person_id];
            $expected_file = strtolower((string) ($approved['image_file'] ?? ''));
            $actual_file = strtolower((string) ($image['file_name'] ?? ''));
            if ($expected_file !== '' && $actual_file !== '' && $expected_file !== $actual_file) {
                $summary['errors']++;
                continue;
            }

            $existing_attachment_id = $this->find_existing_person_portrait_attachment($person_id, '');
            if ($existing_attachment_id > 0) {
                $summary['already_imported']++;
                $summary['skipped']++;
                continue;
            }

            $summary['importable']++;
            $rows[] = array_merge($image, array(
                'person_id' => $person_id,
                'expected_name' => (string) ($approved['expected_name'] ?? ''),
                'tmdb_name' => (string) ($approved['tmdb_name'] ?? ''),
                'tmdb_person_id' => intval($approved['tmdb_person_id'] ?? 0),
                'profile_path' => (string) ($approved['profile_path'] ?? ''),
                'image_file' => (string) ($approved['image_file'] ?? ''),
                'status' => (string) ($approved['status'] ?? 'OK'),
            ));
        }

        $queued = array_slice($rows, $offset, $limit);
        $summary['queued'] = count($queued);

        return array(
            'summary' => $summary,
            'rows' => $queued,
        );
    }

    private function read_profile_image_batch_results_csv($path) {
        $rows = $this->read_profile_image_batch_csv_rows($path);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $approved = array();
        foreach ($rows as $row) {
            $person_id = strtolower(trim((string) ($row['NomineeId'] ?? '')));
            if (!$this->is_imdb_name_entity_id($person_id)) {
                continue;
            }

            $status = strtoupper(trim((string) ($row['Status'] ?? '')));
            $image_file = trim((string) ($row['ImageFile'] ?? ''));
            if ($status !== 'OK' || $image_file === '') {
                continue;
            }

            $approved[$person_id] = array(
                'person_id' => $person_id,
                'expected_name' => trim((string) ($row['ExpectedName'] ?? '')),
                'tmdb_name' => trim((string) ($row['TMDBName'] ?? '')),
                'tmdb_person_id' => intval($row['TMDBPersonId'] ?? 0),
                'profile_path' => trim((string) ($row['ProfilePath'] ?? '')),
                'image_file' => sanitize_file_name($image_file),
                'status' => 'OK',
            );
        }

        return $approved;
    }

    private function read_profile_image_batch_missing_csv($path) {
        $rows = $this->read_profile_image_batch_csv_rows($path);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $profiles_missing = array();
        foreach ($rows as $row) {
            $person_id = strtolower(trim((string) ($row['NomineeId'] ?? '')));
            if (!$this->is_imdb_name_entity_id($person_id)) {
                continue;
            }

            $profiles_missing[$person_id] = array(
                'person_id' => $person_id,
                'expected_name' => trim((string) ($row['ExpectedName'] ?? '')),
                'status' => strtoupper(trim((string) ($row['Status'] ?? 'NO_PHOTO'))),
            );
        }

        return $profiles_missing;
    }

    private function read_profile_image_batch_csv_rows($path) {
        $path = trim((string) $path, " \t\n\r\0\x0B\"'");
        if ($path === '' || !is_readable($path)) {
            return new WP_Error('aat_profile_batch_csv_unreadable', 'Profile image batch CSV is not readable.');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return new WP_Error('aat_profile_batch_csv_open_failed', 'Profile image batch CSV could not be opened.');
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            return new WP_Error('aat_profile_batch_csv_headers_missing', 'Profile image batch CSV headers are missing.');
        }
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }

        $rows = array();
        while (($raw = fgetcsv($handle)) !== false) {
            $row = array();
            foreach ($headers as $index => $header) {
                $row[(string) $header] = isset($raw[$index]) ? trim((string) $raw[$index]) : '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function read_profile_image_batch_source_manifest($source_path) {
        $source_path = trim((string) $source_path, " \t\n\r\0\x0B\"'");
        $allowed_extensions = array('.jpg', '.jpeg');
        $manifest = array();

        $add_file = function($file_name, $source_type, $path, $zip_path = '') use (&$manifest, $allowed_extensions) {
            $file_name = sanitize_file_name((string) $file_name);
            $extension = strtolower(strrchr($file_name, '.'));
            if (!in_array($extension, $allowed_extensions, true)) {
                return;
            }

            if (!preg_match('/(nm\d{7,9})/i', $file_name, $matches)) {
                return;
            }

            $person_id = strtolower((string) $matches[1]);
            if (!$this->is_imdb_name_entity_id($person_id)) {
                return;
            }

            $manifest[$person_id] = array(
                'person_id' => $person_id,
                'source_type' => $source_type,
                'source_path' => $path,
                'zip_path' => $zip_path,
                'file_name' => $file_name,
            );
        };

        if (is_dir($source_path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $add_file($file->getFilename(), 'directory', $file->getPathname(), '');
            }
            return $manifest;
        }

        $lower_source = strtolower($source_path);
        if (is_file($source_path) && substr($lower_source, -4) === '.zip') {
            if (!class_exists('ZipArchive')) {
                return new WP_Error('aat_profile_batch_zip_unavailable', 'ZipArchive is not available. Extract oscars-profile-images first and pass that folder as --source.');
            }

            $zip = new ZipArchive();
            if ($zip->open($source_path) !== true) {
                return new WP_Error('aat_profile_batch_zip_open_failed', 'The oscars-profile-images zip could not be opened.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $entry = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($entry === '' || substr($entry, -1) === '/') {
                    continue;
                }
                if (strpos(str_replace('\\', '/', $entry), 'oscars-profile-images/') === false) {
                    continue;
                }
                $add_file(basename($entry), 'zip', $source_path, $entry);
            }

            $zip->close();
            return $manifest;
        }

        return new WP_Error('aat_profile_batch_source_invalid', 'The --source value must be an extracted image folder or the oscars-profile-images zip.');
    }

    private function import_manual_person_profile_image($row, $batch_label) {
        $person_id = strtolower(trim((string) ($row['person_id'] ?? '')));
        if (!$this->is_imdb_name_entity_id($person_id)) {
            return new WP_Error('aat_profile_batch_invalid_person_id', 'A valid IMDb person ID is required for manual profile image import.');
        }

        $existing_attachment_id = $this->find_existing_person_portrait_attachment($person_id, '');
        if ($existing_attachment_id > 0) {
            return array(
                'status' => 'existing',
                'attachment_id' => $existing_attachment_id,
                'person_id' => $person_id,
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp_file = $this->copy_profile_image_source_to_temp_file($row);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $image_info = @getimagesize($tmp_file);
        if (!is_array($image_info) || (string) ($image_info['mime'] ?? '') !== 'image/jpeg') {
            @unlink($tmp_file);
            return new WP_Error('aat_profile_batch_not_jpeg', 'Manual profile image is not a valid JPEG.');
        }

        $expected_name = trim((string) ($row['expected_name'] ?? ''));
        if ($expected_name === '') {
            $context = $this->get_person_context_for_imdb_id($person_id);
            $expected_name = trim((string) ($context['name'] ?? ''));
        }
        if ($expected_name === '') {
            $expected_name = trim((string) $this->get_entity_display_name('name', $person_id));
        }
        if ($expected_name === '') {
            $expected_name = strtoupper($person_id);
        }

        $file_array = array(
            'name' => sanitize_file_name($person_id . '-manual-profile.jpg'),
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, 0, sprintf(__('Portrait %s', 'academy-awards-table'), $person_id));
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return $attachment_id;
        }

        $attachment_id = intval($attachment_id);
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_title' => $expected_name . ' portrait',
            'post_excerpt' => sprintf(__('Verified manual batch portrait for %s.', 'academy-awards-table'), $expected_name),
        ));

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $expected_name . ' portrait');
        update_post_meta($attachment_id, '_aat_person_imdb_id', $person_id);
        update_post_meta($attachment_id, '_aat_person_portrait_source', 'manual-batch-upload');
        update_post_meta($attachment_id, '_aat_person_portrait_verified', '1');
        update_post_meta($attachment_id, '_aat_person_portrait_batch', sanitize_key((string) $batch_label));
        update_post_meta($attachment_id, '_aat_person_portrait_original_file', sanitize_file_name((string) ($row['file_name'] ?? '')));
        update_post_meta($attachment_id, '_aat_tmdb_person_id', intval($row['tmdb_person_id'] ?? 0));
        update_post_meta($attachment_id, '_aat_tmdb_profile_path', (string) ($row['profile_path'] ?? ''));

        delete_transient('aat_person_profile_attachment_v2_' . $person_id);

        return array(
            'status' => 'imported',
            'attachment_id' => $attachment_id,
            'person_id' => $person_id,
        );
    }

    private function copy_profile_image_source_to_temp_file($row) {
        $source_type = (string) ($row['source_type'] ?? '');
        $source_path = (string) ($row['source_path'] ?? '');
        $file_name = sanitize_file_name((string) ($row['file_name'] ?? 'profile.jpg'));
        $tmp_file = wp_tempnam($file_name);

        if (!$tmp_file) {
            return new WP_Error('aat_profile_batch_temp_failed', 'Could not create a temporary file for manual profile image import.');
        }

        if ($source_type === 'directory') {
            if (!is_readable($source_path) || !copy($source_path, $tmp_file)) {
                @unlink($tmp_file);
                return new WP_Error('aat_profile_batch_copy_failed', 'Could not copy the manual profile image into a temporary file.');
            }
            return $tmp_file;
        }

        if ($source_type === 'zip') {
            if (!class_exists('ZipArchive')) {
                @unlink($tmp_file);
                return new WP_Error('aat_profile_batch_zip_unavailable', 'ZipArchive is not available for manual profile image import.');
            }

            $zip_path = (string) ($row['zip_path'] ?? '');
            $zip = new ZipArchive();
            if ($zip->open($source_path) !== true) {
                @unlink($tmp_file);
                return new WP_Error('aat_profile_batch_zip_open_failed', 'The manual profile image zip could not be opened.');
            }

            $input = $zip->getStream($zip_path);
            if (!$input) {
                $zip->close();
                @unlink($tmp_file);
                return new WP_Error('aat_profile_batch_zip_entry_missing', 'The manual profile image zip entry could not be read.');
            }

            $output = fopen($tmp_file, 'wb');
            if (!$output) {
                fclose($input);
                $zip->close();
                @unlink($tmp_file);
                return new WP_Error('aat_profile_batch_temp_open_failed', 'Could not open the temporary profile image for writing.');
            }

            stream_copy_to_stream($input, $output);
            fclose($output);
            fclose($input);
            $zip->close();
            return $tmp_file;
        }

        @unlink($tmp_file);
        return new WP_Error('aat_profile_batch_unknown_source_type', 'Manual profile image source type is not supported.');
    }

    private function get_person_portrait_existing_review_states() {
        return array(
            'needs_review' => __('Needs Review', 'academy-awards-table'),
            'approved_to_adopt' => __('Approved To Adopt', 'academy-awards-table'),
            'wrong_person_or_label' => __('Wrong Person Or Label', 'academy-awards-table'),
            'not_a_person' => __('Not A Person', 'academy-awards-table'),
            'needs_better_source' => __('Needs Better Source', 'academy-awards-table'),
            'reject_ignore' => __('Reject / Ignore', 'academy-awards-table'),
            'resolved' => __('Resolved', 'academy-awards-table'),
        );
    }

    private function sanitize_person_portrait_existing_review_state($state) {
        $state = sanitize_key((string) $state);
        $states = $this->get_person_portrait_existing_review_states();
        return isset($states[$state]) ? $state : 'needs_review';
    }

    private function get_person_portrait_existing_issue_types() {
        return array(
            'none' => __('None', 'academy-awards-table'),
            'missing_expected_name' => __('Missing Expected Name', 'academy-awards-table'),
            'expected_label_mismatch' => __('Expected Label Mismatch', 'academy-awards-table'),
            'expected_source_gap' => __('Expected Source Gap', 'academy-awards-table'),
            'suspicious_label' => __('Suspicious Label', 'academy-awards-table'),
            'manual_note' => __('Manual Note', 'academy-awards-table'),
        );
    }

    private function sanitize_person_portrait_existing_issue_type($issue_type) {
        $issue_type = sanitize_key((string) $issue_type);
        $issue_types = $this->get_person_portrait_existing_issue_types();
        return isset($issue_types[$issue_type]) ? $issue_type : 'none';
    }

    private function get_person_portrait_existing_review_key($attachment_id, $person_id) {
        return absint($attachment_id) . '|' . strtolower(trim((string) $person_id));
    }

    private function get_default_person_portrait_existing_review_record($attachment_id, $person_id) {
        $states = $this->get_person_portrait_existing_review_states();
        $issue_types = $this->get_person_portrait_existing_issue_types();
        return array(
            'attachment_id' => absint($attachment_id),
            'candidate_person_id' => strtolower(trim((string) $person_id)),
            'review_state' => 'needs_review',
            'review_state_label' => $states['needs_review'],
            'issue_type' => 'none',
            'issue_type_label' => $issue_types['none'],
            'correction_note' => '',
            'reviewer_user_id' => 0,
            'reviewed_at' => '',
            'updated_at' => '',
            'is_reviewed' => false,
            'is_approved' => false,
        );
    }

    private function get_person_portrait_existing_review_records($pairs) {
        global $wpdb;

        $attachment_ids = array();
        $person_ids = array();
        foreach ((array) $pairs as $pair) {
            $attachment_id = absint($pair['attachment_id'] ?? 0);
            $person_id = strtolower(trim((string) ($pair['person_id'] ?? '')));
            if ($attachment_id > 0 && $this->is_imdb_name_entity_id($person_id)) {
                $attachment_ids[$attachment_id] = true;
                $person_ids[$person_id] = true;
            }
        }

        if (empty($attachment_ids) || empty($person_ids)) {
            return array();
        }

        $table = $this->get_person_portrait_existing_reviews_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $attachment_ids = array_keys($attachment_ids);
        $person_ids = array_keys($person_ids);
        $attachment_placeholders = implode(', ', array_fill(0, count($attachment_ids), '%d'));
        $person_placeholders = implode(', ', array_fill(0, count($person_ids), '%s'));
        $sql = $wpdb->prepare(
            "SELECT attachment_id, candidate_person_id, review_state, issue_type, correction_note, reviewer_user_id, reviewed_at, updated_at FROM $table WHERE attachment_id IN ($attachment_placeholders) AND candidate_person_id IN ($person_placeholders)",
            array_merge($attachment_ids, $person_ids)
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $states = $this->get_person_portrait_existing_review_states();
        $issue_types = $this->get_person_portrait_existing_issue_types();
        $records = array();
        foreach ($rows as $row) {
            $attachment_id = absint($row['attachment_id'] ?? 0);
            $person_id = strtolower(trim((string) ($row['candidate_person_id'] ?? '')));
            if ($attachment_id <= 0 || !$this->is_imdb_name_entity_id($person_id)) {
                continue;
            }

            $state = $this->sanitize_person_portrait_existing_review_state($row['review_state'] ?? '');
            $issue_type = $this->sanitize_person_portrait_existing_issue_type($row['issue_type'] ?? '');
            $records[$this->get_person_portrait_existing_review_key($attachment_id, $person_id)] = array(
                'attachment_id' => $attachment_id,
                'candidate_person_id' => $person_id,
                'review_state' => $state,
                'review_state_label' => $states[$state] ?? $states['needs_review'],
                'issue_type' => $issue_type,
                'issue_type_label' => $issue_types[$issue_type] ?? $issue_types['none'],
                'correction_note' => (string) ($row['correction_note'] ?? ''),
                'reviewer_user_id' => (int) ($row['reviewer_user_id'] ?? 0),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'is_reviewed' => true,
                'is_approved' => $state === 'approved_to_adopt',
            );
        }

        return $records;
    }

    private function get_current_existing_person_portrait_candidate_row($attachment_id, $person_id) {
        $attachment_id = absint($attachment_id);
        $person_id = strtolower(trim((string) $person_id));

        if ($attachment_id <= 0) {
            return new WP_Error('aat_existing_portrait_review_missing_attachment', __('A valid attachment is required for existing portrait review.', 'academy-awards-table'));
        }
        if (!$this->is_imdb_name_entity_id($person_id)) {
            return new WP_Error('aat_existing_portrait_review_bad_person_id', __('A valid IMDb person ID is required for existing portrait review.', 'academy-awards-table'));
        }
        if (get_post_type($attachment_id) !== 'attachment' || !wp_attachment_is_image($attachment_id)) {
            return new WP_Error('aat_existing_portrait_review_not_image', __('The selected attachment is not an image attachment.', 'academy-awards-table'));
        }

        $audit = $this->build_profile_image_existing_media_audit(array(
            'folder' => 'PEOPLE',
            'sample' => 0,
            'all_media' => 0,
            'output_csv' => '',
            'state' => 'reusable_nm_filename',
        ));
        if (is_wp_error($audit)) {
            return new WP_Error('aat_existing_portrait_review_audit_unavailable', $audit->get_error_message());
        }

        foreach ((array) ($audit['rows'] ?? array()) as $row) {
            if (intval($row['attachment_id'] ?? 0) !== $attachment_id) {
                continue;
            }
            if (strtolower(trim((string) ($row['candidate_person_id'] ?? ''))) !== $person_id || empty($row['adoption_candidate'])) {
                return new WP_Error('aat_existing_portrait_review_stale_candidate', __('This PEOPLE attachment no longer matches the submitted person candidate.', 'academy-awards-table'));
            }

            return $row;
        }

        return new WP_Error('aat_existing_portrait_review_missing_candidate', __('This PEOPLE attachment is no longer present in the reusable candidate audit.', 'academy-awards-table'));
    }

    private function replace_person_portrait_existing_review_record($args) {
        $attachment_id = absint($args['attachment_id'] ?? 0);
        $person_id = strtolower(trim((string) ($args['candidate_person_id'] ?? '')));
        if ($attachment_id <= 0 || !$this->is_imdb_name_entity_id($person_id)) {
            return new WP_Error('aat_existing_portrait_review_bad_key', __('Existing portrait review requires an attachment and IMDb person ID.', 'academy-awards-table'));
        }

        $states = $this->get_person_portrait_existing_review_states();
        $state = sanitize_key((string) ($args['review_state'] ?? ''));
        if (!isset($states[$state])) {
            return new WP_Error('aat_existing_portrait_review_bad_state', __('Choose a supported existing PEOPLE portrait review state.', 'academy-awards-table'));
        }

        $issue_types = $this->get_person_portrait_existing_issue_types();
        $issue_type = sanitize_key((string) ($args['issue_type'] ?? ''));
        if (!isset($issue_types[$issue_type])) {
            return new WP_Error('aat_existing_portrait_review_bad_issue_type', __('Choose a supported existing PEOPLE portrait issue type.', 'academy-awards-table'));
        }

        $note = sanitize_textarea_field((string) ($args['correction_note'] ?? ''));
        $reviewer_user_id = absint($args['reviewer_user_id'] ?? get_current_user_id());
        $now = current_time('mysql');

        global $wpdb;
        $table = $this->get_person_portrait_existing_reviews_table_name();
        $this->maybe_create_person_portrait_existing_reviews_table();

        $result = $wpdb->replace(
            $table,
            array(
                'attachment_id' => $attachment_id,
                'candidate_person_id' => $person_id,
                'review_state' => $state,
                'issue_type' => $issue_type,
                'correction_note' => $note,
                'reviewer_user_id' => $reviewer_user_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('aat_existing_portrait_review_save_failed', __('Could not save the existing PEOPLE portrait review.', 'academy-awards-table'));
        }

        return true;
    }

    private function save_person_portrait_existing_review_record_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_existing_portrait_review_forbidden', __('You do not have permission to save existing PEOPLE portrait reviews.', 'academy-awards-table'));
        }

        $attachment_id = isset($request['existing_review_attachment_id']) ? absint($request['existing_review_attachment_id']) : 0;
        $person_id = isset($request['existing_review_person_id']) ? strtolower(trim(sanitize_text_field(wp_unslash($request['existing_review_person_id'])))) : '';
        $candidate_row = $this->get_current_existing_person_portrait_candidate_row($attachment_id, $person_id);
        if (is_wp_error($candidate_row)) {
            return $candidate_row;
        }
        if (!empty($candidate_row['duplicate_person_id'])) {
            return new WP_Error('aat_existing_portrait_review_duplicate_candidate', __('Duplicate PEOPLE portrait candidates must stay in duplicate-specific visual review.', 'academy-awards-table'));
        }

        $note = isset($request['existing_review_note']) ? sanitize_textarea_field(wp_unslash($request['existing_review_note'])) : '';

        return $this->replace_person_portrait_existing_review_record(array(
            'attachment_id' => $attachment_id,
            'candidate_person_id' => $person_id,
            'review_state' => $request['existing_review_state'] ?? '',
            'issue_type' => $request['existing_review_issue_type'] ?? '',
            'correction_note' => $note,
            'reviewer_user_id' => get_current_user_id(),
        ));
    }

    private function get_existing_person_portrait_adoption_rows($args = array()) {
        $limit = isset($args['limit']) ? intval($args['limit']) : 24;
        $limit = max(1, min(60, $limit));
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;
        $offset = max(0, $offset);
        $view = isset($args['view']) ? sanitize_key((string) $args['view']) : 'all';
        if (!in_array($view, array('all', 'hold_review', 'needs_review', 'needs_source', 'wrong_label', 'approved', 'ready', 'duplicates', 'duplicate_groups', 'manual'), true)) {
            $view = 'all';
        }

        $audit = $this->build_profile_image_existing_media_audit(array(
            'folder' => 'PEOPLE',
            'sample' => 0,
            'all_media' => 0,
            'output_csv' => '',
            'state' => 'reusable_nm_filename',
        ));
        if (is_wp_error($audit)) {
            return array(
                'rows' => array(),
                'summary' => array(
                    'error' => $audit->get_error_message(),
                    'returned' => 0,
                ),
            );
        }

        $audit_rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
        $candidate_rows = array();
        foreach ($audit_rows as $row) {
            $row_state = (string) ($row['state'] ?? '');
            $is_manual_review = $row_state === 'needs_manual_review';
            if (!$is_manual_review && $row_state !== 'reusable_nm_filename') {
                continue;
            }
            if (!$is_manual_review && empty($row['adoption_candidate'])) {
                continue;
            }

            $attachment_id = intval($row['attachment_id'] ?? 0);
            if ($is_manual_review) {
                $label = trim((string) ($row['post_title'] ?? ''));
                if ($label === '') {
                    $label = trim(basename((string) ($row['attached_file'] ?? '')));
                }
                if ($label === '') {
                    $label = sprintf(__('Attachment #%d', 'academy-awards-table'), $attachment_id);
                }

                $candidate_rows[] = array_merge($row, array(
                    'attachment_id' => $attachment_id,
                    'person_id' => '',
                    'label' => $label,
                    'thumb_url' => $attachment_id > 0 ? (string) wp_get_attachment_image_url($attachment_id, 'medium') : '',
                    'full_url' => $attachment_id > 0 ? (string) wp_get_attachment_url($attachment_id) : '',
                    'profile_url' => '',
                    'match_strategy' => (string) ($row['match_strategy'] ?? 'none'),
                    'detected_person_id' => (string) ($row['detected_person_id'] ?? ''),
                    'explicit_person_id' => (string) ($row['explicit_person_id'] ?? ''),
                    'state_label' => __('Manual review needed', 'academy-awards-table'),
                    'manual_review' => 1,
                    'manual_review_reason' => __('No safe IMDb person ID was detected for this PEOPLE image.', 'academy-awards-table'),
                ));
                continue;
            }

            $person_id = strtolower(trim((string) ($row['candidate_person_id'] ?? '')));
            $label = trim((string) ($row['candidate_label'] ?? ''));
            if ($label === '') {
                $label = trim((string) $this->get_entity_display_name('name', $person_id));
            }

            $candidate_rows[] = array_merge($row, array(
                'attachment_id' => $attachment_id,
                'person_id' => $person_id,
                'label' => $label !== '' ? $label : strtoupper($person_id),
                'thumb_url' => $attachment_id > 0 ? (string) wp_get_attachment_image_url($attachment_id, 'medium') : '',
                'full_url' => $attachment_id > 0 ? (string) wp_get_attachment_url($attachment_id) : '',
                'profile_url' => $this->build_entity_url_from_id($person_id),
                'state_label' => !empty($row['duplicate_person_id']) ? __('Duplicate candidate', 'academy-awards-table') : __('Ready to adopt', 'academy-awards-table'),
            ));
        }

        $duplicate_groups = array();
        foreach ($candidate_rows as $candidate_row) {
            if (empty($candidate_row['duplicate_person_id'])) {
                continue;
            }

            $person_id = (string) ($candidate_row['person_id'] ?? '');
            if ($person_id === '') {
                continue;
            }
            if (!isset($duplicate_groups[$person_id])) {
                $duplicate_groups[$person_id] = array();
            }

            $duplicate_groups[$person_id][] = array(
                'attachment_id' => intval($candidate_row['attachment_id'] ?? 0),
                'post_title' => (string) ($candidate_row['post_title'] ?? ''),
                'attached_file' => (string) ($candidate_row['attached_file'] ?? ''),
                'thumb_url' => (string) ($candidate_row['thumb_url'] ?? ''),
                'full_url' => (string) ($candidate_row['full_url'] ?? ''),
            );
        }

        $duplicate_group_review_rows_map = array();
        foreach ($candidate_rows as $candidate_row) {
            if (empty($candidate_row['duplicate_person_id'])) {
                continue;
            }

            $person_id = (string) ($candidate_row['person_id'] ?? '');
            if ($person_id === '' || isset($duplicate_group_review_rows_map[$person_id])) {
                continue;
            }

            $duplicate_group_candidates = isset($duplicate_groups[$person_id]) && is_array($duplicate_groups[$person_id])
                ? array_values($duplicate_groups[$person_id])
                : array();
            if (count($duplicate_group_candidates) < 2) {
                continue;
            }

            $duplicate_group_review_rows_map[$person_id] = array_merge($candidate_row, array(
                'duplicate_group_review' => 1,
                'duplicate_group_person_id' => $person_id,
                'duplicate_group_candidates' => $duplicate_group_candidates,
                'duplicate_group' => $duplicate_group_candidates,
                'duplicate_count' => count($duplicate_group_candidates),
                'state_label' => __('Duplicate group review', 'academy-awards-table'),
            ));
        }
        $duplicate_group_review_rows = array_values($duplicate_group_review_rows_map);

        $review_pairs = array();
        foreach ($candidate_rows as $candidate_row) {
            if (!empty($candidate_row['manual_review']) || !empty($candidate_row['duplicate_person_id'])) {
                continue;
            }

            $candidate_attachment_id = intval($candidate_row['attachment_id'] ?? 0);
            $candidate_person_id = strtolower(trim((string) ($candidate_row['person_id'] ?? '')));
            if ($candidate_attachment_id > 0 && $this->is_imdb_name_entity_id($candidate_person_id)) {
                $review_pairs[] = array(
                    'attachment_id' => $candidate_attachment_id,
                    'person_id' => $candidate_person_id,
                );
            }
        }
        $existing_review_records = $this->get_person_portrait_existing_review_records($review_pairs);
        $existing_review_states = $this->get_person_portrait_existing_review_states();
        $existing_review_counts = array();
        foreach (array_keys($existing_review_states) as $state_key) {
            $existing_review_counts[$state_key] = 0;
        }

        foreach ($candidate_rows as $index => $candidate_row) {
            if (!empty($candidate_row['manual_review']) || !empty($candidate_row['duplicate_person_id'])) {
                continue;
            }

            $candidate_attachment_id = intval($candidate_row['attachment_id'] ?? 0);
            $candidate_person_id = strtolower(trim((string) ($candidate_row['person_id'] ?? '')));
            $review_key = $this->get_person_portrait_existing_review_key($candidate_attachment_id, $candidate_person_id);
            $review = $existing_review_records[$review_key] ?? $this->get_default_person_portrait_existing_review_record($candidate_attachment_id, $candidate_person_id);
            $candidate_rows[$index]['existing_review'] = $review;
            $candidate_rows[$index]['existing_review_state'] = (string) ($review['review_state'] ?? 'needs_review');
            $candidate_rows[$index]['existing_review_state_label'] = (string) ($review['review_state_label'] ?? ($existing_review_states['needs_review'] ?? 'Needs Review'));
            $candidate_rows[$index]['existing_review_issue_type'] = (string) ($review['issue_type'] ?? 'none');
            $candidate_rows[$index]['existing_review_is_approved'] = !empty($review['is_approved']);

            $review_state_key = (string) ($review['review_state'] ?? 'needs_review');
            if (isset($existing_review_counts[$review_state_key])) {
                $existing_review_counts[$review_state_key]++;
            }
        }

        $ready_total = 0;
        $duplicate_total = 0;
        $manual_review_total = 0;
        $existing_hold_total = 0;
        $existing_approved_total = 0;
        $duplicate_person_ids = array();
        $filtered_rows = array();
        foreach ($candidate_rows as $candidate_row) {
            $is_manual = !empty($candidate_row['manual_review']);
            $is_duplicate = !empty($candidate_row['duplicate_person_id']);
            $is_existing_approved = !$is_manual && !$is_duplicate && !empty($candidate_row['existing_review_is_approved']);
            $is_existing_hold = !$is_manual && !$is_duplicate && !$is_existing_approved;
            $existing_review_state = !$is_manual && !$is_duplicate ? (string) ($candidate_row['existing_review_state'] ?? 'needs_review') : '';
            $is_existing_needs_review = $is_existing_hold && $existing_review_state === 'needs_review';
            $is_existing_needs_source = $is_existing_hold && $existing_review_state === 'needs_better_source';
            $is_existing_wrong_label = $is_existing_hold && in_array($existing_review_state, array('wrong_person_or_label', 'not_a_person'), true);
            if ($is_manual) {
                $manual_review_total++;
            } elseif ($is_duplicate) {
                $duplicate_total++;
                $person_id = (string) ($candidate_row['person_id'] ?? '');
                if ($person_id !== '') {
                    $duplicate_person_ids[$person_id] = true;
                    $candidate_row['duplicate_group'] = $duplicate_groups[$person_id] ?? array();
                }
            } else {
                $ready_total++;
                if ($is_existing_approved) {
                    $existing_approved_total++;
                } else {
                    $existing_hold_total++;
                }
            }

            if ($view === 'duplicate_groups') {
                continue;
            }
            if ($view === 'hold_review' && !$is_existing_hold) {
                continue;
            }
            if ($view === 'needs_review' && !$is_existing_needs_review) {
                continue;
            }
            if ($view === 'needs_source' && !$is_existing_needs_source) {
                continue;
            }
            if ($view === 'wrong_label' && !$is_existing_wrong_label) {
                continue;
            }
            if ($view === 'approved' && !$is_existing_approved) {
                continue;
            }
            if ($view === 'manual' && !$is_manual) {
                continue;
            }
            if ($view !== 'manual' && $is_manual) {
                continue;
            }
            if ($view === 'ready' && $is_duplicate) {
                continue;
            }
            if ($view === 'duplicates' && !$is_duplicate) {
                continue;
            }

            $filtered_rows[] = $candidate_row;
        }

        if ($view === 'duplicate_groups') {
            $paged_rows = array_slice($duplicate_group_review_rows, $offset, $limit);
            $filtered_rows = $duplicate_group_review_rows;
        } else {
            $paged_rows = array_slice($filtered_rows, $offset, $limit);
        }
        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : array();
        $summary['returned'] = count($paged_rows);
        $summary['adoption_total'] = $ready_total + $duplicate_total;
        $summary['adoption_review_total'] = count($filtered_rows);
        $summary['ready_adoption_total'] = $ready_total;
        $summary['existing_hold_review_total'] = $existing_hold_total;
        $summary['existing_approved_total'] = $existing_approved_total;
        $summary['existing_review_counts'] = $existing_review_counts;
        $summary['existing_needs_review_total'] = intval($existing_review_counts['needs_review'] ?? 0);
        $summary['existing_needs_source_total'] = intval($existing_review_counts['needs_better_source'] ?? 0);
        $summary['existing_wrong_label_total'] = intval($existing_review_counts['wrong_person_or_label'] ?? 0) + intval($existing_review_counts['not_a_person'] ?? 0);
        $summary['duplicate_adoption_total'] = $duplicate_total;
        $summary['duplicate_person_total'] = count($duplicate_person_ids);
        $summary['duplicate_group_review_total'] = count($duplicate_group_review_rows);
        $summary['manual_review_total'] = $manual_review_total;
        $summary['adoption_view'] = $view;
        $summary['adoption_limit'] = $limit;
        $summary['adoption_offset'] = $offset;

        return array(
            'rows' => $paged_rows,
            'summary' => $summary,
        );
    }

    private function adopt_existing_person_portrait_attachment($attachment_id, $person_id, $note = '', $options = array()) {
        $attachment_id = absint($attachment_id);
        $person_id = strtolower(trim((string) $person_id));
        $note = sanitize_textarea_field((string) $note);
        $options = is_array($options) ? $options : array();
        $allow_duplicate = !empty($options['allow_duplicate']);
        $require_confirmation = !empty($options['require_confirmation']);
        $confirm_person_id = isset($options['confirm_person_id']) ? strtolower(trim(sanitize_text_field((string) $options['confirm_person_id']))) : '';

        if ($attachment_id <= 0) {
            return new WP_Error('aat_existing_portrait_missing_attachment', __('A valid attachment is required.', 'academy-awards-table'));
        }
        if (!$this->is_imdb_name_entity_id($person_id)) {
            return new WP_Error('aat_existing_portrait_invalid_person', __('A valid IMDb person ID is required.', 'academy-awards-table'));
        }
        $label = trim((string) $this->get_entity_display_name('name', $person_id));
        if ($label === '') {
            return new WP_Error('aat_existing_portrait_missing_route', __('That IMDb person ID does not currently resolve to an Oscars person route.', 'academy-awards-table'));
        }
        if (get_post_type($attachment_id) !== 'attachment' || !wp_attachment_is_image($attachment_id)) {
            return new WP_Error('aat_existing_portrait_not_image', __('The selected attachment is not an image attachment.', 'academy-awards-table'));
        }

        $entity_set = $this->get_profile_image_coverage_id_set('entities');
        $entity_ids = is_array($entity_set['ids'] ?? null) ? $entity_set['ids'] : array();
        $label_index = $this->get_profile_image_person_label_index();
        $attachment_rows = $this->get_profile_image_existing_media_attachment_rows(array($attachment_id));
        if (empty($attachment_rows)) {
            return new WP_Error('aat_existing_portrait_attachment_missing', __('The selected attachment could not be inspected.', 'academy-awards-table'));
        }

        $row = $this->build_profile_image_existing_media_audit_row($attachment_rows[0], $entity_ids, $label_index);
        $audit = $this->build_profile_image_existing_media_audit(array(
            'folder' => 'PEOPLE',
            'sample' => 0,
            'all_media' => 0,
            'output_csv' => '',
        ));
        if (is_wp_error($audit)) {
            return new WP_Error('aat_existing_portrait_audit_unavailable', $audit->get_error_message());
        }

        $matched_people_row = false;
        $audit_rows = is_array($audit['rows'] ?? null) ? $audit['rows'] : array();
        if (!empty($audit_rows)) {
            foreach ($audit_rows as $audit_row) {
                if (intval($audit_row['attachment_id'] ?? 0) !== $attachment_id) {
                    continue;
                }

                $row = array_merge($row, $audit_row);
                $matched_people_row = true;
                break;
            }
        }
        if (!$matched_people_row) {
            return new WP_Error('aat_existing_portrait_not_people_media', __('The selected attachment is not currently present in the PEOPLE folder audit.', 'academy-awards-table'));
        }

        if ((string) ($row['candidate_person_id'] ?? '') !== $person_id || empty($row['adoption_candidate'])) {
            return new WP_Error('aat_existing_portrait_not_candidate', __('The selected attachment is not a reusable candidate for that person.', 'academy-awards-table'));
        }
        if (!empty($row['duplicate_person_id'])) {
            if (!$allow_duplicate) {
                return new WP_Error('aat_existing_portrait_duplicate_candidate', __('Duplicate candidate rows require manual review before adoption.', 'academy-awards-table'));
            }
            if ($confirm_person_id !== $person_id) {
                return new WP_Error('aat_existing_portrait_duplicate_confirmation', __('Type the exact IMDb person ID to resolve a duplicate portrait group.', 'academy-awards-table'));
            }

            $duplicate_attachment_ids = array();
            foreach ($audit_rows as $audit_row) {
                $audit_person_id = strtolower(trim((string) ($audit_row['candidate_person_id'] ?? '')));
                if ($audit_person_id !== $person_id) {
                    continue;
                }
                if ((string) ($audit_row['state'] ?? '') !== 'reusable_nm_filename' || empty($audit_row['adoption_candidate'])) {
                    continue;
                }

                $duplicate_attachment_id = intval($audit_row['attachment_id'] ?? 0);
                if ($duplicate_attachment_id > 0) {
                    $duplicate_attachment_ids[$duplicate_attachment_id] = true;
                }
            }

            if (count($duplicate_attachment_ids) < 2 || empty($duplicate_attachment_ids[$attachment_id])) {
                return new WP_Error('aat_existing_portrait_duplicate_group_mismatch', __('The selected attachment is not part of the current duplicate portrait group.', 'academy-awards-table'));
            }

            $duplicate_note = sprintf(
                'Duplicate resolver confirmed %1$s; selected attachment #%2$d from %3$d PEOPLE candidates.',
                $person_id,
                $attachment_id,
                count($duplicate_attachment_ids)
            );
            $note = trim($note) !== '' ? trim($note) . "\n" . $duplicate_note : $duplicate_note;
        }
        if (empty($row['duplicate_person_id'])) {
            $review_records = $this->get_person_portrait_existing_review_records(array(array(
                'attachment_id' => $attachment_id,
                'person_id' => $person_id,
            )));
            $review_key = $this->get_person_portrait_existing_review_key($attachment_id, $person_id);
            $review = $review_records[$review_key] ?? $this->get_default_person_portrait_existing_review_record($attachment_id, $person_id);
            if (empty($review['is_approved'])) {
                return new WP_Error('aat_existing_portrait_review_required', __('Approve this existing PEOPLE portrait review before adoption.', 'academy-awards-table'));
            }
        }
        if (empty($row['duplicate_person_id']) && $require_confirmation && $confirm_person_id !== $person_id) {
            return new WP_Error('aat_existing_portrait_confirmation', __('Type the exact IMDb person ID to adopt this existing portrait.', 'academy-awards-table'));
        }

        update_post_meta($attachment_id, '_aat_person_imdb_id', $person_id);
        update_post_meta($attachment_id, '_aat_person_portrait_source', 'existing-media-adoption');
        update_post_meta($attachment_id, '_aat_person_portrait_verified', '1');
        update_post_meta($attachment_id, '_aat_person_portrait_adopted_at', current_time('mysql', true));
        update_post_meta($attachment_id, '_aat_person_portrait_adopted_by', get_current_user_id());
        update_post_meta($attachment_id, '_aat_person_portrait_adoption_note', $note);
        delete_transient('aat_person_profile_attachment_v2_' . $person_id);

        return array(
            'attachment_id' => $attachment_id,
            'person_id' => $person_id,
            'label' => $label,
            'source' => 'existing-media-adoption',
        );
    }

    private function get_person_portrait_import_queue_rows($args = array()) {
        global $wpdb;

        $allowed_states = array('all', 'candidate_external', 'ready', 'needs_attention');
        $state_filter = isset($args['state']) ? sanitize_key((string) $args['state']) : 'candidate_external';
        if (!in_array($state_filter, $allowed_states, true)) {
            $state_filter = 'candidate_external';
        }

        $limit = isset($args['limit']) ? intval($args['limit']) : 50;
        $limit = max(1, min(200, $limit));
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;
        $offset = max(0, $offset);
        $refresh_tmdb = !empty($args['refresh_tmdb']);
        $ids_raw = isset($args['ids_raw']) ? (string) $args['ids_raw'] : '';
        $selected_ids = $this->parse_person_ids_from_text($ids_raw);

        $roster = array();
        $source = 'wordpress';
        $total_roster = 0;

        if (!empty($selected_ids)) {
            $source = 'manual';
            $total_roster = count($selected_ids);
            foreach ($selected_ids as $person_id) {
                $context = $this->get_person_context_for_imdb_id($person_id);
                $label = trim((string) ($context['name'] ?? ''));
                if ($label === '') {
                    $label = trim((string) $this->get_entity_display_name('name', $person_id));
                }
                $roster[] = array(
                    'person_id' => $person_id,
                    'label' => $label,
                );
            }
        } else {
            $entities_table = $this->get_entities_table_name();
            $total_roster = intval($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$entities_table}
                     WHERE entity_type = %s
                       AND entity_id REGEXP %s",
                    'name',
                    '^nm[0-9]{7,9}$'
                )
            ));
            $roster = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT entity_id AS person_id, label
                     FROM {$entities_table}
                     WHERE entity_type = %s
                       AND entity_id REGEXP %s
                     ORDER BY label ASC, entity_id ASC
                     LIMIT %d OFFSET %d",
                    'name',
                    '^nm[0-9]{7,9}$',
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
            if (!is_array($roster)) {
                $roster = array();
            }
        }

        $rows = array();
        $force_tmdb_lookup = $refresh_tmdb || !empty($selected_ids);
        foreach ($roster as $item) {
            $row = $this->build_person_portrait_import_queue_row(
                (string) ($item['person_id'] ?? ''),
                (string) ($item['label'] ?? ''),
                $force_tmdb_lookup
            );
            if ($state_filter !== 'all' && $row['state'] !== $state_filter) {
                continue;
            }
            $rows[] = $row;
        }

        return array(
            'summary' => array(
                'source' => $source,
                'state' => $state_filter,
                'limit' => $limit,
                'offset' => $offset,
                'refresh_tmdb' => $refresh_tmdb,
                'total_roster' => $total_roster,
                'scanned' => count($roster),
                'returned' => count($rows),
            ),
            'rows' => $rows,
        );
    }

    private function build_person_portrait_import_queue_row($person_id, $label = '', $refresh_tmdb = false) {
        $person_id = strtolower(trim((string) $person_id));
        $label = trim((string) $label);
        $context = array();
        if ($this->is_imdb_name_entity_id($person_id)) {
            $context = $this->get_person_context_for_imdb_id($person_id);
        }
        if ($label === '') {
            $label = trim((string) ($context['name'] ?? ''));
        }
        if ($label === '') {
            $label = strtoupper($person_id);
        }

        $row = array(
            'person_id' => $person_id,
            'label' => $label,
            'state' => 'needs_attention',
            'state_label' => __('Needs attention', 'academy-awards-table'),
            'visual_source' => 'none',
            'local_attachment_id' => 0,
            'thumb_url' => '',
            'tmdb_person_id' => 0,
            'tmdb_profile_path' => '',
            'tmdb_profile_url' => '',
            'tmdb_has_context_backdrop' => false,
            'nomination_count' => 0,
            'profile_url' => '',
            'notes' => array(),
        );

        if (!$this->is_imdb_name_entity_id($person_id)) {
            $row['notes'][] = __('Invalid IMDb person ID.', 'academy-awards-table');
            return $row;
        }

        $entity_rows = $this->get_entity_rows('name', $person_id);
        $row['nomination_count'] = is_array($entity_rows) ? count($entity_rows) : 0;
        $row['profile_url'] = $this->build_entity_url_from_id($person_id);

        $resolved = $this->resolve_profile_attachment_for_person($person_id, $label);
        $attachment_id = intval($resolved['attachment_id'] ?? 0);
        if ($attachment_id > 0) {
            $row['state'] = 'ready';
            $row['state_label'] = __('Ready', 'academy-awards-table');
            $row['visual_source'] = 'local-media-library';
            $row['local_attachment_id'] = $attachment_id;
            $thumb_url = wp_get_attachment_image_url($attachment_id, array(80, 80));
            $row['thumb_url'] = is_string($thumb_url) ? $thumb_url : '';
            $row['notes'][] = __('Local verified portrait is already connected.', 'academy-awards-table');
            return $row;
        }

        $tmdb = get_transient('aat_tmdb_person_v2_' . $person_id);
        if ($refresh_tmdb) {
            $tmdb = $this->get_tmdb_person_data_for_imdb_id($person_id);
        }

        if (is_array($tmdb) && !empty($tmdb['profile_path']) && !empty($tmdb['profile_full'])) {
            $row['state'] = 'candidate_external';
            $row['state_label'] = __('Candidate external', 'academy-awards-table');
            $row['visual_source'] = 'tmdb-person-profile';
            $row['tmdb_person_id'] = intval($tmdb['id'] ?? 0);
            $row['tmdb_profile_path'] = (string) $tmdb['profile_path'];
            $row['tmdb_profile_url'] = (string) $tmdb['profile_full'];
            $row['thumb_url'] = (string) $tmdb['profile_full'];
            $row['notes'][] = __('TMDb profile image candidate exists; review before import.', 'academy-awards-table');
            return $row;
        }

        if (is_array($tmdb) && !empty($tmdb['backdrop_full'])) {
            $row['tmdb_has_context_backdrop'] = true;
            $row['notes'][] = __('TMDb only has title/backdrop context; not acceptable as a portrait.', 'academy-awards-table');
        } else {
            $row['notes'][] = __('No local portrait or TMDb profile image candidate.', 'academy-awards-table');
        }

        return $row;
    }

    private function import_tmdb_person_profile_portrait($person_id) {
        $person_id = strtolower(trim((string) $person_id));
        if (!$this->is_imdb_name_entity_id($person_id)) {
            return new WP_Error('aat_invalid_person_id', __('A valid IMDb person ID is required.', 'academy-awards-table'));
        }

        $context = $this->get_person_context_for_imdb_id($person_id);
        $label = trim((string) ($context['name'] ?? ''));
        if ($label === '') {
            $label = trim((string) $this->get_entity_display_name('name', $person_id));
        }
        if ($label === '') {
            $label = strtoupper($person_id);
        }

        $tmdb = $this->get_tmdb_person_data_for_imdb_id($person_id);
        if (!is_array($tmdb) || empty($tmdb['profile_path']) || empty($tmdb['profile_full'])) {
            return new WP_Error('aat_no_tmdb_profile', __('TMDb does not have a verified person profile image for this nominee.', 'academy-awards-table'));
        }

        $profile_path = (string) $tmdb['profile_path'];
        if (strpos($profile_path, '/') !== 0) {
            return new WP_Error('aat_invalid_tmdb_profile_path', __('TMDb returned an invalid profile image path.', 'academy-awards-table'));
        }

        $existing_attachment_id = $this->find_existing_person_portrait_attachment($person_id, $profile_path);
        if ($existing_attachment_id > 0) {
            return array(
                'status' => 'existing',
                'attachment_id' => $existing_attachment_id,
                'person_id' => $person_id,
            );
        }

        $profile_full = 'https://image.tmdb.org/t/p/h632' . $profile_path;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp_file = download_url($profile_full, 30);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        $extension = pathinfo(parse_url($profile_path, PHP_URL_PATH), PATHINFO_EXTENSION);
        $extension = $extension !== '' ? strtolower($extension) : 'jpg';
        if (!in_array($extension, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            $extension = 'jpg';
        }

        $file_array = array(
            'name' => sanitize_file_name($person_id . '-profile.' . $extension),
            'tmp_name' => $tmp_file,
        );

        $attachment_id = media_handle_sideload($file_array, 0, sprintf(__('Portrait %s', 'academy-awards-table'), $person_id));
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return $attachment_id;
        }

        $attachment_id = intval($attachment_id);
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_title' => $label . ' portrait',
            'post_excerpt' => sprintf(__('Verified TMDb person profile image for %s.', 'academy-awards-table'), $label),
        ));

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $label . ' portrait');
        update_post_meta($attachment_id, '_aat_person_imdb_id', $person_id);
        update_post_meta($attachment_id, '_aat_person_portrait_source', 'tmdb-person-profile');
        update_post_meta($attachment_id, '_aat_tmdb_profile_path', $profile_path);
        update_post_meta($attachment_id, '_aat_tmdb_person_id', intval($tmdb['id'] ?? 0));
        update_post_meta($attachment_id, '_aat_person_portrait_verified', '1');

        delete_transient('aat_person_profile_attachment_v2_' . $person_id);

        return array(
            'status' => 'imported',
            'attachment_id' => $attachment_id,
            'person_id' => $person_id,
            'profile_path' => $profile_path,
        );
    }

    private function find_existing_person_portrait_attachment($person_id, $profile_path = '') {
        global $wpdb;

        $person_id = strtolower(trim((string) $person_id));
        if (!$this->is_imdb_name_entity_id($person_id)) {
            return 0;
        }

        $profile_path = trim((string) $profile_path);
        $source_values = $profile_path !== '' ? array('tmdb-person-profile') : array('existing-media-adoption', 'manual-batch-upload', 'tmdb-person-profile');
        $source_placeholders = implode(', ', array_fill(0, count($source_values), '%s'));
        $meta_conditions = array(
            "pm_person.meta_key = '_aat_person_imdb_id'",
            "pm_person.meta_value = %s",
            "pm_source.meta_key = '_aat_person_portrait_source'",
            "pm_source.meta_value IN ({$source_placeholders})",
        );
        $params = array_merge(array($person_id), $source_values);

        $profile_join = '';
        if ($profile_path !== '') {
            $profile_join = "INNER JOIN {$wpdb->postmeta} pm_profile ON pm_profile.post_id = p.ID";
            $meta_conditions[] = "pm_profile.meta_key = '_aat_tmdb_profile_path'";
            $meta_conditions[] = "pm_profile.meta_value = %s";
            $params[] = $profile_path;
        }

        $where = implode(' AND ', $meta_conditions);
        $sql = "SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_person ON pm_person.post_id = p.ID
                INNER JOIN {$wpdb->postmeta} pm_source ON pm_source.post_id = p.ID
                {$profile_join}
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type LIKE 'image/%'
                  AND {$where}
                ORDER BY p.post_date DESC, p.ID DESC
                LIMIT 1";

        return intval($wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }


    public function get_poster_attachment_id_for_title($tt) {
        $tt = strtolower(trim((string) $tt));
        if (!preg_match('/^tt\d{7,8}$/', $tt)) return 0;

        // 1) Review featured image
        $review_ids = $this->get_review_ids_for_title_id($tt, 1);
        if (!empty($review_ids)) {
            $rid = (int) $review_ids[0];
            $thumb_id = get_post_thumbnail_id($rid);
            if ($thumb_id) return (int) $thumb_id;
        }

        // 2) Poster mapping table
        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';
        $aid = $wpdb->get_var($wpdb->prepare("SELECT attachment_id FROM $poster_table WHERE imdb_id = %s", $tt));
        return intval($aid);
    }

    public function get_poster_img_html_for_title($tt, $size = 'medium', $attrs = array()) {
        $aid = $this->get_poster_attachment_id_for_title($tt);
        if (!$aid) return '';
        $display_title = trim((string) $this->lookup_title_label($tt));
        $defaults = array(
            'loading' => 'lazy',
            'decoding' => 'async',
            'class' => 'aat-poster-img',
        );
        if ($display_title !== '') {
            $defaults['alt'] = $display_title . ' poster';
        }
        $attrs = is_array($attrs) ? array_merge($defaults, $attrs) : $defaults;
        $html = wp_get_attachment_image($aid, $size, false, $attrs);
        if ($display_title !== '' && is_string($html) && $html !== '') {
            $safe_title = esc_attr($display_title . ' poster');
            $html = preg_replace('/\salt="[^"]*"/i', ' alt="' . $safe_title . '"', $html, 1);
            $html = preg_replace('/\sdata-image-title="[^"]*"/i', ' data-image-title="' . $safe_title . '"', $html, 1);
        }
        return $html;
    }

    private function get_omdb_audit_local_poster_summary($tt) {
        $tt = strtolower(trim((string) $tt));
        if (!$this->is_title_entity_id($tt)) {
            return array();
        }

        $attachment_id = $this->get_poster_attachment_id_for_title($tt);
        $mapped_attachment_id = 0;
        $source = '';
        $updated_at = '';

        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';
        $mapped = $wpdb->get_row($wpdb->prepare("SELECT attachment_id, source, updated_at FROM $poster_table WHERE imdb_id = %s", $tt), ARRAY_A);
        if (is_array($mapped)) {
            $mapped_attachment_id = (int) ($mapped['attachment_id'] ?? 0);
            $source = (string) ($mapped['source'] ?? '');
            $updated_at = (string) ($mapped['updated_at'] ?? '');
        }

        $thumb_url = $attachment_id ? wp_get_attachment_image_url($attachment_id, array(60, 90)) : '';

        return array(
            'imdb_id' => $tt,
            'attachment_id' => $attachment_id,
            'mapped_attachment_id' => $mapped_attachment_id,
            'source' => $source,
            'updated_at' => $updated_at,
            'thumb_url' => $thumb_url ? (string) $thumb_url : '',
            'has_local_poster' => $attachment_id > 0,
            'has_mapping' => $mapped_attachment_id > 0,
        );
    }

    private function import_omdb_poster_from_request($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('aat_omdb_poster_forbidden', __('You do not have permission to import OMDb posters.', 'academy-awards-table'));
        }

        if (empty($request['aat_omdb_poster_import_confirm'])) {
            return new WP_Error('aat_omdb_poster_unconfirmed', __('Confirm the one-row poster import before applying it.', 'academy-awards-table'));
        }

        $imdb_id = isset($request['aat_omdb_poster_import_imdb_id']) ? strtolower(trim((string) wp_unslash($request['aat_omdb_poster_import_imdb_id']))) : '';
        if (!$this->is_title_entity_id($imdb_id)) {
            return new WP_Error('aat_omdb_poster_bad_id', __('Invalid IMDb title ID for poster import.', 'academy-awards-table'));
        }

        $omdb = $this->get_omdb_data_for_imdb_id($imdb_id, true);
        if (!empty($omdb['error'])) {
            return new WP_Error('aat_omdb_poster_source_error', (string) $omdb['error']);
        }

        $poster = trim((string) ($omdb['poster'] ?? ''));
        if ($poster === '' || strtoupper($poster) === 'N/A') {
            return new WP_Error('aat_omdb_poster_missing', __('OMDb does not expose a poster for this title.', 'academy-awards-table'));
        }

        $poster_api_url = $this->get_omdb_poster_api_url($imdb_id);
        if ($poster_api_url === '') {
            return new WP_Error('aat_omdb_poster_key_missing', __('The OMDb patron poster API URL is not available. Confirm the OMDb key is configured.', 'academy-awards-table'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($poster_api_url, 30);
        if (is_wp_error($tmp)) {
            return new WP_Error('aat_omdb_poster_download_failed', $tmp->get_error_message());
        }

        $safe_title = sanitize_file_name((string) ($omdb['title'] ?? $imdb_id));
        if ($safe_title === '') {
            $safe_title = $imdb_id;
        }

        $file = array(
            'name' => $imdb_id . '-' . $safe_title . '-omdb-poster.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp),
        );

        $attachment_id = media_handle_sideload(
            $file,
            0,
            sprintf(
                /* translators: 1: title, 2: IMDb title ID */
                __('%1$s poster (%2$s)', 'academy-awards-table'),
                (string) ($omdb['title'] ?? $imdb_id),
                $imdb_id
            )
        );

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return new WP_Error('aat_omdb_poster_sideload_failed', $attachment_id->get_error_message());
        }

        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', trim((string) ($omdb['title'] ?? $imdb_id)) . ' poster');
        update_post_meta((int) $attachment_id, '_aat_omdb_poster_imdb_id', $imdb_id);
        update_post_meta((int) $attachment_id, '_aat_omdb_poster_source', 'omdb-poster-api');

        $this->set_poster_attachment_id($imdb_id, (int) $attachment_id, 'omdb-poster-api');
        $this->save_omdb_poster_review_record(
            $imdb_id,
            'accepted',
            sprintf(
                /* translators: 1: attachment ID */
                __('Imported and accepted OMDb poster as Media Library attachment %1$d.', 'academy-awards-table'),
                (int) $attachment_id
            )
        );
        $this->clear_awards_runtime_caches(array($imdb_id));

        return array(
            'imdb_id' => $imdb_id,
            'attachment_id' => (int) $attachment_id,
        );
    }

    /**
     * Save/update poster mapping.
     */
    public function set_poster_attachment_id($tt, $attachment_id, $source = '') {
        $tt = strtolower(trim((string) $tt));
        $attachment_id = intval($attachment_id);
        $source = sanitize_text_field($source);

        if (!preg_match('/^tt\d{7,8}$/', $tt)) return false;

        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';

        $now = current_time('mysql');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT imdb_id FROM $poster_table WHERE imdb_id = %s", $tt));

        if ($existing) {
            $wpdb->update(
                $poster_table,
                array(
                    'attachment_id' => $attachment_id,
                    'source' => $source,
                    'updated_at' => $now,
                ),
                array('imdb_id' => $tt),
                array('%d','%s','%s'),
                array('%s')
            );
        } else {
            $wpdb->insert(
                $poster_table,
                array(
                    'imdb_id' => $tt,
                    'attachment_id' => $attachment_id,
                    'source' => $source,
                    'updated_at' => $now,
                ),
                array('%s','%d','%s','%s')
            );
        }

        return true;
    }

    /**
     * When a review is saved, automatically sync its featured image into the Poster Library.
     */
    public function maybe_sync_poster_from_review($post_id, $post) {
        if (wp_is_post_revision($post_id)) return;
        if (!($post instanceof WP_Post)) return;

        $review_post_type = $this->get_review_post_type();
        if ($post->post_type !== $review_post_type) return;

        if (!current_user_can('edit_post', $post_id)) return;

        $meta_key = $this->get_review_imdb_meta_key();
        $tt = strtolower(trim((string) get_post_meta($post_id, $meta_key, true)));
        if (!preg_match('/^tt\d{7,8}$/', $tt)) return;

        $thumb_id = get_post_thumbnail_id($post_id);
        if (!$thumb_id) return;

        $this->set_poster_attachment_id($tt, (int) $thumb_id, 'review-featured-image');
    }

    /**
     * Bulk-sync posters from existing reviews.
     * Returns array( synced => int, skipped => int )
     */
    public function sync_posters_from_reviews() {
        $meta_key = $this->get_review_imdb_meta_key();
        $post_type = $this->get_review_post_type();

        $q = new WP_Query(array(
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => 5000,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => $meta_key,
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        $synced = 0;
        $skipped = 0;

        if (!empty($q->posts) && is_array($q->posts)) {
            foreach ($q->posts as $pid) {
                $pid = (int) $pid;
                $tt = strtolower(trim((string) get_post_meta($pid, $meta_key, true)));
                if (!preg_match('/^tt\d{7,8}$/', $tt)) { $skipped++; continue; }
                $thumb_id = get_post_thumbnail_id($pid);
                if (!$thumb_id) { $skipped++; continue; }

                $ok = $this->set_poster_attachment_id($tt, (int) $thumb_id, 'bulk-review-sync');
                if ($ok) $synced++; else $skipped++;
            }
        }

        return array('synced' => $synced, 'skipped' => $skipped);
    }

    /**
     * Download a remote poster URL and store it in the Media Library.
     */
    private function sideload_api_poster_image($imdb_id, $image_url, $title, $source) {
        $imdb_id = strtolower(trim((string) $imdb_id));
        $image_url = trim((string) $image_url);
        $title = trim((string) $title);
        $source = trim((string) $source);

        if (!$this->is_title_entity_id($imdb_id)) {
            return new WP_Error('aat_poster_import_bad_id', __('Invalid IMDb title ID for poster import.', 'academy-awards-table'));
        }

        if ($image_url === '') {
            return new WP_Error('aat_poster_import_missing_url', __('Poster image URL is missing.', 'academy-awards-table'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $safe_title = sanitize_file_name($title !== '' ? $title : $imdb_id);
        if ($safe_title === '') {
            $safe_title = $imdb_id;
        }

        $path = parse_url($image_url, PHP_URL_PATH);
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5) {
            $ext = 'jpg';
        }

        $file = array(
            'name' => $imdb_id . '-' . $safe_title . '-poster.' . $ext,
            'type' => 'image/' . ($ext === 'png' ? 'png' : ($ext === 'webp' ? 'webp' : 'jpeg')),
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => @filesize($tmp),
        );

        $attachment_id = media_handle_sideload(
            $file,
            0,
            sprintf(
                /* translators: 1: title, 2: IMDb title ID */
                __('%1$s poster (%2$s)', 'academy-awards-table'),
                $title !== '' ? $title : $imdb_id,
                $imdb_id
            )
        );

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        $alt = trim(($title !== '' ? $title : $imdb_id) . ' poster');
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $alt);
        update_post_meta((int) $attachment_id, '_aat_omdb_poster_imdb_id', $imdb_id);
        update_post_meta((int) $attachment_id, '_aat_omdb_poster_source', $source);

        $this->set_poster_attachment_id($imdb_id, (int) $attachment_id, $source);
        $this->clear_awards_runtime_caches(array($imdb_id));

        return array(
            'imdb_id' => $imdb_id,
            'attachment_id' => (int) $attachment_id,
            'source' => $source,
        );
    }

    /**
     * Import one title poster using OMDb first, then TMDB fallback.
     */
    private function import_title_poster_from_apis($imdb_id, $force_refresh = false) {
        $imdb_id = strtolower(trim((string) $imdb_id));
        if (!$this->is_title_entity_id($imdb_id)) {
            return new WP_Error('aat_poster_sync_bad_id', __('Invalid IMDb title ID.', 'academy-awards-table'));
        }

        $existing_attachment = $this->get_poster_attachment_id_for_title($imdb_id);
        if ($existing_attachment > 0) {
            // Write the mapping through to the poster table when coverage came
            // from a review featured image, so this title stops occupying a
            // candidate slot on every future sync run.
            global $wpdb;
            $poster_table = $wpdb->prefix . 'aat_posters';
            $mapped = $wpdb->get_var($wpdb->prepare("SELECT imdb_id FROM $poster_table WHERE imdb_id = %s", $imdb_id));
            if (!$mapped) {
                $this->set_poster_attachment_id($imdb_id, $existing_attachment, 'review-featured-auto');
            }
            return array('status' => 'exists');
        }

        $title = trim((string) $this->lookup_title_label($imdb_id));

        // 1) OMDb patron poster endpoint (best source when available).
        $omdb = $this->get_omdb_data_for_imdb_id($imdb_id, $force_refresh);
        if (empty($omdb['error'])) {
            $poster = trim((string) ($omdb['poster'] ?? ''));
            $poster_api_url = $this->get_omdb_poster_api_url($imdb_id);
            if ($poster !== '' && strtoupper($poster) !== 'N/A' && $poster_api_url !== '') {
                $imported = $this->sideload_api_poster_image(
                    $imdb_id,
                    $poster_api_url,
                    $title !== '' ? $title : trim((string) ($omdb['title'] ?? '')),
                    'omdb-poster-api-auto'
                );
                if (!is_wp_error($imported)) {
                    return array('status' => 'imported', 'source' => 'omdb');
                }
            }
        }

        // 2) TMDB fallback.
        $tmdb = $this->get_tmdb_data_for_imdb_id($imdb_id);
        $tmdb_poster = trim((string) ($tmdb['poster_full'] ?? ''));
        if ($tmdb_poster !== '') {
            $imported = $this->sideload_api_poster_image(
                $imdb_id,
                $tmdb_poster,
                $title !== '' ? $title : trim((string) ($tmdb['title'] ?? '')),
                'tmdb-poster-api-auto'
            );
            if (!is_wp_error($imported)) {
                return array('status' => 'imported', 'source' => 'tmdb');
            }
        }

        // Both APIs came up empty — park this title for a week so the sync
        // batch keeps advancing instead of retrying the same misses forever.
        $this->record_poster_api_sync_failure($imdb_id);

        return array('status' => 'missing');
    }

    /**
     * Candidate title IDs for API poster sync.
     */
    private function get_candidate_title_ids_for_api_poster_sync($limit = 100) {
        global $wpdb;

        $limit = max(1, min(500, intval($limit)));
        $this->ensure_projection_data_available();

        // Titles that already have a mapped poster are excluded in SQL so every
        // run advances into genuinely-missing territory instead of re-reading
        // the same alphabetical batch forever. Titles that recently failed both
        // APIs are held back for a week so they cannot clog the batch either.
        $recent_failures = $this->get_poster_api_sync_recent_failures();
        $fetch_limit = min(500, $limit + count($recent_failures));

        $entities_table = $this->get_entities_table_name();
        $poster_table = $wpdb->prefix . 'aat_posters';
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT entity_id FROM $entities_table
                 WHERE entity_type = %s AND entity_id REGEXP %s
                   AND entity_id NOT IN (SELECT imdb_id FROM $poster_table)
                 ORDER BY sort_label ASC LIMIT %d",
                'title',
                '^tt[0-9]{7,9}$',
                $fetch_limit
            )
        );

        $out = array();
        foreach ((array) $rows as $row_id) {
            $row_id = strtolower(trim((string) $row_id));
            if (!$this->is_title_entity_id($row_id) || isset($recent_failures[$row_id])) {
                continue;
            }
            $out[$row_id] = true;
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * Titles that failed both poster APIs recently (tt => unix timestamp).
     * Entries expire after a week so new artwork upstream gets retried.
     */
    private function get_poster_api_sync_recent_failures() {
        $failures = get_option('aat_poster_api_sync_recent_failures', array());
        if (!is_array($failures)) {
            return array();
        }

        $cutoff = time() - WEEK_IN_SECONDS;
        $fresh = array();
        foreach ($failures as $tt => $ts) {
            $tt = strtolower(trim((string) $tt));
            if (intval($ts) >= $cutoff && $this->is_title_entity_id($tt)) {
                $fresh[$tt] = intval($ts);
            }
        }

        if (count($fresh) !== count($failures)) {
            update_option('aat_poster_api_sync_recent_failures', $fresh, false);
        }

        return $fresh;
    }

    private function record_poster_api_sync_failure($tt) {
        $tt = strtolower(trim((string) $tt));
        if (!$this->is_title_entity_id($tt)) {
            return;
        }

        $failures = $this->get_poster_api_sync_recent_failures();
        $failures[$tt] = time();
        // Keep the newest entries if the list somehow balloons.
        if (count($failures) > 3000) {
            arsort($failures);
            $failures = array_slice($failures, 0, 3000, true);
        }
        update_option('aat_poster_api_sync_recent_failures', $failures, false);
    }

    /**
     * Count ledger titles that still have no mapped local poster.
     */
    public function count_titles_without_mapped_poster() {
        global $wpdb;

        $this->ensure_projection_data_available();
        $entities_table = $this->get_entities_table_name();
        $poster_table = $wpdb->prefix . 'aat_posters';

        return intval($wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $entities_table
                 WHERE entity_type = %s AND entity_id REGEXP %s
                   AND entity_id NOT IN (SELECT imdb_id FROM $poster_table)",
                'title',
                '^tt[0-9]{7,9}$'
            )
        ));
    }

    /**
     * Bulk-sync missing posters from OMDb/TMDB API sources.
     */
    public function sync_posters_from_apis($limit = 100, $force_refresh = false) {
        $title_ids = $this->get_candidate_title_ids_for_api_poster_sync($limit);
        $synced = 0;
        $synced_omdb = 0;
        $synced_tmdb = 0;
        $skipped_existing = 0;
        $missing = 0;

        foreach ($title_ids as $title_id) {
            $result = $this->import_title_poster_from_apis($title_id, $force_refresh);
            if (is_wp_error($result)) {
                $missing++;
                continue;
            }

            $status = isset($result['status']) ? (string) $result['status'] : '';
            if ($status === 'exists') {
                $skipped_existing++;
                continue;
            }

            if ($status === 'imported') {
                $synced++;
                if (($result['source'] ?? '') === 'omdb') {
                    $synced_omdb++;
                } elseif (($result['source'] ?? '') === 'tmdb') {
                    $synced_tmdb++;
                }
                continue;
            }

            $missing++;
        }

        return array(
            'processed' => count($title_ids),
            'synced' => $synced,
            'synced_omdb' => $synced_omdb,
            'synced_tmdb' => $synced_tmdb,
            'skipped_existing' => $skipped_existing,
            'missing' => $missing,
        );
    }

    /**
     * Verify admin AJAX requests.
     */
    private function verify_admin_ajax_request() {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aat_admin_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
    }

    /**
     * Admin AJAX: entity search (titles + people + companies).
     * Used by Tracker V2 and Poster Library admin screens.
     */
    public function ajax_tracker_search_entities() {
        $this->verify_admin_ajax_request();

        global $wpdb;
        $awards_table = $wpdb->prefix . 'academy_awards';

        $q = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
        $q = trim($q);

        if (strlen($q) < 2) {
            wp_send_json_success(array('results' => array()));
        }

        $results = array();
        $seen = array();

        // If the user pasted a raw IMDb ID, return it directly.
        if (preg_match('/^(tt\d{7,8}|nm\d{7,8}|co\d{7,8})$/i', $q, $m)) {
            $id = strtolower($m[1]);
            $type = (strpos($id, 'tt') === 0) ? 'title' : ((strpos($id, 'nm') === 0) ? 'name' : 'company');
            $label = $this->get_entity_display_name($type, $id);
            if ($label === '') $label = strtoupper($id);
            $results[] = array('id' => $id, 'type' => $type, 'label' => $label);
            wp_send_json_success(array('results' => $results));
        }

        $like = '%' . $wpdb->esc_like($q) . '%';

        // Film titles
        $film_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT film, film_id FROM $awards_table WHERE film != '' AND film LIKE %s LIMIT 80", $like),
            ARRAY_A
        );
        if (is_array($film_rows)) {
            foreach ($film_rows as $r) {
                $films = array_filter(array_map('trim', explode('|', (string) ($r['film'] ?? ''))), 'strlen');
                $ids = array_filter(array_map('trim', explode('|', (string) ($r['film_id'] ?? ''))), 'strlen');
                if (empty($films) || empty($ids) || count($films) !== count($ids)) continue;

                foreach ($ids as $i => $id) {
                    $id = strtolower($id);
                    $title = (string) ($films[$i] ?? '');
                    if ($title === '' || !preg_match('/^tt\d+$/', $id)) continue;
                    if (stripos($title, $q) === false) continue;

                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $results[] = array('id' => $id, 'type' => 'title', 'label' => $title);
                    if (count($results) >= 20) break 2;
                }
            }
        }

        // People / companies in nominee strings
        $name_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT nominees, nominee_ids, name FROM $awards_table WHERE (name LIKE %s OR nominees LIKE %s) LIMIT 80", $like, $like),
            ARRAY_A
        );
        if (is_array($name_rows)) {
            foreach ($name_rows as $r) {
                $names = array_filter(array_map('trim', explode('|', (string) ($r['nominees'] ?? ''))), 'strlen');
                $ids = array_filter(array_map('trim', explode('|', (string) ($r['nominee_ids'] ?? ''))), 'strlen');

                if (empty($names) || empty($ids) || count($names) !== count($ids)) {
                    // fallback: try the Name field with first ID (best-effort)
                    $fallback_name = (string) ($r['name'] ?? '');
                    if ($fallback_name !== '' && !empty($ids)) {
                        $id = strtolower((string) $ids[0]);
                        if (($this->is_name_entity_id($id) || $this->is_company_entity_id($id)) && !isset($seen[$id])) {
                            $seen[$id] = true;
                            $type = $this->is_company_entity_id($id) ? 'company' : 'name';
                            $results[] = array('id' => $id, 'type' => $type, 'label' => $fallback_name);
                        }
                    }
                    continue;
                }

                foreach ($ids as $i => $id) {
                    $id = strtolower($id);
                    $label = (string) ($names[$i] ?? '');
                    if ($label === '') continue;
                    if (stripos($label, $q) === false) continue;
                    if (!$this->is_name_entity_id($id) && !$this->is_company_entity_id($id)) continue;
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $type = $this->is_company_entity_id($id) ? 'company' : 'name';
                    $results[] = array('id' => $id, 'type' => $type, 'label' => $label);
                    if (count($results) >= 20) break 2;
                }
            }
        }

        wp_send_json_success(array('results' => $results));
    }

    /**
     * Admin AJAX: add/update tracker pick.
     */
    public function ajax_tracker_add_pick() {
        $this->verify_admin_ajax_request();

        // Defensive: on some hosts (and some manual upload/update flows) activate() might not run.
        // Ensure the tracker table exists before we write.
        $this->maybe_upgrade_schema();

        global $wpdb;
        $tracker_table = $wpdb->prefix . 'aat_tracker';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tracker_table));
        if ($exists !== $tracker_table) {
            wp_send_json_error(array('message' => 'Tracker table is missing. Please re-activate the plugin or run the schema repair.'));
        }

        $ceremony = isset($_POST['ceremony']) ? intval($_POST['ceremony']) : 0;
        $category = isset($_POST['canonical_category']) ? sanitize_text_field(wp_unslash($_POST['canonical_category'])) : '';
        $tier = isset($_POST['tier']) ? sanitize_text_field(wp_unslash($_POST['tier'])) : 'watch';
        $entity_type = isset($_POST['entity_type']) ? sanitize_text_field(wp_unslash($_POST['entity_type'])) : 'title';
        $entity_id = isset($_POST['entity_id']) ? strtolower(sanitize_text_field(wp_unslash($_POST['entity_id']))) : '';
        $rank = isset($_POST['rank']) ? intval($_POST['rank']) : 1;
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        $allowed_tiers = array('prediction','lock','watch','longshot');
        if (!in_array($tier, $allowed_tiers, true)) $tier = 'watch';

        if ($ceremony <= 0 || $category === '' || $entity_id === '') {
            wp_send_json_error(array('message' => 'Missing required fields.'));
        }

        // Normalize rank
        if ($rank <= 0) $rank = 1;
        if ($rank > 100) $rank = 100;

        // Validate entity id
        if ($entity_type === 'title' && !preg_match('/^tt\d+$/', $entity_id)) $entity_type = 'title';
        if ($entity_type === 'name' && !preg_match('/^nm\d+$/', $entity_id)) $entity_type = 'title';
        if ($entity_type === 'company' && !preg_match('/^co\d+$/', $entity_id)) $entity_type = 'title';

        $now = current_time('mysql');

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $tracker_table WHERE ceremony=%d AND canonical_category=%s AND tier=%s AND entity_type=%s AND entity_id=%s",
                $ceremony, $category, $tier, $entity_type, $entity_id
            )
        );

        $result = null;

        if ($existing_id) {
            $result = $wpdb->update(
                $tracker_table,
                array(
                    'rank' => $rank,
                    'note' => $note,
                    'updated_at' => $now,
                ),
                array('id' => intval($existing_id)),
                array('%d','%s','%s'),
                array('%d')
            );
        } else {
            $result = $wpdb->insert(
                $tracker_table,
                array(
                    'ceremony' => $ceremony,
                    'canonical_category' => $category,
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'tier' => $tier,
                    'rank' => $rank,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%d','%s','%s','%s','%s','%d','%s','%s','%s')
            );
        }

        if ($result === false || !empty($wpdb->last_error)) {
            wp_send_json_error(array('message' => 'Database write failed. ' . (defined('WP_DEBUG') && WP_DEBUG ? $wpdb->last_error : '')));
        }

        wp_send_json_success(array('message' => 'Saved.'));
    }

    /**
     * Admin AJAX: delete tracker pick.
     */
    public function ajax_tracker_delete_pick() {
        $this->verify_admin_ajax_request();

        $this->maybe_upgrade_schema();

        global $wpdb;
        $tracker_table = $wpdb->prefix . 'aat_tracker';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tracker_table));
        if ($exists !== $tracker_table) {
            wp_send_json_error(array('message' => 'Tracker table is missing.'));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid ID.'));
        }

        $result = $wpdb->delete($tracker_table, array('id' => $id), array('%d'));

        if ($result === false || !empty($wpdb->last_error)) {
            wp_send_json_error(array('message' => 'Delete failed.' . (defined('WP_DEBUG') && WP_DEBUG ? (' ' . $wpdb->last_error) : '')));
        }

        wp_send_json_success(array('message' => 'Deleted.'));
    }

    /**
     * Admin AJAX: save poster mapping.
     */
    public function ajax_posters_save() {
        $this->verify_admin_ajax_request();

        $tt = isset($_POST['imdb_id']) ? strtolower(sanitize_text_field(wp_unslash($_POST['imdb_id']))) : '';
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : 'manual';

        if (!preg_match('/^tt\d{7,8}$/', $tt) || $attachment_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid IMDb ID or attachment.'));
        }

        $this->set_poster_attachment_id($tt, $attachment_id, $source);
        wp_send_json_success(array('message' => 'Poster saved.'));
    }

    public function ajax_posters_delete() {
        $this->verify_admin_ajax_request();

        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';

        $tt = isset($_POST['imdb_id']) ? strtolower(sanitize_text_field(wp_unslash($_POST['imdb_id']))) : '';
        if (!preg_match('/^tt\d{7,8}$/', $tt)) {
            wp_send_json_error(array('message' => 'Invalid IMDb ID.'));
        }

        $wpdb->delete($poster_table, array('imdb_id' => $tt), array('%s'));
        wp_send_json_success(array('message' => 'Removed.'));
    }

    public function ajax_posters_sync_from_reviews() {
        $this->verify_admin_ajax_request();
        $out = $this->sync_posters_from_reviews();
        wp_send_json_success(array(
            'message' => 'Synced from reviews.',
            'synced' => $out['synced'] ?? 0,
            'skipped' => $out['skipped'] ?? 0,
        ));
    }

    /**
     * Admin AJAX: auto-import missing posters from OMDb/TMDB APIs.
     */
    public function ajax_posters_sync_from_apis() {
        $this->verify_admin_ajax_request();

        if ($this->get_omdb_api_key() === '' && $this->get_tmdb_api_key() === '') {
            wp_send_json_error(array(
                'message' => __('No API key is configured. Paste your OMDb and/or TMDB key in the API Settings box above, save, then run the import again.', 'academy-awards-table'),
            ));
        }

        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 120;
        $limit = max(1, min(500, $limit));

        $out = $this->sync_posters_from_apis($limit, false);
        wp_send_json_success(array(
            'message' => 'API poster sync completed.',
            'processed' => $out['processed'] ?? 0,
            'synced' => $out['synced'] ?? 0,
            'synced_omdb' => $out['synced_omdb'] ?? 0,
            'synced_tmdb' => $out['synced_tmdb'] ?? 0,
            'skipped_existing' => $out['skipped_existing'] ?? 0,
            'missing' => $out['missing'] ?? 0,
            'remaining' => $this->count_titles_without_mapped_poster(),
        ));
    }

    /**
     * Return Oscar Picks and Facts that mention the current entity.
     */
    public function get_entity_editorial_references($entity, $id, $label = '', $limit = 6) {
        global $wpdb;

        $entity = sanitize_key($entity);
        $id = trim((string) sanitize_text_field($id));
        $label = trim((string) wp_strip_all_tags($label));
        $limit = max(1, min(12, (int) $limit));

        if (!in_array($entity, array('title', 'name', 'company'), true)) {
            return array();
        }

        $terms = array();
        foreach (array($label, $id) as $term) {
            $term = trim((string) $term);
            if ($term !== '' && strlen($term) >= 3) {
                $terms[strtolower($term)] = $term;
            }
        }

        if (empty($terms)) {
            return array();
        }

        $cache_key = 'aat_entity_editorial_refs_' . md5($entity . '|' . $id . '|' . implode('|', array_keys($terms)) . '|' . $limit);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $where_parts = array();
        $values = array('publish');
        foreach ($terms as $term) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where_parts[] = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR pm.meta_value LIKE %s)';
            array_push($values, $like, $like, $like, $like);
        }

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
                AND pm.meta_key IN (
                    '_lunara_pick_film',
                    '_lunara_pick_person',
                    '_lunara_pick_ceremony_year',
                    '_lunara_pick_oscar_entity_url',
                    '_lunara_fact_detected_films'
                )
            WHERE p.post_status = %s
                AND p.post_type IN ('lunara_oscar_pick', 'oscar_fact')
                AND (" . implode(' OR ', $where_parts) . ")
            ORDER BY
                CASE WHEN p.post_type = 'lunara_oscar_pick' THEN 0 ELSE 1 END,
                p.post_date DESC
            LIMIT %d
        ";
        $values[] = $limit;

        $post_ids = $wpdb->get_col($wpdb->prepare($sql, $values));
        $references = array();

        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, array('lunara_oscar_pick', 'oscar_fact'), true)) {
                continue;
            }

            $excerpt_source = has_excerpt($post_id) ? (string) $post->post_excerpt : (string) $post->post_content;
            $excerpt_source = strip_shortcodes($excerpt_source);
            $excerpt = trim((string) wp_trim_words(wp_strip_all_tags($excerpt_source), 24, '...'));
            $meta_bits = array();

            if ($post->post_type === 'lunara_oscar_pick') {
                $film = trim((string) get_post_meta($post_id, '_lunara_pick_film', true));
                $person = trim((string) get_post_meta($post_id, '_lunara_pick_person', true));
                $year = (int) get_post_meta($post_id, '_lunara_pick_ceremony_year', true);
                $status = trim((string) get_post_meta($post_id, '_lunara_pick_status', true));

                if ($film !== '') {
                    $meta_bits[] = $film;
                }
                if ($person !== '') {
                    $meta_bits[] = $person;
                }
                if ($year > 0) {
                    $meta_bits[] = (string) $year;
                }
                if ($status !== '') {
                    $meta_bits[] = ucfirst($status);
                }
            }

            $references[] = array(
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'type_label' => $post->post_type === 'lunara_oscar_pick' ? __('Oscar Pick', 'academy-awards-table') : __('Oscar Fact', 'academy-awards-table'),
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'excerpt' => $excerpt,
                'meta_label' => implode(' / ', array_slice(array_values(array_filter($meta_bits, 'strlen')), 0, 4)),
            );
        }

        set_transient($cache_key, $references, HOUR_IN_SECONDS);
        return $references;
    }

    /**
     * Reviews integration
     * We connect Lunara Film reviews (CPT: review) to IMDb title IDs (tt1234567).
     * Store the review film ID in post meta (default: _lunara_imdb_title_id).
     */
    public function get_review_post_type() {
        return apply_filters('aat_review_post_type', 'review');
    }

    public function get_review_imdb_meta_key() {
        return apply_filters('aat_review_imdb_meta_key', '_lunara_imdb_title_id');
    }

    /**
     * Return review post IDs that correspond to a given IMDb title id (tt...).
     */
    public function get_review_ids_for_title_id($tt, $limit = 5) {
        $tt = strtolower(trim((string) $tt));
        if (!preg_match('/^tt\d{7,8}$/', $tt)) {
            return array();
        }

        $limit = max(1, (int) $limit);
        $cache_key = 'aat_review_ids_' . $tt . '_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $args = array(
            'post_type'              => $this->get_review_post_type(),
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => $this->get_review_imdb_meta_key(),
                    'value'   => $tt,
                    'compare' => '=',
                ),
            ),
            'orderby'                => 'date',
            'order'                  => 'DESC',
        );

        $ids = get_posts($args);
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_numeric')) : array();

        set_transient($cache_key, $ids, 6 * HOUR_IN_SECONDS);
        return $ids;
    }

    /**
     * Build a stable transient hash for mixed arguments.
     */
    private function build_cache_hash($parts) {
        return md5(wp_json_encode($parts));
    }

    /**
     * Cached total stats over the distinct awards rowset.
     */
    private function get_total_awards_stats($table_name) {
        $cache_key = 'aat_total_stats_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['records_total'], $cached['winners_total'])) {
            return $cached;
        }

        $projection_counts = $this->get_projection_total_counts();
        $stats = array(
            'records_total' => intval($projection_counts['records']),
            'winners_total' => intval($projection_counts['winners']),
        );

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        return $stats;
    }

    /**
     * Extract normalized IMDb title ids from a pipe-delimited film_id value.
     */
    private function extract_title_ids($film_ids_raw) {
        $film_ids_raw = (string) $film_ids_raw;
        if ($film_ids_raw === '') {
            return array();
        }

        $tt_ids = array();
        $ids = array_filter(array_map('trim', explode('|', $film_ids_raw)), 'strlen');
        foreach ($ids as $fid) {
            $fid = strtolower($fid);
            if (preg_match('/^tt\d{7,8}$/', $fid)) {
                $tt_ids[] = $fid;
            }
        }

        return array_values(array_unique($tt_ids));
    }

    /**
     * Replace one exact IMDb title ID token inside a pipe-delimited film_id value.
     */
    private function replace_title_id_token($film_ids_raw, $current_imdb_id, $candidate_imdb_id) {
        $current_imdb_id = strtolower(trim((string) $current_imdb_id));
        $candidate_imdb_id = strtolower(trim((string) $candidate_imdb_id));

        if (!$this->is_title_entity_id($current_imdb_id) || !$this->is_title_entity_id($candidate_imdb_id) || $current_imdb_id === $candidate_imdb_id) {
            return (string) $film_ids_raw;
        }

        $tokens = array_filter(array_map('trim', explode('|', (string) $film_ids_raw)), 'strlen');
        if (empty($tokens)) {
            return (string) $film_ids_raw;
        }

        $out = array();
        foreach ($tokens as $token) {
            $token = strtolower(trim((string) $token));
            if ($token === $current_imdb_id) {
                $token = $candidate_imdb_id;
            }
            if ($this->is_title_entity_id($token) && !in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        return implode('|', $out);
    }

    /**
     * Cached filtered stats for the server-side table endpoint.
     */
    private function get_filtered_awards_stats($table_name, $where_sql, $values) {
        global $wpdb;

        $cache_key = 'aat_filtered_stats_v2_' . $this->build_cache_hash(array($where_sql, $values));
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['records_filtered'], $cached['winners_filtered'])) {
            return $cached;
        }

        $fields = $this->get_awards_row_fields_sql();
        $stats_sql = "SELECT COUNT(*) AS records_filtered, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS winners_filtered FROM (SELECT DISTINCT $fields FROM $table_name WHERE $where_sql) aat_filtered_rows";
        if (!empty($values)) {
            $stats_sql = $wpdb->prepare($stats_sql, $values);
        }

        $row = $wpdb->get_row($stats_sql, ARRAY_A);
        $stats = array(
            'records_filtered' => isset($row['records_filtered']) ? (int) $row['records_filtered'] : 0,
            'winners_filtered' => isset($row['winners_filtered']) ? (int) $row['winners_filtered'] : 0,
        );

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        return $stats;
    }

    /**
     * Resolve a newest-review permalink map for one or more IMDb title ids.
     */
    private function get_review_permalink_map_for_title_ids($tt_ids) {
        $review_map = array();
        $tt_ids = array_values(array_unique(array_filter(array_map('strtolower', (array) $tt_ids))));

        foreach ($tt_ids as $tt) {
            if (!preg_match('/^tt\d{7,8}$/', $tt)) {
                continue;
            }

            $review_ids = $this->get_review_ids_for_title_id($tt, 1);
            if (empty($review_ids[0])) {
                continue;
            }

            $review_map[$tt] = get_permalink((int) $review_ids[0]);
        }

        return $review_map;
    }

    /**
     * AJAX: Get awards meta (filter dropdown values + global counts)
     */
    public function ajax_get_awards_meta() {
        // Nonce is optional for public, read-only endpoints (avoids cached-page nonce expiry issues).
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'aat_nonce')) {
            // Intentionally allow through (public read-only data).
        }

        // Return cached meta if available.
        $meta_cache_key = 'aat_awards_meta_v1';
        $cached_meta = get_transient($meta_cache_key);
        if ($cached_meta !== false && is_array($cached_meta)) {
            wp_send_json_success($cached_meta);
        }

        // Get unique values for filters
        $categories = $this->get_projection_categories_list();
        $classes = $this->get_projection_classes_list();
        $years = $this->get_projection_years_list();
        $ceremonies = $this->get_projection_ceremonies_list();

        $stats = $this->get_total_awards_stats($this->get_table_name());
        $total_records = (int) $stats['records_total'];
        $total_winners = (int) $stats['winners_total'];

        $meta_data = array(
            'categories' => $categories,
            'classes' => $classes,
            'years' => $years,
            'ceremonies' => $ceremonies,
            'totals' => array(
                'records' => $total_records,
                'winners' => $total_winners,
                'categories' => is_array($categories) ? count($categories) : 0,
                'ceremonies' => is_array($ceremonies) ? count($ceremonies) : 0,
            ),
        );

        set_transient($meta_cache_key, $meta_data, 10 * MINUTE_IN_SECONDS);

        wp_send_json_success($meta_data);
    }

    /**
     * Helper: Build WHERE SQL and values for prepared statements.
     */
    private function build_where_sql($wpdb, $category, $class, $year, $ceremony, $winners_only, $global_search, $year_prefix, &$values) {
        $where_clauses = array('1=1');
        $values = array();

        if (!empty($category)) {
            $where_clauses[] = 'canonical_category = %s';
            $values[] = $category;
        }

        if (!empty($class)) {
            $where_clauses[] = 'class = %s';
            $values[] = $class;
        }

        if (!empty($year)) {
            $where_clauses[] = 'year = %s';
            $values[] = $year;
        } elseif (!empty($year_prefix)) {
            // Used by the decade quick filters (e.g. 2020s => "202%")
            $where_clauses[] = 'year LIKE %s';
            $values[] = $wpdb->esc_like($year_prefix) . '%';
        }

        if (!empty($ceremony) && intval($ceremony) > 0) {
            $where_clauses[] = 'ceremony = %d';
            $values[] = intval($ceremony);
        }

        if ($winners_only) {
            $where_clauses[] = 'winner = 1';
        }

        if (!empty($global_search)) {
            $search_term = '%' . $wpdb->esc_like($global_search) . '%';
            $where_clauses[] = '(name LIKE %s OR film LIKE %s OR canonical_category LIKE %s OR category LIKE %s OR nominees LIKE %s OR detail LIKE %s OR note LIKE %s)';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        return implode(' AND ', $where_clauses);
    }

    /**
     * AJAX: DataTables server-side endpoint
     *
     * Returns JSON in the format DataTables expects:
     * { draw, recordsTotal, recordsFiltered, data }
     */
    public function ajax_get_awards_datatable() {
        // Nonce is optional for public, read-only endpoints (avoids cached-page nonce expiry issues).
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'aat_nonce')) {
            // Intentionally allow through (public read-only data).
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';

        // DataTables paging/search/order
        $draw = isset($_POST['draw']) ? intval($_POST['draw']) : 0;
        $start = isset($_POST['start']) ? max(0, intval($_POST['start'])) : 0;
        $length = isset($_POST['length']) ? intval($_POST['length']) : 25;

        // Guardrails (prevents someone from requesting the whole DB in one request)
        if ($length < 1) {
            $length = 25;
        }
        $length = min($length, 200);

        $global_search = '';
        if (isset($_POST['search']) && is_array($_POST['search']) && isset($_POST['search']['value'])) {
            $global_search = sanitize_text_field(wp_unslash($_POST['search']['value']));
        }

        $order_col_idx = 1;
        $order_dir = 'desc';
        if (isset($_POST['order']) && is_array($_POST['order']) && !empty($_POST['order'][0])) {
            $order_col_idx = isset($_POST['order'][0]['column']) ? intval($_POST['order'][0]['column']) : 1;
            $order_dir = isset($_POST['order'][0]['dir']) ? sanitize_text_field(wp_unslash($_POST['order'][0]['dir'])) : 'desc';
        }
        $order_dir = (strtolower($order_dir) === 'asc') ? 'ASC' : 'DESC';

        // Custom UI filters
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        $class = isset($_POST['class']) ? sanitize_text_field(wp_unslash($_POST['class'])) : '';
        $year = isset($_POST['year']) ? sanitize_text_field(wp_unslash($_POST['year'])) : '';
        $ceremony = isset($_POST['ceremony']) ? intval($_POST['ceremony']) : 0;
        $winners_only = isset($_POST['winners_only']) && wp_unslash($_POST['winners_only']) === 'true';

        // Column search (used by decade quick-filters via column search on the Year column)
        $year_prefix = '';
        if (empty($year) && isset($_POST['columns']) && is_array($_POST['columns']) && isset($_POST['columns'][2]) && isset($_POST['columns'][2]['search']) && is_array($_POST['columns'][2]['search'])) {
            $year_prefix = isset($_POST['columns'][2]['search']['value']) ? sanitize_text_field(wp_unslash($_POST['columns'][2]['search']['value'])) : '';
        }

        // Map DataTables column indexes to database columns for ordering.
        // Indexes correspond to the front-end column definitions:
        // 0 control, 1 ceremony, 2 year, 3 category, 4 nominee, 5 film, 6 status
        $order_map = array(
            1 => 'ceremony',
            2 => 'year',
            3 => 'canonical_category',
            4 => 'name',
            5 => 'film',
            6 => 'winner',
        );
        $order_col = isset($order_map[$order_col_idx]) ? $order_map[$order_col_idx] : 'ceremony';

        // WHERE
        $values = array();
        $where_sql = $this->build_where_sql($wpdb, $category, $class, $year, $ceremony, $winners_only, $global_search, $year_prefix, $values);

        // counts
        $records_total_key = 'aat_records_total_v2';
        $records_total = get_transient($records_total_key);
        if ($records_total === false) {
            $total_stats = $this->get_total_awards_stats($table_name);
            $records_total = (int) $total_stats['records_total'];
            set_transient($records_total_key, $records_total, 5 * MINUTE_IN_SECONDS);
        }
        $records_total = intval($records_total);

        $filtered_stats = $this->get_filtered_awards_stats($table_name, $where_sql, $values);
        $records_filtered = (int) $filtered_stats['records_filtered'];
        $winners_filtered = (int) $filtered_stats['winners_filtered'];

        // Data
        $fields = 'id, ' . $this->get_awards_row_fields_sql();

        // Stable ordering: whatever the user chooses, keep consistent tie-breakers.
        $order_sql = "$order_col $order_dir, ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC, id ASC";

        $data_sql = "SELECT $fields FROM $table_name WHERE $where_sql ORDER BY $order_sql LIMIT %d OFFSET %d";
        $data_values = array_merge($values, array($length, $start));
        $data_sql = $wpdb->prepare($data_sql, $data_values);

        $rows = $wpdb->get_results($data_sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        // Add stable, URL-safe slugs for hub page linking (Category pages).
        // Keeps the front-end simple and ensures our slugs match WordPress sanitize_title().
        foreach ($rows as $i => $row) {
            $row = $this->normalize_awards_row($row);
            $cat = isset($row['canonical_category']) ? (string) $row['canonical_category'] : '';
            $row['category_slug'] = $cat ? sanitize_title($cat) : '';
            $rows[$i] = $row;
        }


        // OPTIONAL: Attach Lunara review URLs (if you have a review that matches this film's IMDb title id).
        // We keep this lightweight and only add a single URL (newest review) per film.
        $tt_ids = array();
        foreach ($rows as $row) {
            foreach ($this->extract_title_ids($row['film_id'] ?? '') as $fid) {
                $tt_ids[$fid] = true;
            }
        }

        $review_map = !empty($tt_ids) ? $this->get_review_permalink_map_for_title_ids(array_keys($tt_ids)) : array();

        foreach ($rows as $i => $row) {
            $rows[$i]['review_url'] = '';
            foreach ($this->extract_title_ids($row['film_id'] ?? '') as $fid) {
                if (isset($review_map[$fid])) {
                    $rows[$i]['review_url'] = (string) $review_map[$fid];
                    break;
                }
            }
        }

        // DataTables expects a bare JSON object, not a wp_send_json_success wrapper.
        wp_send_json(array(
            'draw' => $draw,
            'recordsTotal' => $records_total,
            'recordsFiltered' => $records_filtered,
            'data' => $rows,
            'stats' => array(
                'filtered_total' => $records_filtered,
                'filtered_winners' => $winners_filtered,
            ),
        ));
    }

    /**
     * AJAX: Get awards data (legacy, non-server-side)
     */
    public function ajax_get_awards_data() {
        // Nonce is optional for public, read-only endpoints (avoids cached-page nonce expiry issues).
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'aat_nonce')) {
            // Intentionally allow through (public read-only data).
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';

        // Get filter parameters
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
        $class = isset($_POST['class']) ? sanitize_text_field($_POST['class']) : '';
        $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : '';
        $ceremony = isset($_POST['ceremony']) ? intval($_POST['ceremony']) : 0;
        $winners_only = isset($_POST['winners_only']) && $_POST['winners_only'] === 'true';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        // Build query
        $where_clauses = array('1=1');
        $values = array();

        if (!empty($category)) {
            $where_clauses[] = 'canonical_category = %s';
            $values[] = $category;
        }

        if (!empty($class)) {
            $where_clauses[] = 'class = %s';
            $values[] = $class;
        }

        if (!empty($year)) {
            $where_clauses[] = 'year = %s';
            $values[] = $year;
        }

        if ($ceremony > 0) {
            $where_clauses[] = 'ceremony = %d';
            $values[] = $ceremony;
        }

        if ($winners_only) {
            $where_clauses[] = 'winner = 1';
        }

        if (!empty($search)) {
            $where_clauses[] = '(name LIKE %s OR film LIKE %s OR category LIKE %s OR nominees LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where = implode(' AND ', $where_clauses);

        // Only return the fields we need for the front-end table.
        $fields = $this->get_awards_row_fields_sql();
        // Safety cap: legacy endpoint should not dump the entire dataset.
        $query = "SELECT DISTINCT $fields FROM $table_name WHERE $where ORDER BY ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC LIMIT 500";

        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }

        $results = $wpdb->get_results($query, ARRAY_A);
        if (is_array($results)) {
            foreach ($results as $index => $row) {
                $results[$index] = $this->normalize_awards_row($row);
            }
        }

        // Filter values are now served by the dedicated ajax_get_awards_meta() endpoint.
        wp_send_json_success(array(
            'data' => $results,
            'categories' => array(),
            'classes' => array(),
            'years' => array(),
            'ceremonies' => array(),
            'total' => count($results),
        ));
    }

    /**
     * AJAX: Import data from CSV/JSON
     */
    
/**
 * Import Oscars data from an uploaded CSV/TSV or JSON file.
 *
 * This endpoint does a full replace (TRUNCATE + import).
 * For the most reliable workflow, prefer the bundled importer for the full dataset,
 * and use the "Quick Ceremony Update" delta importer for new nominations/winners.
 */
public function ajax_import_data() {
    $this->verify_admin_ajax_request();

    $upload = null;
    if (!empty($_FILES['import_file']) && isset($_FILES['import_file']['tmp_name'])) {
        $upload = $_FILES['import_file'];
    } elseif (!empty($_FILES['file']) && isset($_FILES['file']['tmp_name'])) {
        $upload = $_FILES['file'];
    }

    if (!$upload) {
        wp_send_json_error(array('message' => 'No file uploaded.'));
    }

    if (!empty($upload['error'])) {
        wp_send_json_error(array('message' => 'Upload error: ' . (int) $upload['error']));
    }

    $tmp_path = $upload['tmp_name'];
    $filename = isset($upload['name']) ? (string) $upload['name'] : '';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    global $wpdb;
    $table_name = $this->get_table_name();
    $this->maybe_upgrade_schema();

    // Full replace import
    $wpdb->query("TRUNCATE TABLE $table_name");

    // Invalidate performance caches
    delete_transient('aat_records_total_v1');
    delete_transient('aat_records_total_v2');
    delete_transient('aat_total_stats_v2');
    delete_transient('aat_awards_meta_v1');

    // JSON import (array of objects)
    if ($ext === 'json') {
        $raw = @file_get_contents($tmp_path);
        $rows = json_decode($raw, true);

        if (!is_array($rows)) {
            wp_send_json_error(array('message' => 'Invalid JSON file.'));
        }

        $imported = 0;
        $errors = 0;
        $skipped_duplicates = 0;
        $seen_fingerprints = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors++;
                continue;
            }

            $db_row = $this->build_import_db_row($row);
            $fingerprint = $this->get_awards_row_fingerprint($db_row);
            if (isset($seen_fingerprints[$fingerprint])) {
                $skipped_duplicates++;
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;

            $result = $wpdb->insert(
                $table_name,
                $db_row
            );

            if ($result === false) {
                $errors++;
                continue;
            }

            $imported++;
        }

        $screenplay_repair = $this->repair_writing_credit_rows();
        $best_picture_repair = $this->repair_best_picture_credit_rows();
        $international_feature_repair = $this->repair_international_feature_credit_rows();
        $documentary_short_repair = $this->repair_documentary_and_short_credit_rows();
        $reporting_rebuild = $this->rebuild_reporting_tables();

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'skipped_duplicates' => $skipped_duplicates,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
            'reporting_rebuild' => $reporting_rebuild,
        ));
    }

    // CSV/TSV import
    $sample = @file_get_contents($tmp_path, false, null, 0, 4096);
    $delimiter = (is_string($sample) && strpos($sample, "\t") !== false) ? "\t" : ",";

    try {
        $sf = new SplFileObject($tmp_path, 'r');
        $sf->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $sf->setCsvControl($delimiter);

        $header = $sf->fgetcsv();
        if (!is_array($header) || count($header) < 3) {
            wp_send_json_error(array('message' => 'Could not read header row. Please check the file format.'));
        }

        $header = array_map('trim', $header);

        $required = array('Ceremony', 'Year', 'Class', 'CanonicalCategory', 'Category', 'Film', 'Name', 'Winner');
        $missing = array();
        foreach ($required as $req) {
            if (!in_array($req, $header, true)) {
                $missing[] = $req;
            }
        }
        if (!empty($missing)) {
            wp_send_json_error(array(
                'message' => 'Missing required columns: ' . implode(', ', $missing) . '.'
            ));
        }

        $imported = 0;
        $errors = 0;
        $skipped_duplicates = 0;
        $seen_fingerprints = array();

        while (!$sf->eof()) {
            $row = $sf->fgetcsv();

            if ($row === false || $row === null) {
                continue;
            }

            // fgetcsv can return [null] at EOF
            if (is_array($row) && count($row) === 1 && $row[0] === null) {
                continue;
            }

            if (count($row) !== count($header)) {
                $errors++;
                continue;
            }

            $data = array_combine($header, $row);
            if (!is_array($data)) {
                $errors++;
                continue;
            }

            $db_row = $this->build_import_db_row($data);
            $fingerprint = $this->get_awards_row_fingerprint($db_row);
            if (isset($seen_fingerprints[$fingerprint])) {
                $skipped_duplicates++;
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;

            $result = $wpdb->insert(
                $table_name,
                $db_row
            );

            if ($result === false) {
                $errors++;
                continue;
            }

            $imported++;
        }

        /**
         * Fires after a full CSV/JSON data import completes.
         * Theme and other plugins can hook here to clear derived caches.
         */
        do_action( 'aat_after_data_import', 'full', $imported );

        $screenplay_repair = $this->repair_writing_credit_rows();
        $best_picture_repair = $this->repair_best_picture_credit_rows();
        $international_feature_repair = $this->repair_international_feature_credit_rows();
        $documentary_short_repair = $this->repair_documentary_and_short_credit_rows();
        $reporting_rebuild = $this->rebuild_reporting_tables();

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'skipped_duplicates' => $skipped_duplicates,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
            'reporting_rebuild' => $reporting_rebuild,
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Import failed: ' . $e->getMessage()));
    }
}

/**
 * Delta import: replace exactly one ceremony using an uploaded TSV/CSV file.
 *
 * This is the most reliable way to ingest new nominations/winners without re-importing full history.
 */
public function ajax_import_ceremony_delta() {
    $this->verify_admin_ajax_request();

    if (empty($_FILES['delta_file']) || !isset($_FILES['delta_file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'No file uploaded.'));
    }

    $file = $_FILES['delta_file'];

    if (!empty($file['error'])) {
        wp_send_json_error(array('message' => 'Upload error: ' . (int) $file['error']));
    }

    $tmp_path = $file['tmp_name'];

    $sample = @file_get_contents($tmp_path, false, null, 0, 4096);
    $delimiter = (is_string($sample) && strpos($sample, "\t") !== false) ? "\t" : ",";

    global $wpdb;
    $table_name = $this->get_table_name();
    $this->maybe_upgrade_schema();

    try {
        $sf = new SplFileObject($tmp_path, 'r');
        $sf->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $sf->setCsvControl($delimiter);

        $header = $sf->fgetcsv();
        if (!is_array($header) || count($header) < 3) {
            wp_send_json_error(array('message' => 'Could not read header row. Please check the file format.'));
        }
        $header = array_map('trim', $header);

        $required = array('Ceremony', 'Year', 'Class', 'CanonicalCategory', 'Category', 'Film', 'Name', 'Winner');
        $missing = array();
         foreach ($required as $req) {
            if (!in_array($req, $header, true)) {
                $missing[] = $req;
            }
        }
        if (!empty($missing)) {
            wp_send_json_error(array(
                'message' => 'Missing required columns: ' . implode(', ', $missing) . '.'
            ));
        }

        $ceremony_set = array();
        $rows = array();
        $skipped = 0;

        while (!$sf->eof()) {
            $row = $sf->fgetcsv();

            if ($row === false || $row === null) {
                continue;
            }

            if (is_array($row) && count($row) === 1 && $row[0] === null) {
                continue;
            }

            if (count($row) !== count($header)) {
                $skipped++;
                continue;
            }

            $data = array_combine($header, $row);
            if (!is_array($data)) {
                $skipped++;
                continue;
            }

            $cer = isset($data['Ceremony']) ? intval($data['Ceremony']) : 0;
            if ($cer <= 0) {
                $skipped++;
                continue;
            }

            $ceremony_set[$cer] = true;
            $rows[] = $data;
        }

        $ceremonies = array_keys($ceremony_set);
        if (count($ceremonies) !== 1) {
            wp_send_json_error(array(
                'message' => 'Delta import expects exactly one Ceremony in the file. Found: ' . count($ceremonies) . '. If you have multiple ceremonies, import them one at a time (recommended).'
            ));
        }
        $ceremony = intval($ceremonies[0]);

        // Replace that ceremony only
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE ceremony = %d", $ceremony));

        // Invalidate performance caches
        delete_transient('aat_records_total_v1');
        delete_transient('aat_records_total_v2');
        delete_transient('aat_total_stats_v2');
        delete_transient('aat_awards_meta_v1');

        $imported = 0;
        $errors = 0;
        $skipped_duplicates = 0;
        $seen_fingerprints = array();

        foreach ($rows as $data) {
            $db_row = $this->build_import_db_row($data);
            $fingerprint = $this->get_awards_row_fingerprint($db_row);
            if (isset($seen_fingerprints[$fingerprint])) {
                $skipped_duplicates++;
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;

            $result = $wpdb->insert(
                $table_name,
                $db_row
            );

            if ($result === false) {
                $errors++;
                continue;
            }

            $imported++;
        }

        /** Fires after a ceremony delta import completes. */
        do_action( 'aat_after_data_import', 'delta', $imported );

        $screenplay_repair = $this->repair_writing_credit_rows();
        $best_picture_repair = $this->repair_best_picture_credit_rows();
        $international_feature_repair = $this->repair_international_feature_credit_rows();
        $documentary_short_repair = $this->repair_documentary_and_short_credit_rows();
        $reporting_rebuild = $this->rebuild_reporting_tables();

        wp_send_json_success(array(
            'ceremony' => $ceremony,
            'imported' => $imported,
            'errors' => $errors,
            'skipped' => $skipped,
            'skipped_duplicates' => $skipped_duplicates,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
            'reporting_rebuild' => $reporting_rebuild,
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Delta import failed: ' . $e->getMessage()));
    }
}

/**
 * Repair tables and rewrite rules.
 */
public function ajax_repair_schema() {
    $this->verify_admin_ajax_request();

    $this->maybe_upgrade_schema();
    $this->register_rewrite_rules();
    flush_rewrite_rules();
    $reporting_rebuild = $this->rebuild_reporting_tables();

    wp_send_json_success(array(
        'message' => 'Schema, reporting tables, and rewrite rules repaired.',
        'reporting_rebuild' => $reporting_rebuild,
    ));
}

public function ajax_import_bundled_data() {
        check_ajax_referer('aat_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!file_exists(AAT_BUNDLED_CSV_PATH)) {
            wp_send_json_error('Bundled oscars.csv not found.');
        }

        @set_time_limit(0);

        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';
        $stage_table = $wpdb->prefix . 'academy_awards_import_stage';
        $state_option = 'aat_bundled_import_state';

        $chunk_size = apply_filters('aat_bundled_import_chunk_size', 500);
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;

        $source_hash = hash_file('sha256', AAT_BUNDLED_CSV_PATH);
        if (!is_string($source_hash) || $source_hash === '') {
            wp_send_json_error(array('message' => 'Bundled dataset hash could not be calculated. The current database was preserved.'));
        }

        $import_state = array();
        if ($offset === 0) {
            $source_census = $this->get_bundled_award_group_census();
            if (empty($source_census['available']) || intval($source_census['rows'] ?? 0) <= 0) {
                wp_send_json_error(array('message' => 'Bundled dataset census failed. The current database was preserved.'));
            }

            $main_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));
            if ($main_exists !== $table_name) {
                wp_send_json_error(array('message' => 'The active Academy Awards table does not exist. Nothing was changed.'));
            }

            $wpdb->query("DROP TABLE IF EXISTS `$stage_table`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("CREATE TABLE `$stage_table` LIKE `$table_name`") === false) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                wp_send_json_error(array(
                    'message' => 'Could not create the validated import staging table. The current database was preserved.',
                    'database_error' => (string) $wpdb->last_error,
                ));
            }

            $backup_table = substr($wpdb->prefix . 'academy_awards_backup_' . gmdate('Ymd_His'), 0, 64);
            $import_state = array(
                'status' => 'importing',
                'source_hash' => $source_hash,
                'source_signature' => (string) ($source_census['signature'] ?? ''),
                'expected_rows' => intval($source_census['rows']),
                'expected_winners' => intval($source_census['winners']),
                'next_offset' => 0,
                'imported' => 0,
                'errors' => 0,
                'skipped_duplicates' => 0,
                'stage_table' => $stage_table,
                'backup_table' => $backup_table,
                'started_at' => current_time('mysql'),
            );
            update_option($state_option, $import_state, false);
            update_option('aat_bundled_total_rows', intval($source_census['rows']), false);
        } else {
            $import_state = get_option($state_option, array());
            if (!is_array($import_state) || ($import_state['status'] ?? '') !== 'importing') {
                wp_send_json_error(array('message' => 'No resumable bundled import is active. Start again at row 0; the current database was preserved.'));
            }
            if (!hash_equals((string) ($import_state['source_hash'] ?? ''), $source_hash)) {
                wp_send_json_error(array('message' => 'The bundled dataset changed during import. Start again; the current database was preserved.'));
            }
            if (intval($import_state['next_offset'] ?? -1) !== $offset) {
                wp_send_json_error(array('message' => 'The import offset is out of sequence. Start again; the current database was preserved.'));
            }

            $stage_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($stage_table)));
            if ($stage_exists !== $stage_table) {
                wp_send_json_error(array('message' => 'The import staging table is missing. Start again; the current database was preserved.'));
            }
        }

        $total_rows = intval($import_state['expected_rows'] ?? 0);
        $expected_winners = intval($import_state['expected_winners'] ?? 0);
        $backup_table = (string) ($import_state['backup_table'] ?? '');
        if (
            $total_rows <= 0 ||
            $expected_winners <= 0 ||
            strpos($backup_table, $wpdb->prefix . 'academy_awards_backup_') !== 0 ||
            !preg_match('/^[A-Za-z0-9_]+$/', $backup_table)
        ) {
            wp_send_json_error(array('message' => 'The bundled import state is invalid. The current database was preserved.'));
        }

        $file = new SplFileObject(AAT_BUNDLED_CSV_PATH, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl("\t");

        // Read header
        $file->rewind();
        $headers = $file->fgetcsv();
        if (!is_array($headers) || empty($headers)) {
            wp_send_json_error('Bundled CSV header could not be read.');
        }
        $headers = array_map('trim', $headers);
        // Strip UTF-8 BOM from the first header field if present
        $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        // Seek to the correct line (header is line 0)
        $seek_line = $offset + 1;
        $file->seek($seek_line);

        $imported = 0;
        $errors = 0;
        $processed = 0;
        $skipped_duplicates = 0;

        // Collect rows for batch INSERT
        $batch_rows = array();
        $seen_fingerprints = array();
        $db_columns = array('ceremony','year','class','canonical_category','category','film','film_id','name','nominees','nominee_ids','winner','detail','note','citation');

        for ($i = 0; $i < $chunk_size && !$file->eof(); $i++) {
            $row_values = $file->current();
            $file->next();
            $processed++;

            if (!is_array($row_values)) {
                continue;
            }

            // Handle empty trailing lines
            if (count($row_values) === 1 && trim((string) $row_values[0]) === '') {
                continue;
            }

            // Normalize column count.
            if (count($row_values) < count($headers)) {
                $row_values = array_pad($row_values, count($headers), '');
            } elseif (count($row_values) > count($headers)) {
                // If extra columns exist, merge them into the final field.
                $fixed = array_slice($row_values, 0, count($headers) - 1);
                $fixed[] = implode("\t", array_slice($row_values, count($headers) - 1));
                $row_values = $fixed;
            }

            $row = array_combine($headers, $row_values);
            if (!$row) {
                $errors++;
                continue;
            }

            $db_row = $this->build_import_db_row($row);
            $fingerprint = $this->get_awards_row_fingerprint($db_row);
            if (isset($seen_fingerprints[$fingerprint])) {
                $skipped_duplicates++;
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;

            $batch_rows[] = array(
                $db_row['ceremony'],
                $db_row['year'],
                $db_row['class'],
                $db_row['canonical_category'],
                $db_row['category'],
                $db_row['film'],
                $db_row['film_id'],
                $db_row['name'],
                $db_row['nominees'],
                $db_row['nominee_ids'],
                $db_row['winner'],
                $db_row['detail'],
                $db_row['note'],
                $db_row['citation'],
            );
        }

        // Batch INSERT in groups of 50 for efficiency
        $batch_insert_size = 50;
        $col_list = '`' . implode('`,`', $db_columns) . '`';

        for ($b = 0; $b < count($batch_rows); $b += $batch_insert_size) {
            $slice = array_slice($batch_rows, $b, $batch_insert_size);
            $placeholders = array();
            $values = array();

            foreach ($slice as $r) {
                $placeholders[] = '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s)';
                foreach ($r as $v) {
                    $values[] = $v;
                }
            }

            $sql = "INSERT INTO $stage_table ($col_list) VALUES " . implode(',', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));

            if ($result === false) {
                $errors += count($slice);
            } else {
                $imported += count($slice);
            }
        }

        $new_offset = $offset + $processed;
        $done = ($new_offset >= $total_rows);
        $import_state['next_offset'] = $new_offset;
        $import_state['imported'] = intval($import_state['imported'] ?? 0) + $imported;
        $import_state['errors'] = intval($import_state['errors'] ?? 0) + $errors;
        $import_state['skipped_duplicates'] = intval($import_state['skipped_duplicates'] ?? 0) + $skipped_duplicates;
        update_option($state_option, $import_state, false);

        $screenplay_repair = array();
        $best_picture_repair = array();
        $international_feature_repair = array();
        $documentary_short_repair = array();

        if ($done) {
            $stage_rows = intval($wpdb->get_var("SELECT COUNT(*) FROM `$stage_table`")); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stage_winners = intval($wpdb->get_var("SELECT COUNT(*) FROM `$stage_table` WHERE winner = 1")); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stage_census = $this->get_database_award_group_census($stage_table);
            $expected_signature = (string) ($import_state['source_signature'] ?? '');
            $stage_signature = (string) ($stage_census['signature'] ?? '');
            $signature_matches = !empty($stage_census['available'])
                && $expected_signature !== ''
                && hash_equals($expected_signature, $stage_signature);
            $total_errors = intval($import_state['errors']);

            if ($total_errors > 0 || $stage_rows !== $total_rows || $stage_winners !== $expected_winners || !$signature_matches) {
                $import_state['status'] = 'validation_failed';
                $import_state['validated_rows'] = $stage_rows;
                $import_state['validated_winners'] = $stage_winners;
                $import_state['validated_signature'] = $stage_signature;
                $import_state['failed_at'] = current_time('mysql');
                update_option($state_option, $import_state, false);

                wp_send_json_error(array(
                    'message' => sprintf(
                        'Bundled import validation failed before the database swap: expected %1$d rows / %2$d winners, found %3$d rows / %4$d winners with %5$d insert errors; credit-aware content signature match: %6$s. The current database is still live and unchanged.',
                        $total_rows,
                        $expected_winners,
                        $stage_rows,
                        $stage_winners,
                        $total_errors,
                        $signature_matches ? 'yes' : 'no'
                    ),
                    'live_preserved' => true,
                    'stage_table' => $stage_table,
                ));
            }

            $swap_sql = "RENAME TABLE `$table_name` TO `$backup_table`, `$stage_table` TO `$table_name`";
            if ($wpdb->query($swap_sql) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $import_state['status'] = 'swap_failed';
                $import_state['database_error'] = (string) $wpdb->last_error;
                $import_state['failed_at'] = current_time('mysql');
                update_option($state_option, $import_state, false);

                wp_send_json_error(array(
                    'message' => 'The validated import could not be swapped into place. The current database is still live and unchanged.',
                    'live_preserved' => true,
                    'database_error' => (string) $wpdb->last_error,
                ));
            }

            $import_state['status'] = 'swapped';
            $import_state['swapped_at'] = current_time('mysql');
            update_option($state_option, $import_state, false);

            // The bundled file is the canonical source. Preserve it exactly;
            // repair helpers remain available for legacy/manual imports only.
            $screenplay_repair = array('updated' => 0, 'resolved_ids' => 0, 'source' => 'canonical-bundled');
            $best_picture_repair = array('updated' => 0, 'resolved_ids' => 0, 'source' => 'canonical-bundled');
            $international_feature_repair = array('updated' => 0, 'resolved_ids' => 0, 'source' => 'canonical-bundled');
            $documentary_short_repair = array('updated' => 0, 'resolved_ids' => 0, 'source' => 'canonical-bundled');
            $reporting_rebuild = $this->rebuild_reporting_tables();

            // Invalidate performance caches
            delete_transient('aat_records_total_v1');
            delete_transient('aat_records_total_v2');
            delete_transient('aat_total_stats_v2');
            delete_transient('aat_awards_meta_v1');

            // Confirm how many rows actually ended up in the DB.
            $inserted_total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name"));
            $inserted_winners = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE winner = 1"));
            $import_state['status'] = 'completed';
            $import_state['final_rows'] = $inserted_total;
            $import_state['final_winners'] = $inserted_winners;
            $import_state['final_signature'] = $stage_signature;
            $import_state['reporting_rebuild'] = $reporting_rebuild;
            $import_state['completed_at'] = current_time('mysql');
            update_option($state_option, $import_state, false);

            $message = sprintf(
                __('Bundled import complete: %1$d canonical rows and %2$d winners are now live. The credit-aware source signature matched, no post-import credit mutation was applied, and the previous table remains available as %3$s. (%4$d rows processed; %5$d insert errors.)', 'academy-awards-table'),
                $inserted_total,
                $inserted_winners,
                $backup_table,
                $new_offset,
                intval($import_state['errors'])
            );
        } else {
            $message = sprintf(__('Importing… %d of %d rows processed.', 'academy-awards-table'), $new_offset, $total_rows);
        }

        if ( $done ) {
            /** Fires after the final chunk of a bundled import completes. */
            do_action( 'aat_after_data_import', 'bundled', $inserted_total );
        }

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => intval($import_state['errors']),
            'skipped_duplicates' => intval($import_state['skipped_duplicates']),
            'offset' => $new_offset,
            'total_rows' => $total_rows,
            'done' => $done,
            'message' => $message,
            'backup_table' => $done ? $backup_table : '',
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
            'reporting_rebuild' => isset($reporting_rebuild) ? $reporting_rebuild : array(),
        ));
    }

    /**
     * Build a stable census of award groups for source-of-truth comparisons.
     *
     * Credits are part of the award identity. Keeping Name and Nominees in the
     * signature prevents a repair or import from silently flattening official
     * role prose or dropping individually linked people.
     */
    private function finalize_award_group_census($groups, $rows, $winners) {
        ksort($groups, SORT_STRING);
        $signature_rows = array();

        foreach ($groups as $key => $counts) {
            $signature_rows[] = $key . "\x1f" . intval($counts['rows'] ?? 0) . "\x1f" . intval($counts['winners'] ?? 0);
        }

        return array(
            'available' => true,
            'rows' => intval($rows),
            'winners' => intval($winners),
            'groups' => $groups,
            'signature' => hash('sha256', implode("\n", $signature_rows)),
        );
    }

    private function get_award_group_integrity_key($row) {
        $fields = array('ceremony', 'year', 'class', 'canonical_category', 'category', 'film', 'film_id');
        $values = array();

        foreach ($fields as $field) {
            $values[] = trim((string) ($row[$field] ?? ''));
        }

        foreach (array('name', 'nominees') as $credit_field) {
            $credit_value = trim((string) ($row[$credit_field] ?? ''));
            $values[] = (string) preg_replace('/\s+/u', ' ', $credit_value);
        }

        $id_blob = (string) ($row['film_id'] ?? '') . ' ' . (string) ($row['nominee_ids'] ?? '');
        preg_match_all('/(?:tt|nm|co)\d{5,10}/i', strtolower($id_blob), $matches);
        $entity_ids = array_values(array_unique((array) ($matches[0] ?? array())));
        sort($entity_ids, SORT_STRING);
        $values[] = implode(',', $entity_ids);
        $values[] = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) ($row['detail'] ?? '')));

        return implode("\x1f", $values);
    }

    private function get_bundled_award_group_census() {
        if (!defined('AAT_BUNDLED_CSV_PATH') || !is_readable(AAT_BUNDLED_CSV_PATH)) {
            return array(
                'available' => false,
                'error' => __('The bundled Oscars dataset is missing or unreadable.', 'academy-awards-table'),
            );
        }

        try {
            $file = new SplFileObject(AAT_BUNDLED_CSV_PATH, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $file->setCsvControl("\t");
            $headers = $file->fgetcsv();
            if (!is_array($headers) || empty($headers)) {
                throw new RuntimeException(__('The bundled Oscars dataset header could not be read.', 'academy-awards-table'));
            }

            $headers = array_map('trim', $headers);
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', (string) $headers[0]);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $groups = array();
            $rows = 0;
            $winners = 0;

            while (!$file->eof()) {
                $values = $file->fgetcsv();
                if (!is_array($values) || (count($values) === 1 && $values[0] === null)) {
                    continue;
                }
                if (count($values) < count($headers)) {
                    $values = array_pad($values, count($headers), '');
                }
                if (count($values) !== count($headers)) {
                    continue;
                }

                $source_row = array_combine($headers, $values);
                if (!is_array($source_row)) {
                    continue;
                }

                $row = $this->build_import_db_row($source_row);
                $key = $this->get_award_group_integrity_key($row);
                if (!isset($groups[$key])) {
                    $groups[$key] = array('rows' => 0, 'winners' => 0);
                }
                $groups[$key]['rows']++;
                $groups[$key]['winners'] += !empty($row['winner']) ? 1 : 0;
                $rows++;
                $winners += !empty($row['winner']) ? 1 : 0;
            }

            return $this->finalize_award_group_census($groups, $rows, $winners);
        } catch (Throwable $error) {
            return array(
                'available' => false,
                'error' => $error->getMessage(),
            );
        }
    }

    private function get_database_award_group_census($awards_table) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, detail, winner
             FROM $awards_table",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array(
                'available' => false,
                'error' => __('The live awards census query failed.', 'academy-awards-table'),
            );
        }

        $groups = array();
        $total_rows = 0;
        $total_winners = 0;
        foreach ($rows as $row) {
            $key = $this->get_award_group_integrity_key($row);
            if (!isset($groups[$key])) {
                $groups[$key] = array('rows' => 0, 'winners' => 0);
            }
            $groups[$key]['rows']++;
            $groups[$key]['winners'] += !empty($row['winner']) ? 1 : 0;
            $total_rows++;
            $total_winners += !empty($row['winner']) ? 1 : 0;
        }

        return $this->finalize_award_group_census($groups, $total_rows, $total_winners);
    }

    /**
     * Read-only integrity summary for the Lunara Control Desk.
     *
     * This reports current database health without writing, repairing, or caching anything.
     */
    public function get_lunara_integrity_summary($limit = 8) {
        global $wpdb;

        $limit        = max(1, min(20, intval($limit)));
        $awards_table = $wpdb->prefix . 'academy_awards';
        $poster_table = $wpdb->prefix . 'aat_posters';
        $checks       = array();
        $samples      = array();
        $routes       = array();

        $awards_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $awards_table)) === $awards_table);
        $poster_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $poster_table)) === $poster_table);

        if (!$awards_exists) {
            return array(
                'version' => AAT_VERSION,
                'checks'  => array(
                    array(
                        'label' => __('Awards table', 'academy-awards-table'),
                        'value' => __('Missing', 'academy-awards-table'),
                        'state' => 'needs',
                        'note'  => __('The Academy Awards data table was not found.', 'academy-awards-table'),
                    ),
                ),
                'samples' => array(),
                'routes'  => array(),
            );
        }

        $facts_table = $this->get_award_facts_table_name();
        $nominees_table = $this->get_award_nominees_table_name();
        $entities_table = $this->get_entities_table_name();
        $entity_stats_table = $this->get_entity_stats_table_name();
        $category_stats_table = $this->get_category_stats_table_name();
        $ceremony_stats_table = $this->get_ceremony_stats_table_name();
        $facts_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $facts_table)) === $facts_table);
        $nominees_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nominees_table)) === $nominees_table);
        $entities_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entities_table)) === $entities_table);
        $entity_stats_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $entity_stats_table)) === $entity_stats_table);
        $category_stats_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $category_stats_table)) === $category_stats_table);
        $ceremony_stats_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ceremony_stats_table)) === $ceremony_stats_table);
        $reporting_insert_failures = get_option('aat_reporting_insert_failures', array());
        $reporting_schema_failures = get_option('aat_reporting_schema_migration_failures', array());
        $reporting_insert_failure_total = intval($reporting_insert_failures['total'] ?? 0);
        $reporting_schema_failure_total = count((array) ($reporting_schema_failures['failures'] ?? array()));
        $reporting_rows = 0;
        $reporting_winners = 0;
        $reporting_missing_rows_total = 0;
        $reporting_orphan_rows_total = 0;
        $reporting_content_mismatch_total = 0;
        $reporting_nominee_rows = 0;
        $expected_nominee_rows = 0;
        $reporting_nominee_count_drift_total = 0;
        $reporting_nominee_drift_total = 0;
        $reporting_entity_stats_drift_total = 0;
        $reporting_missing_rows = array();
        $reporting_drift_rows = array();

        if ($facts_exists) {
            $reporting_rows = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table"));
            $reporting_winners = intval($wpdb->get_var("SELECT COUNT(*) FROM $facts_table WHERE winner = 1"));
            $reporting_missing_rows_total = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $awards_table awards
                     LEFT JOIN $facts_table facts ON facts.source_award_id = awards.id
                     WHERE facts.id IS NULL"
                )
            );
            $reporting_missing_rows = (array) $wpdb->get_results(
                "SELECT awards.id, awards.ceremony, awards.canonical_category, awards.film, awards.winner
                 FROM $awards_table awards
                 LEFT JOIN $facts_table facts ON facts.source_award_id = awards.id
                 WHERE facts.id IS NULL
                 ORDER BY awards.ceremony ASC LIMIT $limit",
                ARRAY_A
            );
            $reporting_orphan_rows_total = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $facts_table facts
                     LEFT JOIN $awards_table awards ON awards.id = facts.source_award_id
                     WHERE awards.id IS NULL"
                )
            );
            $reporting_content_mismatch_total = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $facts_table facts
                     INNER JOIN $awards_table awards ON awards.id = facts.source_award_id
                     WHERE facts.ceremony != awards.ceremony
                        OR facts.year_label != awards.year
                        OR facts.winner != awards.winner"
                )
            );
            $reporting_drift_rows = (array) $wpdb->get_results(
                "SELECT facts.source_award_id, awards.ceremony AS source_ceremony,
                        facts.ceremony AS projected_ceremony, awards.winner AS source_winner,
                        facts.winner AS projected_winner, awards.canonical_category, awards.film
                 FROM $facts_table facts
                 LEFT JOIN $awards_table awards ON awards.id = facts.source_award_id
                 WHERE awards.id IS NULL
                    OR facts.ceremony != awards.ceremony
                    OR facts.year_label != awards.year
                    OR facts.winner != awards.winner
                 ORDER BY facts.source_award_id ASC LIMIT $limit",
                ARRAY_A
            );
        }

        $expected_nominee_count_sql = "CASE
            WHEN TRIM(COALESCE(awards.nominee_ids, '')) = '' THEN 0
            ELSE 1
                + LENGTH(REPLACE(awards.nominee_ids, ',', '|'))
                - LENGTH(REPLACE(REPLACE(awards.nominee_ids, ',', '|'), '|', ''))
        END";
        $expected_nominee_rows = intval(
            $wpdb->get_var("SELECT COALESCE(SUM($expected_nominee_count_sql), 0) FROM $awards_table awards")
        );

        if ($nominees_exists) {
            $reporting_nominee_rows = intval($wpdb->get_var("SELECT COUNT(*) FROM $nominees_table"));
            $reporting_nominee_count_drift_total = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM (
                        SELECT awards.id
                        FROM $awards_table awards
                        LEFT JOIN $nominees_table nominees ON nominees.source_award_id = awards.id
                        GROUP BY awards.id, awards.nominee_ids
                        HAVING COUNT(nominees.id) != $expected_nominee_count_sql
                    ) nominee_count_drift"
                )
            );
            $reporting_nominee_drift_total = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $nominees_table nominees
                     LEFT JOIN $awards_table awards ON awards.id = nominees.source_award_id
                     WHERE awards.id IS NULL
                        OR nominees.ceremony != awards.ceremony
                        OR nominees.winner != awards.winner
                        OR FIND_IN_SET(
                            nominees.entity_id,
                            REPLACE(REPLACE(COALESCE(awards.nominee_ids, ''), ' ', ''), '|', ',')
                        ) = 0"
                )
            );
            $reporting_nominee_drift_total += $reporting_nominee_count_drift_total;
        }

        if ($entities_exists && $entity_stats_exists) {
            $orphan_stats = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $entity_stats_table stats
                     LEFT JOIN $entities_table entities ON entities.entity_id = stats.entity_id
                     WHERE entities.entity_id IS NULL"
                )
            );
            $missing_stats = intval(
                $wpdb->get_var(
                    "SELECT COUNT(*) FROM $entities_table entities
                     LEFT JOIN $entity_stats_table stats ON stats.entity_id = entities.entity_id
                     WHERE stats.entity_id IS NULL"
                )
            );
            $content_stats = 0;
            if ($facts_exists && $nominees_exists) {
                $content_stats = intval(
                    $wpdb->get_var(
                        "SELECT COUNT(*) FROM $entity_stats_table stats
                         INNER JOIN $entities_table entities ON entities.entity_id = stats.entity_id
                         LEFT JOIN (
                            SELECT entity_id,
                                   COUNT(*) AS nominations,
                                   COALESCE(SUM(winner), 0) AS wins,
                                   COUNT(DISTINCT ceremony) AS ceremonies,
                                   MIN(ceremony) AS first_ceremony,
                                   MAX(ceremony) AS last_ceremony
                            FROM (
                                SELECT film_entity_id AS entity_id, ceremony, winner
                                FROM $facts_table
                                WHERE film_entity_id != ''
                                UNION ALL
                                SELECT entity_id, ceremony, winner
                                FROM $nominees_table
                                WHERE entity_id != ''
                            ) projection_rows
                            GROUP BY entity_id
                         ) expected ON expected.entity_id = stats.entity_id
                         WHERE expected.entity_id IS NULL
                            OR stats.entity_type != entities.entity_type
                            OR stats.label != entities.label
                            OR stats.nominations != expected.nominations
                            OR stats.wins != expected.wins
                            OR stats.ceremonies != expected.ceremonies
                            OR stats.first_ceremony != expected.first_ceremony
                            OR stats.last_ceremony != expected.last_ceremony"
                    )
                );
            }
            $reporting_entity_stats_drift_total = $orphan_stats + $missing_stats + $content_stats;
        }

        $total_rows          = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table"));
        $source_winner_rows  = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE winner = 1"));
        $latest_ceremony     = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $awards_table"));
        $category_count      = intval($wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $awards_table WHERE canonical_category != ''"));
        $title_id_rows       = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film_id REGEXP '(^|\\\\|)tt[0-9]{7,9}(\\\\||$)'"));
        $missing_title_ids   = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film != '' AND (film_id IS NULL OR film_id = '')"));
        $invalid_title_ids   = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film_id != '' AND film_id NOT REGEXP '^(tt[0-9]{7,9})(\\\\|tt[0-9]{7,9})*$'"));
        $nominee_rows        = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE nominees != ''"));
        $missing_nominee_ids = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE nominees != '' AND (nominee_ids IS NULL OR nominee_ids = '')"));
        $bundled_census      = $this->get_bundled_award_group_census();
        $database_census     = $this->get_database_award_group_census($awards_table);
        $bundled_parity      = !empty($bundled_census['available'])
            && !empty($database_census['available'])
            && intval($bundled_census['rows'] ?? 0) === intval($database_census['rows'] ?? 0)
            && intval($bundled_census['winners'] ?? 0) === intval($database_census['winners'] ?? 0)
            && hash_equals((string) ($bundled_census['signature'] ?? ''), (string) ($database_census['signature'] ?? ''));
        $bundled_group_differences = array();
        $bundled_group_difference_total = 0;
        $reporting_rollup_drift_total = 0;

        if (!empty($bundled_census['available']) && !empty($database_census['available'])) {
            $all_group_keys = array_unique(array_merge(
                array_keys((array) ($bundled_census['groups'] ?? array())),
                array_keys((array) ($database_census['groups'] ?? array()))
            ));
            foreach ($all_group_keys as $group_key) {
                $expected = (array) (($bundled_census['groups'] ?? array())[$group_key] ?? array('rows' => 0, 'winners' => 0));
                $actual = (array) (($database_census['groups'] ?? array())[$group_key] ?? array('rows' => 0, 'winners' => 0));
                if ($expected === $actual) {
                    continue;
                }

                $bundled_group_difference_total++;
                if (count($bundled_group_differences) < $limit) {
                    $parts = explode("\x1f", $group_key);
                    $bundled_group_differences[] = array(
                        'label' => __('Bundled dataset drift', 'academy-awards-table'),
                        'detail' => sprintf(
                            __('Ceremony %1$d / %2$s / %3$s / expected %4$d rows, %5$d winners / live %6$d rows, %7$d winners', 'academy-awards-table'),
                            intval($parts[0] ?? 0),
                            (string) ($parts[3] ?? ''),
                            (string) ($parts[5] ?? ''),
                            intval($expected['rows'] ?? 0),
                            intval($expected['winners'] ?? 0),
                            intval($actual['rows'] ?? 0),
                            intval($actual['winners'] ?? 0)
                        ),
                    );
                }
            }
        }

        if (!$facts_exists) {
            $reporting_rollup_drift_total++;
        } else {
            if (!$category_stats_exists) {
                $reporting_rollup_drift_total++;
            } else {
                $category_expected_sql = "SELECT category_slug,
                                                 COUNT(*) AS nominations,
                                                 COALESCE(SUM(winner), 0) AS wins,
                                                 COUNT(DISTINCT ceremony) AS ceremonies,
                                                 MIN(ceremony) AS first_ceremony,
                                                 MAX(ceremony) AS last_ceremony
                                          FROM $facts_table
                                          WHERE category_slug != ''
                                          GROUP BY category_slug";
                $reporting_rollup_drift_total += intval(
                    $wpdb->get_var(
                        "SELECT COUNT(*) FROM $category_stats_table stats
                         LEFT JOIN ($category_expected_sql) expected ON expected.category_slug = stats.category_slug
                         WHERE expected.category_slug IS NULL
                            OR stats.nominations != expected.nominations
                            OR stats.wins != expected.wins
                            OR stats.ceremonies != expected.ceremonies
                            OR stats.first_ceremony != expected.first_ceremony
                            OR stats.last_ceremony != expected.last_ceremony"
                    )
                );
                $reporting_rollup_drift_total += intval(
                    $wpdb->get_var(
                        "SELECT COUNT(*) FROM ($category_expected_sql) expected
                         LEFT JOIN $category_stats_table stats ON stats.category_slug = expected.category_slug
                         WHERE stats.category_slug IS NULL"
                    )
                );
            }

            if (!$ceremony_stats_exists) {
                $reporting_rollup_drift_total++;
            } else {
                $ceremony_expected_sql = "SELECT ceremony,
                                                 MAX(year_label) AS year_label,
                                                 COUNT(*) AS nominations,
                                                 COALESCE(SUM(winner), 0) AS wins,
                                                 COUNT(DISTINCT category_slug) AS categories_total,
                                                 COUNT(DISTINCT CASE WHEN winner = 1 THEN category_slug ELSE NULL END) AS winner_categories
                                          FROM $facts_table
                                          GROUP BY ceremony";
                $reporting_rollup_drift_total += intval(
                    $wpdb->get_var(
                        "SELECT COUNT(*) FROM $ceremony_stats_table stats
                         LEFT JOIN ($ceremony_expected_sql) expected ON expected.ceremony = stats.ceremony
                         WHERE expected.ceremony IS NULL
                            OR stats.year_label != expected.year_label
                            OR stats.nominations != expected.nominations
                            OR stats.wins != expected.wins
                            OR stats.categories_total != expected.categories_total
                            OR stats.winner_categories != expected.winner_categories"
                    )
                );
                $reporting_rollup_drift_total += intval(
                    $wpdb->get_var(
                        "SELECT COUNT(*) FROM ($ceremony_expected_sql) expected
                         LEFT JOIN $ceremony_stats_table stats ON stats.ceremony = expected.ceremony
                         WHERE stats.ceremony IS NULL"
                    )
                );
            }
        }

        $reporting_ready = $facts_exists
            && $nominees_exists
            && $entities_exists
            && $entity_stats_exists
            && $category_stats_exists
            && $ceremony_stats_exists
            && $reporting_rows === $total_rows
            && $reporting_winners === $source_winner_rows
            && $reporting_missing_rows_total === 0
            && $reporting_orphan_rows_total === 0
            && $reporting_content_mismatch_total === 0
            && $reporting_nominee_rows === $expected_nominee_rows
            && $reporting_nominee_count_drift_total === 0
            && $reporting_nominee_drift_total === 0
            && $reporting_entity_stats_drift_total === 0
            && $reporting_rollup_drift_total === 0
            && $reporting_insert_failure_total === 0
            && $reporting_schema_failure_total === 0;

        $poster_count                 = 0;
        $missing_poster_attachments   = 0;
        $invalid_poster_ids           = 0;
        $poster_ratio_watch_count     = 0;
        $poster_metadata_watch_count  = 0;
        $poster_records_checked_count = 0;
        $poster_attachment_samples    = array();
        $poster_ratio_samples         = array();

        if ($poster_exists) {
            $poster_count               = intval($wpdb->get_var("SELECT COUNT(*) FROM $poster_table"));
            $invalid_poster_ids         = intval($wpdb->get_var("SELECT COUNT(*) FROM $poster_table WHERE imdb_id NOT REGEXP '^tt[0-9]{7,9}$'"));
            $missing_poster_attachments = intval($wpdb->get_var("SELECT COUNT(*) FROM $poster_table p LEFT JOIN {$wpdb->posts} posts ON posts.ID = p.attachment_id WHERE p.attachment_id > 0 AND posts.ID IS NULL"));
            $poster_rows                = $wpdb->get_results("SELECT imdb_id, attachment_id FROM $poster_table WHERE attachment_id > 0 ORDER BY updated_at DESC LIMIT 500", ARRAY_A);

            if (is_array($poster_rows)) {
                foreach ($poster_rows as $poster_row) {
                    $poster_records_checked_count++;
                    $attachment_id = isset($poster_row['attachment_id']) ? intval($poster_row['attachment_id']) : 0;
                    $metadata      = $attachment_id ? wp_get_attachment_metadata($attachment_id) : array();

                    if (empty($metadata['width']) || empty($metadata['height'])) {
                        $poster_metadata_watch_count++;
                        continue;
                    }

                    $ratio = floatval($metadata['width']) / max(1, floatval($metadata['height']));
                    if ($ratio < 0.58 || $ratio > 0.74) {
                        $poster_ratio_watch_count++;
                        if (count($poster_ratio_samples) < $limit) {
                            $poster_ratio_samples[] = array(
                                'label'  => __('Poster ratio flag', 'academy-awards-table'),
                                'detail' => sprintf(
                                    __('%1$s / attachment %2$d / %3$dx%4$d / ratio %5$.2f', 'academy-awards-table'),
                                    isset($poster_row['imdb_id']) ? strtolower((string) $poster_row['imdb_id']) : '',
                                    $attachment_id,
                                    intval($metadata['width']),
                                    intval($metadata['height']),
                                    $ratio
                                ),
                            );
                        }
                    }
                }
            }

            $poster_attachment_rows = $wpdb->get_results("SELECT p.imdb_id, p.attachment_id, p.source, p.updated_at, posts.ID AS attachment_exists FROM $poster_table p LEFT JOIN {$wpdb->posts} posts ON posts.ID = p.attachment_id WHERE (p.attachment_id > 0 AND posts.ID IS NULL) OR p.imdb_id NOT REGEXP '^tt[0-9]{7,9}$' ORDER BY p.updated_at DESC LIMIT $limit", ARRAY_A);
            if (is_array($poster_attachment_rows)) {
                foreach ($poster_attachment_rows as $row) {
                    $imdb_id       = isset($row['imdb_id']) ? strtolower(trim((string) $row['imdb_id'])) : '';
                    $attachment_id = isset($row['attachment_id']) ? intval($row['attachment_id']) : 0;
                    $source        = isset($row['source']) ? trim((string) $row['source']) : '';
                    $updated_at    = isset($row['updated_at']) ? trim((string) $row['updated_at']) : '';
                    $issue_label   = preg_match('/^tt[0-9]{7,9}$/', $imdb_id) ? __('Poster attachment missing', 'academy-awards-table') : __('Poster IMDb ID invalid', 'academy-awards-table');

                    $poster_attachment_samples[] = array(
                        'label'  => $issue_label,
                        'detail' => sprintf(
                            __('%1$s / attachment %2$d / source %3$s / updated %4$s', 'academy-awards-table'),
                            $imdb_id,
                            $attachment_id,
                            $source !== '' ? $source : __('unknown', 'academy-awards-table'),
                            $updated_at !== '' ? $updated_at : __('unknown', 'academy-awards-table')
                        ),
                    );
                }
            }
        }

        $review_post_type = $this->get_review_post_type();
        $review_meta_key  = $this->get_review_imdb_meta_key();
        $review_mappings  = intval(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value REGEXP '^tt[0-9]{7,9}$'",
                    $review_post_type,
                    $review_meta_key
                )
            )
        );
        $invalid_review_mappings = intval(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value != '' AND pm.meta_value NOT REGEXP '^tt[0-9]{7,9}$'",
                    $review_post_type,
                    $review_meta_key
                )
            )
        );

        $checks[] = array(
            'label' => __('Academy rows', 'academy-awards-table'),
            'value' => number_format_i18n($total_rows),
            'state' => $total_rows > 0 ? 'ready' : 'needs',
            'note'  => sprintf(__('Latest ceremony: %d; categories: %d.', 'academy-awards-table'), $latest_ceremony, $category_count),
        );
        $checks[] = array(
            'label' => __('Bundled data parity', 'academy-awards-table'),
            'value' => !empty($database_census['available'])
                ? sprintf(__('%1$s rows / %2$s winners', 'academy-awards-table'), number_format_i18n(intval($database_census['rows'] ?? 0)), number_format_i18n(intval($database_census['winners'] ?? 0)))
                : __('Census unavailable', 'academy-awards-table'),
            'state' => $bundled_parity ? 'ready' : 'needs',
            'note' => !empty($bundled_census['available'])
                ? sprintf(__('Expected %1$s rows / %2$s winners; %3$s award groups differ.', 'academy-awards-table'), number_format_i18n(intval($bundled_census['rows'] ?? 0)), number_format_i18n(intval($bundled_census['winners'] ?? 0)), number_format_i18n($bundled_group_difference_total))
                : (string) ($bundled_census['error'] ?? __('Bundled census unavailable.', 'academy-awards-table')),
        );
        $checks[] = array(
            'label' => __('Reporting derivation', 'academy-awards-table'),
            'value' => $facts_exists
                ? sprintf(
                    __('%1$s awards; %2$s/%3$s nominee links', 'academy-awards-table'),
                    number_format_i18n($reporting_rows),
                    number_format_i18n($reporting_nominee_rows),
                    number_format_i18n($expected_nominee_rows)
                )
                : __('Missing facts table', 'academy-awards-table'),
            'state' => $reporting_ready ? 'ready' : 'needs',
            'note'  => sprintf(
                __('%1$d missing; %2$d orphaned; %3$d stale facts; %4$d nominee drift across %5$d awards; %6$d entity-stat drift; %7$d rollup drift; %8$d insert failures; %9$d schema failures.', 'academy-awards-table'),
                $reporting_missing_rows_total,
                $reporting_orphan_rows_total,
                $reporting_content_mismatch_total,
                $reporting_nominee_drift_total,
                $reporting_nominee_count_drift_total,
                $reporting_entity_stats_drift_total,
                $reporting_rollup_drift_total,
                $reporting_insert_failure_total,
                $reporting_schema_failure_total
            ),
        );
        $checks[] = array(
            'label' => __('IMDb title IDs', 'academy-awards-table'),
            'value' => sprintf(__('%1$s rows with IDs', 'academy-awards-table'), number_format_i18n($title_id_rows)),
            'state' => ($missing_title_ids > 0 || $invalid_title_ids > 0) ? 'weak' : 'ready',
            'note'  => sprintf(__('%1$d missing; %2$d invalid.', 'academy-awards-table'), $missing_title_ids, $invalid_title_ids),
        );
        $checks[] = array(
            'label' => __('Nominee/person/company IDs', 'academy-awards-table'),
            'value' => sprintf(__('%1$d missing of %2$d nominee rows', 'academy-awards-table'), $missing_nominee_ids, $nominee_rows),
            'state' => $missing_nominee_ids > 0 ? 'weak' : 'ready',
            'note'  => __('Used for title, person, and company route opportunities.', 'academy-awards-table'),
        );
        $checks[] = array(
            'label' => __('Poster Library', 'academy-awards-table'),
            'value' => $poster_exists ? sprintf(__('%d records', 'academy-awards-table'), $poster_count) : __('Missing table', 'academy-awards-table'),
            'state' => (!$poster_exists || $invalid_poster_ids > 0 || $missing_poster_attachments > 0) ? 'weak' : 'ready',
            'note'  => sprintf(__('%1$d invalid IDs; %2$d missing attachments.', 'academy-awards-table'), $invalid_poster_ids, $missing_poster_attachments),
        );
        $checks[] = array(
            'label' => __('Poster ratio watch', 'academy-awards-table'),
            'value' => sprintf(__('%1$d flagged of %2$d checked', 'academy-awards-table'), $poster_ratio_watch_count, $poster_records_checked_count),
            'state' => ($poster_ratio_watch_count > 0 || $poster_metadata_watch_count > 0) ? 'weak' : 'ready',
            'note'  => sprintf(__('%d records have missing image metadata.', 'academy-awards-table'), $poster_metadata_watch_count),
        );
        $checks[] = array(
            'label' => __('Review-to-title mappings', 'academy-awards-table'),
            'value' => sprintf(__('%d valid review links', 'academy-awards-table'), $review_mappings),
            'state' => $invalid_review_mappings > 0 ? 'weak' : 'ready',
            'note'  => sprintf(__('%d invalid review IMDb IDs.', 'academy-awards-table'), $invalid_review_mappings),
        );

        $invalid_rows = $wpdb->get_results("SELECT ceremony, canonical_category, film, film_id FROM $awards_table WHERE film_id != '' AND film_id NOT REGEXP '^(tt[0-9]{7,9})(\\\\|tt[0-9]{7,9})*$' ORDER BY ceremony DESC LIMIT $limit", ARRAY_A);
        if (is_array($invalid_rows)) {
            foreach ($invalid_rows as $row) {
                $samples[] = array(
                    'label'  => __('Invalid film IMDb ID', 'academy-awards-table'),
                    'detail' => sprintf('%1$s / %2$s / %3$s (%4$s)', $row['ceremony'], $row['canonical_category'], $row['film'], $row['film_id']),
                );
            }
        }

        $missing_rows = $wpdb->get_results("SELECT ceremony, canonical_category, film FROM $awards_table WHERE film != '' AND (film_id IS NULL OR film_id = '') ORDER BY ceremony DESC LIMIT $limit", ARRAY_A);
        if (is_array($missing_rows)) {
            foreach ($missing_rows as $row) {
                $samples[] = array(
                    'label'  => __('Missing film IMDb ID', 'academy-awards-table'),
                    'detail' => sprintf('%1$s / %2$s / %3$s', $row['ceremony'], $row['canonical_category'], $row['film']),
                );
            }
        }

        foreach ($reporting_missing_rows as $row) {
            $samples[] = array(
                'label' => __('Reporting row missing', 'academy-awards-table'),
                'detail' => sprintf(
                    '#%1$d / ceremony %2$d / %3$s / %4$s%5$s',
                    intval($row['id'] ?? 0),
                    intval($row['ceremony'] ?? 0),
                    (string) ($row['canonical_category'] ?? ''),
                    (string) ($row['film'] ?? ''),
                    !empty($row['winner']) ? ' / WINNER' : ''
                ),
            );
        }

        foreach ($reporting_drift_rows as $row) {
            $samples[] = array(
                'label' => __('Reporting row stale', 'academy-awards-table'),
                'detail' => sprintf(
                    '#%1$d / ceremony %2$d -> %3$d / winner %4$d -> %5$d / %6$s / %7$s',
                    intval($row['source_award_id'] ?? 0),
                    intval($row['source_ceremony'] ?? 0),
                    intval($row['projected_ceremony'] ?? 0),
                    intval($row['source_winner'] ?? 0),
                    intval($row['projected_winner'] ?? 0),
                    (string) ($row['canonical_category'] ?? ''),
                    (string) ($row['film'] ?? '')
                ),
            );
        }

        foreach (array_slice((array) ($reporting_insert_failures['failures'] ?? array()), 0, $limit) as $failure) {
            $row = (array) ($failure['row'] ?? array());
            $samples[] = array(
                'label' => __('Reporting insert failed', 'academy-awards-table'),
                'detail' => sprintf(
                    '%1$s / source #%2$d / ceremony %3$d / %4$s',
                    (string) ($failure['table'] ?? ''),
                    intval($row['source_award_id'] ?? 0),
                    intval($row['ceremony'] ?? 0),
                    (string) ($failure['error'] ?? __('Unknown SQL error', 'academy-awards-table'))
                ),
            );
        }

        foreach (array_slice((array) ($reporting_schema_failures['failures'] ?? array()), 0, $limit) as $failure) {
            $samples[] = array(
                'label' => __('Reporting schema migration failed', 'academy-awards-table'),
                'detail' => sprintf(
                    '%1$s / %2$s / %3$s',
                    (string) ($failure['table'] ?? ''),
                    (string) ($failure['change'] ?? ''),
                    (string) ($failure['error'] ?? __('Unknown SQL error', 'academy-awards-table'))
                ),
            );
        }

        $samples = array_merge($bundled_group_differences, $samples, $poster_attachment_samples, $poster_ratio_samples);

        if ($latest_ceremony > 0) {
            $routes[] = array(
                'label' => sprintf(__('Ceremony %d', 'academy-awards-table'), $latest_ceremony),
                'url'   => $this->get_ceremony_url($latest_ceremony),
            );
        }

        $routes[] = array(
            'label' => __('Best Picture', 'academy-awards-table'),
            'url'   => $this->get_category_url('BEST PICTURE'),
        );

        $sample_title_id = (string) $wpdb->get_var("SELECT film_id FROM $awards_table WHERE film_id REGEXP '(^|\\\\|)tt[0-9]{7,9}(\\\\||$)' ORDER BY ceremony DESC LIMIT 1");
        if ($sample_title_id !== '') {
            $title_parts = preg_split('/\|/', $sample_title_id);
            $title_id    = is_array($title_parts) ? strtolower(trim((string) $title_parts[0])) : '';
            if (preg_match('/^tt\d{7,9}$/', $title_id)) {
                $routes[] = array(
                    'label' => __('Sample Title', 'academy-awards-table'),
                    'url'   => $this->build_entity_url_from_id($title_id),
                );
            }
        }

        $sample_person_ids = (string) $wpdb->get_var("SELECT nominee_ids FROM $awards_table WHERE nominee_ids REGEXP '(^|\\\\|)nm[0-9]{7,9}(\\\\||$)' ORDER BY ceremony DESC LIMIT 1");
        if ($sample_person_ids !== '') {
            $person_parts = preg_split('/\|/', $sample_person_ids);
            foreach ((array) $person_parts as $person_id) {
                $person_id = strtolower(trim((string) $person_id));
                if (preg_match('/^nm\d{7,9}$/', $person_id)) {
                    $routes[] = array(
                        'label' => __('Sample Person', 'academy-awards-table'),
                        'url'   => $this->build_entity_url_from_id($person_id),
                    );
                    break;
                }
            }
        }

        return array(
            'version' => AAT_VERSION,
            'checks'  => $checks,
            'samples' => array_slice($samples, 0, $limit),
            'routes'  => $routes,
            'poster_attachment_samples' => $poster_attachment_samples,
            'poster_ratio_samples'      => $poster_ratio_samples,
            'reporting_missing_rows_total' => $reporting_missing_rows_total,
            'reporting_orphan_rows_total' => $reporting_orphan_rows_total,
            'reporting_content_mismatch_total' => $reporting_content_mismatch_total,
            'reporting_nominee_rows' => $reporting_nominee_rows,
            'expected_nominee_rows' => $expected_nominee_rows,
            'reporting_nominee_count_drift_total' => $reporting_nominee_count_drift_total,
            'reporting_nominee_drift_total' => $reporting_nominee_drift_total,
            'reporting_entity_stats_drift_total' => $reporting_entity_stats_drift_total,
            'reporting_rollup_drift_total' => $reporting_rollup_drift_total,
            'reporting_insert_failures' => $reporting_insert_failures,
            'reporting_schema_failures' => $reporting_schema_failures,
            'bundled_data_parity' => array(
                'ready' => $bundled_parity,
                'expected_rows' => intval($bundled_census['rows'] ?? 0),
                'expected_winners' => intval($bundled_census['winners'] ?? 0),
                'database_rows' => intval($database_census['rows'] ?? 0),
                'database_winners' => intval($database_census['winners'] ?? 0),
                'different_groups' => $bundled_group_difference_total,
            ),
        );
    }

    /**
     * AJAX: Clear all data
     */
    public function ajax_clear_data() {
        check_ajax_referer('aat_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';
        $wpdb->query("TRUNCATE TABLE $table_name");
        $reporting_rebuild = $this->rebuild_reporting_tables();

        // Invalidate performance caches
        delete_transient('aat_records_total_v1');
        delete_transient('aat_records_total_v2');
        delete_transient('aat_total_stats_v2');
        delete_transient('aat_awards_meta_v1');

        /** Fires after all data is cleared. */
        do_action( 'aat_after_data_import', 'clear', 0 );

        wp_send_json_success(array(
            'message' => 'All data cleared.',
            'reporting_rebuild' => $reporting_rebuild,
        ));
    }
}

require_once AAT_PLUGIN_DIR . 'includes/class-aat-blocks.php';

if ( ! function_exists( 'aat_search_entities' ) ) {
    /**
     * Fast ledger search across films and people for the live palette.
     *
     * Reads aat_entity_stats only — one small precomputed table (label,
     * type, nominations, wins) — with prefix matches ranked above infix
     * and winners above nominees. Callers cache; this stays a single
     * bounded query.
     *
     * @param string $q     Query text (min 2 chars).
     * @param int    $limit Max rows (1–12).
     * @return array[] { label, type, nominations, wins, url }
     */
    function aat_search_entities( $q, $limit = 6 ) {
        global $wpdb;

        $q = trim( (string) $q );
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) < 2 : strlen( $q ) < 2 ) {
            return array();
        }
        $limit       = max( 1, min( 12, (int) $limit ) );
        $stats_table = $wpdb->prefix . 'aat_entity_stats';
        $like_infix  = '%' . $wpdb->esc_like( $q ) . '%';
        $like_prefix = $wpdb->esc_like( $q ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entity_id, entity_type, label, nominations, wins
                 FROM {$stats_table}
                 WHERE label LIKE %s
                 ORDER BY (label LIKE %s) DESC, wins DESC, nominations DESC, label ASC
                 LIMIT %d",
                $like_infix,
                $like_prefix,
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return array();
        }

        $plugin = class_exists( 'Academy_Awards_Table' ) ? Academy_Awards_Table::get_instance() : null;
        $out    = array();

        foreach ( $rows as $row ) {
            $url = $plugin ? (string) $plugin->get_entity_url( $row['entity_id'] ) : '';
            if ( '' === $url ) {
                continue;
            }

            $out[] = array(
                'label'       => (string) $row['label'],
                'type'        => (string) $row['entity_type'],
                'nominations' => (int) $row['nominations'],
                'wins'        => (int) $row['wins'],
                'url'         => $url,
            );
        }

        return $out;
    }
}

// Initialize plugin
Academy_Awards_Table::get_instance();
