<?php

$root = dirname(__DIR__);
$plugin_path = $root . '/academy-awards-table.php';
$csv_path = $root . '/data/oscars.csv';
$plugin = file_get_contents($plugin_path);
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$method_slice = function ($source, $start_marker, $end_marker) {
    $start = strpos($source, $start_marker);
    if ($start === false) {
        return '';
    }

    $end = strpos($source, $end_marker, $start + strlen($start_marker));
    if ($end === false) {
        return '';
    }

    return substr($source, $start, $end - $start);
};

$assert(is_string($plugin) && $plugin !== '', 'Plugin source should be readable.');
$assert(is_readable($csv_path), 'Authoritative bundled Oscars dataset should be readable.');
$assert(hash_file('sha256', $csv_path) === 'fad75163dfe626ce48139f72aca121a492982f6a7956b5350d7e51e38d7d4a95', 'Bundled Oscars dataset hash should match the vetted source.');

$csv_rows = 0;
$csv_winners = 0;
$ceremony_98_rows = 0;
$ceremony_98_winners = 0;
$ceremony_4_sound_rows = array();
$max_entity_id_length = 0;
$csv = fopen($csv_path, 'r');
if ($csv) {
    $headers = fgetcsv($csv, 0, "\t");
    if (is_array($headers)) {
        $csv_rows++;
    }
    while (($row = fgetcsv($csv, 0, "\t")) !== false) {
        if ($row === array(null) || !is_array($headers) || count($row) !== count($headers)) {
            continue;
        }

        $csv_rows++;
        $record = array_combine($headers, $row);
        $winner = strtolower(trim((string) ($record['Winner'] ?? '')));
        $is_winner = in_array($winner, array('1', 'true', 'yes'), true);
        $csv_winners += $is_winner ? 1 : 0;

        foreach (preg_split('/[|,\s]+/', (string) (($record['FilmId'] ?? '') . '|' . ($record['NomineeIds'] ?? ''))) as $entity_id) {
            $entity_id = trim((string) $entity_id);
            if ($entity_id !== '' && $entity_id !== '?') {
                $max_entity_id_length = max($max_entity_id_length, strlen($entity_id));
            }
        }

        $ceremony = intval($record['Ceremony'] ?? 0);
        if ($ceremony === 98) {
            $ceremony_98_rows++;
            $ceremony_98_winners += $is_winner ? 1 : 0;
        }

        $category = trim((string) ($record['CanonicalCategory'] ?? ''));
        if ($ceremony === 4 && $category === 'SOUND RECORDING') {
            $ceremony_4_sound_rows[] = array(
                'name' => trim((string) ($record['Name'] ?? '')),
                'nominees' => trim((string) ($record['Nominees'] ?? '')),
                'winner' => $is_winner,
            );
        }
    }
    fclose($csv);
}
$assert($csv_rows === 12138, 'Bundled Oscars dataset should contain one header plus 12,137 data rows.');
$assert($csv_winners === 3515, 'Bundled Oscars dataset should contain exactly 3,515 winner rows.');
$assert($max_entity_id_length <= 64, 'Every bundled title, person, company, or local entity ID should fit the 64-character reporting schema.');
$assert($ceremony_98_rows === 144, 'Ceremony 98 should contain all 144 vetted records.');
$assert($ceremony_98_winners === 44, 'Ceremony 98 should contain all 44 vetted winner records.');
$assert(count($ceremony_4_sound_rows) === 4, 'Ceremony 4 Sound Recording should contain four studio records.');
$ceremony_4_sound_winners = array_values(array_filter($ceremony_4_sound_rows, static function ($row) {
    return !empty($row['winner']);
}));
$assert(count($ceremony_4_sound_winners) === 1, 'Ceremony 4 Sound Recording should contain exactly one winner.');
$assert(($ceremony_4_sound_winners[0]['nominees'] ?? '') === 'Paramount Publix', 'Paramount Publix should be the ceremony 4 Sound Recording winner.');

$migration = $method_slice($plugin, 'private function maybe_upgrade_reporting_schema()', 'private function maybe_create_reporting_tables(');
$table_setup = $method_slice($plugin, 'private function maybe_create_reporting_tables(', 'private function maybe_create_person_credit_reviews_table(');
$rebuild = $method_slice($plugin, 'private function rebuild_reporting_tables()', 'private function get_projection_total_counts()');
$integrity = $method_slice($plugin, 'public function get_lunara_integrity_summary(', 'public function ajax_clear_data()');
$identity_key = $method_slice($plugin, 'private function get_award_group_integrity_key(', 'private function get_bundled_award_group_census()');
$bundled_census = $method_slice($plugin, 'private function get_bundled_award_group_census()', 'private function get_database_award_group_census(');
$database_census = $method_slice($plugin, 'private function get_database_award_group_census(', 'public function get_lunara_integrity_summary(');

$assert($migration !== '', 'Reporting schema migration should be inspectable.');
$assert($table_setup !== '', 'Reporting table setup should be inspectable.');
$assert($rebuild !== '', 'Reporting rebuild should be inspectable.');
$assert($integrity !== '', 'Lunara integrity summary should be inspectable.');
$assert($identity_key !== '', 'Award identity key should be inspectable.');
$assert($bundled_census !== '', 'Bundled award-group census should be inspectable.');
$assert($database_census !== '', 'Database award-group census should be inspectable.');

foreach (array(
    'AAT_BUNDLED_CSV_PATH',
    '$this->build_import_db_row($source_row)',
    '$groups[$key][\'winners\']',
) as $marker) {
    $assert(strpos($bundled_census, $marker) !== false, "Bundled census should include source-parity marker: {$marker}");
}

foreach (array(
    "preg_match_all('/(?:tt|nm|co)\\d{5,10}/i'",
    "array('name', 'nominees')",
    "['nominee_ids']",
    "['detail']",
) as $marker) {
    $assert(strpos($identity_key, $marker) !== false, "Award identity should include row discriminator: {$marker}");
}

foreach (array(
    'SELECT ceremony, year, class, canonical_category, category, film, film_id, name, nominees, nominee_ids, detail, winner',
    '$groups[$key][\'winners\'] += !empty($row[\'winner\']) ? 1 : 0;',
) as $marker) {
    $assert(strpos($database_census, $marker) !== false, "Database census should include source-parity marker: {$marker}");
}

foreach (array(
    "MODIFY entity_id varchar(64) NOT NULL",
    "MODIFY film_entity_id varchar(64) NOT NULL DEFAULT ''",
    "MODIFY primary_entity_id varchar(64) NOT NULL DEFAULT ''",
    "MODIFY entity_id varchar(64) NOT NULL DEFAULT ''",
    "MODIFY top_title_entity_id varchar(64) NOT NULL DEFAULT ''",
) as $alter) {
    $assert(strpos($migration, $alter) !== false, "Reporting migration should include {$alter}.");
}

$assert(strpos($migration, "get_option('aat_reporting_schema_version')") !== false, 'Reporting migration should be versioned independently of the plugin release.');
$assert(strpos($migration, "update_option('aat_reporting_schema_migration_failures'") !== false, 'Reporting migration failures should remain visible and retryable.');
$assert(strpos($migration, "update_option('aat_reporting_schema_version'") !== false, 'Successful reporting migration should persist its schema version.');
$assert(strpos($table_setup, '$reporting_schema_ready = $this->maybe_upgrade_reporting_schema();') !== false, 'Reporting setup should run and retain the in-place migration result.');
$assert(strpos($table_setup, 'return $reporting_schema_ready;') !== false, 'Reporting setup should return whether the schema migration succeeded.');
$assert(strpos($rebuild, 'if (!$reporting_schema_ready)') !== false, 'Reporting rebuild should abort before truncation when schema migration fails.');
$assert(strpos($rebuild, "'preserved_existing_tables' => true") !== false, 'Reporting rebuild should report that existing derived tables were preserved after a migration failure.');
$source_read_position = strpos($rebuild, '$rows = $wpdb->get_results(');
$first_truncate_position = strpos($rebuild, '$wpdb->query("TRUNCATE TABLE');
$assert($source_read_position !== false && $first_truncate_position !== false && $source_read_position < $first_truncate_position, 'Reporting rebuild should validate source rows before truncating existing projections.');
$assert(strpos($rebuild, "'source_read_failed' => true") !== false, 'Reporting rebuild should expose a failed or empty source read.');
$assert(strpos($rebuild, 'if (!is_array($rows))') !== false, 'Reporting rebuild should distinguish a failed query from an intentionally empty source table.');
$assert(strpos($rebuild, '!is_array($rows) || empty($rows)') === false, 'An intentionally empty source should clear stale reporting projections.');

foreach (array(
    'entity_id varchar(64) NOT NULL',
    "film_entity_id varchar(64) NOT NULL DEFAULT ''",
    "primary_entity_id varchar(64) NOT NULL DEFAULT ''",
    "entity_id varchar(64) NOT NULL DEFAULT ''",
    "top_title_entity_id varchar(64) NOT NULL DEFAULT ''",
) as $schema_marker) {
    $assert(strpos($table_setup, $schema_marker) !== false, "Fresh reporting tables should use {$schema_marker}.");
}

$long_entity_id = 'lnm-the-all-union-cinema-and-photo-research-institute-nikfi';
$assert(strlen($long_entity_id) > 32 && strlen($long_entity_id) <= 64, 'Long local entity fixture should prove why varchar(32) was unsafe and varchar(64) is sufficient.');
$assert(strpos(file_get_contents($csv_path), 'The ALL-UNION CINEMA AND PHOTO RESEARCH INSTITUTE (NIKFI)') !== false, 'Long local entity fixture should come from the authoritative dataset.');

foreach (array(
    '$safe_insert = function',
    'wp_check_invalid_utf8',
    '$insert_failures_total++',
    "update_option('aat_reporting_insert_failures'",
    "delete_option('aat_reporting_insert_failures')",
    "'insert_failures' => \$insert_failures_total",
) as $marker) {
    $assert(strpos($rebuild, $marker) !== false, "Reporting rebuild should expose guarded-insert marker: {$marker}");
}

$assert(substr_count($rebuild, '$safe_insert(') >= 8, 'Every reporting table family should use the guarded insert path.');
foreach (array('ceremonies', 'categories', 'entities', 'facts', 'nominees', 'category_stats', 'entity_stats', 'ceremony_stats') as $table_variable) {
    $assert(strpos($rebuild, '$wpdb->insert($' . $table_variable . '_table') === false, "Reporting rebuild should not silently insert into {$table_variable} without the guard.");
}

foreach (array(
    "get_option('aat_reporting_insert_failures'",
    "get_option('aat_reporting_schema_migration_failures'",
    'LEFT JOIN $facts_table facts ON facts.source_award_id = awards.id',
    "'label' => __('Reporting derivation'",
    "'label' => __('Reporting row missing'",
    "'label' => __('Reporting row stale'",
    "'label' => __('Reporting insert failed'",
    "'label' => __('Bundled data parity'",
    "'label' => __('Bundled dataset drift'",
    "'bundled_data_parity' => array(",
    'LEFT JOIN $awards_table awards ON awards.id = facts.source_award_id',
    'facts.winner != awards.winner',
    '$reporting_orphan_rows_total',
    '$reporting_nominee_drift_total',
    '$reporting_nominee_rows',
    '$expected_nominee_rows',
    '$reporting_nominee_count_drift_total',
    'HAVING COUNT(nominees.id) != $expected_nominee_count_sql',
    'FIND_IN_SET(',
    '$reporting_entity_stats_drift_total',
    'projection_rows',
    'stats.nominations != expected.nominations',
    'stats.ceremonies != expected.ceremonies',
    'stats.first_ceremony != expected.first_ceremony',
    'stats.last_ceremony != expected.last_ceremony',
    '$category_expected_sql',
    '$ceremony_expected_sql',
    'stats.categories_total != expected.categories_total',
    'stats.winner_categories != expected.winner_categories',
    '$reporting_rollup_drift_total',
) as $marker) {
    $assert(strpos($integrity, $marker) !== false, "Control Desk integrity should expose reporting marker: {$marker}");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . DIRECTORY_SEPARATOR;
    }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url() {
        return 'https://example.invalid/wp-content/plugins/academy-awards-table/';
    }
}
if (!function_exists('add_action')) {
    function add_action() {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter() {
        return true;
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode() {
        return true;
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook() {
        return true;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($value) {
        return strip_tags((string) $value);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value);
        return trim((string) $value);
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = str_replace(array("\r\n", "\r"), "\n", $value);
        return trim($value);
    }
}

require_once $plugin_path;
$plugin_instance = Academy_Awards_Table::get_instance();
$reflection = new ReflectionClass($plugin_instance);
$identity_method = $reflection->getMethod('get_award_group_integrity_key');
$identity_method->setAccessible(true);
$credit_fixture = array(
    'ceremony' => 97,
    'year' => '2024',
    'class' => 'Writing',
    'canonical_category' => 'WRITING (Original Screenplay)',
    'category' => 'WRITING (Original Screenplay)',
    'film' => 'September 5',
    'film_id' => 'tt28082769',
    'name' => 'Written by Moritz Binder, Tim Fehlbaum, Alex David',
    'nominees' => 'Moritz Binder|Tim Fehlbaum|Alex David',
    'nominee_ids' => 'nm9415549|nm2959497|nm1017048',
    'detail' => '',
);
$flattened_credit_fixture = $credit_fixture;
$flattened_credit_fixture['name'] = 'Moritz Binder, Tim Fehlbaum, Alex David';
$assert(
    $identity_method->invoke($plugin_instance, $credit_fixture) !== $identity_method->invoke($plugin_instance, $flattened_credit_fixture),
    'Award identity should detect changes to official credit prose even when entity IDs stay the same.'
);
$truncated_nominees_fixture = $credit_fixture;
$truncated_nominees_fixture['nominees'] = 'Moritz Binder';
$assert(
    $identity_method->invoke($plugin_instance, $credit_fixture) !== $identity_method->invoke($plugin_instance, $truncated_nominees_fixture),
    'Award identity should detect a truncated structured nominee list even when entity IDs stay the same.'
);
$census_method = $reflection->getMethod('get_bundled_award_group_census');
$census_method->setAccessible(true);
$runtime_census = $census_method->invoke($plugin_instance);
$assert(!empty($runtime_census['available']), 'Bundled award-group census should execute without WordPress database access.');
$assert(intval($runtime_census['rows'] ?? 0) === 12137, 'Bundled award-group census should report 12,137 rows.');
$assert(intval($runtime_census['winners'] ?? 0) === 3515, 'Bundled award-group census should report 3,515 winners.');
$assert(preg_match('/^[a-f0-9]{64}$/', (string) ($runtime_census['signature'] ?? '')) === 1, 'Bundled award-group census should produce a SHA-256 signature.');
$mixed_identity_groups = array_filter((array) ($runtime_census['groups'] ?? array()), static function ($counts) {
    $rows = intval($counts['rows'] ?? 0);
    $winners = intval($counts['winners'] ?? 0);
    return $winners > 0 && $winners < $rows;
});
$assert(count($mixed_identity_groups) === 0, 'Bundled award identity keys should never mix winner and non-winner rows.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Reporting integrity contract OK: authoritative 12,137-row/3,515-winner dataset, ceremony sentinels, 64-character IDs, guarded inserts.\n";
