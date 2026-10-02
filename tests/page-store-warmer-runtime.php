<?php
/**
 * Whole-site page store warmer: switches, walk order, throttling, pause on slow
 * or failing responses, resumability, restart on a generation retire, status,
 * all against the real classes with WordPress and $wpdb stubbed.
 *
 * Run: php tests/page-store-warmer-runtime.php
 */
if (getenv('AAT_STORE_CHILD') === '1') {
    // Child process: serve one request from a pre-filled store and exit.
    require __DIR__ . '/page-store-stubs.inc';
    $GLOBALS['options']['aat_page_store_scope'] = 'site';
    $GLOBALS['options']['aat_page_store_editorial_generation'] = '5';
    $GLOBALS['options']['aat_page_store_generation'] = '1';
    if (getenv('AAT_TEST_WARMER') === 'on') {
        $GLOBALS['options']['aat_page_store_warmer'] = 'on';
    }
    $GLOBALS['wpdb']->rows = unserialize(base64_decode(getenv('AAT_STORE_ROWS')));
    $_SERVER['REQUEST_URI'] = getenv('AAT_STORE_URI');
    AAT_Page_Store::maybe_serve();
    echo 'FELL-THROUGH';
    exit;
}

require __DIR__ . '/page-store-stubs.inc';

$checks = 0;
function pw_assert($ok, $msg) {
    global $checks;
    ++$checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

// ---- Extra WordPress stubs ---------------------------------------------------
$GLOBALS['filters'] = array();
$GLOBALS['http'] = array();           // every wp_remote_get: array(url, args)
$GLOBALS['http_mode'] = 'ok';         // ok | error | 503
$GLOBALS['uncapturable'] = array();   // paths that render but never store
$GLOBALS['rest'] = array();
$GLOBALS['can'] = false;
class WP_Error_Stub { public function get_error_message() { return 'timed out'; } }
function apply_filters($name, $value) { return array_key_exists($name, $GLOBALS['filters']) ? $GLOBALS['filters'][$name] : $value; }
function add_option($k, $v = '', $d = '', $a = 'yes') { if (array_key_exists($k, $GLOBALS['options'])) { return false; } $GLOBALS['options'][$k] = $v; return true; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['scheduled'][$h]); }
function home_url($path = '') { return 'https://lunarafilm.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_permalink($id) { return $GLOBALS['wpdb']->posts_data[$id]['url'] ?? false; }
function get_post_type_archive_link($type) { return array('movie' => 'https://lunarafilm.test/film/', 'person' => 'https://lunarafilm.test/talent/', 'review' => 'https://lunarafilm.test/reviews/', 'journal' => 'https://lunarafilm.test/journal/')[$type] ?? false; }
function is_wp_error($r) { return $r instanceof WP_Error_Stub; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function current_user_can($cap) { return $GLOBALS['can']; }
function rest_ensure_response($d) { return $d; }
function register_rest_route($ns, $route, $args) { $GLOBALS['rest'][$ns . $route] = $args; }
function wp_remote_get($url, $args = array()) {
    $GLOBALS['http'][] = array($url, $args);
    if ($GLOBALS['http_mode'] === 'error') {
        return new WP_Error_Stub();
    }
    $path = parse_url($url, PHP_URL_PATH);
    if ($GLOBALS['http_mode'] === '503') {
        return array('code' => 503);
    }
    if (!in_array($path, $GLOBALS['uncapturable'], true)) {
        $GLOBALS['wpdb']->rows[sha1('lunarafilm.test' . $path . '?')] = array(
            'cache_key' => sha1('lunarafilm.test' . $path . '?'),
            'generation' => AAT_Page_Store::generation_for('editorial'),
            'path' => $path,
            'html' => 'x',
            'stored_at' => time(),
        );
    }
    return array('code' => 200);
}
class Warmer_WPDB extends Page_Store_WPDB {
    public $posts = 'wp_posts';
    public $posts_data = array();
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return array($sql, $args);
    }
    public function get_col($q) {
        list($sql, $args) = $q;
        if (strpos($sql, 'FROM wp_posts') !== false) {
            list($type, $upper, $limit) = $args;
            $ids = array();
            foreach ($this->posts_data as $id => $p) {
                if ($p['type'] === $type && $p['status'] === 'publish' && $p['password'] === '' && $id < $upper) {
                    $ids[] = $id;
                }
            }
            rsort($ids);
            return array_slice($ids, 0, $limit);
        }
        // Store lookup: generation, since, then keys.
        $gen = array_shift($args);
        $since = array_shift($args);
        $out = array();
        foreach ($args as $key) {
            $r = $this->rows[$key] ?? null;
            if ($r && $r['generation'] === $gen && $r['stored_at'] >= $since) {
                $out[] = $key;
            }
        }
        return $out;
    }
    public function get_var($q) {
        list($sql, $args) = $q;
        $n = 0;
        if (strpos($sql, 'FROM wp_posts') !== false) {
            foreach ($this->posts_data as $p) {
                if ($p['status'] === 'publish' && $p['password'] === '' && in_array($p['type'], $args, true)) {
                    ++$n;
                }
            }
            return $n;
        }
        foreach ($this->rows as $r) {
            if ($r['generation'] === $args[0] && $r['stored_at'] >= $args[1]) {
                ++$n;
            }
        }
        return $n;
    }
}
$wpdb = new Warmer_WPDB();
$GLOBALS['wpdb'] = $wpdb;

// Site: 5 reviews, 3 journal posts, 4 films, 2 people, plus things that must never be walked.
$id = 100;
foreach (array('review' => array(5, 'reviews'), 'journal' => array(3, 'journal'), 'movie' => array(4, 'film'), 'person' => array(2, 'talent')) as $type => $def) {
    for ($i = 1; $i <= $def[0]; $i++) {
        ++$id;
        $wpdb->posts_data[$id] = array('type' => $type, 'status' => 'publish', 'password' => '', 'url' => "https://lunarafilm.test/{$def[1]}/{$type}-{$i}/");
    }
}
$wpdb->posts_data[900] = array('type' => 'review', 'status' => 'draft', 'password' => '', 'url' => 'https://lunarafilm.test/reviews/a-draft/');
$wpdb->posts_data[901] = array('type' => 'review', 'status' => 'publish', 'password' => 'secret', 'url' => 'https://lunarafilm.test/reviews/protected/');
$wpdb->posts_data[902] = array('type' => 'movie', 'status' => 'publish', 'password' => '', 'url' => 'https://lunarafilm.test/?p=902');
$EXPECTED_POSTS = 14;
$EXPECTED_URLS = $EXPECTED_POSTS + 4;

$GLOBALS['filters'] = array('aat_page_store_warmer_gap_ms' => 0, 'aat_page_store_warmer_chunk' => 3, 'aat_page_store_warmer_max_requests' => 1000, 'aat_page_store_warmer_tick_budget' => 30);

function pw_reset_site() {
    global $wpdb;
    $GLOBALS['http'] = array();
    $GLOBALS['http_mode'] = 'ok';
    $GLOBALS['uncapturable'] = array();
    $GLOBALS['scheduled'] = array();
    $wpdb->rows = array();
    foreach (array('aat_page_store_warmer', 'aat_page_store_warmer_state', 'aat_page_store_warmer_lock', 'aat_page_store_scope', 'aat_page_store_mode') as $k) {
        unset($GLOBALS['options'][$k]);
    }
    $GLOBALS['options']['aat_page_store_editorial_generation'] = '1';
    $r = new ReflectionProperty('AAT_Page_Store', 'editorial_generation_cache');
    $r->setAccessible(true);
    $r->setValue(null, null);
}
function pw_enable() {
    $GLOBALS['options']['aat_page_store_scope'] = 'site';
    $GLOBALS['options']['aat_page_store_warmer'] = 'on';
}
/** What WP-Cron does: take the event off, then run the callback. */
function pw_cron_tick() {
    unset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]);
    AAT_Page_Store_Warmer::tick();
}
function pw_paths() {
    return array_map(function ($h) { return parse_url($h[0], PHP_URL_PATH); }, $GLOBALS['http']);
}

// ---- Off by default ----------------------------------------------------------
pw_reset_site();
pw_assert(!AAT_Page_Store_Warmer::enabled(), 'The warmer is off by default.');
AAT_Page_Store_Warmer::schedule();
pw_assert(!isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'Nothing is scheduled while the warmer is off.');
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 0 && !isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'A tick while off requests nothing and does not reschedule.');
pw_assert(AAT_Page_Store_Warmer::state()['status'] === 'disabled', 'A tick while off records the disabled status.');
AAT_Page_Store::bump_editorial();
pw_assert(!isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'A retire while the warmer is off schedules nothing.');

// The warmer needs the warmer option, the site scope and the store on.
$GLOBALS['options']['aat_page_store_warmer'] = 'on';
pw_assert(!AAT_Page_Store_Warmer::enabled(), 'The warmer option alone is not enough: the scope must be site.');
$GLOBALS['options']['aat_page_store_scope'] = 'site';
pw_assert(AAT_Page_Store_Warmer::enabled(), 'Warmer on, scope site, store on: enabled.');
$GLOBALS['options']['aat_page_store_mode'] = 'off';
pw_assert(!AAT_Page_Store_Warmer::enabled(), 'The store kill switch stops the warmer.');
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 0, 'A tick with the store off requests nothing.');
unset($GLOBALS['options']['aat_page_store_mode']);
unset($GLOBALS['options']['aat_page_store_scope']);
pw_assert(!AAT_Page_Store_Warmer::enabled(), 'Scope back to Oscars only stops the warmer.');
unset($GLOBALS['options']['aat_page_store_warmer_state']);

// ---- A full walk -------------------------------------------------------------
pw_reset_site();
pw_enable();
AAT_Page_Store_Warmer::schedule();
pw_assert(isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'Enabled: a tick is scheduled.');
pw_cron_tick();
$paths = pw_paths();
pw_assert(count($paths) === $EXPECTED_URLS && count(array_unique($paths)) === $EXPECTED_URLS, 'Every published film, person, review, journal post and archive root is requested once.');
pw_assert(array_slice($paths, 0, 4) === array('/film/', '/talent/', '/reviews/', '/journal/'), 'Archive roots are warmed first.');
pw_assert(array_slice($paths, 4, 5) === array('/reviews/review-5/', '/reviews/review-4/', '/reviews/review-3/', '/reviews/review-2/', '/reviews/review-1/'), 'Reviews come next, newest first.');
pw_assert(strpos($paths[9], '/journal/') === 0 && strpos($paths[12], '/film/') === 0 && strpos($paths[16], '/talent/') === 0, 'Then journal, films and people.');
foreach (array('/reviews/a-draft/', '/reviews/protected/', '/') as $never) {
    pw_assert(!in_array($never, $paths, true), "$never is never requested.");
}
pw_assert(!in_array('/?p=902', $paths, true) && !array_filter($paths, function ($p) { return $p === '/'; }), 'A permalink with a query string is never requested.');
pw_assert($GLOBALS['http'][0][1]['cookies'][AAT_Page_Store_Warmer::WARM_COOKIE] === '1' && $GLOBALS['http'][0][1]['redirection'] === 0 && $GLOBALS['http'][0][0] === 'https://lunarafilm.test/film/', 'Requests carry the wp_-prefixed warm cookie, follow no redirects and use the site URL.');
$state = AAT_Page_Store_Warmer::state();
pw_assert($state['status'] === 'complete' && $state['warmed'] === $EXPECTED_URLS && $state['skipped'] === 0 && $state['uncaptured'] === 0, 'The walk completes and counts what it stored.');
pw_assert(count($wpdb->rows) === $EXPECTED_URLS, 'Every requested page ended up in the store.');
pw_assert($state['generation'] === AAT_Page_Store::generation_for('editorial'), 'The walk records the generation it filled.');
pw_assert(isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]) && $GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK] > time() + 14, 'A complete walk idles, with the next check scheduled.');

// An idle check before the next walk is due does nothing; a due walk skips fresh rows.
$GLOBALS['http'] = array();
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 0, 'Idle: no requests before the next walk is due.');
$s = AAT_Page_Store_Warmer::state();
$s['next_pass_at'] = time() - 1;
$GLOBALS['options']['aat_page_store_warmer_state'] = $s;
pw_cron_tick();
$s = AAT_Page_Store_Warmer::state();
pw_assert(count($GLOBALS['http']) === 0 && $s['skipped'] === $EXPECTED_URLS && $s['pass'] === 2 && $s['status'] === 'complete', 'A second walk skips every stored, fresh page and requests nothing.');
// Rows older than the refresh age are re-rendered.
foreach ($wpdb->rows as $k => $r) {
    $wpdb->rows[$k]['stored_at'] = time() - AAT_Page_Store_Warmer::REFRESH_AGE - 60;
}
$s['next_pass_at'] = time() - 1;
$GLOBALS['options']['aat_page_store_warmer_state'] = $s;
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === $EXPECTED_URLS, 'Rows older than the refresh age are rendered again, so pages never age out.');
// A deleted row (or one past the serve life) is rendered again too.
$GLOBALS['http'] = array();
unset($wpdb->rows[sha1('lunarafilm.test/film/movie-1/?')]);
$s = AAT_Page_Store_Warmer::state();
$s['next_pass_at'] = time() - 1;
$GLOBALS['options']['aat_page_store_warmer_state'] = $s;
pw_cron_tick();
pw_assert(pw_paths() === array('/film/movie-1/'), 'Only the missing page is requested.');

// ---- Rate limit and resume ---------------------------------------------------
pw_reset_site();
pw_enable();
$GLOBALS['filters']['aat_page_store_warmer_max_requests'] = 5;
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 5, 'A tick makes at most the request cap.');
pw_assert(AAT_Page_Store_Warmer::state()['status'] === 'running' && isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'A capped tick leaves the walk running with the next tick scheduled.');
$ticks = 1;
while (AAT_Page_Store_Warmer::state()['status'] !== 'complete' && $ticks < 20) {
    pw_cron_tick();
    ++$ticks;
}
$paths = pw_paths();
pw_assert(count($paths) === $EXPECTED_URLS && count(array_unique($paths)) === $EXPECTED_URLS, 'Resuming across ticks requests every page exactly once.');
pw_assert($ticks === 4, 'The walk took 4 capped ticks (18 pages at 5 per tick).');
// Resume after a killed tick: the cursor is in the option; a fresh process picks it up (stored pages are skipped).
pw_reset_site();
pw_enable();
$GLOBALS['filters']['aat_page_store_warmer_max_requests'] = 7;
pw_cron_tick();
$cursor = AAT_Page_Store_Warmer::state();
pw_assert($cursor['status'] === 'running' && ($cursor['source'] > 0 || $cursor['last_id'] > 0), 'The cursor is saved in the option after each chunk.');
$done = count($GLOBALS['http']);
$GLOBALS['options']['aat_page_store_warmer_lock'] = time() - 1000; // The killed tick left its lock behind.
$GLOBALS['filters']['aat_page_store_warmer_max_requests'] = 1000;
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === $EXPECTED_URLS, 'A stale lock is retaken, and nothing already stored is requested again.');
$GLOBALS['filters']['aat_page_store_warmer_max_requests'] = 1000;

// ---- Lock keeps one tick at a time -------------------------------------------
pw_reset_site();
pw_enable();
$GLOBALS['options']['aat_page_store_warmer_lock'] = time();
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 0 && isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'A held lock means no overlapping tick; it tries again later.');
pw_cron_tick();
unset($GLOBALS['options']['aat_page_store_warmer_lock']);
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === $EXPECTED_URLS && !isset($GLOBALS['options']['aat_page_store_warmer_lock']), 'The lock is released after a tick.');

// ---- Pause when the server is slow or failing -------------------------------
pw_reset_site();
pw_enable();
$GLOBALS['filters']['aat_page_store_warmer_slow_ms'] = -1; // Every response counts as slow.
pw_cron_tick();
$s = AAT_Page_Store_Warmer::state();
pw_assert(count($GLOBALS['http']) === 1 && $s['status'] === 'paused' && $s['paused_until'] >= time() + AAT_Page_Store_Warmer::BACKOFF_MIN - 2, 'A slow response ends the tick and pauses the warmer.');
pw_assert(strpos($s['last_error'], 'slow response') === 0, 'The pause records its reason.');
$sched = $GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK];
pw_assert($sched >= $s['paused_until'] - 2, 'The next tick waits out the pause.');
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === 1, 'A tick during the pause requests nothing.');
$s = AAT_Page_Store_Warmer::state();
$s['paused_until'] = time() - 1;
$GLOBALS['options']['aat_page_store_warmer_state'] = $s;
pw_cron_tick();
$s2 = AAT_Page_Store_Warmer::state();
pw_assert(count($GLOBALS['http']) === 2 && $s2['slow_streak'] === 2 && $s2['paused_until'] >= time() + 2 * AAT_Page_Store_Warmer::BACKOFF_MIN - 2, 'Repeated slowness doubles the pause.');
pw_assert($s2['warmed'] === 2 && $s2['uncaptured'] === 0, 'The slow page itself still counts as stored, and is not blamed.');
// Recovery: healthy ticks resume from the unfinished chunk and clear the streak.
$GLOBALS['filters']['aat_page_store_warmer_slow_ms'] = 6000;
$s2['paused_until'] = time() - 1;
$GLOBALS['options']['aat_page_store_warmer_state'] = $s2;
pw_cron_tick();
$s3 = AAT_Page_Store_Warmer::state();
pw_assert($s3['status'] === 'complete' && $s3['slow_streak'] === 0 && count(array_unique(pw_paths())) === $EXPECTED_URLS && count(pw_paths()) === $EXPECTED_URLS, 'After the pause the walk finishes, requesting nothing twice, and the streak resets.');
unset($GLOBALS['filters']['aat_page_store_warmer_slow_ms']);

foreach (array('error' => 'request failed', '503' => 'HTTP 503') as $mode => $label) {
    pw_reset_site();
    pw_enable();
    $GLOBALS['http_mode'] = (string) $mode;
    pw_cron_tick();
    $s = AAT_Page_Store_Warmer::state();
    pw_assert(count($GLOBALS['http']) === 1 && $s['status'] === 'paused' && strpos($s['last_error'], $label) === 0 && $s['errors'] === 1, "A $label response pauses the warmer after one request.");
    pw_assert($s['uncaptured'] === 0 && !$s['bad'], "A $label is not blamed on the page.");
}
// A load average above the cap pauses before any request.
pw_reset_site();
pw_enable();
$GLOBALS['filters']['aat_page_store_warmer_max_load'] = 0.0001;
if (function_exists('sys_getloadavg') && @sys_getloadavg() && sys_getloadavg()[0] > 0.0001) {
    pw_cron_tick();
    pw_assert(count($GLOBALS['http']) === 0 && AAT_Page_Store_Warmer::state()['status'] === 'paused', 'A load average over the cap pauses with no request.');
}
unset($GLOBALS['filters']['aat_page_store_warmer_max_load']);

// ---- Pages that render but never store ---------------------------------------
pw_reset_site();
pw_enable();
$GLOBALS['uncapturable'] = array('/film/movie-2/');
pw_cron_tick();
$s = AAT_Page_Store_Warmer::state();
pw_assert($s['status'] === 'complete' && $s['uncaptured'] === 1 && $s['warmed'] === $EXPECTED_URLS - 1 && count($s['bad']) === 1, 'A page that renders but is never stored is counted and remembered.');
$GLOBALS['http'] = array();
$s['next_pass_at'] = time() - 1;
foreach ($wpdb->rows as $k => $r) {
    $wpdb->rows[$k]['stored_at'] = time() - AAT_Page_Store_Warmer::REFRESH_AGE - 60;
}
$GLOBALS['options']['aat_page_store_warmer_state'] = $s;
pw_cron_tick();
pw_assert(!in_array('/film/movie-2/', pw_paths(), true) && count($GLOBALS['http']) === $EXPECTED_URLS - 1, 'It is not requested again on every walk.');

// ---- A retire restarts the walk ----------------------------------------------
pw_reset_site();
pw_enable();
pw_cron_tick();
pw_assert(AAT_Page_Store_Warmer::state()['status'] === 'complete', 'Setup: first walk complete.');
$GLOBALS['http'] = array();
unset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]);
AAT_Page_Store::on_post_status('publish', 'publish', (object) array('post_type' => 'post'));
AAT_Page_Store::flush_editorial_bump();
$when = $GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK] ?? 0;
pw_assert($when >= time() + AAT_Page_Store_Warmer::SETTLE - 2 && $when <= time() + AAT_Page_Store_Warmer::SETTLE + 2, 'A retire schedules a tick after the settle delay.');
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === $EXPECTED_URLS && AAT_Page_Store_Warmer::state()['generation'] === AAT_Page_Store::generation_for('editorial'), 'After a retire the whole site is walked again under the new generation.');
$GLOBALS['http'] = array();
AAT_Page_Store::bump('test');
pw_cron_tick();
pw_assert(count($GLOBALS['http']) === $EXPECTED_URLS, 'A full retire (import, deploy, theme) restarts the walk too.');

// ---- Editorial row life ------------------------------------------------------
pw_reset_site();
pw_assert(AAT_Page_Store::editorial_max_age() === AAT_Page_Store::EDITORIAL_MAX_AGE, 'Without the warmer, editorial rows keep their 6 hour life.');
$GLOBALS['options']['aat_page_store_warmer'] = 'on';
pw_assert(AAT_Page_Store::editorial_max_age() === AAT_Page_Store_Warmer::WARMED_MAX_AGE && AAT_Page_Store_Warmer::WARMED_MAX_AGE === 86400, 'With the warmer on they live 24 hours.');
pw_assert(AAT_Page_Store_Warmer::REFRESH_AGE * 2 <= AAT_Page_Store_Warmer::WARMED_MAX_AGE, 'The refresh age leaves at least half the serve life as headroom for a slow walk.');
$GLOBALS['options']['aat_page_store_scope'] = 'site';
$GLOBALS['options']['aat_page_store_editorial_generation'] = '5';
$GLOBALS['options']['aat_page_store_generation'] = '1';
$rq = new ReflectionProperty('AAT_Page_Store', 'editorial_generation_cache');
$rq->setAccessible(true);
$rq->setValue(null, null);
$gq = new ReflectionProperty('AAT_Page_Store', 'generation_cache');
$gq->setAccessible(true);
$gq->setValue(null, null);
$_SERVER['REQUEST_URI'] = '/film/sister/';
$_COOKIE = array();
$ereq = AAT_Page_Store::eligible_request();
$store = new ReflectionMethod('AAT_Page_Store', 'store_if_clean');
$store->setAccessible(true);
AAT_Page_Store::$test_headers = array('Content-Type: text/html');
AAT_Page_Store::$test_status = 200;
$wpdb->rows = array();
$ehtml = '<!doctype html><html><body>' . str_repeat('Sister ', 3000) . '</body></html>';
$store->invoke(null, $ereq, $ehtml);
$rows = $wpdb->rows;
$rows[$ereq['key']]['stored_at'] = time() - 7 * 3600;
$run = function ($rows, $warmer) {
    $env = 'AAT_STORE_CHILD=1 AAT_TEST_WARMER=' . $warmer . ' AAT_STORE_URI=' . escapeshellarg('/film/sister/') . ' AAT_STORE_ROWS=' . escapeshellarg(base64_encode(serialize($rows)));
    return (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1');
};
pw_assert($run($rows, 'off') === 'FELL-THROUGH', 'A 7 hour old editorial row is not served without the warmer.');
pw_assert($run($rows, 'on') === $ehtml, 'A 7 hour old editorial row is served while the warmer keeps pages fresh.');
$rows[$ereq['key']]['stored_at'] = time() - 25 * 3600;
pw_assert($run($rows, 'on') === 'FELL-THROUGH', 'Nothing editorial is served past 24 hours.');
AAT_Page_Store::$test_headers = null;
AAT_Page_Store::$test_status = null;

// ---- Status and REST ---------------------------------------------------------
pw_reset_site();
pw_enable();
pw_cron_tick();
$st = AAT_Page_Store_Warmer::status();
pw_assert($st['enabled'] === true && $st['status'] === 'complete' && $st['stored_pages'] === $EXPECTED_URLS && $st['expected_pages'] === $EXPECTED_URLS + 1 && $st['coverage_pct'] === 94.7 && $st['walk_generation_matches'] === true, 'Status reports coverage against published, unprotected posts plus archive roots (a post whose permalink has a query string counts as expected, and is never walked).');
pw_assert($st['switches'] === array('store_mode' => 'on', 'scope' => 'site', 'warmer' => 'on') && $st['editorial_row_life_seconds'] === 86400 && $st['pass_counts']['warmed'] === $EXPECTED_URLS, 'Status shows the switches, row life and pass counts.');
AAT_Page_Store_Warmer::register_rest();
$route = $GLOBALS['rest']['lunara-ledger/v1/page-store-warmer'] ?? null;
pw_assert(is_array($route) && $route['methods'] === 'GET', 'The status endpoint is a GET route.');
pw_assert($route['permission_callback']() === false, 'The status endpoint is closed to the public.');
$GLOBALS['can'] = true;
pw_assert($route['permission_callback']() === true && $route['callback']()['expected_pages'] === $EXPECTED_URLS + 1, 'An admin gets the status.');
$GLOBALS['can'] = false;

// ---- Source guards -----------------------------------------------------------
$warm_src = file_get_contents(dirname(__DIR__) . '/includes/class-aat-page-store-warmer.php');
$store_src = file_get_contents(dirname(__DIR__) . '/includes/class-aat-page-store.php');
pw_assert(strpos($warm_src, "get_option(self::OPTION, 'off') === 'on'") !== false, 'The warmer defaults to off.');
pw_assert(strpos($store_src, "require_once __DIR__ . '/class-aat-page-store-warmer.php'") !== false && strpos($store_src, 'AAT_Page_Store_Warmer::init()') !== false, 'The page store loads and starts the warmer.');
pw_assert(strpos($warm_src, 'wp_remote_get') !== false && substr_count($warm_src, 'wp_remote_get(') === 1 && strpos($warm_src, 'wp_remote_request') === false && strpos($warm_src, 'curl_multi') === false, 'One blocking request at a time: no parallel fetching.');
pw_assert(AAT_Page_Store_Warmer::GAP_MS >= 100 && AAT_Page_Store_Warmer::MAX_REQUESTS <= 30 && AAT_Page_Store_Warmer::TICK_BUDGET <= 60 && AAT_Page_Store_Warmer::SLOW_MS <= 10000, 'Default throttle: a pause between requests, a request cap and time budget per tick, and a slow limit.');

echo "Page store warmer runtime passed: {$checks} checks.\n";
