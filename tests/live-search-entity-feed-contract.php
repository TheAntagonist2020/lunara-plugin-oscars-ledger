<?php

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(is_string($plugin) && $plugin !== '', 'Plugin source should be readable.');

$helper = '';
if (preg_match("/if \\( ! function_exists\\( 'aat_search_entities' \\) \\) \\{(?P<body>.*?)\\n\\}/s", $plugin, $match)) {
    $helper = $match['body'];
}

$assert($helper !== '', 'Plugin should expose the guarded aat_search_entities() integration helper.');
$assert(strpos($helper, 'function aat_search_entities( $q, $limit = 6 )') !== false, 'Entity search helper should keep the public two-argument signature.');
$assert(strpos($helper, 'mb_strlen( $q ) < 2') !== false, 'Entity search helper should reject undersized queries.');
$assert(strpos($helper, 'min( 12, (int) $limit )') !== false, 'Entity search helper should cap result counts at 12.');
$assert(strpos($helper, "\$wpdb->prefix . 'aat_entity_stats'") !== false, 'Entity search helper should use the derived entity stats table.');
$assert(strpos($helper, '$wpdb->esc_like( $q )') !== false, 'Entity search helper should escape LIKE input.');
$assert(strpos($helper, '$wpdb->prepare(') !== false, 'Entity search helper should prepare its query.');
$assert(strpos($helper, 'LIMIT %d') !== false, 'Entity search helper should bind the bounded limit.');
$assert(strpos($helper, "Academy_Awards_Table::get_instance()") !== false, 'Entity search helper should delegate route generation to the plugin singleton.');
$assert(strpos($helper, "get_entity_url( \$row['entity_id'] )") !== false, 'Entity search helper should use the canonical entity URL API.');

foreach (array('label', 'type', 'nominations', 'wins', 'url') as $field) {
    $assert(strpos($helper, "'{$field}'") !== false, "Entity search results should expose {$field}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Live-search entity feed contract OK.\n";
