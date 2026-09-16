<?php
/** Exercise the production enqueue/shortcode methods with WordPress's real parser. */
require __DIR__ . '/fixtures/wordpress-shortcodes.php';
$source = file_get_contents(dirname(__DIR__) . '/academy-awards-table.php');
$fixture_dir = sys_get_temp_dir() . '/lunara-asset-routing-' . bin2hex(random_bytes(6)) . '/';
mkdir($fixture_dir . 'templates', 0777, true);
file_put_contents($fixture_dir . 'templates/table-display.php', '<?php echo json_encode($atts);');
register_shutdown_function(static function () use ($fixture_dir) {
    unlink($fixture_dir . 'templates/table-display.php');
    rmdir($fixture_dir . 'templates');
    rmdir($fixture_dir);
});
define('AAT_PLUGIN_DIR', $fixture_dir);
define('AAT_PLUGIN_URL', 'https://example.test/plugin/');
define('AAT_VERSION', 'test');
class WP_Post {
    public $post_content = '';
    public $post_name = 'example';
}
function routing_method($source, $name) {
    preg_match('/    (?:public|private) function ' . preg_quote($name, '/') . '\(/', $source, $match, PREG_OFFSET_CAPTURE);
    if (!$match) throw new RuntimeException('Missing method: ' . $name);
    $output = ''; $depth = 0; $opened = false;
    foreach (token_get_all('<?php ' . substr($source, $match[0][1])) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $output .= $token[1]; continue; }
        $output .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) return $output;
    }
    throw new RuntimeException('Unclosed method: ' . $name);
}
function sanitize_text_field($value) { return strip_tags((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
function get_query_var($key) { return $GLOBALS['route'][$key] ?? ''; }
function get_page_template_slug($post) { return $GLOBALS['route']['template'] ?? ''; }
function is_page($slug) { return $GLOBALS['post']->post_name === $slug; }
function has_block($name, $post) { return strpos($post->post_content, '<!-- wp:' . $name) !== false; }
function parse_blocks($content) { return $GLOBALS['blocks']; }
function apply_filters($hook, $value, ...$args) { return $hook === 'aat_force_enqueue_assets' ? $GLOBALS['force'] : $value; }
function wp_enqueue_style($handle, $url = '', $deps = array(), $version = '') { $GLOBALS['styles'][$handle] = $deps; }
function wp_enqueue_script($handle, $url = '', $deps = array(), $version = '', $footer = false) { $GLOBALS['scripts'][$handle] = $deps; }
function wp_localize_script($handle, $name, $data) { $GLOBALS['localized'][] = $handle; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action) { return 'fixture'; }
function __($text, $domain = '') { return $text; }
$methods = '';
foreach (array('enqueue_scripts', 'shortcode_requests_autoload', 'block_requests_autoload', 'is_entity_request', 'is_hub_request', 'render_shortcode', 'render_tracker_shortcode') as $name) {
    $methods .= routing_method($source, $name);
}
eval('class AssetRoutingFixture {' . $methods . '
    public function enqueue_theme_route_assets($deps) { $GLOBALS["theme_deps"] = $deps; }
    public function get_entity_base_url() { return "https://example.test/oscars/"; }
    public function get_database_url() { return "https://example.test/oscars/"; }
    public function get_max_ceremony() { return 98; }
    public function get_latest_year_label() { return "2026"; }
}');
$shortcode_tags = array_fill_keys(array('academy_awards', 'lunara_awards_tracker', 'lunara_oscar_ballot', 'academy_awards_ballot', 'lunara_awards_tracker_v2', 'academy_awards_tracker_v2'), true);
$plugin = new AssetRoutingFixture();
$checks = 0;
function verify($value, $message) {
    ++$GLOBALS['checks'];
    if (!$value) throw new RuntimeException($message);
}
function run_route($name, $content, $route, $get, $expected, $blocks = array(), $force = false) {
    $GLOBALS['post'] = new WP_Post();
    $GLOBALS['post']->post_content = $content;
    $GLOBALS['post']->post_name = $route['post_name'] ?? 'example';
    $GLOBALS['route'] = $route;
    $GLOBALS['blocks'] = $blocks;
    $GLOBALS['force'] = $force;
    $GLOBALS['styles'] = $GLOBALS['scripts'] = $GLOBALS['localized'] = array();
    $_GET = $get;
    $GLOBALS['plugin']->enqueue_scripts();
    foreach (array('aat-styles', 'aat-script', 'aat-tracker-v2', 'aat-ballot') as $handle) {
        $exists = isset($GLOBALS['styles'][$handle]) || isset($GLOBALS['scripts'][$handle]);
        verify($exists === in_array($handle, $expected, true), "$name: wrong asset presence for $handle");
    }
    $table = in_array('aat-script', $expected, true);
    foreach (array('datatables-js', 'datatables-responsive-js') as $handle) {
        verify(isset($GLOBALS['scripts'][$handle]) === $table, "$name: wrong table dependency $handle");
    }
    verify(isset($GLOBALS['styles']['aat-ballot-styles']) === in_array('aat-ballot', $expected, true), "$name: ballot styling must follow its script");
}
$base = array('aat-styles');
$table = array('aat-styles', 'aat-script');
run_route('ordinary page', '', array(), array(), array());
run_route('unrelated query', '', array(), array('view'=>'table'), array());
run_route('portal landing', '', array('post_name'=>'oscars'), array(), $base);
run_route('portal explorer', '', array('post_name'=>'oscars'), array('view'=>'table'), $table);
run_route('assigned portal', '', array('template'=>'page-oscars.php'), array('view'=>'table'), $table);
foreach (array('title', 'name', 'company') as $entity) {
    run_route("$entity ignores table query", '', array('aat_entity'=>$entity,'aat_entity_id'=>'record'), array('view'=>'table'), $base);
}
foreach (array('category', 'ceremony', 'categories', 'ceremonies', 'about') as $hub) {
    run_route("$hub landing", '', array('aat_hub'=>$hub), array(), $base);
    run_route("$hub query", '', array('aat_hub'=>$hub), array('view'=>'table'), in_array($hub,array('category','ceremony')) ? $table : $base);
}
run_route('shortcode landing', '[academy_awards]', array(), array(), $base);
run_route('shortcode explorer', '[academy_awards]', array(), array('view'=>'table'), $table);
run_route('embedded shortcode', '[academy_awards layout="embedded"]', array(), array(), $table);
foreach (array('true','1','yes','YES') as $value) run_route("autoload $value", '[academy_awards autoload="'.$value.'"]', array(), array(), $table);
foreach (array('false','0','no','trueish') as $value) run_route("no autoload $value", '[academy_awards autoload='.$value.']', array(), array(), $base);
run_route('escaped shortcode', '[[academy_awards autoload=true]]', array(), array(), $base);
run_route('attribute lookalike', '[academy_awards note="autoload=true"]', array(), array(), $base);
run_route('tracker default', '[lunara_awards_tracker]', array(), array(), $table);
run_route('tracker full', '[lunara_awards_tracker layout="full"]', array(), array(), $base);
run_route('ballot with irrelevant query', '[lunara_oscar_ballot]', array(), array('view'=>'table'), array('aat-styles','aat-ballot'));
run_route('tracker v2 with irrelevant query', '[lunara_awards_tracker_v2]', array(), array('view'=>'table'), array('aat-styles','aat-tracker-v2'));
run_route('mixed widgets', '[academy_awards layout="embedded"][academy_awards_ballot][academy_awards_tracker_v2]', array(), array(), array('aat-styles','aat-script','aat-ballot','aat-tracker-v2'));
foreach (array('academy-awards/database', 'academy-awards/tracker') as $block) {
    foreach (array(array(),array('layout'=>'full'),array('autoload'=>true),array('layout'=>'embedded'),array('autoload'=>'false','layout'=>'full')) as $attrs) {
        $embedded = ($attrs['layout'] ?? ($block === 'academy-awards/tracker' ? 'embedded' : 'full')) === 'embedded';
        $immediate = $embedded || ($attrs['autoload'] ?? false) === true;
        run_route('nested '.$block.' '.json_encode($attrs), '<!-- wp:'.$block.' -->', array(), array(), $immediate ? $table : $base, array(array('blockName'=>'core/group','innerBlocks'=>array(array('blockName'=>$block,'attrs'=>$attrs)))));
    }
}
run_route('force compatibility', '', array(), array(), $table, array(), true);
foreach (array('render_shortcode','render_tracker_shortcode') as $method) {
    $atts = json_decode($plugin->$method(array('autoload'=>'yes','layout'=>'full')), true);
    verify($atts['autoload'] === 'yes', "$method must pass autoload to the real template boundary");
    verify($atts['layout'] === 'full', "$method must preserve explicit full layout");
}
echo "Frontend asset routing passed: $checks checks.\n";
