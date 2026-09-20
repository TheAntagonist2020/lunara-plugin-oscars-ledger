<?php

$root = dirname(__DIR__);
$files = array_merge(array($root . '/academy-awards-table.php'), glob($root . '/includes/*.php'));
$source = file_get_contents($root . '/academy-awards-table.php');
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

// dbDelta() finds the table name with preg_match('|CREATE TABLE ([^ ]*)|'). With
// "IF NOT EXISTS" that captures "IF", dbDelta skips the diff entirely, and new
// columns/indexes never reach existing installs. Until 2.7.90 that silently kept
// eight defined indexes off production.
$create_count = 0;
foreach ($files as $file) {
    $contents = file_get_contents($file);
    $assert(stripos($contents, 'CREATE TABLE IF NOT EXISTS') === false, basename($file) . ' must not use CREATE TABLE IF NOT EXISTS (dbDelta reads the table name as "IF").');

    preg_match_all('/CREATE TABLE ([^ \n]*)/', $contents, $matches);
    foreach ($matches[1] as $captured) {
        $create_count++;
        $assert(strtoupper($captured) !== 'IF', basename($file) . ' has a CREATE TABLE whose dbDelta-parsed name is "IF".');
        $assert(preg_match('/^(\$[a-z_]+|`\$[a-z_]+`|\{?\$[a-z_]+\}?)$/', $captured) === 1, basename($file) . " CREATE TABLE should name a table variable, got '{$captured}'.");
    }
}
$assert($create_count >= 21, 'Expected the 21 plugin schema statements to be checked.');

// Every schema statement must reach dbDelta; a direct $wpdb->query() of a plain
// CREATE TABLE would error on an existing table.
$assert(preg_match('/\$wpdb->query\(\s*"CREATE TABLE \$/', $source) === 0, 'Plugin schema statements should go through dbDelta, not $wpdb->query.');

// maybe_upgrade_schema() runs on plugins_loaded: the dbDelta diff must be gated
// to once per plugin version, not every request.
if (preg_match('/public function maybe_upgrade_schema\(\) \{(.*?)\n        \$installed = get_option/s', $source, $m)) {
    $head = $m[1];
    $gate_at = strpos($head, "get_option('aat_schema_checked_version', '') !== AAT_VERSION");
    $first_create_at = strpos($head, '$this->maybe_create_');
    $assert($gate_at !== false, 'maybe_upgrade_schema should gate schema checks on aat_schema_checked_version.');
    $assert($gate_at !== false && $first_create_at !== false && $gate_at < $first_create_at, 'Every maybe_create_* call should sit inside the version gate.');
    $assert(strpos($head, "update_option('aat_schema_checked_version', AAT_VERSION, true)") !== false, 'The gate should record the checked version (autoloaded, so the check costs no query).');
    $assert(strpos($head, 'maybe_create_reporting_tables()') !== false, 'Reporting tables should still be checked inside the gate.');
} else {
    $failures[] = 'Could not locate the schema-check block in maybe_upgrade_schema().';
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Schema dbDelta contract OK.\n";
