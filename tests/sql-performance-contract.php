<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'README.md',
    'readme.txt',
);

$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$source = array();
foreach ($files as $relative_path) {
    $path = $root . '/' . $relative_path;
    $source[$relative_path] = file_get_contents($path);
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$plugin = $source['academy-awards-table.php'];

$assert(strpos($plugin, 'Version: 2.7.32') !== false, 'Plugin header should report 2.7.32.');
$assert(strpos($plugin, "define('AAT_VERSION', '2.7.32')") !== false, 'AAT_VERSION should report 2.7.32.');
$assert(strpos($source['README.md'], 'Current baseline: `2.7.32`') !== false, 'README should report 2.7.32.');
$assert(strpos($source['readme.txt'], 'Stable tag: 2.7.32') !== false, 'readme stable tag should report 2.7.32.');
$assert(strpos($source['readme.txt'], '= 2.7.32 =') !== false, 'readme changelog should include 2.7.32.');

$required_reporting_indexes = array(
    'facts ceremony/category/winner' => 'KEY ceremony_category_winner (ceremony, category_slug, winner)',
    'facts category/ceremony/winner' => 'KEY category_ceremony_winner (category_slug, ceremony, winner)',
    'facts film ceremony lookup' => 'KEY film_ceremony (film_entity_id, ceremony)',
    'facts primary entity ceremony lookup' => 'KEY primary_entity_ceremony (primary_entity_id, ceremony)',
    'nominees entity ceremony lookup' => 'KEY entity_ceremony (entity_id, ceremony)',
    'nominees entity type/entity lookup' => 'KEY entity_type_entity (entity_type, entity_id)',
);

foreach ($required_reporting_indexes as $label => $needle) {
    $assert(strpos($plugin, $needle) !== false, "Missing reporting index: {$label}.");
}

$assert(substr_count($plugin, 'KEY category_ceremony_winner (canonical_category(191), ceremony, winner)') >= 2, 'Legacy awards table should include category-first composite indexes in both schema paths.');
$assert(substr_count($plugin, 'KEY winner_category_ceremony (winner, canonical_category(191), ceremony)') >= 2, 'Legacy awards table should include winner-first composite indexes in both schema paths.');
$assert(strpos($plugin, 'UNIQUE KEY source_award_id (source_award_id)') !== false, 'Award facts should preserve unique source_award_id lookup.');
$assert(strpos($plugin, 'UNIQUE KEY source_entity_nominee (source_award_id, entity_id, nominee_ordinal)') !== false, 'Award nominees should preserve source/entity uniqueness.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscars SQL performance contract OK.\n";
