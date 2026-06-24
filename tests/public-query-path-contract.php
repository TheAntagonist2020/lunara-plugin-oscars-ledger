<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/hub-page.php',
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
$hub_template = $source['templates/hub-page.php'];

$assert(strpos($plugin, 'public function get_hub_page_stats()') !== false, 'Plugin should expose a public hub stats helper.');
$assert(strpos($plugin, 'public function get_ceremony_summary($ceremony)') !== false, 'Plugin should expose a public ceremony summary helper.');
$assert(strpos($plugin, 'public function get_latest_year_label()') !== false, 'Plugin should expose the latest year label helper.');
$assert(strpos($hub_template, 'get_hub_page_stats') !== false, 'Hub template should use the public hub stats helper.');
$assert(strpos($hub_template, 'get_ceremony_summary') !== false, 'Ceremony template should use the public ceremony summary helper.');

$hub_stats_method = '';
if (preg_match('/public function get_hub_page_stats\(\) \{(?P<body>.*?)\n    \}\n\n    \/\*\*/s', $plugin, $match)) {
    $hub_stats_method = $match['body'];
}

$ceremony_summary_method = '';
if (preg_match('/public function get_ceremony_summary\(\$ceremony\) \{(?P<body>.*?)\n    \}\n\n    \/\*\*/s', $plugin, $match)) {
    $ceremony_summary_method = $match['body'];
}

$assert($hub_stats_method !== '', 'Hub stats helper body should be discoverable.');
$assert($ceremony_summary_method !== '', 'Ceremony summary helper body should be discoverable.');
$latest_year_method = '';
if (preg_match('/public function get_latest_year_label\(\) \{(?P<body>.*?)\n    \}\n\n    \/\*\*/s', $plugin, $match)) {
    $latest_year_method = $match['body'];
}
$assert($latest_year_method !== '', 'Latest year label helper body should be discoverable.');

foreach (array(
    'hub facts projection table' => 'get_award_facts_table_name',
    'hub category stats projection table' => 'get_category_stats_table_name',
    'hub ceremony stats projection table' => 'get_ceremony_stats_table_name',
    'hub ceremonies dimension table' => 'get_ceremonies_table_name',
    'hub legacy fallback table' => 'get_table_name',
) as $label => $needle) {
    $assert(strpos($hub_stats_method, $needle) !== false, "Hub stats helper should reference {$label}.");
}

foreach (array(
    'ceremony stats projection table' => 'get_ceremony_stats_table_name',
    'award facts projection table' => 'get_award_facts_table_name',
    'categories dimension table' => 'get_categories_table_name',
    'ceremonies dimension table' => 'get_ceremonies_table_name',
    'legacy fallback table' => 'get_table_name',
) as $label => $needle) {
    $assert(strpos($ceremony_summary_method, $needle) !== false, "Ceremony summary helper should reference {$label}.");
}

$assert(strpos($latest_year_method, 'get_ceremonies_table_name') !== false, 'Latest year helper should prefer the ceremonies dimension table.');
$assert(strpos($latest_year_method, 'get_table_name') !== false, 'Latest year helper should keep a legacy fallback.');

$legacy_hot_path_queries = array(
    'SELECT COUNT(*) FROM $table_name',
    'SELECT COUNT(DISTINCT canonical_category) FROM $table_name',
    'SELECT MIN(ceremony) FROM $table_name',
    'SELECT MAX(ceremony) FROM $table_name',
    'SELECT COUNT(*) FROM $table_name WHERE ceremony = %d',
    'SELECT DISTINCT canonical_category FROM $table_name WHERE ceremony = %d',
    'SELECT MIN(ceremony) FROM $table_name WHERE ceremony > %d',
    'SELECT MAX(ceremony) FROM $table_name WHERE ceremony < %d',
);

foreach ($legacy_hot_path_queries as $needle) {
    $assert(strpos($hub_template, $needle) === false, "Hub template should not directly run legacy hot-path query: {$needle}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Public Oscars query path contract OK.\n";
