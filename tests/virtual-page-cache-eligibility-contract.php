<?php

$root = dirname(__DIR__);
$plugin_path = $root . '/academy-awards-table.php';
$entity_template_path = $root . '/templates/entity-page.php';
$hub_template_path = $root . '/templates/hub-page.php';

$plugin = file_get_contents($plugin_path);
$entity_template = file_get_contents($entity_template_path);
$hub_template = file_get_contents($hub_template_path);
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(is_string($plugin) && $plugin !== '', 'Plugin source should be readable.');
$assert(is_string($entity_template) && $entity_template !== '', 'Entity template should be readable.');
$assert(is_string($hub_template) && $hub_template !== '', 'Hub template should be readable.');

$method_start = strpos($plugin, 'public function fix_virtual_page_status()');
$method_end = strpos($plugin, 'public function maybe_entity_template(', $method_start);
$method = ($method_start !== false && $method_end !== false)
    ? substr($plugin, $method_start, $method_end - $method_start)
    : '';

$assert($method !== '', 'Virtual-page status method should be discoverable.');
$assert(strpos($method, 'is_entity_request()') !== false, 'Entity routes should still be recognized.');
$assert(strpos($method, 'is_hub_request()') !== false, 'Hub routes should still be recognized.');
$assert(strpos($method, '$wp_query->is_404 = false') !== false, 'Valid virtual routes should still clear the provisional 404 state.');
$assert(strpos($method, '$wp_query->is_page = true') !== false, 'Valid virtual routes should still identify as pages.');
$assert(strpos($method, 'status_header(200)') !== false, 'Valid virtual routes should still return HTTP 200.');
$assert(strpos($method, 'nocache_headers()') === false, 'Valid public virtual routes must remain eligible for WordPress.com edge caching.');

$assert(strpos($entity_template, 'status_header(404)') !== false, 'Missing entity pages should still return HTTP 404.');
$assert(strpos($entity_template, 'nocache_headers()') !== false, 'Missing entity pages should remain non-cacheable.');
$assert(strpos($hub_template, 'status_header(404)') !== false, 'Missing hub pages should still return HTTP 404.');
$assert(strpos($hub_template, 'nocache_headers()') !== false, 'Missing hub pages should remain non-cacheable.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscar virtual-page cache eligibility contract OK.\n";
