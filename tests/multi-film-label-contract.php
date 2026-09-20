<?php

$root = dirname(__DIR__);
$plugin_path = $root . '/academy-awards-table.php';
$source = file_get_contents($plugin_path);
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$extract_method = function ($name) use ($source) {
    if (!preg_match('/    private function ' . preg_quote($name, '/') . '\(.*?\n    }\n/s', $source, $m)) {
        return '';
    }
    return $m[0];
};

$split = $extract_method('split_pipe_tokens');
$resolve = $extract_method('resolve_film_label_for_title_id');
$assert($split !== '', 'split_pipe_tokens should exist.');
$assert($resolve !== '', 'resolve_film_label_for_title_id should exist.');

if ($split !== '' && $resolve !== '') {
    eval('class AAT_Multi_Film_Label_Harness {' . $split . $resolve . '
        public function run($film, $ids, $id) { return $this->resolve_film_label_for_title_id($film, $ids, $id); }
    }');
    $h = new AAT_Multi_Film_Label_Harness();

    $film = '7th Heaven|Street Angel|Sunrise';
    $ids = 'tt0018379|tt0019429|tt0018455';
    $assert($h->run($film, $ids, 'tt0018379') === '7th Heaven', 'First title ID should resolve to its own label.');
    $assert($h->run($film, $ids, 'tt0019429') === 'Street Angel', 'Middle title ID should resolve to its own label.');
    $assert($h->run($film, $ids, 'TT0018455') === 'Sunrise', 'Title ID match should be case-insensitive.');
    $assert($h->run($film, $ids, 'tt9999999') === '7th Heaven', 'Unknown ID should fall back to the first label, never the joined string.');
    $assert($h->run('Oppenheimer', 'tt15398776', 'tt15398776') === 'Oppenheimer', 'Single-film rows should pass through unchanged.');
    $assert($h->run(' Oppenheimer ', 'tt15398776', 'tt15398776') === 'Oppenheimer', 'Single-film labels should be trimmed.');
}

// Both label derivations that feed stored/displayed title labels must use the resolver.
$assert(
    preg_match('/\$film_label = \$this->resolve_film_label_for_title_id\(\$row\[\'film\'\] \?\? \'\', \$row\[\'film_id\'\] \?\? \'\', \$film_entity_id\);/', $source) === 1,
    'rebuild_reporting_tables should resolve a single film label for the chosen title entity.'
);
$assert(
    preg_match('/\? \$this->resolve_film_label_for_title_id\(\$row\[\'film\'\] \?\? \'\', \$row\[\'film_id\'\] \?\? \'\', \$film_id\)/', $source) === 1,
    'Ceremony rollup should resolve a single film label for the chosen title ID.'
);

// The pipe-delimited source fields have ONE shared prose formatter.
preg_match('/public function format_pipe_list.*?\n    \}/s', $source, $public_formatter);
preg_match('/private function humanize_pipe_list.*?\n    \}/s', $source, $private_formatter);
$assert(!empty($public_formatter[0]), 'format_pipe_list should be a public formatter templates can call.');
$assert(!empty($private_formatter[0]), 'humanize_pipe_list should exist.');
if (!empty($public_formatter[0]) && !empty($private_formatter[0])) {
    eval('class AAT_Pipe_List_Harness { ' . $public_formatter[0] . $private_formatter[0] . ' }');
    $h = new AAT_Pipe_List_Harness();
    $assert($h->format_pipe_list('7th Heaven|Street Angel|Sunrise') === '7th Heaven, Street Angel and Sunrise', 'Three values should read "A, B and C".');
    $assert($h->format_pipe_list('Diane|Angela|The Wife') === 'Diane, Angela and The Wife', 'Multi-character detail should read as prose.');
    $assert($h->format_pipe_list('The Noose|The Patent Leather Kid') === 'The Noose and The Patent Leather Kid', 'Two values should read "A and B".');
    $assert($h->format_pipe_list(' Sunrise ') === 'Sunrise', 'Single values should pass through trimmed.');
    $assert($h->format_pipe_list('') === '', 'Empty values should stay empty.');
}

// Public hub templates must render pipe-delimited fields as prose, never raw.
$hub = file_get_contents($root . '/templates/hub-page.php');
$assert(strpos($hub, '$aat->format_pipe_list($value)') !== false, 'hub-page.php $aat_film_display should delegate to the shared formatter.');
foreach (array(
    "esc_html((string) \$ballot_row['detail'])" => 'ballot detail',
    "esc_html((string) \$winner_row['detail'])" => 'category history detail',
    "esc_html(' ' . \$latest_winner['detail'])" => 'category spotlight detail',
    "esc_html((string) \$nominee_row['detail'])" => 'nominee detail',
    "\$detail = trim((string) (\$entry['detail'] ?? ''))" => 'winner primary/secondary detail',
) as $needle => $label) {
    $assert(strpos($hub, $needle) === false, "hub-page.php should not print the raw {$label} field.");
}
$entity_page = file_get_contents($root . '/templates/entity-page.php');
$assert(strpos($entity_page, "esc_html((string) \$r['detail'])") === false, 'entity-page.php should not print the raw detail field.');
$assert(strpos($entity_page, 'format_pipe_list') !== false, 'entity-page.php should format the detail field.');

// Every winner helper that shows the film credit must use the prose display.
foreach (array('$aat_winner_primary', '$aat_winner_secondary', '$aat_enrich_winner_entry_links') as $helper) {
    $start = strpos($hub, $helper . ' = function(');
    $assert($start !== false, "hub-page.php should define {$helper}.");
    if ($start !== false) {
        $head = substr($hub, $start, 600);
        $assert(strpos($head, '$film = $aat_film_display($entry[\'film\'] ?? \'\');') !== false, "{$helper} should build its film label with \$aat_film_display.");
        $assert(strpos($head, '$film = trim((string) ($entry[\'film\'] ?? \'\'));') === false, "{$helper} should not use the raw pipe-joined film label.");
    }
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Multi-film label contract OK.\n";
