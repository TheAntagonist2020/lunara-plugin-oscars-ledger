<?php
/**
 * Page store editorial scope: eligibility, separate generation, retirement on
 * edits, capture and serve, against the real class with WordPress and $wpdb
 * stubbed. The scope is off by default and widens only when the option
 * aat_page_store_scope is 'site'.
 *
 * Run: php tests/page-store-editorial-runtime.php
 */
if (getenv('AAT_STORE_CHILD') === '1') {
    // Child process: serve one request from a pre-filled store and exit.
    require __DIR__ . '/page-store-stubs.inc';
    $GLOBALS['options']['aat_page_store_scope'] = getenv('AAT_TEST_SCOPE');
    $GLOBALS['options']['aat_page_store_editorial_generation'] = '5';
    $GLOBALS['options']['aat_page_store_generation'] = '1';
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

$gen = function () {
    $r = new ReflectionProperty('AAT_Page_Store', 'generation_cache');
    $r->setAccessible(true);
    $r->setValue(null, null);
    return AAT_Page_Store::generation();
};
$html = '<!doctype html><html><body>' . str_repeat('Titanic ', 3000) . '</body></html>';
$store = new ReflectionMethod('AAT_Page_Store', 'store_if_clean');
$store->setAccessible(true);

// Default scope is 'oscars': editorial routes are untouched until the option is set.
ps_assert(AAT_Page_Store::scope() === 'oscars', 'The default scope is Oscars only.');
ps_assert(ps_request('/film/sister/') === null && ps_request('/reviews/') === null && ps_request('/journal/some-post/') === null, 'Editorial routes are not stored by default.');
AAT_Page_Store::on_post_status('publish', 'draft', (object) array('post_type' => 'movie'));
AAT_Page_Store::flush_editorial_bump();
ps_assert(!isset($GLOBALS['options']['aat_page_store_editorial_generation']), 'Editorial hooks do nothing while the scope is Oscars only.');

$GLOBALS['options']['aat_page_store_scope'] = 'site';
foreach (array('/film/sister/', '/film/', '/talent/', '/talent/some-person-name/', '/reviews/the-uprising-what-could-have-been/', '/reviews/', '/journal/a-post/', '/journal/') as $uri) {
    $r = ps_request($uri);
    ps_assert(is_array($r) && $r['kind'] === 'editorial', "$uri is storable in the site scope.");
}
$o = ps_request('/oscars/ceremony/70/');
ps_assert(is_array($o) && $o['kind'] === 'oscars', 'Oscars routes keep their own kind.');
foreach (array('/film/page/2/', '/film/sister', '/film/a/b/', '/journal/section/news/', '/film/sister/?cb=1', '/film/sister/?ledger=full', '/reviews/?sort=release_asc', '/', '/about/', '/oscars/', '/wp-admin/') as $uri) {
    ps_assert(ps_request($uri) === null, "$uri is never stored.");
}
ps_assert(ps_request('/film/sister/', array('wordpress_logged_in_abc' => 'x')) === null && ps_request('/film/sister/', array('comment_author_abc' => 'x')) === null, 'Logged-in and commenter visitors bypass editorial pages too.');
ps_assert(ps_request('/film/sister/', array(), 'POST') === null, 'Editorial pages: only GET and HEAD.');
$GLOBALS['options']['aat_page_store_mode'] = 'off';
ps_assert(ps_request('/film/sister/') === null, 'The kill switch also turns the editorial scope off.');
unset($GLOBALS['options']['aat_page_store_mode']);

// Separate generation: editorial edits leave the warmed Oscars pages alone.
$egen = function () {
    $r = new ReflectionProperty('AAT_Page_Store', 'editorial_generation_cache');
    $r->setAccessible(true);
    $r->setValue(null, null);
    return AAT_Page_Store::generation_for('editorial');
};
$og0 = $gen();
$eg0 = $egen();
ps_assert($eg0 !== $og0 && AAT_Page_Store::generation_for('oscars') === $og0, 'Editorial rows have their own generation.');
AAT_Page_Store::on_post_status('draft', 'draft', (object) array('post_type' => 'movie'));
AAT_Page_Store::flush_editorial_bump();
ps_assert($egen() === $eg0, 'Saving a draft retires nothing.');
AAT_Page_Store::on_post_status('publish', 'publish', (object) array('post_type' => 'movie'));
AAT_Page_Store::on_post_status('publish', 'publish', (object) array('post_type' => 'journal'));
AAT_Page_Store::on_post_status('trash', 'publish', (object) array('post_type' => 'person'));
ps_assert($egen() === $eg0, 'Retirement waits for shutdown, so a bulk import bumps once.');
AAT_Page_Store::flush_editorial_bump();
$eg1 = $egen();
ps_assert($eg1 !== $eg0 && $GLOBALS['options']['aat_page_store_editorial_generation'] === '1', 'Editing, publishing or trashing a published post retires editorial rows, once per request.');
ps_assert($gen() === $og0, 'Editorial edits do not retire Oscars pages.');
AAT_Page_Store::on_post_status('publish', 'publish', (object) array('post_type' => 'wp_navigation'));
AAT_Page_Store::on_post_status('publish', 'draft', (object) array('post_type' => 'nav_menu_item'));
AAT_Page_Store::flush_editorial_bump();
ps_assert($egen() === $eg1, 'Post types that feed no editorial page retire nothing.');
AAT_Page_Store::on_editorial_change();
AAT_Page_Store::flush_editorial_bump();
$eg2 = $egen();
ps_assert($eg2 !== $eg1, 'A comment, term or menu change retires editorial rows.');
AAT_Page_Store::on_option_updated('sidebars_widgets');
AAT_Page_Store::flush_editorial_bump();
$eg3 = $egen();
ps_assert($eg3 !== $eg2, 'A widget change retires editorial rows.');
AAT_Page_Store::on_option_updated('some_other_option');
AAT_Page_Store::flush_editorial_bump();
ps_assert($egen() === $eg3, 'Unrelated options retire nothing.');
AAT_Page_Store::bump('test');
ps_assert($egen() !== $eg3 && $gen() !== $og0, 'A data import, deploy or theme change retires both kinds.');
$GLOBALS['options']['aat_page_store_generation'] = '1';
$gen();
$GLOBALS['options']['aat_page_store_editorial_generation'] = '5';
$egen();

// Capture and serve an editorial page (child process again).
$ereq = ps_request('/film/sister/');
$ehtml = '<!doctype html><html><body>' . str_repeat('Sister ', 3000) . '</body></html>';
$GLOBALS['wpdb']->rows = array();
AAT_Page_Store::$test_headers = array('Content-Type: text/html');
AAT_Page_Store::$test_status = 200;
$store->invoke(null, $ereq, $ehtml);
$erow = $GLOBALS['wpdb']->rows[$ereq['key']] ?? null;
ps_assert(is_array($erow) && $erow['generation'] === AAT_Page_Store::generation_for('editorial') && $erow['generation'] !== AAT_Page_Store::generation(), 'An editorial page is stored under the editorial generation.');
$oreq = ps_request('/oscars/ceremony/70/');
$store->invoke(null, $oreq, $html);
$erows = base64_encode(serialize($GLOBALS['wpdb']->rows));
$erun = function ($uri, $rows_b64, $scope = 'site') {
    $env = 'AAT_STORE_CHILD=1 AAT_STORE_URI=' . escapeshellarg($uri) . ' AAT_STORE_ROWS=' . escapeshellarg($rows_b64) . ' AAT_TEST_SCOPE=' . $scope;
    return (string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1');
};
ps_assert($erun('/film/sister/', $erows) === $ehtml, 'A stored editorial page is served whole.');
ps_assert($erun('/film/sister/', $erows, 'oscars') === 'FELL-THROUGH', 'With the scope back at Oscars only, the same row is not served.');
ps_assert($erun('/oscars/ceremony/70/', $erows) === $html, 'Oscars rows still serve beside editorial rows.');
$old = $GLOBALS['wpdb']->rows;
$old[$ereq['key']]['stored_at'] = time() - AAT_Page_Store::EDITORIAL_MAX_AGE - 5;
ps_assert($erun('/film/sister/', base64_encode(serialize($old))) === 'FELL-THROUGH', 'An editorial row older than 6 hours is not served.');
$old = $GLOBALS['wpdb']->rows;
$old[$oreq['key']]['stored_at'] = time() - AAT_Page_Store::EDITORIAL_MAX_AGE - 5;
ps_assert($erun('/oscars/ceremony/70/', base64_encode(serialize($old))) === $html, 'Oscars rows keep their 12 hour life.');
$old = $GLOBALS['wpdb']->rows;
$old[$ereq['key']]['generation'] = AAT_Page_Store::generation();
ps_assert($erun('/film/sister/', base64_encode(serialize($old))) === 'FELL-THROUGH', 'An editorial request never takes a row stored under the Oscars generation.');
AAT_Page_Store::$test_headers = null;
AAT_Page_Store::$test_status = null;
$GLOBALS['options']['aat_page_store_scope'] = 'oscars';


$store_src = file_get_contents(dirname(__DIR__) . '/includes/class-aat-page-store.php');
ps_assert(strpos($store_src, "get_option(self::SCOPE_OPTION, 'oscars')") !== false, 'The editorial scope defaults to Oscars only.');
ps_assert(strpos($store_src, 'editorial_page_is_storable()') !== false && strpos($store_src, 'post_password_required()') !== false && strpos($store_src, 'is_paged()') !== false, 'Editorial capture re-checks the WordPress query: published, unprotected, first page, right post type.');
ps_assert(strpos($store_src, "'before_delete_post'") !== false, 'Permanent deletes retire editorial rows before the post is gone.');
ps_assert(strpos($store_src, "\$request['generation'] = self::generation_for(") !== false, 'Capture pins the generation before rendering.');

echo "Page store editorial runtime passed: {$checks} checks.\n";
