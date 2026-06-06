<?php
/**
 * Plugin Name: Lunara Film - Academy Awards Database
 * Plugin URI: https://lunarafilm.com/oscars/
 * Description: A premium, server-side searchable database of every Academy Award nominee and winner (1st ceremony through 2025), compiled and maintained by Lunara Film.
 * Version: 2.7.9
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
define('AAT_VERSION', '2.7.9');
define('AAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AAT_BUNDLED_CSV_PATH', AAT_PLUGIN_DIR . 'data/oscars.csv');
if (!defined('AAT_TMDB_API_KEY')) {
    define('AAT_TMDB_API_KEY', 'b17bcb1a2b1a44a50898eaf079bcdede');
}

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

        update_option('aat_db_version', AAT_VERSION);

        // Keep rewrites healthy on upgrades
        $rewrite_version = get_option('aat_rewrite_version', '0');
        if (version_compare((string) $rewrite_version, AAT_VERSION, '<')) {
            $this->register_rewrite_rules();
            flush_rewrite_rules();
            update_option('aat_rewrite_version', AAT_VERSION, false);
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
        $table_name = $this->get_table_name();
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
            "SELECT canonical_category, category, film, film_id, winner FROM $table_name WHERE ceremony = %d AND film_id <> '' ORDER BY winner DESC, canonical_category ASC, film ASC",
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
            $r = $this->apply_row_hotfixes($r);
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
        $table_name = $this->get_table_name();
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
            "SELECT ceremony, year, film, film_id, winner FROM $table_name WHERE canonical_category = %s AND film_id <> '' ORDER BY ceremony DESC, winner DESC, film ASC",
            $canonical_category
        ), ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $out = array();
        $seen = array();
        foreach ($rows as $r) {
            $r = $this->apply_row_hotfixes($r);
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

        $table_name = $this->get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS nominations, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS wins, COUNT(DISTINCT ceremony) AS ceremonies, MIN(ceremony) AS first_ceremony, MAX(ceremony) AS last_ceremony FROM $table_name WHERE canonical_category = %s",
                $canonical_category
            ),
            ARRAY_A
        );

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

        $table_name = $this->get_table_name();
        $ceremony = intval($ceremony);
        if ($ceremony <= 0) {
            return array();
        }

        $cache_key = 'aat_ceremony_rollup_v2_' . $ceremony;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ceremony, year, canonical_category, category, film, film_id, name, nominees, nominee_ids, winner, detail, note FROM $table_name WHERE ceremony = %d AND canonical_category != '' ORDER BY canonical_category ASC, winner DESC, film ASC, name ASC",
                $ceremony
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

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

        $table_name = $this->get_table_name();
        $fields = $this->get_awards_row_fields_sql();
        $sql = "SELECT DISTINCT $fields FROM $table_name WHERE canonical_category = %s ORDER BY ceremony DESC, winner DESC, film ASC, name ASC";
        $sql = $wpdb->prepare($sql, $canonical_category);

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            set_transient($cache_key, $empty, 30 * MINUTE_IN_SECONDS);
            return $empty;
        }

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

        $entity_template = AAT_PLUGIN_DIR . 'templates/entity-page.php';
        if (file_exists($entity_template)) {
            return $entity_template;
        }

        return $template;
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

        if ($film === 'Train Dreams' && trim((string) ($row['film_id'] ?? '')) === 'tt16277242') {
            $row['film_id'] = 'tt29768334';
        }

        if ($film === 'Avatar: Fire and Ash' && trim((string) ($row['film_id'] ?? '')) === 'tt13651794') {
            $row['film_id'] = 'tt1757678';
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

        foreach ( array_filter(array_map('trim', explode('|', $raw_ids)), 'strlen') as $part ) {
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
            $raw_nominees = $row['nominees'];

            $row['name'] = $this->strip_screenplay_credit_prefixes($row['name']);

            if ($row['nominees'] !== '') {
                $row['nominees'] = $this->screenplay_credit_to_pipe_list($row['nominees']);
            }

            if ($row['nominees'] === '' && $raw_name !== '') {
                $row['nominees'] = $this->screenplay_credit_to_pipe_list($raw_name);
            }

            if ($row['nominees'] === '' && $raw_nominees !== '') {
                $row['nominees'] = $this->screenplay_credit_to_pipe_list($raw_nominees);
            }

            if ($row['nominees'] !== '') {
                $row['name'] = $this->humanize_pipe_list($row['nominees']);
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
     * Curated screenplay nominee overrides from Lunara tracker research.
     */
    private function get_screenplay_nominee_overrides() {
        static $overrides = null;

        if (is_array($overrides)) {
            return $overrides;
        }

        $rows = array(
            array('97', 'WRITING (Adapted Screenplay)', 'Emilia Pérez', 'Jacques Audiard and Thomas Bidegain', 'Jacques Audiard|Thomas Bidegain', 'nm0002191|nm0081179'),
            array('97', 'WRITING (Original Screenplay)', 'September 5', 'Moritz Binder', 'Moritz Binder', 'nm9415549'),
            array('95', 'WRITING (Adapted Screenplay)', 'All Quiet on the Western Front', 'Edward Berger', 'Edward Berger', 'nm0074163'),
            array('94', 'WRITING (Original Screenplay)', 'The Worst Person in the World', 'Eskil Vogt and Joachim Trier', 'Eskil Vogt|Joachim Trier', 'nm1258777|nm0877285'),
            array('91', 'WRITING (Original Screenplay)', 'Green Book', 'Nick Vallelonga', 'Nick Vallelonga', 'nm0885014'),
            array('90', 'WRITING (Original Screenplay)', 'Three Billboards Outside Ebbing, Missouri', 'Martin McDonagh', 'Martin McDonagh', 'nm1732981'),
            array('82', 'WRITING (Adapted Screenplay)', 'In the Loop', 'Jesse Armstrong', 'Jesse Armstrong', 'nm1104036'),
            array('82', 'WRITING (Original Screenplay)', 'Up', 'Bob Peterson', 'Bob Peterson', 'nm0677037'),
            array('78', 'WRITING (Original Screenplay)', 'Good Night, and Good Luck.', 'George Clooney and Grant Heslov', 'George Clooney|Grant Heslov', 'nm0000123|nm0381416'),
            array('73', 'WRITING (Adapted Screenplay)', 'Crouching Tiger, Hidden Dragon', 'Wang Hui-ling, James Schamus, and Tsai Kuo-jung', 'Wang Hui-ling|James Schamus|Tsai Kuo-jung', 'nm0910924|nm0770005|nm0874631'),
            array('73', 'WRITING (Adapted Screenplay)', 'O Brother, Where Art Thou?', 'Ethan Coen and Joel Coen', 'Ethan Coen|Joel Coen', 'nm0001053|nm0001054'),
            array('71', 'WRITING (Original Screenplay)', 'Life Is Beautiful', 'Vincenzo Cerami', 'Vincenzo Cerami', 'nm0148437'),
            array('71', 'WRITING (Original Screenplay)', 'Shakespeare in Love', 'Marc Norman', 'Marc Norman', 'nm0635565'),
            array('70', 'WRITING (Adapted Screenplay)', 'Wag the Dog', 'Hilary Henkin', 'Hilary Henkin', 'nm0377088'),
            array('70', 'WRITING (Original Screenplay)', 'As Good as It Gets', 'Mark Andrus', 'Mark Andrus', 'nm0029125'),
            array('70', 'WRITING (Original Screenplay)', 'Good Will Hunting', 'Ben Affleck', 'Ben Affleck', 'nm0000255'),
            array('67', 'WRITING (Original Screenplay)', 'Heavenly Creatures', 'Fran Walsh and Peter Jackson', 'Fran Walsh|Peter Jackson', 'nm0909638|nm0001392'),
            array('62', 'WRITING (Adapted Screenplay)', 'Enemies, A Love Story', 'Roger L. Simon and Paul Mazursky', 'Roger L. Simon|Paul Mazursky', 'nm0800363|nm0005196'),
            array('62', 'WRITING (Original Screenplay)', 'sex, lies, and videotape', 'Steven Soderbergh', 'Steven Soderbergh', 'nm0001752'),
            array('60', 'WRITING (Original Screenplay)', 'Au Revoir Les Enfants (Goodbye, Children)', 'Louis Malle', 'Louis Malle', 'nm0001501'),
            array('57', 'WRITING (Adapted Screenplay)', 'Greystoke: The Legend of Tarzan, Lord of the Apes', 'Robert Towne and Michael Austin', 'Robert Towne|Michael Austin', 'nm0001801|nm0042472'),
            array('56', 'WRITING (Adapted Screenplay)', 'Reuben, Reuben', 'Julius J. Epstein', 'Julius J. Epstein', 'nm0258493'),
            array('53', 'WRITING (Adapted Screenplay)', 'The Elephant Man', 'Christopher De Vore, Eric Bergren, and David Lynch', 'Christopher De Vore|Eric Bergren|David Lynch', 'nm0212246|nm0075015|nm0000186'),
            array('51', 'WRITING (Adapted Screenplay)', 'Same Time, Next Year', 'Bernard Slade', 'Bernard Slade', 'nm0805152'),
            array('50', 'WRITING (Adapted Screenplay)', 'Oh, God!', 'Larry Gelbart', 'Larry Gelbart', 'nm0312205'),
            array('49', 'WRITING (Original Screenplay)', 'Cousin, Cousine', 'Jean-Charles Tacchella and Daniele Thompson', 'Jean-Charles Tacchella|Daniele Thompson', 'nm0006621|nm0860019'),
            array('45', 'WRITING (Original Screenplay)', 'Lady Sings the Blues', 'Terence McCloy, Chris Clark, and Suzanne De Passe', 'Terence McCloy|Chris Clark|Suzanne De Passe', 'lnm-terence-mccloy|nm0163771|nm0210867'),
            array('42', 'WRITING (Adapted Screenplay)', 'Goodbye, Mr. Chips', 'Terence Rattigan', 'Terence Rattigan', 'nm0711905'),
            array('42', 'WRITING (Adapted Screenplay)', "They Shoot Horses, Don't They?", 'James Poe and Robert E. Thompson', 'James Poe|Robert E. Thompson', 'nm0688117|nm0860651'),
            array('41', 'WRITING (Adapted Screenplay)', 'Rachel, Rachel', 'Stewart Stern', 'Stewart Stern', 'nm0827856'),
            array('38', 'WRITING (Original Screenplay)', "Casanova '70", 'Agenore Incrocci', 'Agenore Incrocci', 'nm0408488'),
            array('37', 'WRITING (Original Screenplay)', 'One Potato, Two Potato', 'Raphael Hayes and Orville H. Hampton', 'Raphael Hayes|Orville H. Hampton', 'nm0371193|nm0359034'),
            array('37', 'WRITING (Original Screenplay)', 'The Organizer', 'Agenore Incrocci, Furio Scarpelli, and Mario Monicelli', 'Agenore Incrocci|Furio Scarpelli|Mario Monicelli', 'nm0408488|nm0769249|nm0598102'),
            array('36', 'WRITING (Adapted Screenplay)', 'Captain Newman, M.D.', 'Richard L. Breen, Phoebe Ephron, and Henry Ephron', 'Richard L. Breen|Phoebe Ephron|Henry Ephron', 'nm0106764|nm0258290|nm0258288'),
            array('34', 'WRITING (Original Screenplay)', 'Ballad of a Soldier', 'Valentin Yezhov', 'Valentin Yezhov', 'nm0947899'),
            array('33', 'WRITING (Original Screenplay)', 'Hiroshima mon amour', 'Marguerite Duras', 'Marguerite Duras', 'nm0243921'),
            array('30', 'WRITING (Adapted Screenplay)', 'Heaven Knows, Mr. Allison', 'John Huston and John Lee Mahin', 'John Huston|John Lee Mahin', 'nm0001379|nm0536941'),
            array('22', 'WRITING (Original Screenplay)', 'The Quiet One', 'James Agee, Helen Levitt, and Janice Loeb', 'James Agee|Helen Levitt|Janice Loeb', 'nm0012938|nm1844803|nm0517209'),
            array('21', 'WRITING (Adapted Screenplay)', 'Johnny Belinda', 'Irma von Cube and Allen Vincent', 'Irma von Cube|Allen Vincent', 'nm0902121|nm0898573'),
            array('18', 'WRITING (Original Screenplay)', 'What Next, Corporal Hargrove?', 'Harry Kurnitz', 'Harry Kurnitz', 'nm0475823'),
            array('14', 'WRITING (Original Screenplay)', 'Tall, Dark and Handsome', 'Karl Tunberg and Darrell Ware', 'Karl Tunberg|Darrell Ware', 'nm0876562|nm0912090'),
            array('14', 'WRITING (Original Screenplay)', 'Tom, Dick and Harry', 'Paul Jarrico', 'Paul Jarrico', 'nm0418972'),
            array('12', 'WRITING (Adapted Screenplay)', 'Goodbye, Mr. Chips', 'R.C. Sherriff, Claudine West, and Eric Maschwitz', 'R.C. Sherriff|Claudine West|Eric Maschwitz', 'nm0792670|nm0921995|nm0556178'),
            array('11', 'WRITING (Adapted Screenplay)', 'Pygmalion', 'George Bernard Shaw, W. P. Lipscomb, and Cecil Lewis', 'George Bernard Shaw|W. P. Lipscomb|Cecil Lewis', 'nm0789737|nm0513744|nm0507021'),
            array('10', 'WRITING (Adapted Screenplay)', 'Stage Door', 'Morrie Ryskind and Anthony Veiller', 'Morrie Ryskind|Anthony Veiller', 'nm0753452|nm0892044'),
            array('4', 'WRITING (Adapted Screenplay)', 'Little Caesar', 'Francis Faragoh', 'Francis Faragoh', 'nm0267020'),
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
        $overrides = $this->get_single_craft_nominee_overrides();
        $updated = 0;
        $resolved_ids = 0;
        $remote_resolved_ids = 0;

        foreach ((array) $rows as $row) {
            $normalized = $this->normalize_awards_row($row);
            $original_nominee_ids = trim((string) ($normalized['nominee_ids'] ?? ''));
            $override_key = intval($normalized['ceremony'] ?? 0) . '|' . strtoupper(trim((string) ($normalized['canonical_category'] ?? ''))) . '|' . strtolower(trim((string) ($normalized['film'] ?? '')));
            $screenplay_overrides = $this->get_screenplay_nominee_overrides();

            if (isset($screenplay_overrides[$override_key])) {
                $override = $screenplay_overrides[$override_key];
                $normalized['name'] = (string) ($override['name'] ?? $normalized['name'] ?? '');
                $normalized['nominees'] = (string) ($override['nominees'] ?? $normalized['nominees'] ?? '');
                $normalized['nominee_ids'] = (string) ($override['nominee_ids'] ?? $normalized['nominee_ids'] ?? '');
                if ($original_nominee_ids === '' && !empty($normalized['nominee_ids'])) {
                    $resolved_ids++;
                }
            }

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
        $table_name = $wpdb->prefix . 'academy_awards';
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
        $sql = $wpdb->prepare("SELECT MIN(year) FROM $table_name WHERE ceremony = %d", $ceremony);
        $year = $wpdb->get_var($sql);
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
        $table_name = $wpdb->prefix . 'academy_awards';
        $cached = get_transient('aat_max_ceremony_v1');
        if ($cached !== false) {
            return intval($cached);
        }
        $max = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $table_name"));
        set_transient('aat_max_ceremony_v1', $max, 6 * HOUR_IN_SECONDS);
        return $max;
    }

    /**
     * Get the latest year label in the dataset (based on max ceremony).
     */
    public function get_latest_year_label() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';
        $cached = get_transient('aat_latest_year_label_v1');
        if ($cached !== false) {
            return is_string($cached) ? $cached : '';
        }
        $row = $wpdb->get_row("SELECT year FROM $table_name ORDER BY ceremony DESC, id DESC LIMIT 1", ARRAY_A);
        $year = is_array($row) ? (string) ($row['year'] ?? '') : '';
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
            global $wpdb;
            $table_name = $wpdb->prefix . 'academy_awards';
            $cats = $wpdb->get_col("SELECT DISTINCT canonical_category FROM $table_name WHERE canonical_category != '' ORDER BY canonical_category ASC");
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
        $table_name = $wpdb->prefix . 'academy_awards';

        $entity = sanitize_text_field($entity);
        $id = sanitize_text_field($id);

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

        $field = ($entity === 'title') ? 'film_id' : 'nominee_ids';

        $values = array();
        $where = $this->build_pipe_match_where($field, $id, $values);

        $fields = $this->get_awards_row_fields_sql();
        $sql = "SELECT DISTINCT $fields FROM $table_name WHERE $where ORDER BY ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC";
        $sql = $wpdb->prepare($sql, $values);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        foreach ($rows as $index => $row) {
            $rows[$index] = $this->normalize_awards_row($row);
        }

        set_transient($cache_key, $rows, HOUR_IN_SECONDS);
        return $rows;
    }

    /**
     * Determine a display name for an entity using the dataset.
     */
    public function get_entity_display_name($entity, $id) {
        $cache_key = 'aat_entity_label_' . md5($entity . ':' . $id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (string) $cached;
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
                    has_shortcode($post->post_content, 'academy_awards') ||
                    has_shortcode($post->post_content, 'lunara_awards_tracker') ||
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
        $allowed_pages = array('academy-awards-table', 'academy-awards-tracker', 'academy-awards-posters');

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
        $categories = $wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $table_name WHERE canonical_category != ''");
        $years = $wpdb->get_var("SELECT COUNT(DISTINCT year) FROM $table_name WHERE year != ''");

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

        $ceremonies = $wpdb->get_col("SELECT DISTINCT ceremony FROM $awards_table ORDER BY ceremony DESC");
        $categories = $wpdb->get_col("SELECT DISTINCT canonical_category FROM $awards_table WHERE canonical_category != '' ORDER BY canonical_category ASC");

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

        global $wpdb;
        $poster_table = $wpdb->prefix . 'aat_posters';

        $total_posters = intval($wpdb->get_var("SELECT COUNT(*) FROM $poster_table"));
        $rows = $wpdb->get_results("SELECT * FROM $poster_table ORDER BY updated_at DESC LIMIT 500", ARRAY_A);
        if (!is_array($rows)) $rows = array();

        include AAT_PLUGIN_DIR . 'templates/poster-admin.php';
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
        global $wpdb;
        $awards_table = $wpdb->prefix . 'academy_awards';
        $ceremonies = $wpdb->get_col("SELECT DISTINCT ceremony FROM $awards_table ORDER BY ceremony DESC");
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

        global $wpdb;
        $awards_table = $wpdb->prefix . 'academy_awards';
        $ceremonies = $wpdb->get_col("SELECT DISTINCT ceremony FROM $awards_table ORDER BY ceremony DESC");
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
        return '';
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
public function get_tmdb_data_for_imdb_id( $imdb_id ) {
    $imdb_id = strtolower( trim( (string) $imdb_id ) );
    if ( ! preg_match('/^tt\d+$/', $imdb_id) ) {
        return array();
    }

    $cache_key = 'aat_tmdb_v243_' . $imdb_id;
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
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
public function get_tmdb_person_data_for_imdb_id($imdb_id) {
    $imdb_id = strtolower(trim((string) $imdb_id));
    if (!preg_match('/^nm\d+$/', $imdb_id)) {
        return array();
    }

    $cache_key = 'aat_tmdb_person_v2_' . $imdb_id;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
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

public function get_title_visual_package($tt, $size = 'large') {
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

    $tmdb = $this->get_tmdb_data_for_imdb_id($tt);
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
public function get_person_visual_package($nm_id, $size = 'large') {
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
    );

    $tmdb = $this->get_tmdb_person_data_for_imdb_id($nm_id);
    if (is_array($tmdb) && !empty($tmdb)) {
        $out['tmdb'] = $tmdb;
        if (!empty($tmdb['profile_full'])) {
            $out['portrait_url'] = (string) $tmdb['profile_full'];
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
    $backdrop_style = !empty($out['backdrop_url']) ? ' style="background-image: linear-gradient(180deg, rgba(4,11,22,.28), rgba(4,11,22,.82)), url(' . esc_url($out['backdrop_url']) . '); background-size: cover; background-position: center;"' : '';
    $out['fallback_html'] = '<div class="aat-entity-poster-fallback aat-person-portrait-fallback"' . $backdrop_style . '><div class="aat-fallback-inner"><div class="aat-fallback-kicker">LUNARA FILM</div><div class="aat-fallback-title">' . esc_html($display_name) . '</div>' . $meta_line . '</div></div>';

    return $out;
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
            $wpdb->prepare("SELECT nominees, nominee_ids, name FROM $awards_table WHERE name != '' AND name LIKE %s LIMIT 80", $like),
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
        global $wpdb;

        $cache_key = 'aat_total_stats_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['records_total'], $cached['winners_total'])) {
            return $cached;
        }

        $fields = $this->get_awards_row_fields_sql();
        $sql = "SELECT COUNT(*) AS records_total, SUM(CASE WHEN winner = 1 THEN 1 ELSE 0 END) AS winners_total FROM (SELECT DISTINCT $fields FROM $table_name) aat_all_rows";
        $row = $wpdb->get_row($sql, ARRAY_A);

        $stats = array(
            'records_total' => isset($row['records_total']) ? (int) $row['records_total'] : 0,
            'winners_total' => isset($row['winners_total']) ? (int) $row['winners_total'] : 0,
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

        global $wpdb;
        $table_name = $wpdb->prefix . 'academy_awards';

        // Get unique values for filters
        $categories = $wpdb->get_col("SELECT DISTINCT canonical_category FROM $table_name WHERE canonical_category != '' ORDER BY canonical_category ASC");
        $classes = $wpdb->get_col("SELECT DISTINCT class FROM $table_name WHERE class != '' ORDER BY class ASC");
        $years = $wpdb->get_col("SELECT DISTINCT year FROM $table_name ORDER BY ceremony DESC");
        $ceremonies = $wpdb->get_col("SELECT DISTINCT ceremony FROM $table_name ORDER BY ceremony DESC");

        $stats = $this->get_total_awards_stats($table_name);
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
        $fields = $this->get_awards_row_fields_sql();

        // Stable ordering: whatever the user chooses, keep consistent tie-breakers.
        $order_sql = "$order_col $order_dir, ceremony DESC, canonical_category ASC, winner DESC, film ASC, name ASC";

        $data_sql = "SELECT DISTINCT $fields FROM $table_name WHERE $where_sql ORDER BY $order_sql LIMIT %d OFFSET %d";
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

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'skipped_duplicates' => $skipped_duplicates,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
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

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'skipped_duplicates' => $skipped_duplicates,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
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

    wp_send_json_success(array(
        'message' => 'Schema and rewrite rules repaired.'
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

        $chunk_size = apply_filters('aat_bundled_import_chunk_size', 500);
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;

        // Compute total rows (excluding header) once.
        $total_rows = intval(get_option('aat_bundled_total_rows', 0));
        if ($total_rows <= 0 || $offset === 0) {
            $f = new SplFileObject(AAT_BUNDLED_CSV_PATH, 'r');
            $f->seek(PHP_INT_MAX);
            // With a header at line 0, the last line index equals the number of data rows.
            $total_rows = max(0, intval($f->key()));
            update_option('aat_bundled_total_rows', $total_rows, false);
        }

        // First chunk: start clean.
        if ($offset === 0) {
            $wpdb->query("TRUNCATE TABLE $table_name");
            delete_transient('aat_records_total_v1');
            delete_transient('aat_records_total_v2');
            delete_transient('aat_total_stats_v2');
            delete_transient('aat_awards_meta_v1');
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

            $sql = "INSERT INTO $table_name ($col_list) VALUES " . implode(',', $placeholders);
            $result = $wpdb->query($wpdb->prepare($sql, $values));

            if ($result === false) {
                $errors += count($slice);
            } else {
                $imported += count($slice);
            }
        }

        $new_offset = $offset + $processed;
        $done = ($new_offset >= $total_rows);
        $screenplay_repair = array();
        $best_picture_repair = array();
        $international_feature_repair = array();
        $documentary_short_repair = array();

        if ($done) {
            $screenplay_repair = $this->repair_writing_credit_rows();
            $best_picture_repair = $this->repair_best_picture_credit_rows();
            $international_feature_repair = $this->repair_international_feature_credit_rows();
            $documentary_short_repair = $this->repair_documentary_and_short_credit_rows();

            // Invalidate performance caches
            delete_transient('aat_records_total_v1');
            delete_transient('aat_records_total_v2');
            delete_transient('aat_total_stats_v2');
            delete_transient('aat_awards_meta_v1');

            // Confirm how many rows actually ended up in the DB.
            $inserted_total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table_name"));
            $message = sprintf(
                __('Bundled import complete: %1$d rows now in the database. (%2$d rows processed; %3$d insert errors in the final batch; %4$d screenplay rows refreshed; %5$d screenplay nominee ID sets resolved; %6$d Best Picture rows refreshed; %7$d Best Picture nominee ID sets resolved; %8$d International Feature rows refreshed; %9$d International Feature nominee ID sets resolved; %10$d documentary-and-short rows refreshed; %11$d documentary-and-short nominee ID sets resolved.)', 'academy-awards-table'),
                $inserted_total,
                $new_offset,
                $errors,
                isset($screenplay_repair['updated']) ? intval($screenplay_repair['updated']) : 0,
                isset($screenplay_repair['resolved_ids']) ? intval($screenplay_repair['resolved_ids']) : 0,
                isset($best_picture_repair['updated']) ? intval($best_picture_repair['updated']) : 0,
                isset($best_picture_repair['resolved_ids']) ? intval($best_picture_repair['resolved_ids']) : 0,
                isset($international_feature_repair['updated']) ? intval($international_feature_repair['updated']) : 0,
                isset($international_feature_repair['resolved_ids']) ? intval($international_feature_repair['resolved_ids']) : 0,
                isset($documentary_short_repair['updated']) ? intval($documentary_short_repair['updated']) : 0,
                isset($documentary_short_repair['resolved_ids']) ? intval($documentary_short_repair['resolved_ids']) : 0
            );
        } else {
            $message = sprintf(__('Importing… %d of %d rows processed.', 'academy-awards-table'), $new_offset, $total_rows);
        }

        if ( $done ) {
            /** Fires after the final chunk of a bundled import completes. */
            do_action( 'aat_after_data_import', 'bundled', $imported );
        }

        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'skipped_duplicates' => $skipped_duplicates,
            'offset' => $new_offset,
            'total_rows' => $total_rows,
            'done' => $done,
            'message' => $message,
            'screenplay_repair' => $screenplay_repair,
            'best_picture_repair' => $best_picture_repair,
            'international_feature_repair' => $international_feature_repair,
            'documentary_short_repair' => $documentary_short_repair,
        ));
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

        $total_rows          = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table"));
        $latest_ceremony     = intval($wpdb->get_var("SELECT MAX(ceremony) FROM $awards_table"));
        $category_count      = intval($wpdb->get_var("SELECT COUNT(DISTINCT canonical_category) FROM $awards_table WHERE canonical_category != ''"));
        $title_id_rows       = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film_id REGEXP '(^|\\\\|)tt[0-9]{7,9}(\\\\||$)'"));
        $missing_title_ids   = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film != '' AND (film_id IS NULL OR film_id = '')"));
        $invalid_title_ids   = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE film_id != '' AND film_id NOT REGEXP '^(tt[0-9]{7,9})(\\\\|tt[0-9]{7,9})*$'"));
        $nominee_rows        = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE nominees != ''"));
        $missing_nominee_ids = intval($wpdb->get_var("SELECT COUNT(*) FROM $awards_table WHERE nominees != '' AND (nominee_ids IS NULL OR nominee_ids = '')"));

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

        $samples = array_merge($samples, $poster_attachment_samples, $poster_ratio_samples);

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

        // Invalidate performance caches
        delete_transient('aat_records_total_v1');
        delete_transient('aat_records_total_v2');
        delete_transient('aat_total_stats_v2');
        delete_transient('aat_awards_meta_v1');

        /** Fires after all data is cleared. */
        do_action( 'aat_after_data_import', 'clear', 0 );

        wp_send_json_success(array('message' => 'All data cleared.'));
    }
}

require_once AAT_PLUGIN_DIR . 'includes/class-aat-blocks.php';

// Initialize plugin
Academy_Awards_Table::get_instance();
