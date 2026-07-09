<?php

$root = dirname(__DIR__);
$builder_path = $root . '/includes/class-aat-entity-graph-builder.php';
$csv_path = $root . '/data/oscars.csv';
$builder = file_get_contents($builder_path);
$failures = array();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(is_string($builder) && $builder !== '', 'Entity graph builder should be readable.');
$assert(strpos($builder, "array('Ceremony', 'CanonicalCategory', 'FilmId', 'NomineeIds', 'Detail', 'Winner')") !== false, 'Backfill should require the row detail discriminator.');
$assert(strpos($builder, "preg_match_all('/(?:tt|nm|co)\\d{5,10}/i'") !== false, 'Backfill should preserve title, person, and company IDs.');
$assert(strpos($builder, "['detail']") !== false, 'Backfill candidates should read database row detail.');

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $builder_path;
$backfill_key_method = new ReflectionMethod('AAT_Entity_Graph_Builder', 'backfill_key');
$backfill_key_method->setAccessible(true);

$old_key = static function ($row) {
    $category = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) ($row['CanonicalCategory'] ?? '')));
    $id_blob = (string) ($row['FilmId'] ?? '') . ' ' . (string) ($row['NomineeIds'] ?? '');
    preg_match_all('/(?:tt|nm)\d{5,10}/i', strtolower($id_blob), $matches);
    $tokens = array_values(array_unique((array) ($matches[0] ?? array())));
    sort($tokens, SORT_STRING);
    return intval($row['Ceremony'] ?? 0) . '|' . $category . '|' . implode(',', $tokens);
};

$safe_key = static function ($row) use ($backfill_key_method) {
    $id_blob = (string) ($row['FilmId'] ?? '') . ' ' . (string) ($row['NomineeIds'] ?? '');
    return $backfill_key_method->invoke(
        null,
        intval($row['Ceremony'] ?? 0),
        (string) ($row['CanonicalCategory'] ?? ''),
        $id_blob,
        (string) ($row['Detail'] ?? '')
    );
};

$handle = fopen($csv_path, 'rb');
$headers = $handle ? fgetcsv($handle, 0, "\t") : false;
$rows = array();
while ($handle && ($values = fgetcsv($handle, 0, "\t")) !== false) {
    if ($values === array(null) || !is_array($headers) || count($values) !== count($headers)) {
        continue;
    }
    $rows[] = array_combine($headers, $values);
}
if ($handle) {
    fclose($handle);
}

$assert(count($rows) === 12137, 'Canonical dataset should contain 12,137 rows.');

$winner_keys_old = array();
$winner_keys_safe = array();
foreach ($rows as $row) {
    $is_winner = in_array(strtolower(trim((string) ($row['Winner'] ?? ''))), array('1', 'true', 'yes'), true);
    if ($is_winner) {
        $winner_keys_old[$old_key($row)] = true;
        $winner_keys_safe[$safe_key($row)] = true;
    }
}

$old_false_promotions = 0;
$safe_false_promotions = 0;
foreach ($rows as $row) {
    $is_winner = in_array(strtolower(trim((string) ($row['Winner'] ?? ''))), array('1', 'true', 'yes'), true);
    if ($is_winner) {
        continue;
    }
    $old_false_promotions += isset($winner_keys_old[$old_key($row)]) ? 1 : 0;
    $safe_false_promotions += isset($winner_keys_safe[$safe_key($row)]) ? 1 : 0;
}

$assert($old_false_promotions === 12, 'Historical lossy matcher should reproduce the 12 live false-winner promotions.');
$assert($safe_false_promotions === 0, 'Company IDs and row detail should eliminate every false-winner promotion.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Winner backfill identity contract OK: historical +12 reproduced, safe matcher +0.\n";
