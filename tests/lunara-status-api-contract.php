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

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['aat_test_capable'] = true;
$GLOBALS['aat_test_models_ready'] = true;
$GLOBALS['aat_test_scheduled'] = true;
$GLOBALS['aat_test_options'] = array();
$GLOBALS['aat_test_option_reads'] = 0;
$GLOBALS['aat_test_cron_reads'] = 0;
$GLOBALS['aat_test_mutations'] = array();
$GLOBALS['aat_test_actions'] = array();
$GLOBALS['aat_test_wpdb_refs'] = array();

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
        $GLOBALS['aat_test_option_reads']++;
        return array_key_exists($key, $GLOBALS['aat_test_options'])
            ? $GLOBALS['aat_test_options'][$key]
            : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null) {
        $GLOBALS['aat_test_options'][$key] = $value;
        $GLOBALS['aat_test_mutations'][] = array(
            'function' => __FUNCTION__,
            'key' => $key,
            'autoload' => $autoload,
        );
        return true;
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
        $GLOBALS['aat_test_cron_reads']++;
        return !empty($GLOBALS['aat_test_scheduled']) ? 1770000000 : false;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['aat_test_actions'][] = array(
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}
foreach (array(
    'delete_option',
    'set_transient',
    'delete_transient',
    'wp_schedule_event',
    'wp_schedule_single_event',
    'wp_clear_scheduled_hook',
    'wp_delete_post',
) as $mutation_function) {
    if (!function_exists($mutation_function)) {
        eval('function ' . $mutation_function . '(...$args) { $GLOBALS[\'aat_test_mutations\'][] = array(\'function\' => __FUNCTION__); return true; }');
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
    public $last_error = '';
    public $fail_awards = false;
    public $fail_reporting = false;
    public $fail_title_artwork = false;
    public $fail_people_artwork = false;

    public function __construct($with_core_dependencies = true) {
        $this->tables = array('wp_academy_awards', 'wp_aat_award_facts', 'wp_aat_posters', 'wp_aat_entities');
        if ($with_core_dependencies) {
            $this->tables[] = 'wp_posts';
            $this->tables[] = 'wp_postmeta';
        }
        $this->awards = array(
            'records_total' => 12137,
            'updated_at' => '2026-08-28 22:14:00',
            'title_id_rows' => 11700,
            'missing_title_ids' => 0,
            'nominee_rows' => 9700,
            'missing_nominee_ids' => 0,
        );
        $this->posters = array(
            'records_total' => 2400,
            'linked_total' => 2400,
            'invalid_id_total' => 0,
            'missing_attachment_total' => 0,
        );
        $this->people_artwork = array(
            'eligible_total' => 6100,
            'mapped_total' => 6100,
        );
        $this->reporting_rows = 12137;
    }

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', (string) $arg, $query, 1);
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
            return $this->fail_reporting ? null : $this->reporting_rows;
        }
        return null;
    }

    public function get_row($query, $output = null) {
        $this->reads[] = (string) $query;
        if (stripos($query, 'FROM wp_academy_awards') !== false) {
            return $this->fail_awards ? null : $this->awards;
        }
        if (stripos($query, 'FROM wp_aat_posters') !== false) {
            return $this->fail_title_artwork ? null : $this->posters;
        }
        if (stripos($query, 'FROM wp_aat_entities') !== false || stripos($query, 'FROM wp_postmeta') !== false) {
            return $this->fail_people_artwork ? null : $this->people_artwork;
        }
        return null;
    }

    public function get_results($query, $output = null) {
        $this->reads[] = (string) $query;
        return array();
    }

    public function query($query) {
        $GLOBALS['aat_test_mutations'][] = array('function' => 'wpdb::query');
        return false;
    }
}

function aat_test_payload() {
    return array(
        'schema' => 'lunara.oscars.status',
        'schema_version' => 1,
        'plugin_version' => '2.7.83',
        'available' => true,
        'state' => 'healthy',
        'label' => 'Oscar Ledger',
        'message' => 'Oscar records, artwork, and automation are healthy.',
        'count' => 12137,
        'updated_at' => '2026-08-28 22:14:00',
        'integrity' => array(
            'state' => 'healthy',
            'award_rows' => 12137,
            'reporting_rows' => 12137,
            'reporting_gap' => 0,
            'title_ids' => array('present' => 11700, 'missing' => 0),
            'entity_ids' => array('rows' => 9700, 'missing' => 0),
            'tables' => array('awards' => true, 'reporting' => true),
            'failure_counts' => array('insert' => 0, 'schema' => 0),
        ),
        'artwork' => array(
            'state' => 'healthy',
            'mapped' => 2400,
            'linked' => 2400,
            'invalid_ids' => 0,
            'missing_attachments' => 0,
            'titles' => array('available' => true, 'mapped' => 2400, 'linked' => 2400, 'invalid_ids' => 0, 'missing_attachments' => 0),
            'people' => array('available' => true, 'eligible' => 6100, 'mapped' => 6100, 'missing' => 0),
        ),
        'automation' => array(
            'available' => true,
            'state' => 'healthy',
            'label' => 'Entity graph automation',
            'message' => 'Entity graph automation completed recently.',
            'running' => false,
            'scheduled' => true,
            'stage' => 'done',
            'updated_at' => time() - 60,
            'counts' => array('total' => 24337, 'processed' => 24337),
        ),
    );
}

function aat_test_snapshot($expires_at, $refresh_state = 'ready') {
    return array(
        'schema' => 'lunara.oscars.status.snapshot',
        'schema_version' => 1,
        'plugin_version' => '2.7.83',
        'generated_at' => time() - 60,
        'expires_at' => $expires_at,
        'refresh_state' => $refresh_state,
        'payload' => aat_test_payload(),
    );
}

function aat_test_reset_counters() {
    $GLOBALS['aat_test_option_reads'] = 0;
    $GLOBALS['aat_test_cron_reads'] = 0;
    $GLOBALS['aat_test_mutations'] = array();
}

function aat_test_use_wpdb($wpdb) {
    $GLOBALS['aat_test_wpdb_refs'][] = $wpdb;
    $GLOBALS['wpdb'] = $wpdb;
    if (class_exists('AAT_Lunara_Status', false)) {
        $property = new ReflectionProperty('AAT_Lunara_Status', 'request_cache');
        $property->setAccessible(true);
        $property->setValue(null, array());
    }
}

require_once $helper_path;

$assert(method_exists('AAT_Lunara_Status', 'refresh_snapshot'), 'Owner status helper should expose an explicit refresh path.');
$assert(method_exists('AAT_Lunara_Status', 'register_refresh_hooks'), 'Owner status helper should register owner-controlled refresh hooks.');

$snapshot_key = 'aat_lunara_status_snapshot_v1';
$GLOBALS['aat_test_options'] = array(
    $snapshot_key => aat_test_snapshot(time() + 3600),
    'aat_reporting_insert_failures' => array('total' => 0, 'failures' => array()),
    'aat_reporting_schema_migration_failures' => array('failures' => array()),
);
$wpdb = new AAT_Test_Read_Only_Wpdb();
aat_test_use_wpdb($wpdb);
aat_test_reset_counters();

$status = AAT_Lunara_Status::get_status('2.7.83');
$status_again = AAT_Lunara_Status::get_status('2.7.83');
$assert(($status['schema'] ?? '') === 'lunara.oscars.status', 'Status should expose the stable schema marker.');
$assert(($status['schema_version'] ?? null) === 1, 'Status schema version should be 1.');
$assert(($status['plugin_version'] ?? '') === '2.7.83', 'Status should expose plugin version 2.7.83.');
$assert(($status['snapshot']['state'] ?? '') === 'fresh', 'A current snapshot should report fresh.');
$assert($status['count'] === 12137, 'Ordinary status should read the owner snapshot payload.');
$assert($status_again['count'] === 12137, 'Repeated ordinary status should preserve the same payload.');
$assert($wpdb->reads === array(), 'Two ordinary status calls must perform zero database aggregate or table-probe queries.');
$assert($GLOBALS['aat_test_option_reads'] === 1, 'Two ordinary status calls should read the snapshot option once per request.');
$assert($GLOBALS['aat_test_cron_reads'] === 0, 'Ordinary status calls must not read cron state.');
$assert($GLOBALS['aat_test_mutations'] === array(), 'Ordinary status calls must not mutate options, caches, data, schedules, or content.');

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
    $assert(($destination['danger'] ?? null) === false, "{$id} should be classified as non-dangerous.");
    $assert(($destination['operation'] ?? '') === 'navigate', "{$id} should be navigation-only.");
    $assert(strpos((string) ($destination['url'] ?? ''), 'page=' . $slug) !== false, "{$id} should expose its canonical URL.");
}

$authorized_urls = array_column($status['destinations'], 'url', 'id');
$GLOBALS['aat_test_capable'] = false;
$restricted = AAT_Lunara_Status::get_status('2.7.83');
foreach ($restricted['destinations'] as $destination) {
    $assert($destination['available'] === false, 'Destinations should reflect current capability denial.');
    $assert($destination['state'] === 'restricted', 'Capability denial should use the restricted state.');
    $assert($destination['url'] === $authorized_urls[$destination['id']], 'Capability denial must not erase the canonical URL.');
}
$assert($GLOBALS['aat_test_option_reads'] === 1, 'Capability projection should reuse the request snapshot.');
$GLOBALS['aat_test_capable'] = true;

unset($GLOBALS['aat_test_options'][$snapshot_key]);
$missing_wpdb = new AAT_Test_Read_Only_Wpdb();
aat_test_use_wpdb($missing_wpdb);
aat_test_reset_counters();
$missing = AAT_Lunara_Status::get_status('2.7.83');
$assert($missing['available'] === false, 'A missing snapshot should degrade without recomputing aggregates.');
$assert($missing['state'] === 'unavailable', 'A missing snapshot should report unavailable.');
$assert(($missing['snapshot']['state'] ?? '') === 'missing', 'A missing snapshot should be identified explicitly.');
$assert($missing_wpdb->reads === array(), 'A missing snapshot must not trigger database aggregates.');
$assert($GLOBALS['aat_test_option_reads'] === 1, 'A missing snapshot should require one bounded option read.');
$assert($GLOBALS['aat_test_mutations'] === array(), 'A missing snapshot read must remain mutation-free.');

$GLOBALS['aat_test_options'][$snapshot_key] = aat_test_snapshot(time() - 1);
$stale_wpdb = new AAT_Test_Read_Only_Wpdb();
aat_test_use_wpdb($stale_wpdb);
aat_test_reset_counters();
$stale = AAT_Lunara_Status::get_status('2.7.83');
$assert(($stale['snapshot']['state'] ?? '') === 'stale', 'An expired snapshot should report stale.');
$assert($stale['state'] === 'needs_attention', 'A stale healthy payload must degrade to needs_attention.');
$assert($stale_wpdb->reads === array(), 'A stale snapshot must never refresh itself during an ordinary read.');
$assert($GLOBALS['aat_test_option_reads'] === 1 && $GLOBALS['aat_test_cron_reads'] === 0, 'A stale read should stay bounded to one option read and no cron read.');
$assert($GLOBALS['aat_test_mutations'] === array(), 'A stale snapshot read must remain mutation-free.');

$GLOBALS['aat_test_options'][$snapshot_key] = aat_test_snapshot(time() + 3600);
$GLOBALS['aat_test_options'][$snapshot_key]['schema_version'] = 99;
$incompatible_wpdb = new AAT_Test_Read_Only_Wpdb();
aat_test_use_wpdb($incompatible_wpdb);
aat_test_reset_counters();
$incompatible = AAT_Lunara_Status::get_status('2.7.83');
$assert(($incompatible['snapshot']['state'] ?? '') === 'incompatible', 'A schema-mismatched snapshot should report incompatible.');
$assert($incompatible['available'] === false, 'A schema-mismatched snapshot should degrade to unavailable.');
$assert($incompatible_wpdb->reads === array(), 'An incompatible snapshot must not trigger aggregates.');

if (method_exists('AAT_Lunara_Status', 'register_refresh_hooks')) {
    $GLOBALS['aat_test_actions'] = array();
    AAT_Lunara_Status::register_refresh_hooks();
    $registered_hooks = array_column($GLOBALS['aat_test_actions'], 'hook');
    $assert(in_array('aat_entity_graph_heartbeat', $registered_hooks, true), 'Snapshot refresh should reuse the owner entity heartbeat.');
    $assert(in_array('aat_after_data_import', $registered_hooks, true), 'Snapshot refresh should run after owner data imports.');
}

require_once $graph_path;
$GLOBALS['aat_test_options']['aat_entity_graph_state'] = array(
    'stage' => 'done',
    'finished_at' => time() - 60,
    'totals' => array('movies' => 6000, 'people' => 6100, 'studios' => 100, 'ledger' => 12137),
    'processed' => array('movies' => 6000, 'people' => 6100, 'studios' => 100, 'ledger' => 12137),
);
$GLOBALS['aat_test_options']['aat_reporting_insert_failures'] = array('total' => 0, 'failures' => array());
$GLOBALS['aat_test_options']['aat_reporting_schema_migration_failures'] = array('failures' => array());

if (method_exists('AAT_Lunara_Status', 'refresh_snapshot')) {
    $refresh_wpdb = new AAT_Test_Read_Only_Wpdb();
    aat_test_use_wpdb($refresh_wpdb);
    aat_test_reset_counters();
    $refreshed = AAT_Lunara_Status::refresh_snapshot('2.7.83');
    $assert(($refreshed['snapshot']['state'] ?? '') === 'fresh', 'A successful owner refresh should return a fresh snapshot.');
    $assert($refreshed['state'] === 'healthy', 'A successful complete refresh should report healthy.');
    $assert(($refreshed['artwork']['titles']['available'] ?? false) === true, 'Successful title coverage should report available.');
    $assert(($refreshed['artwork']['people']['available'] ?? false) === true, 'Successful people coverage should report available.');
    $assert(count($refresh_wpdb->reads) > 0, 'Only the explicit owner refresh path should run aggregate queries.');
    $assert($GLOBALS['aat_test_option_reads'] <= 3, 'Owner refresh should use bounded failure/graph option reads.');
    $assert($GLOBALS['aat_test_cron_reads'] === 1, 'Owner refresh should read graph scheduling once.');
    $assert(count($GLOBALS['aat_test_mutations']) === 1, 'Owner refresh should write exactly one redacted snapshot option.');
    $assert(($GLOBALS['aat_test_mutations'][0]['key'] ?? '') === $snapshot_key, 'Owner refresh should write the versioned snapshot option.');
    $assert(($GLOBALS['aat_test_mutations'][0]['autoload'] ?? null) === false, 'Owner snapshot storage must be non-autoloaded.');
    $stored = $GLOBALS['aat_test_options'][$snapshot_key] ?? array();
    $assert(($stored['schema_version'] ?? null) === 1, 'Stored snapshot should include schema version 1.');
    $assert(($stored['refresh_state'] ?? '') === 'ready', 'Stored successful snapshot should report ready.');
    $assert(intval($stored['expires_at'] ?? 0) > time(), 'Stored successful snapshot should have a future expiry.');

    $readback_wpdb = new AAT_Test_Read_Only_Wpdb();
    aat_test_use_wpdb($readback_wpdb);
    aat_test_reset_counters();
    $readback = AAT_Lunara_Status::get_status('2.7.83');
    $readback_again = AAT_Lunara_Status::get_status('2.7.83');
    $assert($readback['count'] === 12137 && $readback_again['count'] === 12137, 'Successful owner refresh should be readable through the ordinary API.');
    $assert($readback_wpdb->reads === array(), 'Readback after refresh must perform zero aggregate queries.');
    $assert($GLOBALS['aat_test_option_reads'] === 1 && $GLOBALS['aat_test_cron_reads'] === 0, 'Repeated readback should use one option read and no cron reads.');
    $assert($GLOBALS['aat_test_mutations'] === array(), 'Ordinary readback must not mutate the snapshot or any owner data.');

    $title_failure_wpdb = new AAT_Test_Read_Only_Wpdb();
    $title_failure_wpdb->fail_title_artwork = true;
    $title_failure_wpdb->last_error = 'SQLSTATE secret-key=title-artwork';
    aat_test_use_wpdb($title_failure_wpdb);
    aat_test_reset_counters();
    $title_failure = AAT_Lunara_Status::refresh_snapshot('2.7.83');
    $assert(($title_failure['artwork']['titles']['available'] ?? true) === false, 'A failed title artwork aggregate must report unavailable.');
    $assert(array_key_exists('mapped', $title_failure['artwork']['titles']) && $title_failure['artwork']['titles']['mapped'] === null, 'A failed title artwork aggregate must not manufacture a zero count.');
    $assert($title_failure['artwork']['state'] !== 'healthy' && $title_failure['state'] !== 'healthy', 'A failed title artwork aggregate must degrade artwork and overall state.');
    $assert(($title_failure['snapshot']['state'] ?? '') === 'failed', 'A failed title artwork refresh should persist a failed snapshot.');
    $assert(stripos((string) json_encode($title_failure), 'title-artwork') === false, 'A failed title artwork refresh must redact raw database errors.');

    $people_failure_wpdb = new AAT_Test_Read_Only_Wpdb();
    $people_failure_wpdb->fail_people_artwork = true;
    $people_failure_wpdb->last_error = 'SQLSTATE credential=people-artwork';
    aat_test_use_wpdb($people_failure_wpdb);
    aat_test_reset_counters();
    $people_failure = AAT_Lunara_Status::refresh_snapshot('2.7.83');
    $assert(($people_failure['artwork']['people']['available'] ?? true) === false, 'A failed people artwork aggregate must report unavailable.');
    $assert(array_key_exists('eligible', $people_failure['artwork']['people']) && $people_failure['artwork']['people']['eligible'] === null, 'A failed people artwork aggregate must not manufacture a zero denominator.');
    $assert($people_failure['artwork']['state'] !== 'healthy' && $people_failure['state'] !== 'healthy', 'A failed people artwork aggregate must degrade artwork and overall state.');
    $assert(($people_failure['snapshot']['state'] ?? '') === 'failed', 'A failed people artwork refresh should persist a failed snapshot.');
    $assert(stripos((string) json_encode($people_failure), 'people-artwork') === false, 'A failed people artwork refresh must redact raw database errors.');

    $missing_posts_wpdb = new AAT_Test_Read_Only_Wpdb(false);
    $missing_posts_wpdb->tables[] = 'wp_postmeta';
    aat_test_use_wpdb($missing_posts_wpdb);
    aat_test_reset_counters();
    $missing_posts = AAT_Lunara_Status::refresh_snapshot('2.7.83');
    $assert(($missing_posts['artwork']['titles']['available'] ?? true) === false, 'Missing posts dependency should make title artwork unavailable.');
    $assert(($missing_posts['artwork']['people']['available'] ?? true) === false, 'Missing posts dependency should make people artwork unavailable.');
    $assert($missing_posts['state'] !== 'healthy', 'Missing posts dependency must never produce a healthy snapshot.');

    $missing_postmeta_wpdb = new AAT_Test_Read_Only_Wpdb(false);
    $missing_postmeta_wpdb->tables[] = 'wp_posts';
    aat_test_use_wpdb($missing_postmeta_wpdb);
    aat_test_reset_counters();
    $missing_postmeta = AAT_Lunara_Status::refresh_snapshot('2.7.83');
    $assert(($missing_postmeta['artwork']['titles']['available'] ?? false) === true, 'Title artwork should remain readable when posts exists.');
    $assert(($missing_postmeta['artwork']['people']['available'] ?? true) === false, 'Missing postmeta dependency should make people artwork unavailable.');
    $assert($missing_postmeta['state'] !== 'healthy', 'Missing postmeta dependency must never produce a healthy snapshot.');
}

$forbidden_json = json_encode($status['destinations'] ?? array());
foreach (array('import', 'repair', 'delete', 'teardown', 'rebuild', 'publish', 'credential', 'refresh', 'cache_clear', 'clear_cache') as $forbidden_operation) {
    $assert(stripos((string) $forbidden_json, $forbidden_operation) === false, "Destinations must not expose {$forbidden_operation} operations.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Lunara Oscars status API contract OK.\n";
