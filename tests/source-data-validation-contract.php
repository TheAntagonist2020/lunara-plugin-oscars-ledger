<?php

$root = dirname(__DIR__);
$helper = $root . '/includes/class-aat-source-validator.php';

if (!file_exists($helper)) {
    fwrite(STDERR, "Missing helper: {$helper}\n");
    exit(1);
}

require_once $helper;

if (!class_exists('AAT_Source_Validator')) {
    fwrite(STDERR, "Missing AAT_Source_Validator class.\n");
    exit(1);
}

$sql_path = getenv('AAT_NORMALIZED_SQL');
if (!$sql_path) {
    $sql_path = 'E:\\normalized_academy_awards.sql';
}

$workbook_path = getenv('AAT_SOURCE_WORKBOOK');
if (!$workbook_path) {
    $workbook_path = 'E:\\academy_awards_full_data_REPAIRED.xlsx';
}

$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$sql = AAT_Source_Validator::inspect_normalized_sql($sql_path);
$assert(($sql['exists'] ?? false) === true, 'Normalized SQL source should exist.');
$assert(($sql['source_table'] ?? '') === 'vp_backup_wp_academy_awards', 'SQL source comment should identify vp_backup_wp_academy_awards.');
$assert(($sql['tables'] ?? array()) === array('ceremonies', 'categories', 'films', 'people', 'nominations', 'nomination_films', 'nomination_people'), 'SQL tables should match the normalized candidate contract.');
$assert(($sql['insert_counts']['ceremonies'] ?? 0) === 98, 'SQL should contain 98 ceremony inserts.');
$assert(($sql['insert_counts']['categories'] ?? 0) === 66, 'SQL should contain 66 category inserts.');
$assert(($sql['insert_counts']['films'] ?? 0) === 5259, 'SQL should contain 5,259 film inserts.');
$assert(($sql['insert_counts']['people'] ?? 0) === 6722, 'SQL should contain 6,722 person inserts.');
$assert(($sql['insert_counts']['nominations'] ?? 0) === 12118, 'SQL should contain 12,118 nomination inserts.');
$assert(($sql['insert_counts']['nomination_films'] ?? 0) === 10879, 'SQL should contain 10,879 nomination-film inserts.');
$assert(($sql['insert_counts']['nomination_people'] ?? 0) === 12297, 'SQL should contain 12,297 nomination-person inserts.');
$assert(($sql['route_index_status'] ?? '') === 'missing', 'Imported normalized SQL should be flagged as missing route-oriented indexes.');
$assert(($sql['plugin_mapping_status'] ?? '') === 'external_source_only', 'Imported normalized SQL should remain external source only until mapped to wp_aat_*.');
$assert(($sql['mojibake_count'] ?? 0) > 0, 'SQL should flag visible mojibake before authoritative import.');

$workbook = AAT_Source_Validator::inspect_workbook($workbook_path);
$assert(($workbook['exists'] ?? false) === true, 'Workbook source should exist.');
$assert(($workbook['full_data']['rows'] ?? 0) === 12138, 'Workbook full_data should report 12,138 rows.');
$assert(($workbook['full_data']['cols'] ?? 0) === 14, 'Workbook full_data should report 14 columns.');
$assert(($workbook['ceremony_tab_count'] ?? 0) === 98, 'Workbook should include 98 ceremony tabs.');
$assert(empty($workbook['missing_ceremony_tabs']), 'Workbook should not be missing ceremony tabs.');
$assert(empty($workbook['duplicate_ceremony_tabs']), 'Workbook should not contain duplicate ceremony tabs.');
$assert(($workbook['first_ceremony_tab'] ?? '') === 'Ceremony_1', 'Workbook first ceremony tab should be Ceremony_1.');
$assert(($workbook['last_ceremony_tab'] ?? '') === 'Ceremony_98', 'Workbook last ceremony tab should be Ceremony_98.');

$comparison = AAT_Source_Validator::compare_sources($sql, $workbook);
$assert(($comparison['nomination_row_delta'] ?? null) === 20, 'Workbook should currently have 20 more full_data rows than SQL nominations.');
$assert(($comparison['ready_for_authoritative_import'] ?? true) === false, 'Sources should not be marked ready while SQL mojibake/mapping/index caveats remain.');
$assert(in_array('repair_sql_mojibake', $comparison['required_actions'] ?? array(), true), 'Comparison should require SQL mojibake repair.');
$assert(in_array('map_external_tables_to_wp_aat', $comparison['required_actions'] ?? array(), true), 'Comparison should require wp_aat mapping before route use.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Source data validation contract OK: SQL {$sql['insert_counts']['nominations']} nominations, workbook {$workbook['full_data']['rows']} full_data rows.\n";
