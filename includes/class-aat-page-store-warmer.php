<?php
/**
 * Whole-site page store warmer (editorial scope only, OFF by default).
 *
 * The editorial page store fills on first visit, so the first visitor to each
 * of ~14,000 long-tail pages pays a full 1.4-1.8s render. This warmer walks every
 * published film, person, review and journal post plus the four archive roots in
 * small batches and requests each URL from the server itself, so the page store
 * is filled before anyone arrives and refilled after a generation retire.
 *
 * Runs only when ALL of these hold:
 *   - the store is on (aat_page_store_mode is not 'off'),
 *   - the scope is 'site' (aat_page_store_scope = site),
 *   - the warmer option aat_page_store_warmer is 'on' (default off).
 * Any one of them off and the next tick does nothing and is not rescheduled.
 *
 * How it stays gentle:
 *   - One request at a time (concurrency 1), a pause between requests, a cap on
 *     requests and wall time per cron tick, and a lock so two ticks never overlap.
 *   - It checks the store first and only requests pages that are not stored for the
 *     current generation or are older than the refresh age.
 *   - Slow or failing responses (over SLOW_MS, a WP_Error, 429 or 5xx) end the
 *     tick and pause the warmer with exponential backoff (2 min up to 30 min).
 *     Optional load-average gate through the aat_page_store_warmer_max_load filter.
 *   - Walks reviews and journal first, then films and people, newest first, so the
 *     pages most likely to be visited are warm earliest.
 *
 * Resumable: the cursor (source + last post ID) is saved in the option
 * aat_page_store_warmer_state after every chunk, so a killed tick resumes where it
 * stopped. A generation retire restarts the walk. When a walk finishes, a new one
 * starts after a short idle, re-rendering only rows older than the refresh age.
 *
 * Status: GET /wp-json/lunara-ledger/v1/page-store-warmer (manage_options) or
 * `wp option get aat_page_store_warmer_state --format=json`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AAT_Page_Store_Warmer {
    const OPTION = 'aat_page_store_warmer';          // 'on' enables; anything else is off.
    const STATE_OPTION = 'aat_page_store_warmer_state';
    const LOCK_OPTION = 'aat_page_store_warmer_lock';
    const HOOK = 'aat_page_store_warmer_tick';
    const WARM_COOKIE = 'wp_aat_store_warm';
    const REST_NS = 'lunara-ledger/v1';
    const REST_ROUTE = '/page-store-warmer';

    const LOCK_TTL = 150;            // A tick never runs longer than this; a stale lock is retaken.
    const TICK_BUDGET = 40;          // Seconds of wall time per tick.
    const MAX_REQUESTS = 24;         // Rendered requests per tick.
    const GAP_MS = 250;              // Pause between requests.
    const REQUEST_TIMEOUT = 15;      // Seconds.
    const SLOW_MS = 6000;            // One response slower than this pauses the warmer (a cold render is ~1.5-1.8s).
    const CHUNK = 25;                // Posts examined per step.
    const TICK_INTERVAL = 60;        // Seconds between ticks while there is work.
    const IDLE_INTERVAL = 900;       // Seconds between checks once a walk is complete.
    const REFRESH_AGE = 43200;       // 12h: rows younger than this are not re-rendered.
    const WARMED_MAX_AGE = 86400;    // 24h: how long editorial rows are served while the warmer keeps them fresh.
    const SETTLE = 90;               // Seconds after a retire before the walk restarts, so a burst of edits settles.
    const BACKOFF_MIN = 120;
    const BACKOFF_MAX = 1800;
    const BAD_RETRY = 21600;         // A page that rendered but never stored is retried after 6h.
    const BAD_CAP = 500;

    /** Walk order. 'archives' is the four archive roots; the rest are post types. */
    public static function sources() {
        return array('archives', 'review', 'journal', 'movie', 'person');
    }

    /* ------------------------------------------------------------------
     * Switches and settings
     * ------------------------------------------------------------------ */

    public static function enabled() {
        return get_option(self::OPTION, 'off') === 'on'
            && AAT_Page_Store::mode() === 'on'
            && AAT_Page_Store::scope() === 'site';
    }

    /** The option alone (used at include time to lengthen the editorial row life). */
    public static function option_on() {
        return function_exists('get_option') && get_option(self::OPTION, 'off') === 'on';
    }

    private static function setting($name, $default) {
        return function_exists('apply_filters') ? apply_filters('aat_page_store_warmer_' . $name, $default) : $default;
    }

    /* ------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------ */

    public static function init() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('init', array(__CLASS__, 'schedule'), 21);
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
    }

    /** Keep one tick scheduled while enabled; remove it when not. */
    public static function schedule() {
        if (self::enabled()) {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time() + self::TICK_INTERVAL, self::HOOK);
            }
        } elseif (wp_next_scheduled(self::HOOK)) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /** Called by the page store whenever a generation is retired. Cheap and inert unless enabled. */
    public static function on_retire() {
        if (!function_exists('get_option') || get_option(self::OPTION, 'off') !== 'on') {
            return;
        }
        $when = time() + intval(self::setting('settle', self::SETTLE));
        $next = wp_next_scheduled(self::HOOK);
        // Push a later tick forward only if none is due sooner than the settle time.
        if (!$next || $next < $when - 30) {
            if ($next) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            wp_schedule_single_event($when, self::HOOK);
        }
    }

    /* ------------------------------------------------------------------
     * State
     * ------------------------------------------------------------------ */

    public static function default_state($generation = '') {
        return array(
            'generation' => $generation,
            'status' => 'idle',          // idle | running | paused | complete | disabled
            'source' => 0,               // index into sources()
            'last_id' => 0,              // descending keyset cursor; 0 = start of this source
            'pass' => 0,
            'pass_started' => 0,
            'pass_ended' => 0,
            'next_pass_at' => 0,
            'warmed' => 0,               // rendered and now stored, this generation
            'skipped' => 0,              // already stored and fresh
            'uncaptured' => 0,           // rendered but not stored (page not eligible)
            'errors' => 0,
            'slow_streak' => 0,
            'paused_until' => 0,
            'last_ms' => 0,
            'avg_ms' => 0,
            'last_tick' => 0,
            'last_error' => '',
            'bad' => array(),            // short key => retry-after time
        );
    }

    public static function state() {
        $state = get_option(self::STATE_OPTION, array());
        return array_merge(self::default_state(), is_array($state) ? $state : array());
    }

    private static function save($state) {
        $state['last_tick'] = time();
        update_option(self::STATE_OPTION, $state, false);
    }

    private static function acquire_lock() {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', 'no')) {
            return true;
        }
        $held = intval(get_option(self::LOCK_OPTION, 0));
        if ($held > 0 && ($now - $held) < self::LOCK_TTL) {
            return false;
        }
        update_option(self::LOCK_OPTION, $now, false);
        return true;
    }

    private static function release_lock() {
        delete_option(self::LOCK_OPTION);
    }

    private static function reschedule($seconds) {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + max(15, intval($seconds)), self::HOOK);
        }
    }

    /* ------------------------------------------------------------------
     * Tick
     * ------------------------------------------------------------------ */

    public static function tick() {
        if (!self::enabled()) {
            $state = self::state();
            if ($state['status'] !== 'disabled') {
                $state['status'] = 'disabled';
                self::save($state);
            }
            return; // Not rescheduled. schedule() restarts it when the switches come back on.
        }
        if (!self::acquire_lock()) {
            self::reschedule(self::TICK_INTERVAL);
            return;
        }
        try {
            $next = self::run();
        } catch (\Throwable $e) {
            $state = self::state();
            $state['last_error'] = substr($e->getMessage(), 0, 200);
            $state['errors']++;
            self::save($state);
            $next = self::BACKOFF_MIN;
        }
        self::release_lock();
        self::reschedule($next);
    }

    /** One bounded unit of work. Returns the seconds until the next tick. */
    private static function run() {
        $now = time();
        $generation = AAT_Page_Store::generation_for('editorial');
        $state = self::state();

        if ($state['generation'] !== $generation) {
            // A retire (edit, import, deploy): restart the walk under the new generation.
            $pass = $state['pass'] + 1;
            $state = self::default_state($generation);
            $state['pass'] = $pass;
            $state['pass_started'] = $now;
        }
        if ($state['paused_until'] > $now) {
            $state['status'] = 'paused';
            self::save($state);
            return $state['paused_until'] - $now;
        }
        if ($state['status'] === 'complete') {
            if ($now < $state['next_pass_at']) {
                self::save($state);
                return min(self::IDLE_INTERVAL, $state['next_pass_at'] - $now);
            }
            // Start another walk: only rows older than the refresh age (or never stored) are rendered.
            $state['pass']++;
            $state['pass_started'] = $now;
            $state['source'] = 0;
            $state['last_id'] = 0;
            $state['skipped'] = 0;
            $state['warmed'] = 0;
            $state['uncaptured'] = 0;
        }
        if ($state['pass_started'] === 0) {
            $state['pass_started'] = $now;
            $state['pass'] = max(1, $state['pass']);
        }
        $state['status'] = 'running';

        $load_cap = floatval(self::setting('max_load', 0));
        if ($load_cap > 0 && function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && $load[0] > $load_cap) {
                return self::pause($state, 'load ' . round($load[0], 2));
            }
        }

        $budget = max(5, intval(self::setting('tick_budget', self::TICK_BUDGET)));
        $max_requests = max(1, intval(self::setting('max_requests', self::MAX_REQUESTS)));
        $gap_us = max(0, intval(self::setting('gap_ms', self::GAP_MS))) * 1000;
        $deadline = microtime(true) + $budget;
        $requests = 0;
        $pause_reason = '';

        while (microtime(true) < $deadline && $requests < $max_requests) {
            $chunk = self::next_chunk($state);
            if ($chunk === null) {
                $state['status'] = 'complete';
                $state['pass_ended'] = time();
                $state['next_pass_at'] = time() + intval(self::setting('idle_interval', self::IDLE_INTERVAL));
                break;
            }
            $cold = self::cold_paths($chunk['paths'], $state);
            $state['skipped'] += count($chunk['paths']) - count($cold['cold']) - $cold['bad'];

            $requested = array();
            $interrupted = false;
            foreach ($cold['cold'] as $path) {
                if (microtime(true) >= $deadline || $requests >= $max_requests) {
                    $interrupted = true;
                    break;
                }
                $result = self::warm_one($path);
                ++$requests;
                $requested[] = $path;
                $state['last_ms'] = $result['ms'];
                $state['avg_ms'] = $state['avg_ms'] > 0 ? round($state['avg_ms'] * 0.8 + $result['ms'] * 0.2, 1) : $result['ms'];
                if ($result['error'] !== '') {
                    $state['errors']++;
                    $state['last_error'] = $result['error'];
                    $pause_reason = $result['error'];
                    break;
                }
                if ($result['ms'] > intval(self::setting('slow_ms', self::SLOW_MS))) {
                    $pause_reason = 'slow response ' . round($result['ms']) . 'ms';
                    break;
                }
                if ($gap_us > 0) {
                    usleep($gap_us);
                }
            }

            // Count what actually landed in the store; remember pages that never do.
            if ($requested) {
                $stored = self::stored_keys(self::keys_for($requested), 0);
                // The request that tripped a pause is retried after the pause, not blamed on the page.
                $tripped = $pause_reason !== '' ? end($requested) : null;
                foreach ($requested as $path) {
                    $key = self::key_for($path);
                    if (isset($stored[$key])) {
                        $state['warmed']++;
                    } elseif ($path !== $tripped) {
                        $state['uncaptured']++;
                        $state['bad'][substr($key, 0, 12)] = time() + self::BAD_RETRY;
                    }
                }
                if (count($state['bad']) > self::BAD_CAP) {
                    $state['bad'] = array_slice($state['bad'], -self::BAD_CAP, null, true);
                }
            }

            if ($pause_reason !== '') {
                // Do not advance past a chunk we did not finish; it resumes here (stored pages are skipped).
                return self::pause($state, $pause_reason);
            }
            if ($interrupted) {
                self::save($state);
                break;
            }
            $state['source'] = $chunk['next_source'];
            $state['last_id'] = $chunk['next_last_id'];
            self::save($state);
        }

        $state['slow_streak'] = 0;
        self::save($state);
        if ($state['status'] === 'complete') {
            return min(self::IDLE_INTERVAL, max(15, $state['next_pass_at'] - time()));
        }
        return intval(self::setting('tick_interval', self::TICK_INTERVAL));
    }

    private static function pause($state, $reason) {
        $state['slow_streak']++;
        $backoff = min(self::BACKOFF_MAX, self::BACKOFF_MIN * (int) pow(2, min(6, $state['slow_streak'] - 1)));
        $state['status'] = 'paused';
        $state['paused_until'] = time() + $backoff;
        $state['last_error'] = $reason;
        self::save($state);
        return $backoff;
    }

    /* ------------------------------------------------------------------
     * Walking: next batch of URLs
     * ------------------------------------------------------------------ */

    /**
     * The next chunk of site-relative paths and the cursor that follows it, or
     * null when every source is done. Does not change $state.
     *
     * @return array{paths:string[],next_source:int,next_last_id:int}|null
     */
    public static function next_chunk($state) {
        global $wpdb;
        $sources = self::sources();
        $source = intval($state['source']);
        $last_id = intval($state['last_id']);
        while ($source < count($sources)) {
            $name = $sources[$source];
            if ($name === 'archives') {
                $paths = array();
                foreach (AAT_Page_Store::EDITORIAL_TYPES as $type) {
                    $path = self::storable_path(function_exists('get_post_type_archive_link') ? get_post_type_archive_link($type) : '');
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
                if ($last_id === 0 && $paths) {
                    return array('paths' => $paths, 'next_source' => $source + 1, 'next_last_id' => 0);
                }
                ++$source;
                $last_id = 0;
                continue;
            }
            $upper = $last_id > 0 ? $last_id : 2147483647;
            $posts = $wpdb->posts;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $posts WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND ID < %d ORDER BY ID DESC LIMIT %d", $name, $upper, intval(self::setting('chunk', self::CHUNK)))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ids = array_map('intval', (array) $ids);
            if (!$ids) {
                ++$source;
                $last_id = 0;
                continue;
            }
            if (function_exists('_prime_post_caches')) {
                _prime_post_caches($ids, false, false);
            }
            $paths = array();
            foreach ($ids as $id) {
                $path = self::storable_path(get_permalink($id));
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
            return array('paths' => $paths, 'next_source' => $source, 'next_last_id' => min($ids));
        }
        return null;
    }

    /** A permalink as a site-relative clean path the store accepts, or ''. */
    private static function storable_path($url) {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if (!empty($parts['query']) || !preg_match(AAT_Page_Store::EDITORIAL_PATTERN, $path)) {
            return '';
        }
        return $path;
    }

    /* ------------------------------------------------------------------
     * Store lookups
     * ------------------------------------------------------------------ */

    /** The cache key AAT_Page_Store::eligible_request() computes for a clean path. */
    public static function key_for($path) {
        $parts = wp_parse_url(home_url('/'));
        $host = strtolower((string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        return sha1($host . $path . '?');
    }

    private static function keys_for($paths) {
        $keys = array();
        foreach ($paths as $path) {
            $keys[$path] = self::key_for($path);
        }
        return $keys;
    }

    /**
     * Which of these keys are stored under the current editorial generation and
     * not older than $max_age seconds (0 = anything the store would still serve).
     *
     * @param array<string,string> $keys path => key
     * @return array<string,true>
     */
    private static function stored_keys($keys, $max_age) {
        global $wpdb;
        if (!$keys) {
            return array();
        }
        $served = AAT_Page_Store::editorial_max_age();
        $age = $max_age > 0 ? min($max_age, $served) : $served;
        $values = array_values($keys);
        $marks = implode(',', array_fill(0, count($values), '%s'));
        $table = AAT_Page_Store::table();
        $args = array_merge(array(AAT_Page_Store::generation_for('editorial'), time() - $age), $values);
        $suppress = $wpdb->suppress_errors(true);
        $found = $wpdb->get_col($wpdb->prepare("SELECT cache_key FROM $table WHERE generation = %s AND stored_at >= %d AND cache_key IN ($marks)", $args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
        $wpdb->suppress_errors($suppress);
        return array_fill_keys(array_map('strval', (array) $found), true);
    }

    /**
     * Split a chunk into pages that need rendering and pages to leave alone.
     *
     * @return array{cold:string[],bad:int}
     */
    private static function cold_paths($paths, $state) {
        $keys = self::keys_for($paths);
        $fresh = self::stored_keys($keys, intval(self::setting('refresh_age', self::REFRESH_AGE)));
        $cold = array();
        $bad = 0;
        $now = time();
        foreach ($keys as $path => $key) {
            if (isset($fresh[$key])) {
                continue;
            }
            $short = substr($key, 0, 12);
            if (isset($state['bad'][$short]) && $state['bad'][$short] > $now) {
                ++$bad;
                continue;
            }
            $cold[] = $path;
        }
        return array('cold' => $cold, 'bad' => $bad);
    }

    /* ------------------------------------------------------------------
     * One request
     * ------------------------------------------------------------------ */

    /**
     * Request one page the way the Oscars warmer does: a wp_-prefixed cookie so
     * Batcache passes it through to PHP, and so the store's include-time serve
     * steps aside and the page is rendered and captured fresh.
     *
     * @return array{ms:float,error:string}
     */
    private static function warm_one($path) {
        $start = microtime(true);
        $response = wp_remote_get(home_url($path), array(
            'timeout' => max(5, intval(self::setting('request_timeout', self::REQUEST_TIMEOUT))),
            'redirection' => 0,
            'blocking' => true,
            'cookies' => array(self::WARM_COOKIE => '1'),
            'headers' => array('Cache-Control' => 'no-cache'),
            'user-agent' => 'Lunara page store warmer',
        ));
        $ms = round((microtime(true) - $start) * 1000, 1);
        if (is_wp_error($response)) {
            return array('ms' => $ms, 'error' => 'request failed: ' . substr($response->get_error_message(), 0, 120));
        }
        $code = intval(wp_remote_retrieve_response_code($response));
        if ($code === 429 || $code >= 500) {
            return array('ms' => $ms, 'error' => 'HTTP ' . $code);
        }
        return array('ms' => $ms, 'error' => '');
    }

    /* ------------------------------------------------------------------
     * Status
     * ------------------------------------------------------------------ */

    public static function status() {
        global $wpdb;
        $state = self::state();
        $table = AAT_Page_Store::table();
        $generation = AAT_Page_Store::generation_for('editorial');
        $suppress = $wpdb->suppress_errors(true);
        $stored = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE generation = %s AND stored_at >= %d", $generation, time() - AAT_Page_Store::editorial_max_age()))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->suppress_errors($suppress);
        // Published, unprotected posts of the four types, plus the four archive roots.
        $types = AAT_Page_Store::EDITORIAL_TYPES;
        $posts = $wpdb->posts;
        $marks = implode(',', array_fill(0, count($types), '%s'));
        $expected = count($types) + intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $posts WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($marks)", $types))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
        $next = wp_next_scheduled(self::HOOK);
        return array(
            'enabled' => self::enabled(),
            'switches' => array(
                'store_mode' => AAT_Page_Store::mode(),
                'scope' => AAT_Page_Store::scope(),
                'warmer' => get_option(self::OPTION, 'off') === 'on' ? 'on' : 'off',
            ),
            'status' => $state['status'],
            'generation' => $generation,
            'walk_generation_matches' => $state['generation'] === $generation,
            'pass' => $state['pass'],
            'position' => array('source' => self::sources()[min(intval($state['source']), count(self::sources()) - 1)], 'last_id' => $state['last_id']),
            'stored_pages' => $stored,
            'expected_pages' => $expected,
            'coverage_pct' => $expected > 0 ? round(min(100, 100 * $stored / $expected), 1) : 0,
            'pass_counts' => array('warmed' => $state['warmed'], 'skipped' => $state['skipped'], 'uncaptured' => $state['uncaptured'], 'errors' => $state['errors']),
            'last_ms' => $state['last_ms'],
            'avg_ms' => $state['avg_ms'],
            'paused_until' => $state['paused_until'] > time() ? gmdate('c', $state['paused_until']) : null,
            'last_error' => $state['last_error'],
            'last_tick' => $state['last_tick'] ? gmdate('c', $state['last_tick']) : null,
            'next_tick' => $next ? gmdate('c', $next) : null,
            'editorial_row_life_seconds' => AAT_Page_Store::editorial_max_age(),
        );
    }

    public static function register_rest() {
        register_rest_route(self::REST_NS, self::REST_ROUTE, array(
            'methods' => 'GET',
            'callback' => function () {
                return rest_ensure_response(AAT_Page_Store_Warmer::status());
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }
}
