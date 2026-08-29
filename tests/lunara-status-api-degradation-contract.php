<?php

$root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}

require_once $root . '/includes/class-aat-lunara-status.php';
require_once $root . '/includes/class-aat-entity-graph-builder.php';

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$GLOBALS['wpdb'] = null;
$status = AAT_Lunara_Status::get_status('2.7.83');
$assert($status['available'] === false, 'Status should degrade when the WordPress database helper is absent.');
$assert($status['state'] === 'unavailable', 'Status should report unavailable without WordPress helpers.');
$assert($status['count'] === 0, 'Status should keep a bounded zero count without WordPress helpers.');
foreach ($status['destinations'] as $destination) {
    $assert($destination['available'] === false, 'Destinations should be restricted without capability helpers.');
    $assert($destination['url'] === '', 'Destinations should use an empty URL without the admin URL helper.');
}

$graph = AAT_Entity_Graph_Builder::get_lunara_health();
$assert($graph['available'] === false, 'Graph health should degrade without model helpers.');
$assert($graph['state'] === 'unavailable', 'Graph health should report unavailable without model helpers.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Lunara Oscars status degradation contract OK.\n";
