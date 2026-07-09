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
$verifier = file_get_contents($root . '/tools/verify-live-dataset.php');
$assert(is_string($verifier) && $verifier !== '', 'Live dataset verifier should be readable.');

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

$datatable_start = strpos($plugin, 'public function ajax_get_awards_datatable()');
$datatable_end = strpos($plugin, 'public function ajax_get_awards_data()', $datatable_start);
$datatable_method = ($datatable_start !== false && $datatable_end !== false)
    ? substr($plugin, $datatable_start, $datatable_end - $datatable_start)
    : '';

$entity_rows_start = strpos($plugin, 'public function get_entity_rows($entity, $id)');
$entity_rows_end = strpos($plugin, 'public function get_entity_display_name(', $entity_rows_start);
$entity_rows_method = ($entity_rows_start !== false && $entity_rows_end !== false)
    ? substr($plugin, $entity_rows_start, $entity_rows_end - $entity_rows_start)
    : '';

$bundled_import_start = strpos($plugin, 'public function ajax_import_bundled_data()');
$bundled_import_end = strpos($plugin, 'public function ajax_clear_data()', $bundled_import_start);
$bundled_import_method = ($bundled_import_start !== false && $bundled_import_end !== false)
    ? substr($plugin, $bundled_import_start, $bundled_import_end - $bundled_import_start)
    : '';

$assert($datatable_method !== '', 'Public DataTables query path should be discoverable.');
$assert(strpos($datatable_method, 'get_filtered_awards_stats') !== false, 'Public DataTables path should use indexed database stats.');
$assert(strpos($datatable_method, 'get_awards_row_fields_sql') !== false, 'Public DataTables path should query projected database fields.');
$assert(strpos($datatable_method, "'id, ' . \$this->get_awards_row_fields_sql()") !== false, 'Public DataTables path should include the stable source row ID.');
$assert(strpos($datatable_method, 'name ASC, id ASC') !== false, 'Public DataTables pagination should end with a unique row-ID tie-breaker.');
$assert(strpos($datatable_method, 'SELECT DISTINCT $fields') === false, 'Public DataTables query should not discard the unique row-ID tie-breaker with DISTINCT.');
$assert($entity_rows_method !== '', 'Public entity query path should be discoverable.');
$assert(strpos($entity_rows_method, 'get_award_nominees_table_name') !== false, 'Public entity path should prefer the nominees projection table.');
$assert(strpos($entity_rows_method, 'get_award_facts_table_name') !== false, 'Public entity path should use the award facts projection table.');
$assert($bundled_import_method !== '', 'Bundled dataset importer should be discoverable.');
$assert(strpos($bundled_import_method, "check_ajax_referer('aat_admin_nonce', 'nonce')") !== false, 'Bundled dataset parsing should remain nonce-protected.');
$assert(strpos($bundled_import_method, "current_user_can('manage_options')") !== false, 'Bundled dataset parsing should remain administrator-only.');
$assert(strpos($verifier, "\$argument === '--strict-rows'") !== false, 'Verifier should remove strict mode from positional endpoint arguments.');
$assert(strpos($verifier, "\$source_id = intval(\$row['id'] ?? 0)") !== false, 'Verifier should require stable source row IDs across paginated responses.');
$assert(preg_match('/\$award_group_fields\s*=\s*array\(.*?\'name\'.*?\'nominees\'/s', $verifier) === 1, 'Default award parity should include official credit prose and structured nominee names.');
$assert(strpos($verifier, '|| $award_group_difference_count > 0') !== false, 'Default verifier mode should fail when credit-aware award groups drift.');

$bundled_csv_uses = substr_count($plugin, 'AAT_BUNDLED_CSV_PATH');
$bundled_import_uses = substr_count($bundled_import_method, 'AAT_BUNDLED_CSV_PATH');
$assert($bundled_csv_uses === $bundled_import_uses + 1, 'Bundled CSV should only be defined globally and read inside the protected admin importer.');

foreach (array(
    'hub stats' => $hub_stats_method,
    'ceremony summary' => $ceremony_summary_method,
    'DataTables endpoint' => $datatable_method,
    'entity rows' => $entity_rows_method,
) as $label => $method_body) {
    foreach (array('AAT_BUNDLED_CSV_PATH', 'SplFileObject', 'fgetcsv(') as $csv_reader) {
        $assert(strpos($method_body, $csv_reader) === false, "Public {$label} should not parse the bundled CSV via {$csv_reader}.");
    }
}

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
