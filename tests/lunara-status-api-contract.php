<?php

$root = dirname(__DIR__);
$helper_path = $root . '/includes/class-aat-lunara-status.php';
$graph_path = $root . '/includes/class-aat-entity-graph-builder.php';
$plugin_path = $root . '/academy-awards-table.php';
$readme_path = $root . '/README.md';
$wp_readme_path = $root . '/readme.txt';
$failures = array();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$plugin_source = (string) file_get_contents($plugin_path);
$readme_source = (string) file_get_contents($readme_path);
$wp_readme_source = (string) file_get_contents($wp_readme_path);

$assert(is_file($helper_path), 'Owner-side Lunara status helper should exist.');
$assert(strpos($plugin_source, 'public function get_lunara_status()') !== false, 'Academy_Awards_Table should expose get_lunara_status().');
$assert(strpos($plugin_source, 'public function get_lunara_integrity_summary($limit = 8)') !== false, 'The existing integrity summary signature must remain backward-compatible.');
$assert(strpos($plugin_source, 'Version: 2.7.83') !== false, 'Plugin header version should be 2.7.83.');
$assert(strpos($plugin_source, "define('AAT_VERSION', '2.7.83')") !== false, 'AAT_VERSION should be 2.7.83.');
$assert(strpos($readme_source, 'Current baseline: `2.7.83`.') !== false, 'README current baseline should be 2.7.83.');
$assert(strpos($wp_readme_source, 'Stable tag: 2.7.83') !== false, 'WordPress stable tag should be 2.7.83.');
$assert(strpos($wp_readme_source, '= 2.7.83 =') !== false, 'WordPress changelog should include 2.7.83.');

if (is_file($helper_path)) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', $root . '/');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    $GLOBALS['aat_test_capable'] = true;
    $GLOBALS['aat_test_options'] = array(
        'aat_reporting_insert_failures' => array(
            'total' => 2,
            'failures' => array(
                array('error' => 'SQLSTATE secret-key=abc123', 'row' => array('private' => 'private-payload-value')),
            ),
        ),
        'aat_reporting_schema_migration_failures' => array(
            'failures' => array(
                array('error' => 'ALTER TABLE failed with credential=hidden'),
            ),
        ),
    );
    $GLOBALS['aat_test_models_ready'] = false;
    $GLOBALS['aat_test_scheduled'] = false;
    $GLOBALS['aat_test_mutations'] = array();

    if (!function_exists('__')) {
        function __($text) {
            return $text;
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url($path = '') {
            return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($capability) {
            return !empty($GLOBALS['aat_test_capable']) && $capability === 'manage_options';
        }
    }
    if (!function_exists('get_option')) {
        function get_option($key, $default = false) {
            return array_key_exists($key, $GLOBALS['aat_test_options'])
                ? $GLOBALS['aat_test_options'][$key]
                : $default;
        }
    }
    if (!function_exists('post_type_exists')) {
        function post_type_exists($post_type) {
            return !empty($GLOBALS['aat_test_models_ready'])
                && in_array($post_type, array('movie', 'person', 'ledger_entry'), true);
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook) {
            return !empty($GLOBALS['aat_test_scheduled']) ? 1770000000 : false;
        }
    }

    foreach (array(
        'update_option',
        'delete_option',
        'set_transient',
        'delete_transient',
        'wp_schedule_event',
        'wp_schedule_single_event',
        'wp_clear_scheduled_hook',
        'wp_delete_post',
    ) as $mutation_function) {
        if (!function_exists($mutation_function)) {
            eval('function ' . $mutation_function . '(...$args) { $GLOBALS[\'aat_test_mutations\'][] = __FUNCTION__; return true; }');
        }
    }

    final class AAT_Test_Read_Only_Wpdb {
        public $prefix = 'wp_';
        public $posts = 'wp_posts';
        public $postmeta = 'wp_postmeta';
        public $tables = array();
        public $awards = array();
        public $posters = array();
        public $people_artwork = array();
        public $reporting_rows = 0;
        public $reads = array();

        public function prepare($query, ...$args) {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            foreach ($args as $arg) {
                $replacement = is_int($arg) ? (string) $arg : (string) $arg;
                $query = preg_replace('/%[sd]/', $replacement, $query, 1);
            }
            return $query;
        }

        public function get_var($query) {
            $this->reads[] = (string) $query;
            if (stripos($query, 'SHOW TABLES LIKE') !== false) {
                foreach ($this->tables as $table) {
                    if (strpos($query, $table) !== false) {
                        return $table;
                    }
                }
                return null;
            }
            if (stripos($query, 'wp_aat_award_facts') !== false) {
                return $this->reporting_rows;
            }
            return null;
        }

        public function get_row($query, $output = null) {
            $this->reads[] = (string) $query;
            if (stripos($query, 'FROM wp_academy_awards') !== false) {
                return $this->awards;
            }
            if (stripos($query, 'FROM wp_aat_posters') !== false) {
                return $this->posters;
            }
            if (stripos($query, 'FROM wp_aat_entities') !== false) {
                return $this->people_artwork;
            }
            return null;
        }

        public function get_results($query, $output = null) {
            $this->reads[] = (string) $query;
            return array();
        }

        public function query($query) {
            $GLOBALS['aat_test_mutations'][] = 'wpdb::query';
            return false;
        }
    }

    require_once $helper_path;

    $wpdb = new AAT_Test_Read_Only_Wpdb();
    $wpdb->tables = array('wp_academy_awards', 'wp_aat_award_facts', 'wp_aat_posters', 'wp_aat_entities');
    $wpdb->awards = array(
        'records_total' => 12137,
        'updated_at' => '2026-08-28 22:14:00',
        'title_id_rows' => 11700,
        'missing_title_ids' => 6,
        'nominee_rows' => 9700,
        'missing_nominee_ids' => 14,
    );
    $wpdb->posters = array(
        'records_total' => 2400,
        'linked_total' => 2388,
        'invalid_id_total' => 3,
        'missing_attachment_total' => 5,
    );
    $wpdb->people_artwork = array(
        'eligible_total' => 6100,
        'mapped_total' => 4000,
    );
    $wpdb->reporting_rows = 12135;
    $GLOBALS['wpdb'] = $wpdb;

    $status = AAT_Lunara_Status::get_status('2.7.83');
    $assert(($status['schema'] ?? '') === 'lunara.oscars.status', 'Status should expose the stable schema marker.');
    $assert(($status['schema_version'] ?? null) === 1, 'Status schema version should be 1.');
    $assert(($status['plugin_version'] ?? '') === '2.7.83', 'Status should expose plugin version 2.7.83.');
    foreach (array('available', 'state', 'label', 'message', 'count', 'updated_at', 'integrity', 'artwork', 'automation', 'destinations') as $field) {
        $assert(array_key_exists($field, $status), "Status should include {$field}.");
    }
    $assert($status['available'] === true, 'Status should be available when the master table exists.');
    $assert($status['count'] === 12137, 'Status count should use the bounded owner-side aggregate.');
    $assert(($status['integrity']['reporting_gap'] ?? null) === 2, 'Integrity summary should report a bounded aggregate gap.');
    $assert(($status['integrity']['failure_counts']['insert'] ?? null) === 2, 'Integrity summary should expose only the insert failure count.');
    $assert(($status['integrity']['failure_counts']['schema'] ?? null) === 1, 'Integrity summary should expose only the schema failure count.');
    $assert(($status['artwork']['mapped'] ?? null) === 2400, 'Artwork summary should expose mapped poster count.');
    $assert(($status['artwork']['missing_attachments'] ?? null) === 5, 'Artwork summary should expose the missing attachment count.');
    $assert(($status['artwork']['people']['eligible'] ?? null) === 6100, 'Artwork summary should expose the bounded person-entity denominator.');
    $assert(($status['artwork']['people']['mapped'] ?? null) === 4000, 'Artwork summary should expose verified person portrait mappings.');
    $assert(($status['artwork']['people']['missing'] ?? null) === 2100, 'Artwork summary should derive bounded person portrait coverage gaps.');
    $assert(($status['automation']['state'] ?? '') === 'unavailable', 'Missing graph/model dependencies should degrade to unavailable.');

    $expected_destinations = array(
        'tracker' => 'academy-awards-tracker',
        'ceremony_writeups' => 'academy-awards-ceremony-writeups',
        'poster_library' => 'academy-awards-posters',
        'person_artwork' => 'academy-awards-person-portraits',
        'identity_audit' => 'academy-awards-omdb-audit',
        'entity_integrity' => 'aat-entity-graph',
    );
    $destinations = array();
    foreach ((array) ($status['destinations'] ?? array()) as $destination) {
        $destinations[$destination['id'] ?? ''] = $destination;
    }
    $assert(array_keys($destinations) === array_keys($expected_destinations), 'Status should expose exactly the six stable guided destination IDs.');
    foreach ($expected_destinations as $id => $slug) {
        $destination = $destinations[$id] ?? array();
        $assert(($destination['capability'] ?? '') === 'manage_options', "{$id} should require manage_options.");
        $assert(($destination['available'] ?? false) === true, "{$id} should be available to an authorized consumer.");
        $assert(($destination['state'] ?? '') === 'available', "{$id} should report the available state.");
        $assert(($destination['danger'] ?? null) === false, "{$id} should be classified as non-dangerous.");
        $assert(($destination['operation'] ?? '') === 'navigate', "{$id} should be navigation-only.");
        $assert(strpos((string) ($destination['url'] ?? ''), 'page=' . $slug) !== false, "{$id} should expose its canonical URL.");
    }

    $status_json = json_encode($status);
    foreach (array('SQLSTATE', 'secret-key', 'credential=hidden', 'private-payload-value', 'failures') as $secret_like) {
        $assert(stripos((string) $status_json, $secret_like) === false, "Status must redact {$secret_like} payloads.");
    }
    foreach (array('import', 'repair', 'delete', 'teardown', 'rebuild', 'publish', 'credential', 'cache_clear', 'clear_cache') as $forbidden_operation) {
        $assert(stripos((string) json_encode($status['destinations']), $forbidden_operation) === false, "Destinations must not expose {$forbidden_operation} operations.");
    }
    $assert(count($wpdb->reads) <= 8, 'Normal status reads should stay on a bounded fast path.');
    $assert($GLOBALS['aat_test_mutations'] === array(), 'Status reads must not invoke mutation, cache clear, repair, import, deletion, teardown, publishing, or credential writes.');

    $authorized_urls = array_column($status['destinations'], 'url', 'id');
    $GLOBALS['aat_test_capable'] = false;
    $restricted = AAT_Lunara_Status::get_status('2.7.83');
    foreach ($restricted['destinations'] as $destination) {
        $assert($destination['available'] === false, 'Destinations should reflect current capability denial.');
        $assert($destination['state'] === 'restricted', 'Capability denial should use the restricted state.');
        $assert($destination['url'] === $authorized_urls[$destination['id']], 'Capability denial must not erase the canonical URL.');
    }

    $missing_wpdb = new AAT_Test_Read_Only_Wpdb();
    $GLOBALS['wpdb'] = $missing_wpdb;
    $degraded = AAT_Lunara_Status::get_status('2.7.83');
    $assert($degraded['available'] === false, 'Missing tables should degrade without a fatal error.');
    $assert($degraded['state'] === 'unavailable', 'Missing master table should report unavailable.');
    $assert($degraded['count'] === 0, 'Missing master table should use a safe zero count.');
    $assert($degraded['updated_at'] === null, 'Missing master table should use a null updated_at.');

    require_once $graph_path;
    $GLOBALS['aat_test_models_ready'] = false;
    $GLOBALS['aat_test_scheduled'] = false;
    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array('last_error' => 'SQLSTATE secret-key=graph-secret');
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'unavailable', 'Missing graph models should report unavailable.');
    $assert(stripos((string) json_encode($graph), 'graph-secret') === false, 'Graph health must redact raw errors.');

    $GLOBALS['aat_test_models_ready'] = true;
    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array();
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'unscheduled', 'Ready models without a heartbeat should report unscheduled.');

    $GLOBALS['aat_test_scheduled'] = true;
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'scheduled_idle', 'A scheduled graph with no run should report scheduled_idle.');

    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array('running' => true, 'stage' => 'people', 'started_at' => time() - 120);
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'running', 'An active graph run should report running.');

    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array('last_error' => 'raw database failure must stay private', 'finished_at' => time() - 60);
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'error', 'A graph error should report a redacted error state.');
    $assert(stripos((string) json_encode($graph), 'raw database') === false, 'Graph health should never expose raw error text.');

    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array('finished_at' => time() - 60, 'stage' => 'done');
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'healthy', 'A recent completed scheduled graph should report healthy.');

    $GLOBALS['aat_test_options']['aat_entity_graph_state'] = array('finished_at' => time() - (3 * 24 * 60 * 60), 'stage' => 'done');
    $graph = AAT_Entity_Graph_Builder::get_lunara_health();
    $assert($graph['state'] === 'stale', 'An old completed graph should report stale.');
    $assert($GLOBALS['aat_test_mutations'] === array(), 'Graph health reads must remain mutation-free.');
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Lunara Oscars status API contract OK.\n";
