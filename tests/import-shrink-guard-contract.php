<?php

$root = dirname(__DIR__);
$source = file_get_contents($root . '/academy-awards-table.php');
$admin_js = file_get_contents($root . '/assets/js/admin.js');
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Extract a method body by brace matching, independent of indentation.
$extract_method = function ($name) use ($source) {
    if (!preg_match('/(public|private|protected) function ' . preg_quote($name, '/') . '\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $m[0][1];
    $open = strpos($source, '{', $start);
    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return '';
};

$import = $extract_method('ajax_import_data');
$assert($import !== '', 'ajax_import_data should exist.');

// Structural: nothing destructive happens before the file is parsed and the guard runs.
$parse_at = strpos($import, 'parse_full_import_file(');
$guard_at = strpos($import, "'guard' => 'shrink'");
$backup_at = strpos($import, 'CREATE TABLE');
$tx_at = strpos($import, "START TRANSACTION");
$delete_at = strpos($import, 'DELETE FROM');
$assert(stripos($import, 'TRUNCATE TABLE') === false, 'Full import must not TRUNCATE (it auto-commits and cannot roll back).');
$assert($parse_at !== false && $guard_at !== false && $backup_at !== false && $tx_at !== false && $delete_at !== false, 'Full import should parse, guard, back up, and replace in a transaction.');
if ($parse_at !== false && $guard_at !== false && $backup_at !== false && $tx_at !== false && $delete_at !== false) {
    $assert($parse_at < $guard_at && $guard_at < $backup_at && $backup_at < $tx_at && $tx_at < $delete_at, 'Order must be parse -> guard -> backup -> transaction -> delete.');
}
$assert(strpos($import, "\$_POST['confirm_shrink']") !== false, 'Shrinking imports should require confirm_shrink.');
$assert(strpos($import, "ROLLBACK") !== false, 'A failed insert should roll back.');

// Behavioral: replay the 2026-09-19 incident against the census comparison.
$census_rows = $extract_method('census_award_rows');
$compare = $extract_method('compare_import_census');
$assert($census_rows !== '' && $compare !== '', 'Census helpers should exist.');

if ($census_rows !== '' && $compare !== '') {
    eval('class AAT_Import_Guard_Harness {' . $census_rows . $compare . '
        public function ordinal($n) { return $n . "th"; }
        public function census($rows) { return $this->census_award_rows($rows); }
        public function compare($a, $b) { return $this->compare_import_census($a, $b); }
    }');
    $h = new AAT_Import_Guard_Harness();

    $live = array('rows' => 4, 'winners' => 2, 'ceremony_winners' => array(96 => 1, 97 => 1));

    // The incident: same ceremonies, winner flags lost.
    $lossy = $h->census(array(
        array('ceremony' => 96, 'winner' => 0),
        array('ceremony' => 96, 'winner' => 0),
        array('ceremony' => 97, 'winner' => 1),
        array('ceremony' => 97, 'winner' => 0),
    ));
    $shrinks = $h->compare($live, $lossy);
    $assert(count($shrinks) === 2, 'Losing a winner should report total and per-ceremony shrink.');
    $assert(strpos(implode(' ', $shrinks), '96th ceremony winners would drop from 1 to 0') !== false, 'Per-ceremony loss should name the ceremony.');

    // Fewer rows.
    $fewer = $h->census(array(
        array('ceremony' => 96, 'winner' => 1),
        array('ceremony' => 97, 'winner' => 1),
    ));
    $assert(count($h->compare($live, $fewer)) === 1, 'Dropping rows should be reported.');

    // Winners moving between ceremonies still counts as a loss.
    $moved = $h->census(array(
        array('ceremony' => 96, 'winner' => 0),
        array('ceremony' => 96, 'winner' => 0),
        array('ceremony' => 97, 'winner' => 1),
        array('ceremony' => 97, 'winner' => 1),
    ));
    $assert(count($h->compare($live, $moved)) === 1, 'A ceremony losing winners should be caught even if the total holds.');

    // Growth (a new ceremony) passes cleanly.
    $grown = $h->census(array(
        array('ceremony' => 96, 'winner' => 1),
        array('ceremony' => 96, 'winner' => 0),
        array('ceremony' => 97, 'winner' => 1),
        array('ceremony' => 97, 'winner' => 0),
        array('ceremony' => 98, 'winner' => 1),
    ));
    $assert($h->compare($live, $grown) === array(), 'A pure addition should pass the guard.');

    // First import into an empty table passes.
    $empty = array('rows' => 0, 'winners' => 0, 'ceremony_winners' => array());
    $assert($h->compare($empty, $grown) === array(), 'Importing into an empty table should pass the guard.');
}

// Admin UI: the guard must be surfaced and confirmed, not swallowed.
$assert(strpos($admin_js, "data.guard === 'shrink'") !== false, 'admin.js should handle the shrink guard response.');
$assert(strpos($admin_js, "formData.append('confirm_shrink', '1')") !== false, 'admin.js should re-submit with confirm_shrink only after confirmation.');
$assert(strpos($admin_js, 'window.confirm(') !== false, 'admin.js should ask before re-submitting a shrinking import.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Import shrink guard contract OK.\n";
