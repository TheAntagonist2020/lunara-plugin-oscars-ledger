<?php
/**
 * Oscars page store (2.8.14), with an opt-in editorial scope.
 *
 * Every uncached WordPress request on this site pays about 1.2s before a page is
 * built, and most Oscars URLs are visited too rarely for Batcache (2 hits within
 * 120s, then 15 minutes) to help. The boot probe shows this plugin loads about
 * 0.21-0.32s into a request. So the store keeps every Oscars page it renders
 * and serves it to later anonymous visitors from right here, before any other
 * plugin, the theme, init or the query run.
 *
 * - Capture: an anonymous, eligible Oscars route that renders a clean 200 is
 *   saved (gzcompressed) in its own table under the current generation.
 * - Serve: at plugin include time, an eligible request whose row matches the
 *   current generation and is under MAX_AGE old is answered from the row.
 * - Generation: AAT_VERSION, the active theme's style.css mtime, the dataset
 *   stamp, the label rules and a counter bumped on data changes and Customizer
 *   saves. When any of them changes, old rows stop matching. Nothing is ever
 *   "cleared"; a daily job deletes rows from retired generations.
 * - Warm: a WP-Cron job renders the ceremony, category and index pages, about
 *   25 seconds' worth per run, after each generation change, so they are stored before a visitor
 *   asks. Warm requests carry a wp_-prefixed cookie so Batcache lets them
 *   through to PHP.
 *
 * Editorial scope (OFF by default): set the option aat_page_store_scope
 * to 'site' to also store film, talent, review and journal pages (single pages
 * and the four archive roots, clean URL only). Editing a film, person, review or
 * journal post, or its comments and terms, deletes only that page's row, its
 * archive root and the term archives it belongs to (per-URL invalidation, flushed
 * once at shutdown; every other stored page stays warm). Site-wide changes (posts,
 * pages, ledger types, menus, widgets, site title, front page) still move the
 * editorial generation, which retires every editorial row. Rows are stored for at
 * most 6 hours and sent with a 5 minute max-age. By default they
 * are never warmed, so the first visitor to a page stores it for the second. The
 * whole-site warmer (includes/class-aat-page-store-warmer.php, option
 * aat_page_store_warmer = on, off by default) fills them in the background and
 * after each retire; while it is on, rows are served for up to 24 hours.
 *
 * Kill switch: set the option aat_page_store_mode to 'off' (or define
 * AAT_PAGE_STORE_OFF). Any request with an unrecognised query string always
 * renders fresh, which is also how to check a page against its stored copy.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AAT_Page_Store {
    const TABLE = 'aat_page_store';
    const MAX_AGE = 43200;          // 12h: bounds staleness for review links, portraits and posters.
    const HTTP_MAX_AGE = 900;       // What Batcache and the edge keep a served page for.
    const MIN_BYTES = 10000;
    const WARM_COOKIE = 'wp_aat_store_warm';
    const WARM_BUDGET = 25;         // Seconds of rendering per warm run.
    const WARM_INTERVAL = 60;       // Seconds between warm runs.
    const GEN_OPTION = 'aat_page_store_generation';
    const WARM_OPTION = 'aat_page_store_warm_queue';

    /** Path patterns that may be stored. Exact trailing slash only. */
    const PATH_PATTERN = '#^/oscars/(?:title/tt\d{5,10}|name/(?:nm\d{5,10}|lnm-[a-z0-9-]{1,80})|company/co\d{5,10}|ceremony/\d{1,3}|category/[a-z0-9-]{1,80}|ceremonies|categories|about)/$#';

    /** Editorial routes (scope 'site' only): film, talent, reviews and journal singles and archive roots, clean URL. */
    const EDITORIAL_PATTERN = '#^/(?:film|talent|reviews|journal)/(?:[A-Za-z0-9%._~-]{1,200}/)?$#';
    const EDITORIAL_TYPES = array('movie', 'person', 'review', 'journal');
    const EDITORIAL_MAX_AGE = 21600;     // 6h: bounds staleness for changes that fire no hook (meta-only imports).
    const EDITORIAL_HTTP_MAX_AGE = 300;  // Matches what Batcache sends for these pages today.
    const SCOPE_OPTION = 'aat_page_store_scope';
    const EDITORIAL_GEN_OPTION = 'aat_page_store_editorial_generation';
    const INVALIDATION_OPTION = 'aat_page_store_last_invalidation';
    const INVALIDATION_PATH_CAP = 200;   // More URLs than this in one request (a bulk import): retire editorial rows once instead.
    const TERM_OBJECT_CAP = 100;         // A term with more posts than this retires editorial rows once instead.

    /** Query strings that name a stored variant. Anything else renders fresh. */
    private static $variants = array('', 'ledger=full', 'history=full', 'filmography=full');

    private static $capturing = false;
    private static $generation_cache = null;
    private static $editorial_generation_cache = null;
    private static $editorial_bump_pending = false;
    private static $pending_paths = array();
    private static $invalidation_overflow = false;
    private static $invalidation_hooked = false;

    /** Test seams: the command-line SAPI keeps no response headers or status. */
    public static $test_headers = null;
    public static $test_status = null;

    /* ------------------------------------------------------------------
     * Serve (plugin include time)
     * ------------------------------------------------------------------ */

    public static function maybe_serve() {
        $start = microtime(true);
        $request = self::eligible_request();
        if (!$request || self::is_warm_request()) {
            return;
        }
        $row = self::fetch($request);
        if (!$row) {
            header('X-AAT-Store: MISS');
            return;
        }
        $html = @gzuncompress($row['html']);
        if (!is_string($html) || $html === '') {
            return;
        }
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: public, max-age=' . ($request['kind'] === 'editorial' ? self::EDITORIAL_HTTP_MAX_AGE : self::HTTP_MAX_AGE));
        header('X-AAT-Store: HIT');
        header('Server-Timing: aat-store;desc=HIT;dur=' . round((microtime(true) - $start) * 1000, 1) . ', aat-boot;dur=' . (defined('AAT_BOOT_MS') ? AAT_BOOT_MS : -1), false);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a page this site rendered itself.
        }
        exit;
    }

    /**
     * The storable request, or null. Checked on raw request data because this
     * runs before pluggable functions, the theme and the query exist.
     *
     * @return array{key:string,path:string,variant:string,kind:string}|null
     */
    public static function eligible_request() {
        if (self::mode() !== 'on') {
            return null;
        }
        if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || (defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return null;
        }
        if (function_exists('is_admin') && is_admin()) {
            return null;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        if (!empty($_SERVER['HTTP_X_WP_NONCE'])) {
            return null;
        }
        foreach (array_keys((array) $_COOKIE) as $name) {
            $name = (string) $name;
            if ($name === self::WARM_COOKIE || $name === 'wordpress_test_cookie') {
                continue;
            }
            if (preg_match('/^(wordpress_|wp-postpass_|wp-settings|comment_author_|woocommerce_)/', $name)) {
                return null;
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $parts = explode('?', $uri, 2);
        $path = $parts[0];
        $query = isset($parts[1]) ? $parts[1] : '';
        $kind = '';
        if (in_array($query, self::$variants, true) && preg_match(self::PATH_PATTERN, $path)) {
            $kind = 'oscars';
        } elseif ($query === '' && self::scope() === 'site' && preg_match(self::EDITORIAL_PATTERN, $path)) {
            $kind = 'editorial';
        }
        if ($kind === '') {
            return null;
        }
        if ((string) get_option('blog_public', '1') !== '1') {
            return null;
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        return array(
            'key' => sha1($host . $path . '?' . $query),
            'path' => $path,
            'variant' => $query,
            'kind' => $kind,
        );
    }

    private static function is_warm_request() {
        return isset($_COOKIE[self::WARM_COOKIE]);
    }

    public static function mode() {
        if ((defined('AAT_PAGE_STORE_OFF') && AAT_PAGE_STORE_OFF) || !function_exists('get_option')) {
            return 'off';
        }
        $mode = get_option('aat_page_store_mode', 'on');
        return $mode === 'off' ? 'off' : 'on';
    }

    /** 'oscars' (default) or 'site'. Only the exact option value 'site' widens the store. */
    public static function scope() {
        if (!function_exists('get_option')) {
            return 'oscars';
        }
        return get_option(self::SCOPE_OPTION, 'oscars') === 'site' ? 'site' : 'oscars';
    }

    /* ------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------ */

    /**
     * Everything a stored page depends on that can change without its URL
     * changing. Cheap enough for include time: options are autoloaded and the
     * theme part is one stat() call.
     */
    public static function generation() {
        if (self::$generation_cache !== null) {
            return self::$generation_cache;
        }
        $stylesheet = (string) get_option('stylesheet', '');
        $style = $stylesheet !== '' ? WP_CONTENT_DIR . '/themes/' . $stylesheet . '/style.css' : '';
        $theme_mtime = ($style !== '' && @is_file($style)) ? (string) @filemtime($style) : '0';
        self::$generation_cache = substr(md5(implode('|', array(
            AAT_VERSION,
            $stylesheet,
            $theme_mtime,
            (string) get_option('aat_dataset_stamp', ''),
            (string) get_option('aat_label_rules_version', ''),
            (string) get_option(self::GEN_OPTION, '0'),
        ))), 0, 16);
        return self::$generation_cache;
    }

    /**
     * Generation for a request kind. Editorial rows add their own counter on top
     * of the Oscars generation, so editing a review retires editorial rows
     * without discarding the warmed Oscars pages, while a data import, deploy or
     * theme change still retires both.
     */
    public static function generation_for($kind) {
        if ($kind !== 'editorial') {
            return self::generation();
        }
        if (self::$editorial_generation_cache === null) {
            self::$editorial_generation_cache = substr(md5(self::generation() . '|editorial|' . (string) get_option(self::EDITORIAL_GEN_OPTION, '0')), 0, 16);
        }
        return self::$editorial_generation_cache;
    }

    /**
     * How long an editorial row is served: 6h, because it is only ever stored on a
     * visit and some changes fire no hook. With the whole-site warmer on (option
     * aat_page_store_warmer) it is 24h, because the warmer re-renders anything older
     * than 12h and a full walk of the site takes hours.
     */
    public static function editorial_max_age() {
        if (class_exists('AAT_Page_Store_Warmer') && AAT_Page_Store_Warmer::option_on()) {
            return AAT_Page_Store_Warmer::WARMED_MAX_AGE;
        }
        return self::EDITORIAL_MAX_AGE;
    }

    /** Retire editorial rows only. Pages are stored on first visit, or by the whole-site warmer when it is on. */
    public static function bump_editorial($reason = '') {
        update_option(self::EDITORIAL_GEN_OPTION, (string) (intval(get_option(self::EDITORIAL_GEN_OPTION, '0')) + 1), true);
        self::$editorial_generation_cache = null;
        if (class_exists('AAT_Page_Store_Warmer')) {
            AAT_Page_Store_Warmer::on_retire();
        }
    }

    /** Bulk saves and imports fire many hooks in one request; retire once, at shutdown. */
    public static function bump_editorial_soon($reason = '') {
        if (self::scope() !== 'site' || self::$editorial_bump_pending) {
            return;
        }
        self::$editorial_bump_pending = true;
        add_action('shutdown', array(__CLASS__, 'flush_editorial_bump'), 99);
    }

    public static function flush_editorial_bump() {
        if (self::$editorial_bump_pending) {
            self::$editorial_bump_pending = false;
            self::bump_editorial('deferred');
        }
    }

    /**
     * Retire every stored page (data changed, Customizer saved). Old rows stop
     * matching at once; the daily job deletes them later.
     */
    public static function bump($reason = '') {
        update_option(self::GEN_OPTION, (string) (intval(get_option(self::GEN_OPTION, '0')) + 1), true);
        self::$generation_cache = null;
        self::$editorial_generation_cache = null;
        self::queue_warm();
        if (class_exists('AAT_Page_Store_Warmer')) {
            AAT_Page_Store_Warmer::on_retire();
        }
    }

    /* ------------------------------------------------------------------
     * Capture (template_redirect on an eligible, rendered route)
     * ------------------------------------------------------------------ */

    public static function maybe_capture() {
        if (self::$capturing || is_user_logged_in() || is_404()) {
            return;
        }
        $request = self::eligible_request();
        if (!$request) {
            return;
        }
        if ($request['kind'] === 'editorial' && !self::editorial_page_is_storable()) {
            return;
        }
        // Pin the generation before rendering: an edit that lands mid-render must not
        // leave a page built from the old content under the new generation.
        $request['generation'] = self::generation_for($request['kind']);
        $request['started'] = microtime(true);
        self::$capturing = true;
        ob_start(function ($buffer) use ($request) {
            self::store_if_clean($request, (string) $buffer);
            return $buffer;
        });
    }

    /**
     * Editorial routes are matched by URL shape, so confirm WordPress agrees:
     * a first page of a film, talent, review or journal archive, or a published,
     * unprotected single of one of those types.
     */
    private static function editorial_page_is_storable() {
        if (is_search() || is_paged() || is_preview()) {
            return false;
        }
        if (is_singular(self::EDITORIAL_TYPES)) {
            return get_post_status() === 'publish' && !post_password_required();
        }
        return is_post_type_archive(self::EDITORIAL_TYPES);
    }

    private static function store_if_clean($request, $html) {
        if (strlen($html) < self::MIN_BYTES || stripos($html, '</html>') === false) {
            return;
        }
        // A per-URL delete does not move the generation, so a render that began before
        // an edit and ends after it must not be stored: it may hold the old content.
        if (isset($request['started']) && $request['kind'] === 'editorial' && self::invalidated_since($request['started'])) {
            return;
        }
        $status = self::$test_status !== null ? self::$test_status : http_response_code();
        if ($status !== false && intval($status) !== 200) {
            return;
        }
        foreach ((self::$test_headers !== null ? self::$test_headers : headers_list()) as $header) {
            $lower = strtolower($header);
            if (strpos($lower, 'set-cookie:') === 0) {
                return;
            }
            if (strpos($lower, 'cache-control:') === 0 && (strpos($lower, 'no-cache') !== false || strpos($lower, 'no-store') !== false || strpos($lower, 'private') !== false)) {
                return;
            }
        }
        $packed = gzcompress($html, 6);
        if (!is_string($packed)) {
            return;
        }
        global $wpdb;
        $table = self::table();
        $row = array(
            'cache_key' => $request['key'],
            'generation' => isset($request['generation']) ? $request['generation'] : self::generation_for($request['kind']),
            'path' => substr($request['path'] . ($request['variant'] !== '' ? '?' . $request['variant'] : ''), 0, 255),
            'html' => $packed,
            'bytes' => strlen($html),
            'stored_at' => time(),
        );
        $formats = array('%s', '%s', '%s', '%s', '%d', '%d');
        $suppress = $wpdb->suppress_errors(true);
        $ok = $wpdb->replace($table, $row, $formats);
        if ($ok === false && self::ensure_table()) {
            $wpdb->replace($table, $row, $formats);
        }
        $wpdb->suppress_errors($suppress);
    }

    /* ------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private static function fetch($request) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }
        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare("SELECT html FROM $table WHERE cache_key = %s AND generation = %s AND stored_at >= %d LIMIT 1", $request['key'], self::generation_for($request['kind']), time() - ($request['kind'] === 'editorial' ? self::editorial_max_age() : self::MAX_AGE)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->suppress_errors($suppress);
        return is_array($row) ? $row : null;
    }

    /**
     * Create the table once, through dbDelta, only when a write finds it missing.
     * A 5-minute transient keeps concurrent requests from repeating it.
     */
    public static function ensure_table() {
        global $wpdb;
        if (get_transient('aat_page_store_table_try')) {
            return false;
        }
        set_transient('aat_page_store_table_try', 1, 300);
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            cache_key char(40) NOT NULL,
            generation char(16) NOT NULL,
            path varchar(255) NOT NULL DEFAULT '',
            html longblob NOT NULL,
            bytes int unsigned NOT NULL DEFAULT 0,
            stored_at int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (cache_key),
            KEY generation (generation),
            KEY stored_at (stored_at)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        return true;
    }

    /* ------------------------------------------------------------------
     * Upkeep: generation bumps, garbage collection, warming
     * ------------------------------------------------------------------ */

    public static function init() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'maybe_capture'), 99);

        foreach (array('aat_after_data_import', 'aat_ledger_swapped', 'aat_reporting_tables_rebuilt', 'customize_save_after') as $hook) {
            add_action($hook, array(__CLASS__, 'bump'), 99);
        }

        // Site Studio and the Customizer both save theme mods, which shape Oscars pages.
        add_action('updated_option', array(__CLASS__, 'on_option_updated'), 99, 1);
        // A review appearing or disappearing changes the review cards and links.
        add_action('transition_post_status', array(__CLASS__, 'on_post_status'), 99, 3);

        // Editorial scope only (each handler checks): anything that changes what a film, talent, review or journal page shows.
        // Per-URL: a film, person, review or journal edit deletes only that page's row, its archive root and the
        // term archives it belongs to. Everything site-wide (menus, widgets, site title) still retires all editorial rows.
        add_action('before_delete_post', array(__CLASS__, 'on_post_deleted'), 99, 1);
        add_action('post_updated', array(__CLASS__, 'on_post_updated'), 99, 3);
        foreach (array('comment_post', 'wp_set_comment_status', 'edit_comment', 'delete_comment') as $hook) {
            add_action($hook, array(__CLASS__, 'on_comment_change'), 99, 1);
        }
        foreach (array('created_term', 'edited_term', 'delete_term') as $hook) {
            add_action($hook, array(__CLASS__, 'on_term_change'), 99, 5);
        }
        foreach (array('wp_update_nav_menu', 'wp_delete_nav_menu') as $hook) {
            add_action($hook, array(__CLASS__, 'on_editorial_change'), 99);
        }

        add_action('aat_page_store_daily', array(__CLASS__, 'collect_garbage'));
        add_action('aat_page_store_warm', array(__CLASS__, 'warm_batch'));
        add_action('init', array(__CLASS__, 'schedule'), 20);

        // Whole-site warmer (editorial scope, its own option, off by default).
        if (class_exists('AAT_Page_Store_Warmer')) {
            AAT_Page_Store_Warmer::init();
        }
    }

    public static function on_option_updated($option) {
        if (!is_string($option)) {
            return;
        }
        if (strpos($option, 'theme_mods_') === 0) {
            self::bump('theme_mods');
        } elseif (in_array($option, array('blogname', 'blogdescription', 'sidebars_widgets', 'nav_menu_options', 'show_on_front', 'page_on_front'), true) || strpos($option, 'widget_') === 0) {
            self::bump_editorial_soon('option_' . $option);
        }
    }

    /** Site-wide change (menu, widget, site title, front page, unrelated post type): retire every editorial row. */
    public static function on_editorial_change() {
        self::bump_editorial_soon('editorial_change');
    }

    /** Public post types whose changes can alter an editorial page (the four routes, plus posts and pages that feed their modules). */
    private static function editorial_related_types() {
        return array_merge(self::EDITORIAL_TYPES, array('post', 'page', 'attachment', 'ledger_entry', 'lunara_oscar_pick', 'oscar_fact'));
    }

    public static function on_post_deleted($post_id) {
        if (self::scope() !== 'site') {
            return;
        }
        $post = function_exists('get_post') ? get_post($post_id) : null;
        $type = is_object($post) ? (string) ($post->post_type ?? '') : (function_exists('get_post_type') ? (string) get_post_type($post_id) : '');
        if (in_array($type, self::EDITORIAL_TYPES, true)) {
            // Runs before the row is removed, so the permalink still resolves. Only a published page was ever stored.
            if (is_object($post) && ($post->post_status ?? '') === 'publish') {
                self::invalidate_post($post, true);
            }
        } elseif (in_array($type, self::editorial_related_types(), true)) {
            self::bump_editorial_soon('post_deleted');
        }
    }

    public static function on_post_status($new_status, $old_status, $post) {
        if (!is_object($post)) {
            return;
        }
        $type = (string) ($post->post_type ?? '');
        // A published post saved, published, unpublished or trashed changes the editorial pages that show it.
        if (($new_status === 'publish' || $old_status === 'publish') && in_array($type, self::editorial_related_types(), true)) {
            if (in_array($type, self::EDITORIAL_TYPES, true)) {
                // This page's row and its archive root. The old address (slug change, unpublish, trash) is handled by on_post_updated.
                self::invalidate_post($post, $new_status === 'publish');
            } else {
                self::bump_editorial_soon('post_' . $new_status);
            }
        }
        if ($type !== 'review') {
            return;
        }
        if ($new_status !== $old_status && ($new_status === 'publish' || $old_status === 'publish')) {
            self::bump('review_' . $new_status);
        }
    }

    /** The address a published post had before this save (slug change, unpublish, trash). */
    public static function on_post_updated($post_id, $after = null, $before = null) {
        if (!is_object($before) || ($before->post_status ?? '') !== 'publish' || !function_exists('get_permalink')) {
            return;
        }
        if (!in_array((string) ($before->post_type ?? ''), self::EDITORIAL_TYPES, true)) {
            return;
        }
        self::invalidate_paths(array(get_permalink($before)), 'post_updated');
    }

    /** A comment changed: the commented page's row, or every editorial row when the page is not one of ours. */
    public static function on_comment_change($comment_id) {
        if (self::scope() !== 'site') {
            return;
        }
        $comment = function_exists('get_comment') ? get_comment($comment_id) : null;
        $post = (is_object($comment) && !empty($comment->comment_post_ID) && function_exists('get_post')) ? get_post(intval($comment->comment_post_ID)) : null;
        if (is_object($post) && in_array((string) ($post->post_type ?? ''), self::EDITORIAL_TYPES, true)) {
            if (($post->post_status ?? '') === 'publish') {
                self::invalidate_post($post, true);
            }
            return;
        }
        self::bump_editorial_soon('comment');
    }

    /**
     * A term was created, edited or deleted. When its taxonomy belongs to our post types:
     * the term archive, the archive roots and the pages of the posts carrying it (up to a cap).
     * Any other taxonomy keeps the old behaviour and retires every editorial row.
     *
     * @param int|object $term_id  Term ID (delete_term passes the term object's ID in its first argument too).
     * @param int        $tt_id
     * @param string     $taxonomy
     * @param mixed      $arg4     Unused (term args, or the deleted term).
     * @param mixed      $object_ids delete_term only: IDs of the posts that carried the term.
     */
    public static function on_term_change($term_id, $tt_id = 0, $taxonomy = '', $arg4 = null, $object_ids = null) {
        if (self::scope() !== 'site') {
            return;
        }
        $taxonomy = (string) $taxonomy;
        $tax = ($taxonomy !== '' && function_exists('get_taxonomy')) ? get_taxonomy($taxonomy) : null;
        $types = (is_object($tax) && !empty($tax->object_type)) ? array_values(array_intersect((array) $tax->object_type, self::EDITORIAL_TYPES)) : array();
        if (!$types || !function_exists('get_post')) {
            self::bump_editorial_soon('term');
            return;
        }
        $ids = is_array($object_ids) ? $object_ids : (function_exists('get_objects_in_term') ? get_objects_in_term(intval($term_id), $taxonomy) : array());
        if (!is_array($ids) || count($ids) > self::TERM_OBJECT_CAP) {
            self::bump_editorial_soon('term_many');
            return;
        }
        $paths = array();
        foreach ($types as $type) {
            $paths[] = function_exists('get_post_type_archive_link') ? get_post_type_archive_link($type) : '';
        }
        if (function_exists('get_term_link')) {
            $link = get_term_link(intval($term_id), $taxonomy);
            $paths[] = is_string($link) ? $link : '';
        }
        foreach ($ids as $id) {
            $post = get_post(intval($id));
            if (is_object($post) && in_array((string) ($post->post_type ?? ''), self::EDITORIAL_TYPES, true) && ($post->post_status ?? '') === 'publish' && function_exists('get_permalink')) {
                $paths[] = get_permalink($post);
            }
        }
        self::invalidate_paths($paths, 'term');
    }

    /* ------------------------------------------------------------------
     * Per-URL invalidation (editorial scope)
     * ------------------------------------------------------------------ */

    /** A clean site-relative path the editorial store accepts, from a URL or a path, or ''. */
    public static function editorial_path($url) {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
        if (!empty($parts['query']) || !preg_match(self::EDITORIAL_PATTERN, $path)) {
            return '';
        }
        return $path;
    }

    /** The cache key eligible_request() computes for a clean path on a host (default: the site's own). */
    public static function key_for_path($path, $host = null) {
        if ($host === null) {
            $parts = function_exists('wp_parse_url') && function_exists('home_url') ? wp_parse_url(home_url('/')) : array();
            $host = (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        return sha1(strtolower((string) $host) . $path . '?');
    }

    /**
     * Queue the rows of one edited page: its own URL (when it stays public), its
     * archive root, and the archives of the terms it carries. Deleted at shutdown.
     */
    public static function invalidate_post($post, $include_permalink = true) {
        if (!is_object($post)) {
            return;
        }
        $type = (string) ($post->post_type ?? '');
        $paths = array();
        if ($include_permalink && function_exists('get_permalink')) {
            $paths[] = get_permalink($post);
        }
        if (function_exists('get_post_type_archive_link')) {
            $paths[] = get_post_type_archive_link($type);
        }
        if (!empty($post->ID) && function_exists('get_object_taxonomies') && function_exists('get_the_terms') && function_exists('get_term_link')) {
            foreach ((array) get_object_taxonomies($type) as $taxonomy) {
                $terms = get_the_terms($post, $taxonomy);
                foreach (is_array($terms) ? $terms : array() as $term) {
                    $link = get_term_link($term);
                    $paths[] = is_string($link) ? $link : '';
                }
            }
        }
        // Lets a theme or plugin name pages that show this one (a film page listing its reviews, say).
        $paths = function_exists('apply_filters') ? apply_filters('aat_page_store_related_paths', $paths, $post) : $paths;
        self::invalidate_paths((array) $paths, 'post');
    }

    /** Queue clean paths (or URLs) for deletion at shutdown. Too many in one request retires editorial rows once instead. */
    public static function invalidate_paths($paths, $reason = '') {
        if (self::scope() !== 'site' || self::$invalidation_overflow) {
            return;
        }
        foreach ((array) $paths as $path) {
            $path = self::editorial_path($path);
            if ($path !== '') {
                self::$pending_paths[$path] = true;
            }
        }
        if (count(self::$pending_paths) > self::INVALIDATION_PATH_CAP) {
            self::$invalidation_overflow = true;
            self::$pending_paths = array();
            self::bump_editorial_soon('many_urls');
            return;
        }
        if (self::$pending_paths && !self::$invalidation_hooked && function_exists('add_action')) {
            self::$invalidation_hooked = true;
            add_action('shutdown', array(__CLASS__, 'flush_invalidations'), 98);
        }
    }

    public static function pending_invalidations() {
        return array_keys(self::$pending_paths);
    }

    /** Delete the queued rows, once per request. The editorial generation does not move, so every other stored page stays warm. */
    public static function flush_invalidations() {
        $paths = array_keys(self::$pending_paths);
        self::$pending_paths = array();
        self::$invalidation_overflow = false;
        self::$invalidation_hooked = false;
        if (!$paths) {
            return 0;
        }
        global $wpdb;
        $hosts = array(null);
        $request_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($request_host !== '' && self::key_for_path('/', null) !== self::key_for_path('/', $request_host)) {
            $hosts[] = $request_host; // The site answering on an alias host stores its own keys.
        }
        $keys = array();
        foreach ($paths as $path) {
            foreach ($hosts as $host) {
                $keys[] = self::key_for_path($path, $host);
            }
        }
        // Stamp first: a render that began before this moment is not stored when it ends (see store_if_clean).
        update_option(self::INVALIDATION_OPTION, sprintf('%.3f', microtime(true)), false);
        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        foreach (array_chunk($keys, 100) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '%s'));
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE cache_key IN ($marks)", $chunk)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
        }
        $wpdb->suppress_errors($suppress);
        if (class_exists('AAT_Page_Store_Warmer')) {
            AAT_Page_Store_Warmer::on_rows_deleted();
        }
        return count($paths);
    }

    /** True when a per-URL delete happened at or after $started (a float from microtime). */
    private static function invalidated_since($started) {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::INVALIDATION_OPTION, 'options'); // Read the stamp fresh, not from this request's option cache.
        }
        return floatval(get_option(self::INVALIDATION_OPTION, '0')) >= floatval($started);
    }

    public static function schedule() {
        if (!wp_next_scheduled('aat_page_store_daily')) {
            wp_schedule_event(time() + 600, 'daily', 'aat_page_store_daily');
        }
        // A deploy or theme change moves the generation without a bump; notice
        // it once and warm the new generation.
        if (get_option('aat_page_store_warmed_generation') !== self::generation()) {
            update_option('aat_page_store_warmed_generation', self::generation(), true);
            self::queue_warm();
        }
        // A queue with no run scheduled (a run was killed before 2.8.15 could
        // reschedule) picks up again on the next request.
        $state = get_option(self::WARM_OPTION);
        if (is_array($state) && ($state['generation'] ?? '') === self::generation() && !wp_next_scheduled('aat_page_store_warm')) {
            wp_schedule_single_event(time() + 30, 'aat_page_store_warm');
        }
    }

    public static function collect_garbage() {
        global $wpdb;
        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE (generation <> %s AND generation <> %s) OR stored_at < %d LIMIT 5000", self::generation(), self::generation_for('editorial'), time() - 2 * self::MAX_AGE)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->suppress_errors($suppress);
    }

    /**
     * The pages worth having before anyone asks: the index hubs, every
     * ceremony (both views) and every category.
     *
     * @return string[] Site-relative paths.
     */
    public static function warm_paths() {
        global $wpdb;
        $plugin = class_exists('Academy_Awards_Table') ? Academy_Awards_Table::get_instance() : null;
        if (!$plugin) {
            return array();
        }
        $urls = array($plugin->get_ceremonies_index_url(), $plugin->get_categories_index_url());
        for ($n = intval($plugin->get_max_ceremony()); $n >= 1; $n--) {
            $url = $plugin->get_ceremony_url($n);
            $urls[] = $url;
            $urls[] = add_query_arg('ledger', 'full', $url);
        }
        $table = $plugin->get_table_name();
        $categories = (array) $wpdb->get_col("SELECT DISTINCT canonical_category FROM $table WHERE canonical_category <> ''"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ($categories as $category) {
            $urls[] = $plugin->get_category_url((string) $category);
        }
        $paths = array();
        foreach ($urls as $url) {
            $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
            $query = (string) wp_parse_url((string) $url, PHP_URL_QUERY);
            if ($path !== '') {
                $paths[] = $path . ($query !== '' ? '?' . $query : '');
            }
        }
        return array_values(array_unique($paths));
    }

    public static function queue_warm() {
        update_option(self::WARM_OPTION, array('generation' => self::generation(), 'offset' => 0), false);
        if (!wp_next_scheduled('aat_page_store_warm')) {
            wp_schedule_single_event(time() + 60, 'aat_page_store_warm');
        }
    }

    /**
     * Warm the next stretch of the queue. A cold ceremony can take 3-14s, and
     * the cron worker has a time limit, so a run is built to be killed at any
     * moment without losing its place: the next run is scheduled first, the
     * offset is saved after every page, and the run stops itself after
     * WARM_BUDGET seconds. (2.8.15: the 2.8.14 version rendered 12 pages per
     * run and only then saved and rescheduled, so one killed run stopped
     * warming for good.)
     */
    public static function warm_batch() {
        if (self::mode() !== 'on') {
            return;
        }
        $state = get_option(self::WARM_OPTION);
        if (!is_array($state) || ($state['generation'] ?? '') !== self::generation()) {
            return;
        }
        wp_schedule_single_event(time() + self::WARM_INTERVAL, 'aat_page_store_warm');

        $paths = self::warm_paths();
        $offset = max(0, intval($state['offset'] ?? 0));
        $deadline = microtime(true) + self::WARM_BUDGET;
        $log = array('started' => time(), 'from' => $offset, 'codes' => array());
        while ($offset < count($paths) && microtime(true) < $deadline) {
            $response = wp_remote_get(home_url($paths[$offset]), array(
                'timeout' => 20,
                'redirection' => 0,
                'blocking' => true,
                'cookies' => array(self::WARM_COOKIE => '1'),
                'headers' => array('Cache-Control' => 'no-cache'),
                'user-agent' => 'Lunara Oscars page store warm',
            ));
            $log['codes'][] = is_wp_error($response) ? $response->get_error_code() : (string) wp_remote_retrieve_response_code($response);
            $offset++;
            update_option(self::WARM_OPTION, array('generation' => self::generation(), 'offset' => $offset, 'total' => count($paths)), false);
        }
        $log['to'] = $offset;
        $log['total'] = count($paths);
        $log['finished'] = time();
        update_option('aat_page_store_warm_log', $log, false);

        if ($offset >= count($paths)) {
            delete_option(self::WARM_OPTION);
            wp_clear_scheduled_hook('aat_page_store_warm');
        }
    }

    /**
     * Warm state for /status, so it can be checked from outside.
     */
    public static function report() {
        $state = get_option(self::WARM_OPTION);
        $log = get_option('aat_page_store_warm_log');
        return array(
            'mode' => self::mode(),
            'generation' => self::generation(),
            'warm_queue' => is_array($state) ? array('offset' => intval($state['offset'] ?? 0), 'total' => intval($state['total'] ?? 0), 'next_run' => wp_next_scheduled('aat_page_store_warm') ?: null) : null,
            'last_warm_run' => is_array($log) ? $log : null,
        );
    }
}

require_once __DIR__ . '/class-aat-page-store-warmer.php';
