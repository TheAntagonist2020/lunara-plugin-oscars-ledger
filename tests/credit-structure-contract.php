<?php

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$explorer = file_get_contents($root . '/assets/js/academy-awards-table.js');
$hub = file_get_contents($root . '/templates/hub-page.php');
$ballot = file_get_contents($root . '/templates/ballot.php');
$csv_path = $root . '/data/oscars.csv';
$failures = array();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$slice = static function ($source, $start_marker, $end_marker) {
    $start = strpos($source, $start_marker);
    $end = $start === false ? false : strpos($source, $end_marker, $start + strlen($start_marker));
    return ($start !== false && $end !== false) ? substr($source, $start, $end - $start) : '';
};

$normalize = $slice($plugin, 'private function normalize_awards_row($row)', 'private function build_import_db_row($row)');
$screenplay_normalize = $slice($normalize, 'if ($this->is_screenplay_category($normalized_category))', "if (\$row['name'] === '' && \$row['nominees'] !== '')");
$screenplay_repair = $slice($plugin, 'public function repair_screenplay_credit_rows($allow_remote = false)', 'public function repair_non_screenplay_writing_credit_rows()');
$tracker_search = $slice($plugin, 'public function ajax_tracker_search_entities()', 'public function ajax_tracker_add_pick()');

$assert($normalize !== '', 'Award row normalizer should be inspectable.');
$assert($screenplay_normalize !== '', 'Screenplay-specific normalizer should be inspectable.');
$assert(strpos($screenplay_normalize, '$row[\'name\'] =') === false, 'Screenplay normalization should preserve official Name credit prose.');
$assert(strpos($screenplay_normalize, '$row[\'nominees\'] = $this->screenplay_credit_to_pipe_list') !== false, 'Screenplay normalization should retain a structured nominee list.');
$assert($screenplay_repair !== '', 'Screenplay repair should be inspectable.');
$assert(strpos($screenplay_repair, 'get_screenplay_nominee_overrides') === false, 'Screenplay repair should not overwrite canonical TSV credits with stale overrides.');
$assert(strpos($plugin, 'private function get_screenplay_nominee_overrides') === false, 'The obsolete partial screenplay override table should not remain available for future repair paths.');
$assert(strpos($tracker_search, '(name LIKE %s OR nominees LIKE %s)') !== false, 'Admin entity search should match structured nominee names as well as official credit prose.');

foreach (array(
    "const nominees = this.splitPipe(row && row.nominees",
    'nominees.map((nominee, index)',
    'const officialCredit = row && row.name',
    'normalizeCreditPeople',
    ".replace(/\\band\\b/g, ' ')",
    'aat-credit-line',
) as $marker) {
    $assert(strpos($explorer, $marker) !== false, "Explorer should preserve structured credit marker: {$marker}");
}

$assert(strpos($hub, '$category === \'MUSIC (ORIGINAL SONG)\' && $detail !== \'\'') !== false, 'Original Song category pages should lead with the song title from Detail.');
$assert(strpos($hub, 'strpos($category, \'WRITING (\') === 0') !== false, 'Screenplay category pages should use structured credit framing.');
$assert(strpos($hub, '$aat_credit_people_key') !== false && strpos($hub, '$winner_credit_is_score') !== false && strpos($hub, '$show_winner_credit_line') !== false, 'Category history should suppress conjunction-only duplicate score lines without hiding screenplay or song role prose.');
$assert(strpos($ballot, '$is_original_song && $work_title !== \'\'') !== false, 'Original Song ballots should use Detail as the choice title.');

$target_categories = array(
    'WRITING (Adapted Screenplay)',
    'WRITING (Original Screenplay)',
    'MUSIC (Original Song)',
    'MUSIC (Original Score)',
);
$target_rows = 0;
$misaligned_rows = 0;
$song_rows_without_titles = 0;
$fixtures = array();
$handle = fopen($csv_path, 'rb');
$headers = $handle ? fgetcsv($handle, 0, "\t") : false;
while ($handle && ($values = fgetcsv($handle, 0, "\t")) !== false) {
    if ($values === array(null) || !is_array($headers) || count($values) !== count($headers)) {
        continue;
    }
    $row = array_combine($headers, $values);
    $category = (string) ($row['CanonicalCategory'] ?? '');
    if (!in_array($category, $target_categories, true)) {
        continue;
    }

    $target_rows++;
    $nominees = array_values(array_filter(array_map('trim', explode('|', (string) ($row['Nominees'] ?? ''))), 'strlen'));
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) ($row['NomineeIds'] ?? ''))), 'strlen'));
    if (empty($nominees) || count($nominees) !== count($ids)) {
        $misaligned_rows++;
    }
    if ($category === 'MUSIC (Original Song)' && trim((string) ($row['Detail'] ?? '')) === '') {
        $song_rows_without_titles++;
    }

    $fixture_key = intval($row['Ceremony'] ?? 0) . '|' . (string) ($row['Film'] ?? '') . '|' . $category;
    $fixtures[$fixture_key] = $row;
}
if ($handle) {
    fclose($handle);
}

$assert($target_rows === 1922, 'Canonical screenplay, Original Song, and Original Score set should contain 1,922 rows.');
$assert($misaligned_rows === 0, 'Every target row should keep one NomineeId per structured Nominee.');
$assert($song_rows_without_titles === 0, 'Every Original Song row should keep its nominated song title in Detail.');
$assert(substr_count((string) ($fixtures['97|Sing Sing|WRITING (Adapted Screenplay)']['Nominees'] ?? ''), '|') === 3, 'Sing Sing should retain four individual credited writers.');
$assert(substr_count((string) ($fixtures['97|September 5|WRITING (Original Screenplay)']['Nominees'] ?? ''), '|') === 2, 'September 5 should retain three individual credited writers.');
$assert((string) ($fixtures['94|The Worst Person in the World|WRITING (Original Screenplay)']['NomineeIds'] ?? '') === 'nm1258777|nm1258686', 'The Worst Person in the World should retain the canonical Joachim Trier ID.');
$assert(substr_count((string) ($fixtures['58|The Color Purple|MUSIC (Original Score)']['NomineeIds'] ?? ''), '|') === 11, 'The Color Purple score should retain all 12 individual composer IDs.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Credit structure contract OK: official prose preserved; 1,922 rows keep aligned people and work titles.\n";
