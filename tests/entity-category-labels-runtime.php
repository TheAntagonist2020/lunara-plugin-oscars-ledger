<?php
/** Actual entity template with production category formatting, URLs and warm row-cache reads. */
define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__);
$source = file_get_contents($root . '/academy-awards-table.php');
function entity_method_source($source, $name) {
    if (!preg_match('/    (?:public|private|protected) function ' . preg_quote($name, '/') . '\(/', $source, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Missing production method: ' . $name);
    }
    $start = $match[0][1];
    $out = ''; $depth = 0; $opened = false;
    foreach (token_get_all('<?php ' . substr($source, $start)) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $out .= $token[1]; continue; }
        $out .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) return $out;
    }
    throw new RuntimeException('Unclosed production method: ' . $name);
}
function sanitize_text_field($value) { return strip_tags((string) $value); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-'); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_html_class($value) { return preg_replace('/[^a-zA-Z0-9_-]/', '', $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value) { return $value; }
function __($value, $domain = '') { return $value; }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function _n($one, $many, $count, $domain = '') { return $count === 1 ? $one : $many; }
function wp_kses_post($value) { return $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_unslash($value) { return $value; }
function number_format_i18n($value) { return number_format($value); }
function absint($value) { return abs((int) $value); }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? ''; }
function get_theme_mod($key, $default = false) { return $default; }
function home_url($path = '') { return 'https://example.test' . $path; }
function add_query_arg($key, $value) { return home_url('/?') . urlencode($key) . '=' . urlencode($value); }
function remove_query_arg($key) { return home_url('/'); }
function get_header() { echo '<main>'; }
function get_footer() { echo '</main>'; }
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'aat_entity_route_sections') $GLOBALS['rendered_sections'] = $value;
    return $value;
}
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient(...$args) { throw new RuntimeException('Rendering must not rewrite the warm cache'); }
function delete_transient(...$args) { throw new RuntimeException('Rendering must not invalidate caches'); }
function update_option(...$args) { throw new RuntimeException('Rendering must not write settings'); }
$methods = '';
foreach (array('format_category_display', 'get_category_url', 'get_entity_rows', 'is_title_entity_id', 'is_name_entity_id', 'is_imdb_name_entity_id', 'is_local_name_entity_id', 'is_company_entity_id') as $name) {
    $methods .= entity_method_source($source, $name);
}
eval('class Academy_Awards_Table {' . $methods . '
    public static function get_instance() { static $instance; return $instance ??= new self(); }
    public function get_table_name() { return "awards"; }
    public function get_award_facts_table_name() { return "award_facts"; }
    public function get_award_nominees_table_name() { return "award_nominees"; }
    public function get_entity_base_url() { return "https://example.test/oscars/"; }
    public function get_entity_display_name($kind, $id) { return "Example " . $kind; }
    public function build_entity_url_from_id($id) { return $this->get_entity_base_url() . "title/" . $id . "/"; }
    public function build_imdb_url($id) { return "https://www.imdb.com/title/" . $id . "/"; }
    public function get_ceremony_url($ceremony) { return $this->get_entity_base_url() . "ceremony/" . $ceremony . "/"; }
    public function get_review_ids_for_title_id($id, $limit) { return array(); }
    public function get_poster_img_html_for_title($id, $size, $attrs) { return ""; }
    public function get_route_context() { return array("kind" => get_query_var("aat_entity"), "id" => get_query_var("aat_entity_id")); }
}');
set_error_handler(function($severity, $message, $file, $line) { throw new RuntimeException("$message at $file:$line"); });
function entity_award($category, $ceremony, $winner = 1) {
    return array('canonical_category' => $category, 'ceremony' => $ceremony, 'year' => $ceremony + 1927,
        'winner' => $winner, 'film' => 'Example film', 'film_id' => 'tt1234567', 'name' => 'Example person',
        'nominees' => 'Example person', 'nominee_ids' => 'nm1234567');
}
function render_entity($kind, $award_rows) {
    $id = array('title' => 'tt1234567', 'name' => 'nm1234567', 'company' => 'co1234567')[$kind];
    $GLOBALS['query'] = array('aat_entity' => $kind, 'aat_entity_id' => $id);
    $GLOBALS['cache'] = array('aat_entity_rows_v2_' . md5($kind . ':' . $id) => $award_rows);
    $before = $GLOBALS['cache'];
    ob_start();
    include getenv('AAT_ENTITY_TEMPLATE') ?: dirname(__DIR__) . '/templates/entity-page.php';
    $html = ob_get_clean();
    entity_check($before === $GLOBALS['cache'], "$kind retains raw cached categories and rows");
    entity_check(str_contains($html, 'aat-profile-file'), "$kind actual template rendered");
    return $GLOBALS['rendered_sections'];
}
function entity_links($html, $class) {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<html><body>' . $html . '</body></html>');
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom); $links = array();
    foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]') as $node) {
        $title = $class === 'aat-crossroad-pill' ? $xpath->query('.//span[@class="aat-crossroad-pill-title"]', $node)->item(0) : $node;
        $links[] = array('url' => $node->getAttribute('href'), 'label' => trim(preg_replace('/\s+/', ' ', $title->textContent)));
    }
    return $links;
}
$checks = 0;
function entity_check($condition, $message) {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function entity_expect_label($section, $class, $category, $label, $message) {
    $url = Academy_Awards_Table::get_instance()->get_category_url($category);
    $matches = array_values(array_filter(entity_links($section, $class), function($link) use ($url) { return $link['url'] === $url; }));
    entity_check(count($matches) === 1, "$message retains one canonical category URL");
    entity_check($matches[0]['label'] === $label, "$message displays $label; got " . $matches[0]['label']);
}
foreach (array('title', 'name', 'company') as $kind) {
    foreach (array(array('ART DIRECTION', 84, 'Art Direction'), array('ART DIRECTION', 85, 'Production Design'),
        array('SOUND MIXING', 92, 'Sound Mixing'), array('SOUND MIXING', 93, 'Sound'),
        array('SOUND EDITING', 98, 'Sound Editing'), array('ART DIRECTION (Black-and-White)', 30, 'Art Direction (black-and-white)'),
        array('WRITING (ADAPTED SCREENPLAY)', 98, 'Adapted Screenplay')) as $case) {
        list($category, $ceremony, $expected) = $case;
        $sections = render_entity($kind, array(entity_award($category, $ceremony)));
        entity_expect_label($sections['latest-result'], 'aat-entity-status-tag', $category, $expected, "$kind latest $category at $ceremony");
        entity_expect_label($sections['crossroads'], 'aat-crossroad-pill', $category, $expected, "$kind trail $category at $ceremony");
        entity_expect_label($sections['oscar-history'], 'aat-hub-link', $category, $expected, "$kind history $category at $ceremony");
    }
    // Intentionally unordered: a summary must use each category's latest actual row.
    $mixed = array(entity_award('ART DIRECTION', 84), entity_award('SOUND MIXING', 92),
        entity_award('BEST PICTURE', 98), entity_award('ART DIRECTION', 85));
    foreach (array($mixed, array_reverse($mixed)) as $award_rows) {
        $sections = render_entity($kind, $award_rows);
        entity_expect_label($sections['latest-result'], 'aat-entity-status-tag', 'BEST PICTURE', 'Best Picture', "$kind latest is ceremony98");
        entity_check(count(entity_links($sections['latest-result'], 'aat-entity-status-tag')) === 1, "$kind latest excludes older categories");
        entity_expect_label($sections['crossroads'], 'aat-crossroad-pill', 'ART DIRECTION', 'Production Design', "$kind mixed trail dates design independently");
        entity_expect_label($sections['crossroads'], 'aat-crossroad-pill', 'SOUND MIXING', 'Sound Mixing', "$kind mixed trail keeps historical-only sound");
        $history = entity_links($sections['oscar-history'], 'aat-hub-link');
        $labels = array_column($history, 'label');
        entity_check(in_array('Art Direction', $labels, true) && in_array('Production Design', $labels, true), "$kind mixed history keeps both naming eras");
        entity_check(in_array('Sound Mixing', $labels, true) && !in_array('Sound', $labels, true), "$kind modern picture does not modernize old sound");
    }
}
echo "Entity category labels runtime passed: $checks checks.\n";
