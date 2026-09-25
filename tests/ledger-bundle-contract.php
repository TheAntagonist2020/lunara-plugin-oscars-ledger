<?php
/**
 * Ledger bundle contract (plan-v5 §4.1, §4.2, §11; work unit U01).
 *
 * The deployed bundle in data/ledger/ is the upstream CSV's audited overlay, read exactly
 * as shipped, with every count generated into data/ledger/manifest.json. This test reads
 * every expected number from that manifest or recounts it from the files; it pins no
 * overlay-derived number. Only the upstream file's own shape is taken from the file.
 *
 * It checks, independently of the codec where it can: the source hash and RFC parse;
 * byte copies and every manifest hash; the codec rules; the corrections applied in file
 * order with one before-check per entry (cells corrected more than once included); the
 * additions and their typing table; the corrected sheet against the manifest, the
 * committed TSV and the Python reference; nomination keys; the reference files; numeric
 * titles; needs-review; the licence; `build-ledger-bundle.php --check` (run without git
 * on PATH), its usage and baseline refusals; the baselines directory; the codec file's
 * standalone load; refusal codes of in-test mutations on temporary copies; the privacy
 * tables; step-0 redaction; decades.json; bundle identity; the design drafts; the
 * shipped JSON layout; accepted-drift retire bounds; and the workbook dimensions.
 *
 * Usage: php tests/ledger-bundle-contract.php
 */

$root = dirname(__DIR__);
require_once $root . '/tests/tools/lib/redact-evidence.php';
require_once $root . '/includes/class-aat-ledger-source.php';
require_once $root . '/includes/class-aat-ledger.php';

$failures = array();
$notes = array();
$assert = function ($condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = function (string $relpath) use ($root) {
    $bytes = @file_get_contents($root . '/' . $relpath);
    return is_string($bytes) ? $bytes : null;
};
$json = function (string $relpath) use ($read) {
    $bytes = $read($relpath);
    return $bytes === null ? null : json_decode($bytes, true);
};
$is_list = function ($value) {
    return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
};

$builder = $root . '/tests/tools/build-ledger-bundle.php';
$codec_file = $root . '/includes/class-aat-ledger-source.php';

/**
 * Run a command without a shell. Returns array(status, stdout, stderr).
 *
 * @param array<int, string> $cmd
 * @param array<string, string>|null $env
 */
function ledger_bundle_run(array $cmd, ?array $env = null, ?string $cwd = null): array
{
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return array(-1, '', 'proc_open failed');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($proc), (string) $out, (string) $err);
}

function ledger_bundle_copy_dir(string $from, string $to, array $skip = array()): void
{
    if (!is_dir($from)) {
        return;
    }
    if (!is_dir($to)) {
        mkdir($to, 0700, true);
    }
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }
        if (is_dir($from . '/' . $entry)) {
            ledger_bundle_copy_dir($from . '/' . $entry, $to . '/' . $entry, $skip);
        } else {
            copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }
}

function ledger_bundle_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

function ledger_bundle_which(string $binary): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file($dir . '/' . $binary) && is_executable($dir . '/' . $binary)) {
            return $dir . '/' . $binary;
        }
    }
    return null;
}

$manifest = $json('data/ledger/manifest.json');
if (!is_array($manifest)) {
    fwrite(STDERR, "Ledger bundle contract FAILED:\n- data/ledger/manifest.json is missing or not JSON\n");
    exit(1);
}
$upstream_rows = $manifest['source']['rows'] ?? null;
$assert(is_int($upstream_rows) && $upstream_rows > 0, 'manifest.source.rows should be a positive integer');
$header = $manifest['source']['header'] ?? array();
$assert($header === AAT_Ledger_Source::HEADER, 'manifest.source.header should be the 14 upstream columns');
$assert(($manifest['schema'] ?? null) === AAT_Ledger_Source::BUNDLE_SCHEMA, 'manifest.schema should be ' . AAT_Ledger_Source::BUNDLE_SCHEMA);

// Tokens the privacy patterns must catch, built at run time (plan-v5 errata E5).
$wikidata_url = 'https://www.' . 'wiki' . 'data.org/wiki/' . 'Q' . str_repeat('9', 5);

// ---------------------------------------------------------------------------
// (1) The upstream file: hash and RFC parse with escaping disabled.
// ---------------------------------------------------------------------------
$csv = (string) $read('data/oscars.csv');
$assert(hash('sha256', $csv) === ($manifest['source']['sha256'] ?? null), 'sha256(data/oscars.csv) should equal manifest.source.sha256');
$records = AAT_Ledger_Source::parse_rfc_tsv($csv);
$csv_header = array_shift($records);
$assert($csv_header === $header, 'the CSV header should equal manifest.source.header');
$assert(count($records) === $upstream_rows, 'the RFC parse should give manifest.source.rows rows (got ' . count($records) . ')');
$bad_width = 0;
foreach ($records as $cells) {
    if (count($cells) !== count($header)) {
        $bad_width++;
    }
}
$assert($bad_width === 0, "{$bad_width} upstream rows do not have 14 cells");
$independent = array();
foreach (explode("\n", rtrim($csv, "\n")) as $line) {
    $independent[] = str_getcsv($line, "\t", '"', '');
}
$assert($independent === array_merge(array($csv_header), $records), "the codec's RFC parse should equal str_getcsv with escaping disabled, line by line");
unset($independent);

// ---------------------------------------------------------------------------
// (2) Byte copies and every manifest hash.
// ---------------------------------------------------------------------------
foreach (array('corrections', 'additions', 'needs-review', 'accepted-drift') as $name) {
    $assert($read("data/ledger/{$name}.json") !== null && $read("data/ledger/{$name}.json") === $read("docs/database/{$name}.json"), "data/ledger/{$name}.json should be a byte copy of docs/database/{$name}.json");
}
$hashed = 0;
foreach (AAT_Ledger_Source::manifest_file_hashes($manifest) as $pair) {
    list($file, $sha) = $pair;
    $bytes = $read($file);
    if ($bytes !== null && substr($file, -3) === '.gz') {
        $bytes = @gzdecode($bytes);
    }
    $assert(is_string($bytes) && hash('sha256', $bytes) === $sha, "{$file} should hash to its manifest sha256");
    $hashed++;
}
$assert($hashed >= 8, "the manifest should record the hash of every bundle file (found {$hashed})");
$assert(AAT_Ledger_Source::bundle_id($manifest) === ($manifest['bundle_id'] ?? null), 'manifest.bundle_id should equal the identity of its recorded hashes');

// ---------------------------------------------------------------------------
// (3)-(7) Codec rules, corrections, additions, the corrected sheet and keys,
// recomputed here independently of the codec.
// ---------------------------------------------------------------------------
$field_index = array_flip($header);
$stray_backslash = 0;
foreach ($records as $cells) {
    foreach ($cells as $cell) {
        if (strpos($cell, '\\') !== false && preg_match('~\\\\(?!")~', $cell)) {
            $stray_backslash++;
        }
    }
}
$assert($stray_backslash === 0, "every upstream backslash should precede a quote ({$stray_backslash} cells do not)");

// Upstream nomination keys: decoded cells, before the overlay.
$keys = array();
foreach ($records as $cells) {
    $keys[] = sha1(str_replace('\\"', '"', implode("\x1f", $cells)));
}
$upstream_keys = array_flip($keys);

$corrections = $json('data/ledger/corrections.json');
$assert($is_list($corrections), 'corrections.json should be a JSON array');
$corrections = is_array($corrections) ? $corrections : array();
$required = array('nomination_id', 'field', 'before', 'after', 'reason', 'evidence', 'verification');
$shape_errors = 0;
foreach ($corrections as $entry) {
    $keys_ok = is_array($entry) && !array_diff($required, array_keys($entry)) && !array_diff(array_keys($entry), array_merge($required, array('imdb_id')));
    $types_ok = $keys_ok && is_int($entry['nomination_id']) && $entry['nomination_id'] > 0 && isset($field_index[$entry['field']])
        && is_string($entry['before']) && is_string($entry['after'])
        && is_string($entry['reason']) && $entry['reason'] !== '' && is_string($entry['evidence']) && $entry['evidence'] !== ''
        && is_string($entry['verification']) && $entry['verification'] !== ''
        && (!array_key_exists('imdb_id', $entry) || $entry['imdb_id'] === null || is_string($entry['imdb_id']));
    if (!$types_ok) {
        $shape_errors++;
    }
}
$assert($shape_errors === 0, "{$shape_errors} corrections.json entries do not have the plan §4.1 shape");

$rows = $records;
$mismatches = 0;
$hits = array();
$chain = array();
$reasons = array();
$targets_addition = 0;
foreach ($corrections as $n => $entry) {
    if (!is_array($entry) || !is_int($entry['nomination_id'] ?? null) || !isset($field_index[$entry['field'] ?? ''])) {
        continue;
    }
    $row = $entry['nomination_id'];
    if ($row > $upstream_rows) {
        $targets_addition++;
        continue;
    }
    $cell_key = $row . "\x1f" . $entry['field'];
    $f = $field_index[$entry['field']];
    if ($rows[$row - 1][$f] !== $entry['before']) {
        $mismatches++;
    }
    $rows[$row - 1][$f] = $entry['after'];
    $hits[$cell_key] = ($hits[$cell_key] ?? 0) + 1;
    $chain[$cell_key][] = $n;
    $reasons['r:' . $entry['reason']] = ($reasons['r:' . $entry['reason']] ?? 0) + 1;
}
$assert($mismatches === 0, "applied in file order, {$mismatches} corrections have a before-value that does not match");
$assert($targets_addition === 0, "{$targets_addition} corrections target an addition's source_row");
$multi = 0;
$chain_steps = 0;
$chain_errors = 0;
foreach ($chain as $cell_key => $entries) {
    if (count($entries) < 2) {
        continue;
    }
    $multi++;
    for ($k = 1; $k < count($entries); $k++) {
        $chain_steps++;
        if ($corrections[$entries[$k]]['before'] !== $corrections[$entries[$k - 1]]['after']) {
            $chain_errors++;
        }
    }
}
$assert($chain_errors === 0, "{$chain_errors} chained corrections do not start from the value the previous entry for that cell left");
$notes[] = "{$multi} cell(s) receive more than one correction ({$chain_steps} chained before-check(s))";
$overlay = $manifest['overlay']['corrections'] ?? array();
$assert(($overlay['count'] ?? null) === count($corrections), 'manifest.overlay.corrections.count should equal the entries in corrections.json');
$assert(($overlay['cells'] ?? null) === count($hits), 'manifest.overlay.corrections.cells should equal the distinct corrected cells');
$assert(($overlay['multi_corrected_cells'] ?? null) === $multi, 'manifest.overlay.corrections.multi_corrected_cells should equal the recount');
$recount = array();
foreach ($reasons as $key => $count) {
    $recount[substr($key, 2)] = $count;
}
$recorded = (array) ($overlay['by_reason'] ?? array());
ksort($recount, SORT_STRING);
ksort($recorded, SORT_STRING);
$assert($recorded === $recount, 'manifest.overlay.corrections.by_reason should equal a recount of corrections.json');

// Rule 4: the backslash-quote decode, after which no backslash remains.
$left = 0;
foreach ($rows as $i => $cells) {
    foreach ($cells as $c => $cell) {
        if (strpos($cell, '\\') !== false) {
            $rows[$i][$c] = str_replace('\\"', '"', $cell);
            if (strpos($rows[$i][$c], '\\') !== false) {
                $left++;
            }
        }
    }
}
$assert($left === 0, "{$left} cells keep a backslash after the overlay and the decode");

// Ceremony labels of the corrected upstream rows.
$labels = array();
foreach ($rows as $cells) {
    if (!isset($labels['c' . $cells[0]])) {
        $labels['c' . $cells[0]] = $cells[1];
    }
}

// (5) Additions.
$assert(AAT_Ledger_Source::addition_cell('Ceremony', 97) === '97', "addition_cell('Ceremony', 97) should be '97'");
$assert(AAT_Ledger_Source::addition_cell('Winner', true) === 'True', "addition_cell('Winner', true) should be 'True'");
$assert(AAT_Ledger_Source::addition_cell('Winner', false) === '', "addition_cell('Winner', false) should be ''");
$assert(AAT_Ledger_Source::addition_cell('Film', null) === '', "addition_cell('Film', null) should be ''");
$assert(AAT_Ledger_Source::addition_cell('Film', '1917') === '1917', "addition_cell('Film', '1917') should stay the string '1917'");
$additions = $json('data/ledger/additions.json');
$assert($is_list($additions), 'additions.json should be a JSON array');
$additions = is_array($additions) ? $additions : array();
$added = $manifest['overlay']['additions'] ?? array();
$assert(($added['count'] ?? null) === count($additions), 'manifest.overlay.additions.count should equal the elements of additions.json');
$first = count($additions) ? $upstream_rows + 1 : null;
$last = count($additions) ? $upstream_rows + count($additions) : null;
$assert(($added['first_source_row'] ?? 'x') === $first, 'manifest.overlay.additions.first_source_row should be source.rows + 1');
$assert(($added['last_source_row'] ?? 'x') === $last, 'manifest.overlay.additions.last_source_row should be source.rows + additions.count');
$citation_only_added = 0;
$sorted_header = $header;
sort($sorted_header, SORT_STRING);
foreach ($additions as $k => $element) {
    $source_row = $upstream_rows + 1 + $k;
    $element_keys = is_array($element) ? array_keys($element) : array();
    sort($element_keys, SORT_STRING);
    $assert($element_keys === array('evidence', 'reason', 'row', 'verification'), "addition {$source_row} should have exactly the keys row, reason, evidence and verification");
    foreach (array('reason', 'evidence', 'verification') as $key) {
        $assert(is_string($element[$key] ?? null) && trim($element[$key]) !== '', "addition {$source_row} {$key} should be a non-empty string");
    }
    $row_keys = is_array($element['row'] ?? null) ? array_map('strval', array_keys($element['row'])) : array();
    sort($row_keys, SORT_STRING);
    $assert($row_keys === $sorted_header, "addition {$source_row} row should have exactly the 14 header keys");
    if ($row_keys !== $sorted_header) {
        continue;
    }
    $cells = array();
    foreach ($header as $column) {
        $cells[] = AAT_Ledger_Source::addition_cell($column, $element['row'][$column]);
    }
    foreach ($cells as $cell) {
        $assert(strpos($cell, '\\') === false, "addition {$source_row} has a backslash in a cell");
    }
    $assert(isset($labels['c' . $cells[0]]) && $labels['c' . $cells[0]] === $cells[1], "addition {$source_row} Year should equal ceremony {$cells[0]}'s upstream label");
    $key = AAT_Ledger_Source::nomination_key($cells);
    $assert(!isset($upstream_keys[$key]), "addition {$source_row}'s nomination_key should differ from every upstream key");
    $keys[] = $key;
    if ($cells[5] === '' && $cells[7] === '' && $cells[8] === '') {
        $citation_only_added++;
    }
    $rows[] = $cells;
}
$assert(($added['citation_only'] ?? null) === $citation_only_added, 'manifest.overlay.additions.citation_only should equal the additions with empty Film, Name and Nominees');

// Rules 6 and 7 (shapes and parity) over every corrected row.
$shape = 0;
foreach ($rows as $i => $cells) {
    $ok = preg_match('~^\d{1,3}$~', $cells[0]) && preg_match('~^\d{4}(/\d{2})?$~', $cells[1]) && in_array($cells[10], array('True', ''), true);
    if ($cells[6] !== '') {
        foreach (explode('|', $cells[6]) as $token) {
            $ok = $ok && ($token === '?' || preg_match('~^tt\d{7,10}$~', $token));
        }
        $ok = $ok && substr_count($cells[6], '|') === substr_count($cells[5], '|');
    }
    if ($cells[9] !== '') {
        foreach (explode('|', $cells[9]) as $slot) {
            foreach (explode(',', $slot) as $token) {
                $ok = $ok && ($token === '?' || preg_match('~^(nm|co)\d{7,10}$~', $token));
            }
        }
        $ok = $ok && substr_count($cells[9], '|') === substr_count($cells[8], '|');
    }
    foreach ($cells as $cell) {
        $ok = $ok && strpbrk($cell, "\t\r\n") === false;
    }
    if (!$ok) {
        $shape++;
    }
}
$assert($shape === 0, "{$shape} corrected rows break the shape or parity rules");

// (6) The corrected sheet.
$tsv = implode("\t", $header) . "\n";
foreach ($rows as $cells) {
    $tsv .= implode("\t", $cells) . "\n";
}
$corrected_sha = hash('sha256', $tsv);
$assert($corrected_sha === ($manifest['corrected_sha256'] ?? null), 'the serialized corrected sheet should hash to manifest.corrected_sha256');
$assert(hash_file('sha256', $root . '/docs/database/oscars-corrected.tsv') === ($manifest['corrected_sha256'] ?? null), 'docs/database/oscars-corrected.tsv should hash to manifest.corrected_sha256');
$python = ledger_bundle_which('python3');
if ($python === null) {
    if (getenv('CI')) {
        $failures[] = 'python3 is missing, so tests/tools/corrected_tsv_reference.py cannot run (required when CI is set)';
    } else {
        $notes[] = 'python3 is missing: the Python reference was skipped';
    }
} else {
    list($status, $out, $err) = ledger_bundle_run(array($python, $root . '/tests/tools/corrected_tsv_reference.py', $root));
    $assert($status === 0 && trim($out) === ($manifest['corrected_sha256'] ?? null), 'tests/tools/corrected_tsv_reference.py should print manifest.corrected_sha256 (got ' . trim($out . ' ' . $err) . ')');
}

// (7) Nomination keys.
$expected = $manifest['expected'] ?? array();
$assert(count(array_unique($keys)) === ($expected['nominations'] ?? null), 'the distinct nomination_keys should equal manifest.expected.nominations');
$assert(($expected['nominations'] ?? null) === $upstream_rows + count($additions), 'manifest.expected.nominations should equal source.rows + additions.count');
$assert(($expected['corrections'] ?? null) === count($corrections) + count($additions), 'manifest.expected.corrections should equal corrections.count + additions.count');
$assert(($expected['nominations'] ?? null) === count($rows), 'the corrected sheet should have manifest.expected.nominations rows');

// (3) The whole codec, through the deployed bundle and its manifest (rules 1-16).
$loaded = null;
try {
    $loaded = AAT_Ledger_Source::load_bundle($root, $manifest);
    $assert($loaded['corrected_sha256'] === $corrected_sha, "the codec's corrected sheet should equal the independent recomputation");
    $assert($loaded['rows'] === $rows, "the codec's corrected rows should equal the independent recomputation");
} catch (AAT_Ledger_Refusal $e) {
    $failures[] = 'AAT_Ledger_Source::load_bundle() refused the committed bundle: ' . $e->getMessage();
}

// (3) Fold coverage and privacy.
list($fold_map, $passthrough) = AAT_Ledger_Source::fold_tables();
$allowed = array_flip(array_merge(array_keys($fold_map), $passthrough));
$unmapped = array();
$texts = array();
foreach ($rows as $cells) {
    foreach ($cells as $cell) {
        $texts[] = $cell;
    }
}

// ---------------------------------------------------------------------------
// (8) Reference files.
// ---------------------------------------------------------------------------
$referenced = array('entities' => array(), 'titles' => array());
foreach ($rows as $cells) {
    if ($cells[6] !== '') {
        foreach (explode('|', $cells[6]) as $token) {
            if ($token !== '?') {
                $referenced['titles'][$token] = true;
            }
        }
    }
    if ($cells[9] !== '') {
        foreach (explode('|', $cells[9]) as $slot) {
            foreach (explode(',', $slot) as $token) {
                if ($token !== '?') {
                    $referenced['entities'][$token] = true;
                }
            }
        }
    }
}
$reference_names = array();
$reference_rows = array();
foreach (array('entities' => "imdb_id\tkind\treference_name", 'titles' => "imdb_id\treference_title") as $which => $expected_header) {
    $bytes = (string) $read("data/ledger/{$which}.tsv");
    $lines = explode("\n", rtrim($bytes, "\n"));
    $assert($lines[0] === $expected_header, "data/ledger/{$which}.tsv should have exactly the header " . str_replace("\t", '\t', $expected_header));
    $ids = array();
    $without = 0;
    $previous = '';
    $unsorted = 0;
    $q_cells = 0;
    $dupes = 0;
    foreach (array_slice($lines, 1) as $line) {
        $cells = explode("\t", $line);
        $assert(count($cells) === count(explode("\t", $expected_header)), "data/ledger/{$which}.tsv has a line with the wrong column count");
        foreach ($cells as $cell) {
            if (preg_match('~^Q\d+$~', $cell)) {
                $q_cells++;
            }
        }
        if (isset($ids[$cells[0]])) {
            $dupes++;
        }
        if ($previous !== '' && strcmp($previous, $cells[0]) >= 0) {
            $unsorted++;
        }
        $previous = $cells[0];
        $ids[$cells[0]] = true;
        $name = end($cells);
        if ($name === '') {
            $without++;
        } else {
            $reference_names[] = $name;
        }
        $reference_rows[$which][] = $cells;
    }
    $assert($q_cells === 0, "data/ledger/{$which}.tsv should have no cell that is a bare Q-number");
    $assert($dupes === 0, "data/ledger/{$which}.tsv should have exactly one row per ID");
    $assert($unsorted === 0, "data/ledger/{$which}.tsv should be sorted by imdb_id");
    $missing = array_diff_key($referenced[$which], $ids);
    $extra = array_diff_key($ids, $referenced[$which]);
    $assert(!$missing, "data/ledger/{$which}.tsv lacks " . count($missing) . ' referenced ID(s)');
    $assert(!$extra, "data/ledger/{$which}.tsv has " . count($extra) . ' unreferenced row(s)');
    $assert(($manifest['reference'][$which]['rows'] ?? null) === count($ids), "manifest.reference.{$which}.rows should equal the rows of the file");
    $assert(($manifest['reference'][$which]['without_reference'] ?? null) === $without, "manifest.reference.{$which}.without_reference should equal the rows with an empty name");
}
$overrides = explode("\n", rtrim((string) $read('data/ledger/name-overrides.tsv'), "\n"));
$assert($overrides[0] === "imdb_id\tdisplay_name\treason", 'data/ledger/name-overrides.tsv should have the header imdb_id, display_name, reason');
$assert(($manifest['reference']['name_overrides']['rows'] ?? null) === count($overrides) - 1, 'manifest.reference.name_overrides.rows should equal the data rows of name-overrides.tsv');

foreach (array_merge($texts, $reference_names) as $text) {
    if (preg_match_all('/[^\x00-\x7f]/u', $text, $m)) {
        foreach ($m[0] as $char) {
            if (!isset($allowed[$char])) {
                $unmapped[$char] = true;
            }
        }
    }
}
$assert(!$unmapped, 'every non-ASCII character of the corrected cells and reference names should be a FOLD_MAP key or in FOLD_PASSTHROUGH (unmapped: ' . implode(' ', array_keys($unmapped)) . ')');
foreach ($reference_names as $name) {
    if (redact_evidence($name) !== $name) {
        $failures[] = "a reference name matches a privacy pattern: {$name}";
    }
}
foreach (array('corrections.json', 'additions.json', 'needs-review.json', 'accepted-drift.json') as $relname) {
    $data = $json('data/ledger/' . $relname);
    foreach (Ledger_Redaction::REDACT_KEYS[$relname] as $selector) {
        foreach (ledger_json_select($data, $selector) as $found) {
            $values = is_array($found[1]) ? array_filter($found[1], 'is_string') : array($found[1]);
            foreach ($values as $value) {
                if (is_string($value) && redact_evidence($value) !== $value) {
                    $failures[] = "data/ledger/{$relname} " . implode('.', $found[0]) . ' matches a privacy pattern';
                }
            }
        }
    }
}

// Accepted drift: valid (the codec ran rule 15 above) and counted.
$drift = $json('data/ledger/accepted-drift.json');
$assert(is_array($drift) && ($drift['schema'] ?? null) === AAT_Ledger_Source::DRIFT_SCHEMA, 'accepted-drift.json should carry the schema ' . AAT_Ledger_Source::DRIFT_SCHEMA);
foreach (array('items', 'id_pins', 'restored', 'retire') as $list) {
    $assert(($manifest['reference']['accepted_drift'][$list] ?? null) === count($drift[$list] ?? array(0)), "manifest.reference.accepted_drift.{$list} should equal the entries of accepted-drift.json");
}
$bounds = $manifest['change_bounds'] ?? array();
$assert(($bounds['max_ids_new'] ?? null) === count($additions) + count($drift['restored'] ?? array()), 'change_bounds.max_ids_new should equal additions.count + restored');
$assert(($bounds['max_ids_retired'] ?? null) === count($drift['retire'] ?? array()), 'change_bounds.max_ids_retired should equal the retire entries');

// ---------------------------------------------------------------------------
// (9) Numeric titles stay strings.
// ---------------------------------------------------------------------------
$parsed_titles = AAT_Ledger_Source::parse_reference_tsv((string) $read('data/ledger/titles.tsv'), AAT_Ledger_Source::TITLES_HEADER, 'titles.tsv');
foreach (array('10', '1917', '2010') as $title) {
    $found_title = false;
    foreach ($parsed_titles as $cells) {
        if ($cells[1] === $title) {
            $found_title = true;
        }
    }
    $assert($found_title, "the reference title '{$title}' should round-trip as the string '{$title}'");
    $found_film = false;
    foreach (is_array($loaded) ? $loaded['rows'] : array() as $cells) {
        if ($cells[5] === $title) {
            $found_film = true;
            break;
        }
    }
    $assert($found_film, "a corrected Film cell '{$title}' should stay the string '{$title}'");
}

// ---------------------------------------------------------------------------
// (10) Needs-review.
// ---------------------------------------------------------------------------
$review = $json('data/ledger/needs-review.json');
$assert($is_list($review), 'needs-review.json should be a JSON array');
$unresolved = 0;
$pairs = array();
foreach (is_array($review) ? $review : array() as $item) {
    $status = $item['resolution']['status'] ?? null;
    foreach ($item['rows'] ?? array() as $row) {
        $tokens = array();
        foreach (explode('|', $rows[$row - 1][9] ?? '') as $slot) {
            foreach (explode(',', $slot) as $token) {
                $tokens[] = $token;
            }
        }
        $present = in_array($item['imdb_id'], $tokens, true);
        if ($status === null) {
            $assert($present, "the unresolved needs-review pair ({$row}, {$item['imdb_id']}) should exist in the corrected NomineeIds");
            $pairs[$row . ':' . $item['imdb_id']] = true;
        } elseif ($status === 'corrected') {
            $assert(!$present, "the corrected needs-review ID {$item['imdb_id']} should be gone from source_row {$row}");
        }
    }
    if ($status === null) {
        $unresolved++;
    }
}
$assert(($manifest['reference']['needs_review']['items'] ?? null) === count((array) $review), 'manifest.reference.needs_review.items should equal the items');
$assert(($manifest['reference']['needs_review']['unresolved_items'] ?? null) === $unresolved, 'manifest.reference.needs_review.unresolved_items should equal the unresolved items');
$assert(($manifest['reference']['needs_review']['flagged_pairs'] ?? null) === count($pairs), 'manifest.reference.needs_review.flagged_pairs should equal the unresolved (row, ID) pairs');

// ---------------------------------------------------------------------------
// (11) Licence.
// ---------------------------------------------------------------------------
$licence = (string) $read('data/LICENSE-oscar_data.txt');
$assert(strpos($licence, 'BSD 2-Clause License') === 0, 'data/LICENSE-oscar_data.txt should begin with "BSD 2-Clause License"');
$assert(strpos($licence, 'Copyright (c) 2022, David V. Lu!!') !== false, 'data/LICENSE-oscar_data.txt should carry the DLu copyright line');
$assert(hash('sha256', $licence) === ($manifest['licence']['sha256'] ?? null), 'the licence should hash to manifest.licence.sha256');
$assert(($manifest['licence']['spdx'] ?? null) === 'BSD-2-Clause', 'manifest.licence.spdx should be BSD-2-Clause');

// ---------------------------------------------------------------------------
// (12) --check on the tree, without git on PATH; (13) usage and baseline refusals.
// ---------------------------------------------------------------------------
$tmp_base = rtrim(sys_get_temp_dir(), '/') . '/ledger-bundle-' . getmypid() . '-' . bin2hex(random_bytes(4));
$tmp_dirs = array();
$no_git_path = $tmp_base . '-path';
mkdir($no_git_path, 0700, true);
$tmp_dirs[] = $no_git_path;
$no_git_env = array('PATH' => $no_git_path, 'HOME' => $no_git_path, 'LANG' => 'C');

try {
    list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, $builder, '--check'), $no_git_env, $root);
    $assert($status === 0, "build-ledger-bundle.php --check should exit 0 on the tree with no git on PATH:\n" . trim($out . $err));

    list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, $builder), $no_git_env, $root);
    $assert($status !== 0 && strpos($err, 'Usage') !== false, 'the builder without --baseline should exit non-zero with a usage message');
    $absent = str_repeat('0', 64);
    list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, $builder, '--baseline=' . $absent), $no_git_env, $root);
    $assert($status !== 0 && strpos($err, 'baseline_missing') !== false, 'the builder with --baseline=<id> whose summary is absent should exit non-zero with baseline_missing');

    // (14) The baselines directory.
    $baseline = $manifest['simulation']['baseline'] ?? array();
    $assert(in_array($baseline['kind'] ?? null, array('legacy', 'bundle'), true), 'manifest.simulation.baseline.kind should be legacy or bundle');
    $own = 'docs/database/baselines/' . ($manifest['bundle_id'] ?? 'x') . '.json.gz';
    $allowed_files = array($own => true);
    if (($baseline['kind'] ?? null) === 'bundle') {
        $summary = $read((string) ($baseline['summary'] ?? ''));
        $decoded = is_string($summary) ? @gzdecode($summary) : false;
        $assert(is_string($decoded) && hash('sha256', $decoded) === ($baseline['summary_sha256'] ?? null), 'the recorded baseline summary should exist with sha256 = summary_sha256 (after gzdecode)');
        $allowed_files[(string) $baseline['summary']] = true;
    }
    $present = array();
    foreach (is_dir($root . '/docs/database/baselines') ? scandir($root . '/docs/database/baselines') : array() as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $present['docs/database/baselines/' . $entry] = true;
        }
    }
    $assert(isset($present[$own]), "{$own} (this bundle's own summary) should exist");
    $assert(array_keys(array_diff_key($present, $allowed_files)) === array(), 'docs/database/baselines/ should hold only the recorded baseline and this bundle\'s summary');
    $own_summary = json_decode((string) @gzdecode((string) $read($own)), true);
    $assert(($own_summary['bundle_id'] ?? null) === ($manifest['bundle_id'] ?? 'x') && ($own_summary['corrected_sha256'] ?? null) === ($manifest['corrected_sha256'] ?? 'x'), "this bundle's summary should name its bundle_id and corrected_sha256");
    $assert(count($own_summary['nominations'] ?? array()) === ($expected['nominations'] ?? null), "this bundle's summary should hold one entry per nomination");

    // ---------------------------------------------------------------------------
    // (15) The codec file: no fgetcsv, no WordPress call, standalone load.
    // ---------------------------------------------------------------------------
    $codec_source = (string) file_get_contents($codec_file);
    $assert(strpos($codec_source, 'fgetcsv(') === false, 'includes/class-aat-ledger-source.php should not call fgetcsv');
    $wp_calls = '~\b(add_action|add_filter|apply_filters|do_action|get_option|update_option|delete_option|add_option|get_transient|set_transient|delete_transient|wp_[a-z0-9_]+|esc_[a-z_]+|__|_e|_x|_n|sanitize_[a-z_]+|current_time|home_url|site_url|get_post[a-z_]*|is_admin|trailingslashit|untrailingslashit|absint|register_[a-z_]+|get_locale|remove_accents|get_bloginfo|dbDelta)\s*\(~';
    $assert(!preg_match($wp_calls, $codec_source, $m), 'includes/class-aat-ledger-source.php should call no WordPress function (found ' . ($m[1] ?? '') . ')');
    $standalone = 'require ' . var_export($codec_file, true) . '; $r = AAT_Ledger_Source::load_bundle(' . var_export($root, true) . '); echo $r["corrected_sha256"];';
    list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, '-r', $standalone), $no_git_env);
    $assert($status === 0 && trim($out) === ($manifest['corrected_sha256'] ?? null), "includes/class-aat-ledger-source.php should load standalone with only require and reproduce the corrected sha:\n" . trim($out . $err));

    // ---------------------------------------------------------------------------
    // (16) Mutations: refusal codes of the codec on mutated inputs.
    // ---------------------------------------------------------------------------
    $base_inputs = array(
        'csv' => $csv,
        'corrections' => (string) $read('data/ledger/corrections.json'),
        'additions' => (string) $read('data/ledger/additions.json'),
        'needs_review' => (string) $read('data/ledger/needs-review.json'),
        'accepted_drift' => (string) $read('data/ledger/accepted-drift.json'),
    );
    $codec_code = function (array $inputs) {
        try {
            AAT_Ledger_Source::load($inputs);
            return 'ok';
        } catch (AAT_Ledger_Refusal $e) {
            return $e->refusal_code;
        }
    };
    $with = function (string $key, callable $edit) use ($base_inputs) {
        $data = json_decode($base_inputs[$key], true);
        $edit($data);
        $inputs = $base_inputs;
        $inputs[$key] = AAT_Ledger_Json::encode_shipped($data);
        return $inputs;
    };
    $assert($codec_code($base_inputs) === 'ok', 'the unmutated inputs should load');

    // A stable addition to mutate: the first element, or a synthetic one when the file is empty.
    $has_addition = count($additions) > 0;
    $addition_base = $has_addition ? $additions[0] : null;
    if (!$has_addition) {
        $last_ceremony = 0;
        foreach ($records as $cells) {
            $last_ceremony = max($last_ceremony, (int) $cells[0]);
        }
        $addition_base = array('row' => array_combine($header, array_fill(0, 14, null)), 'reason' => 'fixture', 'evidence' => 'fixture evidence', 'verification' => 'fixture verification');
        $addition_base['row']['Ceremony'] = $last_ceremony;
        $addition_base['row']['Year'] = $labels['c' . $last_ceremony];
        $addition_base['row']['Class'] = $rows[count($records) - 1][2];
        $addition_base['row']['CanonicalCategory'] = $rows[count($records) - 1][3];
        $addition_base['row']['Category'] = $rows[count($records) - 1][4];
        $addition_base['row']['Citation'] = 'A fixture citation.';
    }
    $with_addition = function (callable $edit) use ($with, $addition_base) {
        return $with('additions', function (&$data) use ($edit, $addition_base) {
            $element = $addition_base;
            $edit($element);
            $data[0] = $element;
        });
    };
    $assert($codec_code($with_addition(function (&$e) {
    })) === 'ok', 'the addition fixture should load unchanged');

    // An upstream row with no backslash and no correction, to copy as an addition.
    $corrected_rows = array();
    foreach ($corrections as $entry) {
        $corrected_rows[(int) ($entry['nomination_id'] ?? 0)] = true;
    }
    $plain_row = null;
    foreach ($records as $i => $cells) {
        if (!isset($corrected_rows[$i + 1]) && strpos(implode('', $cells), '\\') === false && $labels['c' . $cells[0]] === $cells[1]) {
            $plain_row = $cells;
            break;
        }
    }
    $note_row = 11336;
    $note_value = $records[$note_row - 1][$field_index['Note']] ?? '';
    foreach ($corrections as $entry) {
        if (($entry['nomination_id'] ?? 0) === $note_row && ($entry['field'] ?? '') === 'Note') {
            $note_value = $entry['after'];
        }
    }
    $assert($note_value !== '' && redact_evidence(str_replace('\\"', '"', $note_value)) !== str_replace('\\"', '"', $note_value), "source_row {$note_row}'s Note should match the year-span patterns (the scope case would be vacuous otherwise)");
    $correction_template = $corrections[0];
    unset($correction_template['imdb_id']);

    $mutations = array(
        array('a flipped before-value', 'overlay_before_mismatch', $with('corrections', function (&$d) {
            $d[0]['before'] .= 'x';
        })),
        array('an injected backslash in an upstream cell', 'codec_violation', (function () use ($base_inputs) {
            $inputs = $base_inputs;
            $lines = explode("\n", $inputs['csv']);
            for ($i = 1; $i < count($lines); $i++) {
                if (strpos($lines[$i], '"') === false && strpos($lines[$i], '\\') === false && $lines[$i] !== '') {
                    $cells = explode("\t", $lines[$i]);
                    $cells[4] .= '\\x';
                    $lines[$i] = implode("\t", $cells);
                    break;
                }
            }
            $inputs['csv'] = implode("\n", $lines);
            return $inputs;
        })()),
        array('an addition element with an extra source_row key', 'addition_invalid', $with_addition(function (&$e) use ($upstream_rows) {
            $e['source_row'] = $upstream_rows + 1;
        })),
        array('an addition element without verification', 'addition_invalid', $with_addition(function (&$e) {
            unset($e['verification']);
        })),
        array('an addition Ceremony of 97.0', 'addition_cell_type', $with_addition(function (&$e) {
            $e['row']['Ceremony'] = (float) $e['row']['Ceremony'];
        })),
        array("an addition Winner of 'yes'", 'addition_cell_type', $with_addition(function (&$e) {
            $e['row']['Winner'] = 'yes';
        })),
        array('a two-entry chain whose second before differs from the first after', 'overlay_before_mismatch', $with('corrections', function (&$d) use ($correction_template) {
            $second = $correction_template;
            $second['before'] = $correction_template['before'] . ' (not the first after)';
            $second['after'] = 'x';
            $d[] = $second;
        })),
        array('a correction whose nomination_id is a string', 'correction_invalid', $with('corrections', function (&$d) {
            $d[0]['nomination_id'] = (string) $d[0]['nomination_id'];
        })),
        array('an addition identical to an upstream row', 'addition_duplicates_upstream', $with_addition(function (&$e) use ($plain_row, $header) {
            foreach ($header as $c => $column) {
                $e['row'][$column] = $plain_row[$c] === '' ? null : $plain_row[$c];
            }
            $e['row']['Ceremony'] = (int) $plain_row[0];
            $e['row']['Winner'] = $plain_row[10] === 'True';
        })),
        array('a correction on an addition row', 'correction_targets_addition', $with('corrections', function (&$d) use ($correction_template, $upstream_rows) {
            $entry = $correction_template;
            $entry['nomination_id'] = $upstream_rows + 1;
            $d[] = $entry;
        })),
        array("an addition Year unlike its ceremony's label", 'addition_year_mismatch', $with_addition(function (&$e) use ($labels) {
            $e['row']['Year'] = $labels['c1'] === $e['row']['Year'] ? $labels['c2'] : $labels['c1'];
        })),
        array('an addition cell containing U+0219', 'fold_unmapped_char', $with_addition(function (&$e) {
            $e['row']['Citation'] = (string) $e['row']['Citation'] . " \u{0219}";
        })),
        array("a Wikidata URL appended to a correction's evidence", 'privacy_violation', $with('corrections', function (&$d) use ($wikidata_url) {
            $d[0]['evidence'] .= ' ' . $wikidata_url;
        })),
        array("source_row {$note_row}'s Note in a correction's before and after", 'ok', $with('corrections', function (&$d) use ($correction_template, $note_row, $note_value) {
            $entry = $correction_template;
            $entry['nomination_id'] = $note_row;
            $entry['field'] = 'Note';
            $entry['before'] = $note_value;
            $entry['after'] = $note_value;
            $d[] = $entry;
        })),
        array('a valid accepted-drift item', 'ok', $with('accepted_drift', function (&$d) {
            $d['items'][] = array('class' => 'production_lost_flag', 'metric' => 'master_rows_changed', 'key' => 1, 'side' => 'prod', 'prod_sha1' => sha1('fixture'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
        })),
        array('an accepted-drift item without evidence', 'accepted_drift_invalid', $with('accepted_drift', function (&$d) {
            $d['items'][] = array('class' => 'production_lost_flag', 'metric' => 'master_rows_changed', 'key' => 1, 'side' => 'prod', 'prod_sha1' => sha1('fixture'), 'reason' => 'fixture reason');
        })),
        array('an id pin and a retire entry on different live ids', 'ok', $with('accepted_drift', function (&$d) {
            $d['id_pins'][] = array('source_row' => 1, 'live_id' => 5, 'prod_sha1' => sha1('pin'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
            $d['retire'][] = array('live_id' => 6, 'class' => 'production_only', 'prod_sha1' => sha1('retire'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
        })),
        array('a retire entry naming a live_id that a pin names', 'accepted_drift_invalid', $with('accepted_drift', function (&$d) {
            $d['id_pins'][] = array('source_row' => 1, 'live_id' => 5, 'prod_sha1' => sha1('pin'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
            $d['retire'][] = array('live_id' => 5, 'class' => 'production_only', 'prod_sha1' => sha1('retire'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
        })),
    );
    $assert($plain_row !== null, 'an uncorrected upstream row without backslashes should exist for the duplicate-addition mutation');
    foreach ($mutations as $mutation) {
        list($name, $want, $inputs) = $mutation;
        $got = $codec_code($inputs);
        $assert($got === $want, "mutation '{$name}' should give {$want}, got {$got}");
    }

    // (17) The privacy tables are the redactor's, verbatim.
    $assert(AAT_Ledger_Source::PRIVACY_PATTERNS === Ledger_Redaction::PRIVACY_PATTERNS, 'AAT_Ledger_Source::PRIVACY_PATTERNS should be identical to the pattern list of tests/tools/lib/redact-evidence.php');
    $assert(AAT_Ledger_Source::REDACT_KEYS === Ledger_Redaction::REDACT_KEYS, 'AAT_Ledger_Source::REDACT_KEYS should be identical to Ledger_Redaction::REDACT_KEYS');

    // ---------------------------------------------------------------------------
    // Builder runs on temporary copies of the tree.
    // ---------------------------------------------------------------------------
    $make_copy = function (bool $with_workbook) use ($root, $tmp_base, &$tmp_dirs) {
        $dir = $tmp_base . '-' . count($tmp_dirs);
        $tmp_dirs[] = $dir;
        mkdir($dir, 0700, true);
        ledger_bundle_copy_dir($root . '/data', $dir . '/data');
        ledger_bundle_copy_dir($root . '/includes', $dir . '/includes');
        ledger_bundle_copy_dir($root . '/docs/database', $dir . '/docs/database', $with_workbook ? array() : array('oscars-corrected.xlsx'));
        ledger_bundle_copy_dir($root . '/tests/tools', $dir . '/tests/tools');
        ledger_bundle_copy_dir($root . '/tests/fixtures/ledger', $dir . '/tests/fixtures/ledger');
        copy($root . '/academy-awards-table.php', $dir . '/academy-awards-table.php');
        return $dir;
    };
    $baseline_arg = ($manifest['simulation']['baseline']['kind'] ?? '') === 'bundle' ? (string) $manifest['simulation']['baseline']['bundle_id'] : 'legacy';
    $generate = function (string $dir, array $extra = array()) use ($baseline_arg) {
        return ledger_bundle_run(array_merge(array(PHP_BINARY, $dir . '/tests/tools/build-ledger-bundle.php', '--baseline=' . $baseline_arg, '--skip-workbook'), $extra), null, $dir);
    };
    $check = function (string $dir) use ($no_git_env) {
        return ledger_bundle_run(array(PHP_BINARY, $dir . '/tests/tools/build-ledger-bundle.php', '--check'), $no_git_env, $dir);
    };
    $edit_copy = function (string $dir, string $relpath, callable $edit) {
        $data = json_decode((string) file_get_contents($dir . '/' . $relpath), true);
        $edit($data);
        file_put_contents($dir . '/' . $relpath, AAT_Ledger_Json::encode_shipped($data));
    };
    $copy_manifest = function (string $dir) {
        return json_decode((string) file_get_contents($dir . '/data/ledger/manifest.json'), true);
    };

    // (16) A Wikidata URL in a correction's evidence: --check exits with evidence_not_redacted.
    $dir = $make_copy(false);
    $edit_copy($dir, 'docs/database/corrections.json', function (&$d) use ($wikidata_url) {
        $d[0]['evidence'] .= ' ' . $wikidata_url;
    });
    list($status, $out, $err) = $check($dir);
    $assert($status !== 0 && strpos($err, 'evidence_not_redacted') !== false, "--check should exit non-zero with evidence_not_redacted when a correction's evidence carries a Wikidata URL:\n" . trim($out . $err));

    // (16) Source_row 11336's Note in a correction's before and after: neither code.
    $dir = $make_copy(false);
    $edit_copy($dir, 'docs/database/corrections.json', function (&$d) use ($correction_template, $note_row, $note_value) {
        $entry = $correction_template;
        $entry['nomination_id'] = $note_row;
        $entry['field'] = 'Note';
        $entry['before'] = $note_value;
        $entry['after'] = $note_value;
        $d[] = $entry;
    });
    list($status, $out, $err) = $generate($dir);
    $assert($status === 0 && strpos($out . $err, 'privacy_violation') === false && strpos($out . $err, 'evidence_not_redacted') === false, "generation should accept a correction whose before and after carry source_row {$note_row}'s Note:\n" . trim($out . $err));
    list($status, $out, $err) = $check($dir);
    $assert($status === 0, "--check should pass after that generation:\n" . trim($out . $err));

    // (18) Step 0 redacts an unredacted evidence value in place, and only that value.
    $dir = $make_copy(false);
    $target = count($corrections) - 1;
    $edit_copy($dir, 'docs/database/corrections.json', function (&$d) use ($wikidata_url, $target) {
        $d[$target]['evidence'] .= ' ' . $wikidata_url;
    });
    $input = (string) file_get_contents($dir . '/docs/database/corrections.json');
    list($status, $out, $err) = $generate($dir);
    $assert($status === 0, "generation should redact and succeed:\n" . trim($out . $err));
    $output = (string) file_get_contents($dir . '/docs/database/corrections.json');
    $in_data = json_decode($input, true);
    $out_data = json_decode($output, true);
    $expected_data = $in_data;
    $expected_data[$target]['evidence'] = redact_evidence($in_data[$target]['evidence']);
    $assert($out_data === $expected_data && $out_data !== $in_data, 'step 0 should change exactly that evidence value, to its redacted form');
    $assert($output === AAT_Ledger_Json::encode_shipped($out_data), 'step 0 should write the shipped JSON layout');
    $diff_lines = array_diff_assoc(explode("\n", $input), explode("\n", $output));
    $assert(count($diff_lines) === 1 && strpos((string) reset($diff_lines), '"evidence": ') !== false, 'step 0 should change one line, the evidence line (changed: ' . count($diff_lines) . ')');
    $assert((string) file_get_contents($dir . '/data/ledger/corrections.json') === $output, 'the bundle copy should be the redacted file');
    $snapshot = array();
    foreach (array('docs/database/corrections.json', 'data/ledger/corrections.json', 'data/ledger/manifest.json', 'docs/database/oscars-corrected.tsv') as $relpath) {
        $snapshot[$relpath] = hash_file('sha256', $dir . '/' . $relpath);
    }
    list($status, $out, $err) = $generate($dir);
    $unchanged = $status === 0 && strpos($out, 'Wrote 0 file(s)') !== false;
    foreach ($snapshot as $relpath => $sha) {
        $unchanged = $unchanged && hash_file('sha256', $dir . '/' . $relpath) === $sha;
    }
    $assert($unchanged, "a second generation should change nothing:\n" . trim($out . $err));

    // (20) bundle_id: an accepted-drift item changes it; reswap_of does not.
    $dir = $make_copy(false);
    list($status, $out, $err) = $generate($dir, array('--reswap-of=' . $manifest['bundle_id']));
    $m2 = $copy_manifest($dir);
    $assert($status === 0 && ($m2['bundle_id'] ?? null) === $manifest['bundle_id'] && ($m2['reswap_of'] ?? null) === $manifest['bundle_id'], "setting only reswap_of should keep bundle_id:\n" . trim($out . $err));
    $edit_copy($dir, 'docs/database/accepted-drift.json', function (&$d) {
        $d['items'][] = array('class' => 'production_lost_flag', 'metric' => 'master_rows_changed', 'key' => 1, 'side' => 'prod', 'prod_sha1' => sha1('fixture'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
    });
    list($status, $out, $err) = $generate($dir);
    $m3 = $copy_manifest($dir);
    $assert($status === 0 && isset($m3['bundle_id']) && $m3['bundle_id'] !== $manifest['bundle_id'], "one added accepted-drift item should change bundle_id:\n" . trim($out . $err));
    $assert(($m3['reference']['accepted_drift']['items'] ?? null) === ($manifest['reference']['accepted_drift']['items'] ?? 0) + 1, 'the manifest should count the added accepted-drift item');
    $assert(!isset($m3['reswap_of']), 'a regenerated bundle with a new bundle_id should drop the old reswap_of');

    // (24) A retire entry raises change_bounds.max_ids_retired by one.
    $edit_copy($dir, 'docs/database/accepted-drift.json', function (&$d) {
        $d['retire'][] = array('live_id' => 999999, 'class' => 'production_only', 'prod_sha1' => sha1('retire'), 'reason' => 'fixture reason', 'evidence' => 'fixture evidence');
    });
    list($status, $out, $err) = $generate($dir);
    $m4 = $copy_manifest($dir);
    $assert($status === 0 && ($m4['change_bounds']['max_ids_retired'] ?? null) === ($m3['change_bounds']['max_ids_retired'] ?? -9) + 1, "a retire entry should raise change_bounds.max_ids_retired by one:\n" . trim($out . $err));
    $assert(count(glob($dir . '/docs/database/baselines/*')) === 1, 'the builder should keep only its own summary in docs/database/baselines/ with a legacy baseline');

    // (25) The workbook dimensions against the manifest.
    if (is_file($root . '/docs/database/oscars-corrected.xlsx')) {
        $dir = $make_copy(true);
        $edit_copy($dir, 'data/ledger/manifest.json', function (&$d) {
            $d['expected']['corrections']++;
        });
        list($status, $out, $err) = $check($dir);
        $assert($status !== 0 && strpos($err, 'workbook_stale') !== false, "--check should fail with workbook_stale when manifest.expected.corrections is off by one:\n" . trim($out . $err));
    } else {
        $notes[] = 'docs/database/oscars-corrected.xlsx is absent (plan §4.14 item 7 fallback): the workbook step and its check are skipped';
    }
} finally {
    foreach ($tmp_dirs as $dir) {
        ledger_bundle_rmdir($dir);
    }
}

// ---------------------------------------------------------------------------
// (19) decades.json from film_year_start.
// ---------------------------------------------------------------------------
$decades = $json('tests/fixtures/ledger/decades.json');
$assert(($decades['1920s'] ?? null) === array(1, 2, 3), "decades.json should map '1920s' to [1, 2, 3]");
$assert(($decades['1930s'] ?? null) === range(4, 12), "decades.json should map '1930s' to ceremonies 4 to 12");
$recomputed = array();
foreach ($labels as $key => $label) {
    $recomputed[intdiv((int) substr($label, 0, 4), 10) * 10 . 's'][] = (int) substr($key, 1);
}
foreach ($rows as $cells) {
    if (!isset($labels['c' . $cells[0]])) {
        $labels['c' . $cells[0]] = $cells[1];
        $recomputed[intdiv((int) substr($cells[1], 0, 4), 10) * 10 . 's'][] = (int) $cells[0];
    }
}
foreach ($recomputed as $decade => $list) {
    sort($recomputed[$decade], SORT_NUMERIC);
}
ksort($recomputed, SORT_STRING);
$assert($decades === $recomputed, 'decades.json should equal floor(film_year_start / 10) x 10 over every ceremony');

// ---------------------------------------------------------------------------
// (21) The design drafts; (22) no dump read; (23) the shipped JSON layout.
// ---------------------------------------------------------------------------
$php_in_design = array();
if (is_dir($root . '/docs/design')) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs/design', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (substr($file->getFilename(), -4) === '.php') {
            $php_in_design[] = $file->getPathname();
        }
    }
}
$assert($php_in_design === array(), 'docs/design/ should hold no *.php file (prototypes are committed as *.php.txt): ' . implode(', ', $php_in_design));
$assert(is_file($root . '/docs/design/ledger-2.8/plan-v5.md'), 'docs/design/ledger-2.8/plan-v5.md (the committed plan) should exist');
foreach (array($codec_file, $root . '/includes/class-aat-ledger.php', $builder, __FILE__) as $php) {
    list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, '-l', $php));
    $assert($status === 0, "php -l should pass on {$php}: " . trim($out . $err));
}
list($status, $out, $err) = ledger_bundle_run(array(PHP_BINARY, $root . '/tests/ledger-privacy-contract.php'));
$assert($status === 0, "tests/ledger-privacy-contract.php should still pass with docs/design/ledger-2.8/ committed:\n" . trim($out . $err));

// Plan-v5 errata E6: the codec never names the dump, and the builder reads no reference
// name from it (the dump reader of tests/tools/lib/redact-evidence.php is not called).
$assert(strpos($codec_source ?? (string) file_get_contents($codec_file), 'data.sql' . '.gz') === false, "includes/class-aat-ledger-source.php should not contain the string 'data.sql.gz'");
$assert(strpos((string) file_get_contents($builder), 'ledger_sql_parse_insert(') === false, 'the bundle builder should read no reference name from the SQL dump');

// Authored files are decoded to arrays, as the builder's step 0 does; the generated
// manifest and fixture keep their objects (an empty by_reason stays {}).
$shipped = array(
    'docs/database/corrections.json' => true, 'docs/database/additions.json' => true, 'docs/database/needs-review.json' => true,
    'docs/database/accepted-drift.json' => true, 'docs/database/tools/adjudications.json' => true, 'docs/database/tools/editor_decisions.json' => true,
    'data/ledger/manifest.json' => false, 'tests/fixtures/ledger/decades.json' => false,
);
foreach ($shipped as $relpath => $as_arrays) {
    $bytes = $read($relpath);
    $assert($bytes !== null && AAT_Ledger_Json::encode_shipped(json_decode($bytes, $as_arrays)) === $bytes, "{$relpath} should round-trip byte for byte through AAT_Ledger_Json::encode_shipped()");
}

// (26) This unit's tests pin no overlay-derived count.
$forbidden = array(360 + 12, 360 + 13, 12000 + 138, 3500 + 16);
foreach (array(__FILE__, $root . '/tests/tools/corrected_tsv_reference.py') as $file) {
    $assert(!preg_match('~(?<!\d)(' . implode('|', $forbidden) . ')(?!\d)~', (string) file_get_contents($file)), basename($file) . ' should contain no overlay-derived count literal');
}

if ($failures) {
    $shown = array_slice($failures, 0, 100);
    fwrite(STDERR, "Ledger bundle contract FAILED:\n- " . implode("\n- ", $shown) . "\n" . (count($failures) > 100 ? '… and ' . (count($failures) - 100) . " more\n" : ''));
    exit(1);
}
foreach ($notes as $note) {
    echo "- {$note}\n";
}
echo 'Ledger bundle contract OK: bundle ' . $manifest['bundle_id'] . ' (' . $manifest['dataset_version'] . ', ' . $manifest['mode'] . '), ' . count($mutations) . " codec mutations.\n";
