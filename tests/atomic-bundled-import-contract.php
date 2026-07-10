<?php

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$admin_js = file_get_contents($root . '/assets/js/admin.js');
$admin_template = file_get_contents($root . '/templates/admin-page.php');
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$start = strpos($plugin, 'public function ajax_import_bundled_data()');
$end = strpos($plugin, 'private function finalize_award_group_census(', $start);
$method = ($start !== false && $end !== false) ? substr($plugin, $start, $end - $start) : '';

$assert($method !== '', 'Bundled importer should be inspectable.');
$assert(strpos($method, "\$stage_table = \$wpdb->prefix . 'academy_awards_import_stage'") !== false, 'Bundled import should use an isolated staging table.');
$assert(strpos($method, 'CREATE TABLE `$stage_table` LIKE `$table_name`') !== false, 'Staging table should inherit the active awards schema.');
$assert(strpos($method, 'INSERT INTO $stage_table') !== false, 'Every bundled chunk should write to the staging table.');
$assert(strpos($method, 'TRUNCATE TABLE $table_name') === false, 'Bundled import must never truncate the active awards table.');
$assert(strpos($method, "'expected_rows' => intval(\$source_census['rows'])") !== false, 'Import state should retain the canonical row expectation.');
$assert(strpos($method, "'expected_winners' => intval(\$source_census['winners'])") !== false, 'Import state should retain the canonical winner expectation.');
$assert(strpos($method, "'source_signature' => (string) (\$source_census['signature'] ?? '')") !== false, 'Import state should retain the credit-aware source signature.');
$assert(strpos($method, '$stage_census = $this->get_database_award_group_census($stage_table)') !== false, 'Completed staging data should receive a full database census.');
$assert(strpos($method, 'hash_equals($expected_signature, $stage_signature)') !== false, 'Staging content signature must match the bundled source.');
$assert(strpos($method, "'live_preserved' => true") !== false, 'Validation and swap failures should report that live data was preserved.');
$assert(strpos($method, 'RENAME TABLE `$table_name` TO `$backup_table`, `$stage_table` TO `$table_name`') !== false, 'Validated data should use one atomic table swap.');
$assert(strpos($method, "'backup_table' => \$backup_table") !== false, 'Import state and responses should expose the rollback table.');
$assert(strpos($method, '$this->repair_writing_credit_rows()') === false, 'Canonical bundled rows should not be mutated by post-import screenplay repairs.');
$assert(strpos($method, '$this->repair_best_picture_credit_rows()') === false, 'Canonical bundled rows should not be mutated by post-import Best Picture repairs.');

$create_position = strpos($method, 'CREATE TABLE `$stage_table` LIKE `$table_name`');
$insert_position = strpos($method, 'INSERT INTO $stage_table');
$validate_position = strpos($method, '$stage_census = $this->get_database_award_group_census($stage_table)');
$swap_position = strpos($method, 'RENAME TABLE `$table_name` TO `$backup_table`');
$report_position = strpos($method, '$reporting_rebuild = $this->rebuild_reporting_tables()');
$assert(
    $create_position !== false && $insert_position > $create_position && $validate_position > $insert_position && $swap_position > $validate_position && $report_position > $swap_position,
    'Import order should be stage, populate, validate, atomically swap, then rebuild reporting.'
);

$assert(strpos($admin_js, 'existing table stays live during import') !== false, 'Admin confirmation should explain the live-table guarantee.');
$assert(strpos($admin_js, 'response.data.message') !== false, 'Admin errors should display structured safety messages.');
$assert(strpos($admin_template, 'validated in a staging table first') !== false, 'Admin page should explain the atomic import workflow.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Atomic bundled import contract OK: canonical data validates off-line, swaps atomically, and keeps a rollback table.\n";
