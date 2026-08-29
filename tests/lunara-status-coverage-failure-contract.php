<?php

$root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['aat_coverage_options'] = array(
    'aat_reporting_insert_failures' => array('total' => 0, 'failures' => array()),
    'aat_reporting_schema_migration_failures' => array('failures' => array()),
    'aat_entity_graph_state' => array(
        'stage' => 'done',
        'finished_at' => time() - 60,
        'totals' => array('movies' => 6000, 'people' => 6100, 'studios' => 100, 'ledger' => 12137),
        'processed' => array('movies' => 6000, 'people' => 6100, 'studios' => 100, 'ledger' => 12137),
    ),
);

function __($text) {
    return $text;
}
function admin_url($path = '') {
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}
function current_user_can($capability) {
    return $capability === 'manage_options';
}
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['aat_coverage_options']) ? $GLOBALS['aat_coverage_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['aat_coverage_options'][$key] = $value;
    return true;
}
function post_type_exists($post_type) {
    return in_array($post_type, array('movie', 'person', 'ledger_entry'), true);
}
function wp_next_scheduled($hook) {
    return 1770000000;
}

final class AAT_Coverage_Failure_Wpdb {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $tables = array('wp_academy_awards', 'wp_aat_award_facts', 'wp_aat_posters', 'wp_aat_entities', 'wp_posts', 'wp_postmeta');
    public $mode = '';
    public $last_error = 'SQLSTATE raw-secret-error';

    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', (string) $arg, $query, 1);
        }
        return $query;
    }

    public function get_var($query) {
        if (stripos($query, 'SHOW TABLES LIKE') !== false) {
            foreach ($this->tables as $table) {
                if (strpos($query, $table) !== false) {
                    return $table;
                }
            }
            return null;
        }
        if (stripos($query, 'wp_aat_award_facts') !== false) {
            return 12137;
        }
        return null;
    }

    public function get_row($query, $output = null) {
        if (stripos($query, 'FROM wp_academy_awards') !== false) {
            return array(
                'records_total' => 12137,
                'updated_at' => '2026-08-28 22:14:00',
                'title_id_rows' => 11700,
                'missing_title_ids' => 0,
                'nominee_rows' => 9700,
                'missing_nominee_ids' => 0,
            );
        }
        if (stripos($query, 'FROM wp_aat_posters') !== false) {
            if ($this->mode === 'title_failure' || !in_array('wp_posts', $this->tables, true)) {
                return null;
            }
            return array('records_total' => 2400, 'linked_total' => 2400, 'invalid_id_total' => 0, 'missing_attachment_total' => 0);
        }
        if (stripos($query, 'FROM wp_aat_entities') !== false || stripos($query, 'FROM wp_postmeta') !== false) {
            if (
                $this->mode === 'people_failure'
                || !in_array('wp_posts', $this->tables, true)
                || !in_array('wp_postmeta', $this->tables, true)
            ) {
                return null;
            }
            return array('eligible_total' => 6100, 'mapped_total' => 6100);
        }
        return null;
    }
}

require_once $root . '/includes/class-aat-entity-graph-builder.php';
require_once $root . '/includes/class-aat-lunara-status.php';

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$run = static function ($mode, $tables = null) {
    $wpdb = new AAT_Coverage_Failure_Wpdb();
    $wpdb->mode = $mode;
    if (is_array($tables)) {
        $wpdb->tables = $tables;
    }
    $GLOBALS['wpdb'] = $wpdb;
    return method_exists('AAT_Lunara_Status', 'refresh_snapshot')
        ? AAT_Lunara_Status::refresh_snapshot('2.7.83')
        : AAT_Lunara_Status::get_status('2.7.83');
};

$title_failure = $run('title_failure');
$assert(($title_failure['artwork']['titles']['available'] ?? true) === false, 'Failed title coverage read should report unavailable.');
$assert(array_key_exists('mapped', $title_failure['artwork']['titles']) && $title_failure['artwork']['titles']['mapped'] === null, 'Failed title coverage read should not manufacture zero coverage.');
$assert(($title_failure['artwork']['state'] ?? 'healthy') !== 'healthy', 'Failed title coverage read should degrade artwork health.');
$assert(($title_failure['state'] ?? 'healthy') !== 'healthy', 'Failed title coverage read should degrade overall health.');

$people_failure = $run('people_failure');
$assert(($people_failure['artwork']['people']['available'] ?? true) === false, 'Failed people coverage read should report unavailable.');
$assert(array_key_exists('eligible', $people_failure['artwork']['people']) && $people_failure['artwork']['people']['eligible'] === null, 'Failed people coverage read should not manufacture a zero denominator.');
$assert(($people_failure['artwork']['state'] ?? 'healthy') !== 'healthy', 'Failed people coverage read should degrade artwork health.');
$assert(($people_failure['state'] ?? 'healthy') !== 'healthy', 'Failed people coverage read should degrade overall health.');

$plugin_tables_only = array('wp_academy_awards', 'wp_aat_award_facts', 'wp_aat_posters', 'wp_aat_entities');
$missing_dependencies = $run('', $plugin_tables_only);
$assert(($missing_dependencies['artwork']['titles']['available'] ?? true) === false, 'Missing posts dependency should make title coverage unavailable.');
$assert(($missing_dependencies['artwork']['people']['available'] ?? true) === false, 'Missing posts/postmeta dependencies should make people coverage unavailable.');
$assert(($missing_dependencies['state'] ?? 'healthy') !== 'healthy', 'Missing core artwork dependencies should never report healthy.');
$assert(stripos((string) json_encode(array($title_failure, $people_failure, $missing_dependencies)), 'raw-secret-error') === false, 'Coverage failures must not expose raw database error text.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Lunara Oscars coverage failure contract OK.\n";
