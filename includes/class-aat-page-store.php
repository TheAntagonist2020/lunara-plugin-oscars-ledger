<?php
/**
 * Oscars page store (2.8.14).
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
 * - Warm: a WP-Cron job renders the ceremony, category and index pages in small
 *   batches after each generation change, so they are stored before a visitor
 *   asks. Warm requests carry a wp_-prefixed cookie so Batcache lets them
 *   through to PHP.
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
    const WARM_BATCH = 12;
    const GEN_OPTION = 'aat_page_store_generation';
    const WARM_OPTION = 'aat_page_store_warm_queue';

    /** Path patterns that may be stored. Exact trailing slash only. */
    const PATH_PATTERN = '#^/oscars/(?:title/tt\d{5,10}|name/(?:nm\d{5,10}|lnm-[a-z0-9-]{1,80})|company/co\d{5,10}|ceremony/\d{1,3}|category/[a-z0-9-]{1,80}|ceremonies|categories|about)/$#';

    /** Query strings that name a stored variant. Anything else renders fresh. */
    private static $variants = array('', 'ledger=full', 'history=full', 'filmography=full');

    private static $capturing = false;
    private static $generation_cache = null;

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
        $row = self::fetch($request['key']);
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
        header('Cache-Control: public, max-age=' . self::HTTP_MAX_AGE);
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
     * @return array{key:string,path:string,variant:string}|null
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
        if (!in_array($query, self::$variants, true) || !preg_match(self::PATH_PATTERN, $path)) {
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
     * Retire every stored page (data changed, Customizer saved). Old rows stop
     * matching at once; the daily job deletes them later.
     */
    public static function bump($reason = '') {
        update_option(self::GEN_OPTION, (string) (intval(get_option(self::GEN_OPTION, '0')) + 1), true);
        self::$generation_cache = null;
        self::queue_warm();
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
        self::$capturing = true;
        ob_start(function ($buffer) use ($request) {
            self::store_if_clean($request, (string) $buffer);
            return $buffer;
        });
    }

    private static function store_if_clean($request, $html) {
        if (strlen($html) < self::MIN_BYTES || stripos($html, '</html>') === false) {
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
            'generation' => self::generation(),
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

    private static function fetch($key) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }
        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare("SELECT html FROM $table WHERE cache_key = %s AND generation = %s AND stored_at >= %d LIMIT 1", $key, self::generation(), time() - self::MAX_AGE), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

        add_action('aat_page_store_daily', array(__CLASS__, 'collect_garbage'));
        add_action('aat_page_store_warm', array(__CLASS__, 'warm_batch'));
        add_action('init', array(__CLASS__, 'schedule'), 20);
    }

    public static function on_option_updated($option) {
        if (is_string($option) && strpos($option, 'theme_mods_') === 0) {
            self::bump('theme_mods');
        }
    }

    public static function on_post_status($new_status, $old_status, $post) {
        if (!is_object($post) || ($post->post_type ?? '') !== 'review') {
            return;
        }
        if ($new_status !== $old_status && ($new_status === 'publish' || $old_status === 'publish')) {
            self::bump('review_' . $new_status);
        }
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
    }

    public static function collect_garbage() {
        global $wpdb;
        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE generation <> %s OR stored_at < %d LIMIT 5000", self::generation(), time() - 2 * self::MAX_AGE)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

    public static function warm_batch() {
        if (self::mode() !== 'on') {
            return;
        }
        $state = get_option(self::WARM_OPTION);
        if (!is_array($state) || ($state['generation'] ?? '') !== self::generation()) {
            return;
        }
        $paths = self::warm_paths();
        $offset = max(0, intval($state['offset'] ?? 0));
        $slice = array_slice($paths, $offset, self::WARM_BATCH);
        foreach ($slice as $path) {
            wp_remote_get(home_url($path), array(
                'timeout' => 20,
                'redirection' => 0,
                'blocking' => true,
                'cookies' => array(self::WARM_COOKIE => '1'),
                'headers' => array('Cache-Control' => 'no-cache'),
                'user-agent' => 'Lunara Oscars page store warm',
            ));
        }
        $offset += count($slice);
        if ($offset < count($paths)) {
            update_option(self::WARM_OPTION, array('generation' => self::generation(), 'offset' => $offset), false);
            wp_schedule_single_event(time() + 120, 'aat_page_store_warm');
        } else {
            delete_option(self::WARM_OPTION);
        }
    }
}
