<?php
/**
 * Oscars page store (2.8.14): eligibility, generation, capture and serve,
 * against the real class with WordPress and $wpdb stubbed.
 *
 * Run: php tests/page-store-runtime.php
 */
if (getenv('AAT_STORE_CHILD') === '1') {
    // Child process: serve one request from a pre-filled store and exit.
    require __DIR__ . '/page-store-stubs.inc';
    $GLOBALS['wpdb']->rows = unserialize(base64_decode(getenv('AAT_STORE_ROWS')));
    $_SERVER['REQUEST_URI'] = getenv('AAT_STORE_URI');
    AAT_Page_Store::maybe_serve();
    echo 'FELL-THROUGH';
    exit;
}

require __DIR__ . '/page-store-stubs.inc';

$checks = 0;
function ps_assert($ok, $msg) {
    global $checks;
    ++$checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}
function ps_request($uri, $cookies = array(), $method = 'GET') {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_COOKIE = $cookies;
    return AAT_Page_Store::eligible_request();
}

// ---- Eligibility --------------------------------------------------------------
ps_assert(is_array(ps_request('/oscars/title/tt0120338/')), 'A title page is storable.');
ps_assert(is_array(ps_request('/oscars/name/nm0000658/')) && is_array(ps_request('/oscars/name/lnm-some-person/')), 'Person pages (IMDb and local ids) are storable.');
ps_assert(is_array(ps_request('/oscars/ceremony/70/')) && is_array(ps_request('/oscars/ceremony/70/?ledger=full')), 'A ceremony and its full ballot are storable.');
ps_assert(is_array(ps_request('/oscars/category/best-picture/?history=full')), 'A category history variant is storable.');
ps_assert(is_array(ps_request('/oscars/ceremonies/')), 'The ceremonies index is storable.');
ps_assert(ps_request('/oscars/') === null, 'The /oscars/ portal (a theme page) is left to the platform.');
ps_assert(ps_request('/oscars/explore/') === null, 'The Explorer is not stored.');
ps_assert(ps_request('/oscars/title/tt0120338') === null, 'Only the canonical trailing-slash URL is stored.');
ps_assert(ps_request('/oscars/title/tt0120338/?utm_source=x') === null && ps_request('/oscars/ceremony/70/?view=table') === null, 'Any other query string renders fresh.');
ps_assert(ps_request('/oscars/title/tt0120338/', array('wordpress_logged_in_abc' => 'x')) === null, 'Logged-in visitors never get a stored page.');
ps_assert(ps_request('/oscars/title/tt0120338/', array('wp-postpass_abc' => 'x')) === null && ps_request('/oscars/title/tt0120338/', array('comment_author_abc' => 'x')) === null, 'Password and commenter cookies bypass the store.');
ps_assert(is_array(ps_request('/oscars/title/tt0120338/', array('_ga' => 'x', 'wordpress_test_cookie' => 'x'))), 'Analytics and the test cookie do not.');
ps_assert(ps_request('/oscars/title/tt0120338/', array(), 'POST') === null, 'Only GET and HEAD.');
$_SERVER['HTTP_X_WP_NONCE'] = 'n';
ps_assert(ps_request('/oscars/title/tt0120338/') === null, 'A request carrying a nonce bypasses the store.');
unset($_SERVER['HTTP_X_WP_NONCE']);
$GLOBALS['options']['blog_public'] = '0';
ps_assert(ps_request('/oscars/title/tt0120338/') === null, 'A private site never serves stored pages.');
$GLOBALS['options']['blog_public'] = '1';
$GLOBALS['options']['aat_page_store_mode'] = 'off';
ps_assert(ps_request('/oscars/title/tt0120338/') === null, 'The kill switch turns the store off.');
unset($GLOBALS['options']['aat_page_store_mode']);
$a = ps_request('/oscars/ceremony/70/');
$b = ps_request('/oscars/ceremony/70/?ledger=full');
ps_assert($a['key'] !== $b['key'], 'Each variant has its own key.');

// ---- Generation ---------------------------------------------------------------
$gen = function () {
    $r = new ReflectionProperty('AAT_Page_Store', 'generation_cache');
    $r->setAccessible(true);
    $r->setValue(null, null);
    return AAT_Page_Store::generation();
};
$g1 = $gen();
$GLOBALS['options']['aat_dataset_stamp'] = 'new-stamp';
$g2 = $gen();
ps_assert($g1 !== $g2, 'A new dataset stamp retires stored pages.');
AAT_Page_Store::bump('test');
$g3 = $gen();
ps_assert($g3 !== $g2 && $GLOBALS['options']['aat_page_store_generation'] === '1', 'A bump retires stored pages.');
ps_assert(!empty($GLOBALS['scheduled']['aat_page_store_warm']), 'A bump queues warming.');

$before = $GLOBALS['options']['aat_page_store_generation'];
AAT_Page_Store::on_option_updated('theme_mods_lunara-theme-blocks');
ps_assert($GLOBALS['options']['aat_page_store_generation'] !== $before, 'Saving theme mods (Site Studio, Customizer) retires stored pages.');
$before = $GLOBALS['options']['aat_page_store_generation'];
AAT_Page_Store::on_option_updated('some_other_option');
AAT_Page_Store::on_post_status('publish', 'publish', (object) array('post_type' => 'review'));
AAT_Page_Store::on_post_status('publish', 'draft', (object) array('post_type' => 'post'));
ps_assert($GLOBALS['options']['aat_page_store_generation'] === $before, 'Unrelated options, review re-saves and other post types do not.');
AAT_Page_Store::on_post_status('publish', 'draft', (object) array('post_type' => 'review'));
ps_assert($GLOBALS['options']['aat_page_store_generation'] !== $before, 'Publishing a review retires stored pages.');
$GLOBALS['options']['aat_page_store_generation'] = '1';
$g3 = $gen();

// ---- Capture ------------------------------------------------------------------
$req = ps_request('/oscars/title/tt0120338/');
$html = '<!doctype html><html><body>' . str_repeat('Titanic ', 3000) . '</body></html>';
$store = new ReflectionMethod('AAT_Page_Store', 'store_if_clean');
$store->setAccessible(true);
AAT_Page_Store::$test_headers = array('Content-Type: text/html');
$store->invoke(null, $req, $html);
$row = $GLOBALS['wpdb']->rows[$req['key']] ?? null;
ps_assert(is_array($row) && gzuncompress($row['html']) === $html && $row['generation'] === $g3 && $row['path'] === '/oscars/title/tt0120338/', 'A clean 200 page is stored compressed under the current generation.');
$GLOBALS['wpdb']->rows = array();
$store->invoke(null, $req, '<html>tiny</html>');
ps_assert(empty($GLOBALS['wpdb']->rows), 'A suspiciously small page is not stored.');
AAT_Page_Store::$test_headers = array('Set-Cookie: x=1');
$store->invoke(null, $req, $html);
ps_assert(empty($GLOBALS['wpdb']->rows), 'A page that sets a cookie is not stored.');
AAT_Page_Store::$test_headers = array('Cache-Control: no-cache, must-revalidate');
$store->invoke(null, $req, $html);
ps_assert(empty($GLOBALS['wpdb']->rows), 'A no-cache page is not stored.');
AAT_Page_Store::$test_headers = array();
AAT_Page_Store::$test_status = 404;
$store->invoke(null, $req, $html);
ps_assert(empty($GLOBALS['wpdb']->rows), 'A non-200 page is not stored.');
AAT_Page_Store::$test_status = 200;

// ---- Serve (child process, because a hit exits) --------------------------------
$store->invoke(null, $req, $html);
$rows = base64_encode(serialize($GLOBALS['wpdb']->rows));
$run = function ($uri, $rows_b64) {
    $env = 'AAT_STORE_CHILD=1 AAT_STORE_URI=' . escapeshellarg($uri) . ' AAT_STORE_ROWS=' . escapeshellarg($rows_b64) . ' AAT_TEST_STAMP=new-stamp AAT_TEST_GEN=1';
    return (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1');
};
$out = $run('/oscars/title/tt0120338/', $rows);
if ($out !== $html) { fwrite(STDERR, substr($out, 0, 400)); } ps_assert($out === $html, 'A stored page is served whole, and the request stops there.');
$out = $run('/oscars/title/tt0111161/', $rows);
ps_assert($out === 'FELL-THROUGH', 'A page with no row falls through to WordPress.');
$old = $GLOBALS['wpdb']->rows;
foreach ($old as $k => $r) { $old[$k]['stored_at'] = time() - AAT_Page_Store::MAX_AGE - 5; }
$out = $run('/oscars/title/tt0120338/', base64_encode(serialize($old)));
ps_assert($out === 'FELL-THROUGH', 'A row older than MAX_AGE is not served.');
$old = $GLOBALS['wpdb']->rows;
foreach ($old as $k => $r) { $old[$k]['generation'] = 'retired-gen-0000'; }
$out = $run('/oscars/title/tt0120338/', base64_encode(serialize($old)));
ps_assert($out === 'FELL-THROUGH', 'A row from a retired generation is not served.');

// ---- Warming survives a killed run (2.8.15) -----------------------------------
AAT_Page_Store::schedule(); // First request after a deploy: notices the generation and queues warming.
$paths = AAT_Page_Store::warm_paths();
ps_assert(count($paths) === 10 && in_array('/oscars/ceremony/3/?ledger=full', $paths, true) && in_array('/oscars/category/best-picture/', $paths, true), 'The warm list covers the indexes, every ceremony in both views and every category.');
unset($GLOBALS['scheduled']['aat_page_store_warm']);
$GLOBALS['warm_kill_after'] = 3;
try { AAT_Page_Store::warm_batch(); } catch (RuntimeException $e) {}
$q = $GLOBALS['options']['aat_page_store_warm_queue'];
ps_assert(intval($q['offset']) === 3, 'A killed run keeps its place: progress is saved after every page.');
ps_assert(!empty($GLOBALS['scheduled']['aat_page_store_warm']), 'A killed run has already scheduled the next one.');
unset($GLOBALS['scheduled']['aat_page_store_warm']);
AAT_Page_Store::schedule();
ps_assert(!empty($GLOBALS['scheduled']['aat_page_store_warm']), 'A stalled queue with nothing scheduled is picked up again on the next request.');
$GLOBALS['warm_kill_after'] = 0;
AAT_Page_Store::warm_batch();
ps_assert(count($GLOBALS['warm_requests']) === 10 && $GLOBALS['warm_requests'][3] === 'https://lunarafilm.test/oscars/ceremony/3/?ledger=full' && count(array_unique($GLOBALS['warm_requests'])) === 10, 'The next run carries on from where the killed one stopped, without repeating pages.');
ps_assert(!isset($GLOBALS['options']['aat_page_store_warm_queue']) && empty($GLOBALS['scheduled']['aat_page_store_warm']), 'A finished queue is removed and stops scheduling.');
$report = AAT_Page_Store::report();
ps_assert($report['warm_queue'] === null && $report['last_warm_run']['to'] === 10 && $report['last_warm_run']['total'] === 10, '/status can report the warm state.');

// ---- A run that cannot get going stops instead of looping every minute (2.8.20) --
// On live, 2.8.14-2.8.19 threw a fatal inside warm_paths() on every run, and
// because 2.8.15 schedules the next run first, WP-Cron re-ran the crash each
// minute for days. The warmer must notice and stop.
AAT_Page_Store::queue_warm();
$GLOBALS['warm_requests'] = array();
$GLOBALS['warm_paths_fail'] = true;
AAT_Page_Store::warm_batch();
$GLOBALS['warm_paths_fail'] = false;
ps_assert(empty($GLOBALS['warm_requests']), 'Nothing is requested when the warm list cannot be built.');
ps_assert(!isset($GLOBALS['options']['aat_page_store_warm_queue']) && empty($GLOBALS['scheduled']['aat_page_store_warm']), 'A run whose warm list throws gives up: queue removed, no next run scheduled.');
$log = $GLOBALS['options']['aat_page_store_warm_log'];
ps_assert(!empty($log['error']) && strpos($log['error'], 'boom') !== false, 'The failure is recorded for /status.');

// Runs that die before saving a page (time limit, memory, an uncatchable
// fatal): three in a row at the same page and the warmer stops.
AAT_Page_Store::queue_warm();
$GLOBALS['warm_kill_before'] = true;
for ($i = 1; $i <= AAT_Page_Store::WARM_MAX_ATTEMPTS; $i++) {
    unset($GLOBALS['scheduled']['aat_page_store_warm']);
    $died = false;
    try { AAT_Page_Store::warm_batch(); } catch (RuntimeException $e) { $died = true; }
    ps_assert($died && !empty($GLOBALS['scheduled']['aat_page_store_warm']) && isset($GLOBALS['options']['aat_page_store_warm_queue']), "Dead run $i keeps the queue and a retry scheduled.");
}
unset($GLOBALS['scheduled']['aat_page_store_warm']);
AAT_Page_Store::warm_batch(); // one more attempt at the same page: gives up before requesting anything
$GLOBALS['warm_kill_before'] = false;
ps_assert(!isset($GLOBALS['options']['aat_page_store_warm_queue']) && empty($GLOBALS['scheduled']['aat_page_store_warm']), 'After ' . AAT_Page_Store::WARM_MAX_ATTEMPTS . ' runs die at the same page the warmer stops instead of retrying every minute.');
$log = $GLOBALS['options']['aat_page_store_warm_log'];
ps_assert(!empty($log['error']) && strpos($log['error'], 'offset 0') !== false, 'The stop is recorded with the page it stuck on.');

// Progress is not a strike: a run that saves even one page resets the count.
AAT_Page_Store::queue_warm();
$GLOBALS['warm_kill_after'] = 1;
for ($i = 1; $i <= 5; $i++) {
    $GLOBALS['warm_requests'] = array();
    unset($GLOBALS['scheduled']['aat_page_store_warm']);
    try { AAT_Page_Store::warm_batch(); } catch (RuntimeException $e) {}
}
$q = $GLOBALS['options']['aat_page_store_warm_queue'] ?? null;
ps_assert(is_array($q) && intval($q['offset']) === 5 && !empty($GLOBALS['scheduled']['aat_page_store_warm']), 'Five runs that each die after one page keep going: progress resets the dead-run count.');
$GLOBALS['warm_kill_after'] = 0;
$GLOBALS['warm_requests'] = array();
AAT_Page_Store::warm_batch();
ps_assert(count($GLOBALS['warm_requests']) === 5 && !isset($GLOBALS['options']['aat_page_store_warm_queue']), 'The next healthy run finishes the remaining pages and clears the queue.');
$log = $GLOBALS['options']['aat_page_store_warm_log'];
ps_assert(empty($log['error']) && $log['to'] === 10, 'A finished run replaces the old failure record.');

// ---- Wiring -------------------------------------------------------------------
$main = file_get_contents(dirname(__DIR__) . '/academy-awards-table.php');
ps_assert(strpos($main, "AAT_Page_Store::maybe_serve();") !== false && strpos($main, "AAT_Page_Store::maybe_serve();") < strpos($main, 'class Academy_Awards_Table'), 'The store serves at plugin include time, before the main class loads.');
ps_assert(substr_count($main, "AAT_Page_Store::bump(") >= 2, 'Data-changing cache clears retire stored pages.');

echo "Page store runtime passed: {$checks} checks.\n";
