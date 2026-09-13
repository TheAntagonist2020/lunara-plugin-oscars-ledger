<?php
// Executes production methods with only WordPress storage/routing dependencies stubbed.
$root = dirname(__DIR__);
$source = file_get_contents(getenv('AAT_CATEGORY_SOURCE') ?: $root . '/academy-awards-table.php');
function method_source($source, $name) {
    $start = strpos($source, '    public function ' . $name . '(');
    if ($start === false) throw new RuntimeException('Missing method ' . $name);
    $tokens = token_get_all('<?php ' . substr($source, $start));
    $out = ''; $depth = 0; $opened = false;
    foreach ($tokens as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $out .= $token[1]; continue; }
        $out .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) return $out;
    }
    throw new RuntimeException('Unclosed method ' . $name);
}
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-'); }
function sanitize_text_field($value) { return strip_tags($value); }
function esc_url_raw($value) { return $value; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? ''; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
define('HOUR_IN_SECONDS', 3600);
$methods = '';
foreach (array('format_category_display', 'resolve_category_slug', 'get_category_url', 'get_route_context', 'get_title_award_context') as $name) $methods .= method_source($source, $name);
eval('class CategoryRuntime {' . $methods . '
public function get_projection_categories_list() { return array("ART DIRECTION", "SOUND MIXING", "SOUND EDITING", "CASTING"); }
public function get_entity_base_url() { return "https://example.test/oscars/"; }
public function is_entity_request() { return false; }
public function is_hub_request() { return true; }
}');
$plugin = new CategoryRuntime(); $checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
foreach (array(array('ART DIRECTION',0,'Production Design'),array('ART DIRECTION',84,'ART DIRECTION'),array('ART DIRECTION',85,'Production Design'),array('ART DIRECTION',98,'Production Design'),array('SOUND MIXING',0,'Sound'),array('SOUND MIXING',92,'SOUND MIXING'),array('SOUND MIXING',93,'Sound'),array('SOUND MIXING',98,'Sound'),array('SOUND EDITING',98,'SOUND EDITING'),array('ART DIRECTION (Black-and-White)',84,'ART DIRECTION (Black-and-White)')) as $case) check($plugin->format_category_display($case[0],$case[1]) === $case[2], 'Dated label ' . json_encode($case));
foreach (array(false, array('art-direction'=>'ART DIRECTION','sound-mixing'=>'SOUND MIXING','sound-editing'=>'SOUND EDITING','casting'=>'CASTING')) as $warm) {
    $GLOBALS['cache'] = array('aat_category_slug_map_v1'=>$warm);
    foreach (array('production-design'=>'ART DIRECTION','art-direction'=>'ART DIRECTION','sound'=>'SOUND MIXING','sound-mixing'=>'SOUND MIXING','sound-editing'=>'SOUND EDITING','casting'=>'CASTING','unknown'=>'') as $slug=>$expected) {
        check($plugin->resolve_category_slug($slug) === $expected, 'Warm/cold slug: ' . $slug);
        $GLOBALS['query'] = array('aat_hub'=>'category','aat_hub_id'=>$slug);
        check($plugin->get_route_context() === array('kind'=>'category','id'=>$expected ?: null), 'Actual route: ' . $slug);
    }
}
check($plugin->get_category_url('ART DIRECTION') === 'https://example.test/oscars/category/art-direction/', 'Preserve old design URL');
check($plugin->get_category_url('SOUND MIXING') === 'https://example.test/oscars/category/sound-mixing/', 'Preserve old sound URL');
$GLOBALS['cache']['aat_title_award_context_v1_tt123'] = array(array('ceremony'=>98,'year'=>2025,'canonical_category'=>'BEST PICTURE','film'=>'Example','winner'=>1),array('ceremony'=>84,'year'=>2011,'canonical_category'=>'ART DIRECTION','film'=>'Example','winner'=>1));
$context = $plugin->get_title_award_context('tt123');
check(in_array('ART DIRECTION', $context['display_categories'], true), 'Title context dates each award independently');
$hub = file_get_contents($root . '/templates/hub-page.php');
check(str_contains($hub, 'format_category_display($cat)'), 'Current index uses modern display formatter');
check(str_contains($hub, 'format_category_display($canonical)'), 'Current category heading uses modern display formatter');
check(substr_count($hub, "format_category_display(\$canonical, intval(\$winner_row['ceremony']") === 2, 'Both winner history layouts use dated labels');
check(str_contains($hub, "format_category_display(\$canonical, intval(\$nominee_row['ceremony']"), 'Nominee history uses dated labels');
check(str_contains($source, "\$cache_key = 'aat_ceremony_rollup_v3_'"), 'Old cached ceremony display labels bypassed');
check(str_contains($source, "'category_label' => \$this->format_category_display(\$category, \$ceremony)"), 'Ceremony winner payload dates labels');
check(str_contains(file_get_contents($root . "/templates/ballot.php"), "format_category_display(\$category, \$ceremony)"), "Historical ballot keeps ceremony labels");
echo "Category names runtime passed: $checks checks.\n";
