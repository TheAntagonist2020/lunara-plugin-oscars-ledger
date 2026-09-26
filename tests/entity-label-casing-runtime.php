<?php
/**
 * Display names printed in capitals (2.8.6).
 *
 * Run: php tests/entity-label-casing-runtime.php
 *
 * The Academy prints Scientific and Technical citations in capitals, so the
 * rebuild used to name 118 people "FARCIOT EDOUART", "WINTON HOCH" and the
 * like. A properly cased credit of the same person now wins; a person with
 * none is title-cased from the Academy's own spelling (never swapped for
 * another source's name). Single-word styling (SZA, EJAE) is kept.
 *
 * The three helpers are lifted from academy-awards-table.php and run as-is.
 */

$root = dirname(__DIR__);
$source = file_get_contents($root . '/academy-awards-table.php');
$failures = array();
$checks = 0;
$check = function ($condition, $message) use (&$failures, &$checks) {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$methods = '';
foreach (array('prefer_entity_label', 'is_shouted_label', 'title_case_shouted_label', 'title_case_name_word') as $name) {
    $start = strpos($source, "    private function {$name}(");
    $check($start !== false, "academy-awards-table.php defines {$name}().");
    if ($start === false) {
        continue;
    }
    $end = strpos($source, "\n    }\n", $start);
    $methods .= substr($source, $start, $end - $start + 7) . "\n";
}
eval('class Lunara_Test_Label_Rules {' . "\n" . $methods . "\n" . '
    public function prefer($a, $b) { return $this->prefer_entity_label($a, $b); }
    public function shouted($a) { return $this->is_shouted_label($a); }
    public function cased($a) { return $this->title_case_shouted_label($a); }
}');
$rules = new Lunara_Test_Label_Rules();

// Which labels count as shouted.
foreach (array('FARCIOT EDOUART', 'F. R. ABBOTT', 'WAN-CHUN MA', 'GÜNTHER SCHAIDT', 'CARLOS DeMATTOS') as $label) {
    $check($rules->shouted($label), "\"{$label}\" is shouted.");
}
foreach (array('SZA', 'EJAE', 'JR', 'Farciot Edouart', 'J. R. R. Tolkien', 'Ub Iwerks', '') as $label) {
    $check(!$rules->shouted($label), "\"{$label}\" is not shouted.");
}

// A properly cased credit replaces a shouted one; nothing else moves.
$check($rules->prefer('', 'FARCIOT EDOUART'), 'A first credit names an unnamed entity.');
$check($rules->prefer('FARCIOT EDOUART', 'Farciot Edouart'), 'A cased credit replaces a shouted one.');
$check(!$rules->prefer('Farciot Edouart', 'FARCIOT EDOUART'), 'A shouted credit never replaces a cased one.');
$check(!$rules->prefer('Ioan Allen', 'Ioan R. Allen'), 'Between two cased credits the first stands.');
$check(!$rules->prefer('SZA', 'Sza'), 'Single-word styling stands.');
$check(!$rules->prefer('FARCIOT EDOUART', ''), 'An empty credit replaces nothing.');
$check(!$rules->prefer('CARLOS DE MATTOS', 'CARLOS DeMATTOS'), 'A mostly-capitals credit does not replace a shouted one.');

// Title-casing keeps the Academy's own spelling.
$cases = array(
    'WINTON HOCH' => 'Winton Hoch',
    'UB IWERKS' => 'Ub Iwerks',
    'JOHN R. MOORE' => 'John R. Moore',
    'F. R. ABBOTT' => 'F. R. Abbott',
    'PHILIP S. J. BOOLE' => 'Philip S. J. Boole',
    'WAN-CHUN MA' => 'Wan-Chun Ma',
    'GÜNTHER SCHAIDT' => 'Günther Schaidt',
    'PROFESSOR HENRI CHRETIEN' => 'Professor Henri Chretien',
    'RICHARD BENJAMIN GRANT' => 'Richard Benjamin Grant',
    'JOHN MCGREGOR III' => 'John McGregor III',
    "PAT O'NEIL" => "Pat O'Neil",
    'SAMUEL SMITH II' => 'Samuel Smith II',
    'IVAN IVANOV' => 'Ivan Ivanov',
    'CARLOS DE MATTOS' => 'Carlos De Mattos',
    'DAVID DiFRANCESCO' => 'David DiFrancesco',
    'MICHAEL MacKENZIE' => 'Michael MacKenzie',
    'TONY DeROSE' => 'Tony DeRose',
    'ANDRÉ LeBLANC' => 'André LeBlanc',
    'GREG LaSALLE' => 'Greg LaSalle',
    'RORY McGREGOR' => 'Rory McGregor',
);
foreach ($cases as $shouted => $expected) {
    $got = $rules->cased($shouted);
    $check($got === $expected, "\"{$shouted}\" title-cases to \"{$expected}\" (got \"{$got}\").");
}

// The rebuild uses the rules for both the entity and its stats, title-cases
// people only, and the display-name cache key moved with the rules.
$check(substr_count($source, 'prefer_entity_label(') === 3, 'The rebuild applies the preference to entities and their stats.');
$check(strpos($source, "\$entity_row['entity_type'] === 'name' && \$this->is_shouted_label(") !== false, 'Only people are title-cased.');
$check(strpos($source, "'l2|' . \$entity . ':' . \$id") !== false, 'The display-name cache key is versioned on the label rules.');

// The entity graph's movie and person posts (the /talent/ pages) follow a
// renamed entity: a rebuild signals, and a background pass renames only
// posts whose title differs byte for byte, keeping every slug.
$graph = file_get_contents($root . '/includes/class-aat-entity-graph-builder.php');
$check(strpos($source, "do_action('aat_reporting_tables_rebuilt');") !== false, 'The rebuild announces itself.');
$check(strpos($graph, "add_action('aat_reporting_tables_rebuilt', array(__CLASS__, 'schedule_title_sync'));") !== false, 'The graph builder listens for rebuilds.');
$check(strpos($graph, 'BINARY p.post_title <> BINARY e.label') !== false, 'Titles compare byte for byte, not by the case-blind collation.');
$check(strpos($graph, "html_entity_decode((string) \$row['post_title'], ENT_QUOTES, 'UTF-8') === \$label") !== false, 'An entity-encoded title is not a change.');
$sync_start = strpos($graph, 'public static function sync_titles()');
$sync_body = $sync_start === false ? '' : substr($graph, $sync_start, strpos($graph, "\n    }\n", $sync_start) - $sync_start);
$check(strpos($sync_body, "wp_update_post(array('ID' => (int) \$row['ID'], 'post_title' => \$label));") !== false && strpos($sync_body, 'post_name') === false, 'Only titles change; slugs are kept.');

if (!empty($failures)) {
    fwrite(STDERR, "Entity label casing runtime failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Entity label casing runtime passed: {$checks} checks.\n";
