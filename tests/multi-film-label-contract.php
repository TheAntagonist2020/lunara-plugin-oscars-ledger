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

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Multi-film label contract OK.\n";
