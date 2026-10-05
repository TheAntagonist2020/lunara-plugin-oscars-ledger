<?php
// Shared WordPress stubs for tests/page-store-runtime.php.
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', __DIR__ . '/no-content');
define('AAT_VERSION', '2.8.14');
define('AAT_BOOT_MS', 1.0);
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['options'] = array('blog_public' => '1', 'aat_dataset_stamp' => getenv('AAT_TEST_STAMP') ?: 'stamp-1', 'aat_label_rules_version' => 'l2', 'stylesheet' => '');
if (getenv('AAT_TEST_GEN')) {
    $GLOBALS['options']['aat_page_store_generation'] = getenv('AAT_TEST_GEN');
}
$GLOBALS['scheduled'] = array();
$_SERVER['HTTP_HOST'] = 'lunarafilm.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function is_admin() { return false; }
function status_header($c) {}
function get_transient($k) { return false; }
function set_transient($k, $v, $t) { return true; }
function wp_next_scheduled($h) { return $GLOBALS['scheduled'][$h] ?? false; }
function wp_schedule_single_event($t, $h) { $GLOBALS['scheduled'][$h] = $t; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['scheduled'][$h] = $t; }
function add_action() {}
class Page_Store_WPDB {
    public $prefix = 'wp_';
    public $rows = array();
    public function suppress_errors($s = true) { return false; }
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function get_row($q, $o = null) {
        list($sql, $args) = $q;
        list($key, $gen, $since) = $args;
        $r = $this->rows[$key] ?? null;
        return ($r && $r['generation'] === $gen && $r['stored_at'] >= $since) ? array('html' => $r['html']) : null;
    }
    public function replace($t, $row, $f) { $this->rows[$row['cache_key']] = $row; return 1; }
    public function query($q) { return true; }
    public function get_charset_collate() { return ''; }
    public function get_col($q) { return array('BEST PICTURE', 'DIRECTING'); }
}
$GLOBALS['wpdb'] = new Page_Store_WPDB();
require dirname(__DIR__) . '/includes/class-aat-page-store.php';
// Warm-run stubs (2.8.15).
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://lunarafilm.test' . $p; } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return false; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return 200; } }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook($h) { unset($GLOBALS['scheduled'][$h]); } }
if (!function_exists('add_query_arg')) { function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c) { return parse_url($u, $c); } }
$GLOBALS['warm_requests'] = array();
if (!function_exists('wp_remote_get')) { function wp_remote_get($url, $args) {
    if (!empty($GLOBALS['warm_kill_after']) && count($GLOBALS['warm_requests']) >= $GLOBALS['warm_kill_after']) {
        throw new RuntimeException('worker killed');
    }
    $GLOBALS['warm_requests'][] = $url;
    return array();
} }
if (!class_exists('Academy_Awards_Table')) { class Academy_Awards_Table {
    public static function get_instance() { return new self(); }
    public function get_ceremonies_index_url() { return home_url('/oscars/ceremonies/'); }
    public function get_categories_index_url() { return home_url('/oscars/categories/'); }
    public function get_max_ceremony() { return 3; }
    public function get_ceremony_url($n) { return home_url('/oscars/ceremony/' . $n . '/'); }
    public function get_table_name() { return 'awards'; }
    public function get_category_url($c) { return home_url('/oscars/category/' . strtolower(str_replace(' ', '-', $c)) . '/'); }
}
}
