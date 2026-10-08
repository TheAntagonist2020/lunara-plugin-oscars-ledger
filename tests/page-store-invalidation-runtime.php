<?php
/**
 * Page store editorial scope: per-URL invalidation. Editing a film, person,
 * review or journal post (or its comments and terms) deletes only that page's
 * row, its archive root and its term archives, leaving every other stored page
 * warm. Site-wide changes still retire all editorial rows.
 *
 * Run: php tests/page-store-invalidation-runtime.php
 */
require __DIR__ . '/page-store-stubs.inc';

$checks = 0;
function pi_assert($ok, $msg) {
    global $checks;
    ++$checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

// ---- WordPress stubs ---------------------------------------------------------
$GLOBALS['filters'] = array();
$GLOBALS['posts'] = array();      // id => object
$GLOBALS['comments'] = array();   // id => object
$GLOBALS['terms'] = array();      // post id => array of term links
$GLOBALS['taxonomies'] = array('genre' => (object) array('object_type' => array('movie')), 'category' => (object) array('object_type' => array('post')));
$GLOBALS['term_links'] = array();  // "id:tax" => url
$GLOBALS['term_objects'] = array(); // "id:tax" => ids
function apply_filters($name, $value, ...$rest) { return isset($GLOBALS['filters'][$name]) ? call_user_func($GLOBALS['filters'][$name], $value, ...$rest) : $value; }
function add_option($k, $v = '', $d = '', $a = 'yes') { if (array_key_exists($k, $GLOBALS['options'])) { return false; } $GLOBALS['options'][$k] = $v; return true; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['scheduled'][$h]); }
function home_url($path = '') { return 'https://lunarafilm.test' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_permalink($post) { return is_object($post) ? ($post->url ?? false) : ($GLOBALS['posts'][$post]->url ?? false); }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_comment($id) { return $GLOBALS['comments'][$id] ?? null; }
function get_post_type_archive_link($type) { return array('movie' => 'https://lunarafilm.test/film/', 'person' => 'https://lunarafilm.test/talent/', 'review' => 'https://lunarafilm.test/reviews/', 'journal' => 'https://lunarafilm.test/journal/')[$type] ?? false; }
function get_object_taxonomies($type) { return $type === 'movie' ? array('genre') : array(); }
function get_the_terms($post, $tax) { return $GLOBALS['terms'][$post->ID] ?? false; }
function get_term_link($term, $tax = '') { return is_object($term) ? $term->link : ($GLOBALS['term_links'][$term . ':' . $tax] ?? false); }
function get_taxonomy($tax) { return $GLOBALS['taxonomies'][$tax] ?? false; }
function get_objects_in_term($id, $tax) { return $GLOBALS['term_objects'][$id . ':' . $tax] ?? array(); }
function wp_cache_delete($k, $g = '') { return true; }

class Inval_WPDB extends Page_Store_WPDB {
    public $deletes = array();
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return array($sql, $args);
    }
    public function query($q) {
        if (is_array($q) && strpos($q[0], 'DELETE FROM') === 0) {
            foreach ($q[1] as $key) {
                $this->deletes[] = $key;
                unset($this->rows[$key]);
            }
        }
        return true;
    }
}
$wpdb = new Inval_WPDB();
$GLOBALS['wpdb'] = $wpdb;

function pi_key($path, $host = 'lunarafilm.test') { return sha1($host . $path . '?'); }
function pi_row($path, $kind = 'editorial') {
    return array('cache_key' => pi_key($path), 'generation' => AAT_Page_Store::generation_for($kind), 'path' => $path, 'html' => 'x', 'stored_at' => time());
}
function pi_fill() {
    global $wpdb;
    $wpdb->rows = array();
    $wpdb->deletes = array();
    foreach (array('/film/sister/', '/film/other/', '/film/', '/talent/', '/reviews/', '/reviews/sister-review/', '/journal/', '/film/drama/', '/film/old-slug/') as $path) {
        $wpdb->rows[pi_key($path)] = pi_row($path);
    }
    $wpdb->rows[pi_key('/oscars/ceremony/70/')] = pi_row('/oscars/ceremony/70/', 'oscars');
}
function pi_has($path) { return isset($GLOBALS['wpdb']->rows[pi_key($path)]); }
function pi_post($id, $type, $status, $url) {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'url' => $url);
    return $GLOBALS['posts'][$id];
}
$egen = function () {
    $r = new ReflectionProperty('AAT_Page_Store', 'editorial_generation_cache');
    $r->setAccessible(true);
    $r->setValue(null, null);
    return AAT_Page_Store::generation_for('editorial');
};

$sister = pi_post(10, 'movie', 'publish', 'https://lunarafilm.test/film/sister/');
$other = pi_post(11, 'movie', 'publish', 'https://lunarafilm.test/film/other/');
$review = pi_post(12, 'review', 'publish', 'https://lunarafilm.test/reviews/sister-review/');
$plain = pi_post(13, 'post', 'publish', 'https://lunarafilm.test/2026/10/hello/');
$GLOBALS['terms'][10] = array((object) array('link' => 'https://lunarafilm.test/film/drama/'), (object) array('link' => 'https://lunarafilm.test/genre/drama/'));

// ---- Off by default ----------------------------------------------------------
pi_fill();
$g0 = $egen();
AAT_Page_Store::on_post_status('publish', 'publish', $sister);
AAT_Page_Store::on_comment_change(1);
AAT_Page_Store::on_term_change(5, 5, 'genre');
AAT_Page_Store::on_post_deleted(10);
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'With the scope at Oscars only, no invalidation is queued.');
pi_assert(AAT_Page_Store::flush_invalidations() === 0 && $wpdb->deletes === array(), 'Nothing is deleted while the scope is Oscars only.');

$GLOBALS['options']['aat_page_store_scope'] = 'site';
$og = AAT_Page_Store::generation();
$g0 = $egen();

// ---- Editing a published film ------------------------------------------------
pi_fill();
AAT_Page_Store::on_post_status('publish', 'publish', $sister);
$queued = AAT_Page_Store::pending_invalidations();
sort($queued);
pi_assert($queued === array('/film/', '/film/drama/', '/film/sister/'), 'A film edit queues its page, its archive root and the term archives that fit the store (' . implode(',', $queued) . ').');
pi_assert(pi_has('/film/sister/'), 'Rows stay until shutdown, so a bulk save deletes once.');
$n = AAT_Page_Store::flush_invalidations();
pi_assert($n === 3 && count($wpdb->deletes) === 3, 'Shutdown deletes exactly the queued rows.');
pi_assert(!pi_has('/film/sister/') && !pi_has('/film/') && !pi_has('/film/drama/'), 'The edited page, its archive root and its term archive are gone.');
pi_assert(pi_has('/film/other/') && pi_has('/talent/') && pi_has('/reviews/') && pi_has('/reviews/sister-review/') && pi_has('/journal/') && pi_has('/film/old-slug/') && pi_has('/oscars/ceremony/70/'), 'Every other stored page stays.');
pi_assert($egen() === $g0 && AAT_Page_Store::generation() === $og && !isset($GLOBALS['options']['aat_page_store_editorial_generation']), 'No generation moved: nothing else was retired.');
pi_assert(isset($GLOBALS['options'][AAT_Page_Store::INVALIDATION_OPTION]) && floatval($GLOBALS['options'][AAT_Page_Store::INVALIDATION_OPTION]) > 0, 'The delete leaves a timestamp for renders in flight.');
pi_assert(AAT_Page_Store::flush_invalidations() === 0, 'A second flush has nothing to do.');

// Each type has its own archive root.
foreach (array(array($review, '/reviews/', '/reviews/sister-review/'), array(pi_post(14, 'journal', 'publish', 'https://lunarafilm.test/journal/a-post/'), '/journal/', '/journal/a-post/'), array(pi_post(15, 'person', 'publish', 'https://lunarafilm.test/talent/some-person/'), '/talent/', '/talent/some-person/')) as $case) {
    pi_fill();
    $wpdb->rows[pi_key($case[2])] = pi_row($case[2]);
    AAT_Page_Store::on_post_status('publish', 'publish', $case[0]);
    AAT_Page_Store::flush_invalidations();
    pi_assert(!pi_has($case[1]) && !pi_has($case[2]) && pi_has('/film/') && pi_has('/film/sister/'), $case[0]->post_type . ': its own archive root and page only.');
}

// ---- Saves that change nothing public ----------------------------------------
pi_fill();
$draft = pi_post(16, 'movie', 'draft', 'https://lunarafilm.test/?p=16');
AAT_Page_Store::on_post_status('draft', 'draft', $draft);
AAT_Page_Store::on_post_status('auto-draft', 'new', $draft);
AAT_Page_Store::on_post_updated(16, $draft, $draft);
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'Saving a draft queues nothing.');
$before = (object) array('ID' => 10, 'post_type' => 'movie', 'post_status' => 'publish', 'url' => 'https://lunarafilm.test/film/old-slug/');
$after = (object) array('ID' => 10, 'post_type' => 'movie', 'post_status' => 'publish', 'url' => 'https://lunarafilm.test/film/sister/');
AAT_Page_Store::on_post_updated(10, $after, $before);
pi_assert(AAT_Page_Store::pending_invalidations() === array('/film/old-slug/'), 'A slug change deletes the old address too.');
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/film/old-slug/') && pi_has('/film/sister/'), 'Only the old address went.');
$draft_before = (object) array('ID' => 17, 'post_type' => 'movie', 'post_status' => 'draft', 'url' => 'https://lunarafilm.test/?p=17');
AAT_Page_Store::on_post_updated(17, $after, $draft_before);
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'A post that was not public has no old address.');

// Unpublish and trash: the old address goes, the archive root goes, the (bogus) trashed permalink is not queued.
pi_fill();
$trashed = (object) array('ID' => 10, 'post_type' => 'movie', 'post_status' => 'trash', 'url' => 'https://lunarafilm.test/film/sister__trashed/');
AAT_Page_Store::on_post_updated(10, $trashed, $sister);
AAT_Page_Store::on_post_status('trash', 'publish', $trashed);
$queued = AAT_Page_Store::pending_invalidations();
sort($queued);
pi_assert($queued === array('/film/', '/film/drama/', '/film/sister/'), 'Trashing a film removes its page, its archive root and its term archives only (' . implode(',', $queued) . ').');
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/film/sister/') && !pi_has('/film/') && pi_has('/film/other/'), 'Trashing deletes just those rows.');

// Publishing a new post.
pi_fill();
unset($wpdb->rows[pi_key('/film/')]);
$new = pi_post(18, 'movie', 'publish', 'https://lunarafilm.test/film/brand-new/');
AAT_Page_Store::on_post_status('publish', 'draft', $new);
pi_assert(in_array('/film/brand-new/', AAT_Page_Store::pending_invalidations(), true) && in_array('/film/', AAT_Page_Store::pending_invalidations(), true), 'Publishing queues the new page and the archive root that now lists it.');
AAT_Page_Store::flush_invalidations();

// Permanent delete.
pi_fill();
AAT_Page_Store::on_post_deleted(10);
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/film/sister/') && !pi_has('/film/') && pi_has('/film/other/'), 'A permanent delete drops the page and its archive root.');
pi_fill();
AAT_Page_Store::on_post_deleted(16); // draft: never stored
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'Deleting a draft queues nothing.');
$e0 = $egen();
AAT_Page_Store::on_post_deleted(13);
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $e0, 'Deleting a post that feeds editorial modules still retires editorial rows.');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();

// ---- Posts and pages feed many pages: still a full editorial retire -----------
$e0 = $egen();
AAT_Page_Store::on_post_status('publish', 'publish', $plain);
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'A plain post queues no URL.');
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $e0, 'A published post or page edit retires editorial rows.');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();

// ---- Comments ----------------------------------------------------------------
pi_fill();
$GLOBALS['comments'][1] = (object) array('comment_post_ID' => 12);
AAT_Page_Store::on_comment_change(1);
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/reviews/sister-review/') && !pi_has('/reviews/') && pi_has('/film/sister/') && pi_has('/film/'), 'A comment on a review deletes that review and its archive root only.');
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() === $g0, 'A comment on a review retires nothing else.');
$GLOBALS['comments'][2] = (object) array('comment_post_ID' => 13);
AAT_Page_Store::on_comment_change(2);
AAT_Page_Store::on_comment_change(99); // unresolvable (already deleted)
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $g0, 'A comment on a non-editorial post, or one that cannot be resolved, retires editorial rows (the safe default).');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();
$GLOBALS['posts'][19] = (object) array('ID' => 19, 'post_type' => 'movie', 'post_status' => 'draft', 'url' => '');
$GLOBALS['comments'][3] = (object) array('comment_post_ID' => 19);
AAT_Page_Store::on_comment_change(3);
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'A comment on an unpublished film queues nothing.');

// ---- Terms -------------------------------------------------------------------
pi_fill();
$GLOBALS['term_links']['5:genre'] = 'https://lunarafilm.test/film/drama/';
$GLOBALS['term_objects']['5:genre'] = array(10, 11, 13);
AAT_Page_Store::on_term_change(5, 5, 'genre');
$queued = AAT_Page_Store::pending_invalidations();
sort($queued);
pi_assert($queued === array('/film/', '/film/drama/', '/film/other/', '/film/sister/'), 'A genre edit queues its archive, the archive root and the films carrying it (' . implode(',', $queued) . ').');
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/film/drama/') && !pi_has('/film/sister/') && !pi_has('/film/other/') && pi_has('/talent/') && pi_has('/reviews/'), 'Only that term\'s pages are deleted.');
pi_fill();
AAT_Page_Store::on_term_change(5, 5, 'genre', (object) array(), array(11)); // delete_term hands over the posts it carried
$queued = AAT_Page_Store::pending_invalidations();
sort($queued);
pi_assert($queued === array('/film/', '/film/drama/', '/film/other/'), 'A deleted term uses the post IDs it was handed (' . implode(',', $queued) . ').');
AAT_Page_Store::flush_invalidations();
$GLOBALS['term_objects']['6:genre'] = range(100, 100 + AAT_Page_Store::TERM_OBJECT_CAP);
AAT_Page_Store::on_term_change(6, 6, 'genre');
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'A term with a very large catalogue queues no URLs.');
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $g0, 'It retires editorial rows once instead.');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();
AAT_Page_Store::on_term_change(7, 7, 'category');
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $g0, 'A term in a taxonomy our types do not use keeps the old behaviour: retire editorial rows.');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();

// ---- Bulk requests -----------------------------------------------------------
pi_fill();
for ($i = 0; $i <= AAT_Page_Store::INVALIDATION_PATH_CAP; $i++) {
    AAT_Page_Store::invalidate_paths(array('/film/bulk-' . $i . '/'));
}
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'Past the cap the queue is dropped.');
AAT_Page_Store::invalidate_paths(array('/film/late/'));
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'And later URLs in that request are not queued either.');
pi_assert(AAT_Page_Store::flush_invalidations() === 0 && $wpdb->deletes === array(), 'A bulk request deletes no rows one by one...');
AAT_Page_Store::flush_editorial_bump();
pi_assert($egen() !== $g0, '...it retires editorial rows once at shutdown.');
unset($GLOBALS['options']['aat_page_store_editorial_generation']);
$egen();
AAT_Page_Store::invalidate_paths(array('/film/sister/'));
pi_assert(AAT_Page_Store::pending_invalidations() === array('/film/sister/'), 'The next request starts clean.');
AAT_Page_Store::flush_invalidations();

// ---- Only store-shaped paths -------------------------------------------------
foreach (array('/film/page/2/', '/film/a/b/', '/film/sister/?x=1', '/', '/oscars/ceremony/70/', 'not a url', '', false) as $bad) {
    AAT_Page_Store::invalidate_paths(array($bad));
}
pi_assert(AAT_Page_Store::pending_invalidations() === array(), 'Only clean editorial paths are ever queued.');
AAT_Page_Store::invalidate_paths(array('https://lunarafilm.test/talent/x-y/'));
pi_assert(AAT_Page_Store::pending_invalidations() === array('/talent/x-y/'), 'Full URLs are reduced to their path.');
AAT_Page_Store::flush_invalidations();

// ---- Hosts and filter --------------------------------------------------------
pi_fill();
$_SERVER['HTTP_HOST'] = 'www.lunarafilm.test';
$wpdb->rows[pi_key('/film/sister/', 'www.lunarafilm.test')] = array_merge(pi_row('/film/sister/'), array('cache_key' => pi_key('/film/sister/', 'www.lunarafilm.test')));
AAT_Page_Store::invalidate_paths(array('/film/sister/'));
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/film/sister/') && !isset($wpdb->rows[pi_key('/film/sister/', 'www.lunarafilm.test')]), 'A row stored under an alias host the request came in on is deleted too.');
$_SERVER['HTTP_HOST'] = 'lunarafilm.test';
pi_assert(AAT_Page_Store::key_for_path('/film/sister/') === pi_key('/film/sister/'), 'The key matches what eligible_request() builds.');
$_SERVER['REQUEST_URI'] = '/film/sister/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_COOKIE = array();
$req = AAT_Page_Store::eligible_request();
pi_assert($req['key'] === AAT_Page_Store::key_for_path('/film/sister/'), 'Same key as the serving path.');

pi_fill();
$GLOBALS['filters']['aat_page_store_related_paths'] = function ($paths, $post) { return array_merge($paths, array('https://lunarafilm.test/reviews/sister-review/')); };
AAT_Page_Store::on_post_status('publish', 'publish', $sister);
AAT_Page_Store::flush_invalidations();
pi_assert(!pi_has('/reviews/sister-review/'), 'The aat_page_store_related_paths filter can name pages that show the edited one.');
$GLOBALS['filters'] = array();

// ---- A render that was in flight during the edit is not stored -----------------
$store = new ReflectionMethod('AAT_Page_Store', 'store_if_clean');
$store->setAccessible(true);
AAT_Page_Store::$test_headers = array('Content-Type: text/html');
AAT_Page_Store::$test_status = 200;
$html = '<!doctype html><html><body>' . str_repeat('Sister ', 3000) . '</body></html>';
pi_fill();
$req = AAT_Page_Store::eligible_request();
$req['generation'] = AAT_Page_Store::generation_for('editorial');
$req['started'] = microtime(true) - 5;
$GLOBALS['options'][AAT_Page_Store::INVALIDATION_OPTION] = sprintf('%.3f', microtime(true) - 1);
unset($wpdb->rows[$req['key']]);
$store->invoke(null, $req, $html);
pi_assert(!isset($wpdb->rows[$req['key']]), 'A page whose render began before the last delete is not stored.');
$req['started'] = microtime(true);
$store->invoke(null, $req, $html);
pi_assert(isset($wpdb->rows[$req['key']]), 'A render that began after it is stored.');
$oreq = array('key' => 'k-oscars', 'path' => '/oscars/ceremony/70/', 'variant' => '', 'kind' => 'oscars', 'started' => microtime(true) - 5, 'generation' => AAT_Page_Store::generation());
$store->invoke(null, $oreq, $html);
pi_assert(isset($wpdb->rows['k-oscars']), 'Oscars pages are not affected by editorial deletes.');
unset($GLOBALS['options'][AAT_Page_Store::INVALIDATION_OPTION]);
$req['started'] = microtime(true) - 5;
unset($wpdb->rows[$req['key']]);
$store->invoke(null, $req, $html);
pi_assert(isset($wpdb->rows[$req['key']]), 'With no delete on record, any render is stored.');
AAT_Page_Store::$test_headers = null;
AAT_Page_Store::$test_status = null;

// ---- The warmer: no restart, just a quicker next pass ----------------------------
pi_fill();
$GLOBALS['scheduled'] = array();
AAT_Page_Store::invalidate_paths(array('/film/sister/'));
AAT_Page_Store::flush_invalidations();
pi_assert(!isset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]), 'With the warmer off a delete schedules nothing.');
$GLOBALS['options']['aat_page_store_warmer'] = 'on';
$gen_now = $egen();
$GLOBALS['options'][AAT_Page_Store_Warmer::STATE_OPTION] = array_merge(AAT_Page_Store_Warmer::default_state($gen_now), array('status' => 'complete', 'pass' => 3, 'next_pass_at' => time() + 800));
AAT_Page_Store::invalidate_paths(array('/film/other/'));
AAT_Page_Store::flush_invalidations();
$state = AAT_Page_Store_Warmer::state();
$when = $GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK] ?? 0;
pi_assert($state['status'] === 'complete' && $state['pass'] === 3 && $state['generation'] === $gen_now, 'A per-URL delete does not reset the walk.');
pi_assert($state['next_pass_at'] <= time() + AAT_Page_Store_Warmer::SETTLE + 2, 'A finished walk starts its next pass after the settle time, not the idle interval.');
pi_assert($when >= time() + AAT_Page_Store_Warmer::SETTLE - 2 && $when <= time() + AAT_Page_Store_Warmer::SETTLE + 2, 'A tick is scheduled to pick the deleted page up.');
pi_assert($egen() === $gen_now, 'The generation the warmer tracks did not move.');
$GLOBALS['options'][AAT_Page_Store_Warmer::STATE_OPTION] = array_merge(AAT_Page_Store_Warmer::default_state($gen_now), array('status' => 'running', 'pass' => 4));
unset($GLOBALS['scheduled'][AAT_Page_Store_Warmer::HOOK]);
AAT_Page_Store::invalidate_paths(array('/film/other/'));
AAT_Page_Store::flush_invalidations();
pi_assert(AAT_Page_Store_Warmer::state()['status'] === 'running' && AAT_Page_Store_Warmer::state()['pass'] === 4, 'A running walk is left alone.');
unset($GLOBALS['options']['aat_page_store_warmer']);

// ---- Wiring ------------------------------------------------------------------
$src = file_get_contents(dirname(__DIR__) . '/includes/class-aat-page-store.php');
foreach (array("'post_updated'", "'delete_comment'", "'created_term', 'edited_term', 'delete_term'", "'before_delete_post'", "add_action('shutdown', array(__CLASS__, 'flush_invalidations')") as $needle) {
    pi_assert(strpos($src, $needle) !== false, "Hook wired: $needle");
}
pi_assert(strpos($src, "'deleted_comment'") === false, 'Comment deletes run before the comment is gone, so its post can still be found.');
pi_assert(strpos($src, "\$request['started'] = microtime(true);") !== false, 'Capture notes when the render began.');

echo "Page store invalidation runtime passed: {$checks} checks.\n";
