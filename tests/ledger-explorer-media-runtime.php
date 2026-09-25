<?php
/**
 * Oscar Ledger Explorer artwork (2.8.4): every row, group and Debrief carries
 * a poster, a portrait or a monogram plate, and the page credits Lunara alone.
 *
 * Run: php tests/ledger-explorer-media-runtime.php
 *
 * WordPress and the plugin are stubbed: a small poster table, two portraits
 * (one in the media library, one cached from TMDB) and nothing else. The real
 * AAT_Ledger_Media and AAT_Explorer render rows, groups and a Debrief.
 */

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);
$failures = array();
$checks = 0;
$check = function ($condition, $message) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$GLOBALS['lle_test_cache'] = array();
function wp_cache_get($key, $group = '') { return $GLOBALS['lle_test_cache'][$group . '|' . $key] ?? false; }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { $GLOBALS['lle_test_cache'][$group . '|' . $key] = $value; return true; }
function wp_attachment_is_image($id) { return in_array((int) $id, array(101, 102, 201), true); }
function wp_get_attachment_image($id, $size, $icon, $attrs) {
    return '<img src="https://example.test/uploads/' . (int) $id . '-300.jpg" width="200" height="300" class="' . $attrs['class'] . '" alt="' . $attrs['alt'] . '" loading="' . $attrs['loading'] . '" sizes="' . $attrs['sizes'] . '">';
}
function wp_get_attachment_image_url($id, $size) { return 'https://example.test/uploads/' . (int) $id . '-300.jpg'; }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return (string) $url; }
function number_format_i18n($n) { return number_format((float) $n); }
function _n($single, $plural, $n) { return (int) $n === 1 ? $single : $plural; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }

class Academy_Awards_Table {
    private static $instance;
    public static function get_instance() { return self::$instance ?: (self::$instance = new self()); }
    public function get_poster_attachment_id_for_title($tt) { return array('tt0000001' => 101, 'tt0000002' => 102)[$tt] ?? 0; }
    public function get_tmdb_data_for_imdb_id($tt, $allow_remote) {
        return $tt === 'tt0000003' ? array('poster_full' => 'https://image.tmdb.org/t/p/w780/three.jpg') : array();
    }
    public function get_person_visual_package($nm, $size, $allow_remote) {
        if ($nm === 'nm0000001') {
            return array('portrait_attachment_id' => 201, 'portrait_url' => 'https://example.test/uploads/201.jpg', 'visual_source' => 'local-media-library');
        }
        if ($nm === 'nm0000002') {
            return array('portrait_attachment_id' => 0, 'portrait_url' => 'https://image.tmdb.org/t/p/w500/two.jpg', 'visual_source' => 'tmdb-person-profile');
        }
        return array('portrait_attachment_id' => 0, 'portrait_url' => '', 'visual_source' => 'none');
    }
    public function build_entity_url_from_id($id) { return 'https://example.test/oscars/' . (strpos($id, 'tt') === 0 ? 'title' : 'name') . '/' . $id . '/'; }
    public function get_entity_base_url() { return 'https://example.test/oscars/'; }
}

require $root . '/includes/class-aat-ledger-media.php';
require $root . '/includes/class-aat-explorer.php';

$call = function ($method, ...$args) {
    $reflection = new ReflectionMethod('AAT_Explorer', $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
};

$slot = function ($name, $ids) {
    $links = array();
    foreach ($ids as $id) {
        $links[] = array('id' => $id, 'name' => $name, 'url' => 'https://example.test/oscars/x/' . $id . '/');
    }
    return array('name' => $name, 'ids' => $ids, 'links' => $links, 'joint' => count($ids) > 1);
};
$record = function ($class, $films, $nominees, $winner = false) {
    return array(
        'id' => 7, 'ceremony' => 98, 'ceremony_label' => '98th', 'year_label' => '2025', 'ceremony_url' => '',
        'category' => array('slug' => 'x', 'name' => 'X', 'as_given' => '', 'class' => $class, 'class_label' => $class, 'url' => ''),
        'winner' => $winner, 'films' => $films, 'credit' => '', 'nominees' => $nominees, 'detail' => array(), 'note' => '', 'citation' => '',
    );
};

// Media resolution.
$check(AAT_Ledger_Media::for_id('tt0000001')['attachment'] === 101, 'A mapped poster resolves to its attachment.');
$check(AAT_Ledger_Media::for_id('tt0000003')['url'] === 'https://image.tmdb.org/t/p/w342/three.jpg', 'A cached TMDB poster is used at thumbnail size.');
$check(AAT_Ledger_Media::for_id('nm0000001')['attachment'] === 201 && AAT_Ledger_Media::for_id('nm0000001')['kind'] === 'portrait', 'A media-library portrait resolves to its attachment.');
$check(AAT_Ledger_Media::for_id('nm0000002')['url'] === 'https://image.tmdb.org/t/p/w185/two.jpg', 'A cached TMDB portrait is used at thumbnail size.');
$check(AAT_Ledger_Media::for_id('tt0000009') === array() && AAT_Ledger_Media::for_id('co0000001') === array(), 'No artwork, and companies, resolve to nothing.');
$check(AAT_Ledger_Media::initials('Industrial Light & Magic') === 'IM' && AAT_Ledger_Media::initials('The Godfather') === 'G' && AAT_Ledger_Media::initials('98') === '98', 'Plates take two initials, skip articles and keep a ceremony number.');

// Rows: a one-person award shows the portrait, a film award the poster.
$acting = $call('render_record', $record('Acting', array($slot('Film One', array('tt0000001'))), array($slot('Person One', array('nm0000001'))), true), array());
$check(strpos($acting, 'lle-media lle-media--row is-portrait') !== false && strpos($acting, '201-300.jpg') !== false, 'An acting row shows the nominee portrait.');
$check(strpos($acting, 'tabindex="-1" aria-hidden="true"') !== false && strpos($acting, 'alt=""') !== false, 'Row artwork is decorative and out of the tab order.');
$check(strpos($acting, '/oscars/name/nm0000001/') !== false, 'Row artwork links to the person it shows.');
$picture = $call('render_record', $record('Title', array($slot('Film Two', array('tt0000002'))), array($slot('Producer One', array('nm0000001')))), array());
$check(strpos($picture, 'is-poster') !== false && strpos($picture, '102-300.jpg') !== false, 'A film award shows the film poster.');
$team = $call('render_record', $record('Writing', array($slot('Film Two', array('tt0000002'))), array($slot('Writer A', array('nm0000001')), $slot('Writer B', array('nm0000002')))), array());
$check(strpos($team, '102-300.jpg') !== false, 'A team award shows the film poster.');
$fallback = $call('render_record', $record('Acting', array($slot('Film One', array('tt0000001'))), array($slot('Person Nine', array('nm0000009')))), array());
$check(strpos($fallback, '101-300.jpg') !== false, 'A person with no portrait falls back to the film poster.');
$focused = $call('render_record', $record('Acting', array($slot('Film One', array('tt0000001'))), array($slot('Person One', array('nm0000001')))), array('nm0000001'));
$check(strpos($focused, '101-300.jpg') !== false, 'On a person\'s own list the row shows their film.');
$plate = $call('render_record', $record('SciTech', array(), array($slot('Industrial Light & Magic', array()))), array());
$check(strpos($plate, 'is-plate') !== false && strpos($plate, '<span class="lle-media__mark">IM</span>') !== false && strpos($plate, '<span class="lle-media lle-media--row is-plate" aria-hidden="true">') !== false, 'A row with no artwork gets an unlinked monogram plate.');

// Groups: every kind gets a box.
$state = array('by' => 'ceremony', 'entity' => array(), 'category' => '', 'ceremony' => 0, 'decade' => '', 'class' => '', 'winner' => false, 'order' => '', 'q' => '', 'pg' => 1);
$list = array('page' => 1, 'items' => array(
    array('value' => 98, 'label' => '98th', 'year_label' => '2025', 'url' => 'https://example.test/c/98/', 'nominations' => 120, 'wins' => 24, 'lead_film' => 'tt0000002'),
    array('value' => 97, 'label' => '97th', 'year_label' => '2024', 'url' => 'https://example.test/c/97/', 'nominations' => 120, 'wins' => 24, 'lead_film' => ''),
));
$groups = $call('render_groups', $state, $list);
$check(substr_count($groups, 'lle-media lle-media--group') === 2, 'Every ceremony group gets a media box.');
$check(strpos($groups, '102-300.jpg') !== false && strpos($groups, '<span class="lle-media__mark">97</span>') !== false, 'A ceremony shows its lead poster, else a numbered plate.');

// The Debrief and the footer.
$debrief = $call('render_debrief', array('id' => 'nm0000002', 'kind' => 'person', 'name' => 'Person Two', 'nominations' => 3, 'wins' => 1, 'ceremonies' => 2, 'url' => 'https://example.test/p/', 'imdb_url' => 'https://www.imdb.com/name/nm0000002/'), $state);
$check(strpos($debrief, 'lle-media--debrief is-portrait') !== false && strpos($debrief, 'w185/two.jpg') !== false, 'A person Debrief shows the portrait.');

$explorer = file_get_contents($root . '/includes/class-aat-explorer.php');
$api = file_get_contents($root . '/includes/class-aat-read-api.php');
foreach (array('DLu', 'oscar_data', 'BSD') as $name) {
    $check(stripos($explorer, $name) === false && stripos($api, $name) === false, "The Explorer and its API name no third party ({$name}).");
}
$check(strpos($explorer, 'compiled and fact-checked by Lunara Film') !== false, 'The Explorer footer credits Lunara Film.');

if (!empty($failures)) {
    fwrite(STDERR, "Ledger Explorer media runtime failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Ledger Explorer media runtime passed: {$checks} checks.\n";
