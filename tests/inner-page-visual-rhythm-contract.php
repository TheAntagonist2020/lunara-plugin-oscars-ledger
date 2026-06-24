<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/hub-page.php',
    'assets/css/academy-awards-table.css',
);

$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$source = array();
foreach ($files as $relative_path) {
    $path = $root . '/' . $relative_path;
    $source[$relative_path] = file_get_contents($path);
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$plugin = $source['academy-awards-table.php'];
$hub_template = $source['templates/hub-page.php'];
$css = $source['assets/css/academy-awards-table.css'];

$assert(strpos($plugin, 'public function get_name_entity_link_by_label($label)') !== false, 'Plugin should expose a label-to-name-entity resolver.');
$assert(strpos($hub_template, '$aat_build_person_link_items') !== false, 'Hub template should build linked person chip items.');
$assert(strpos($hub_template, 'aat-inner-route-system') !== false, 'Category shell should expose the shared inner route system hook.');
$assert(strpos($hub_template, 'aat-generic-category-dossier') !== false, 'Non-premium categories should have a dossier-grade hook.');
$assert(strpos($hub_template, 'aat-category-person-strip') !== false, 'Category rows should render a linked person/craft strip.');
$assert(strpos($hub_template, 'aat-category-ceremony-row aat-ledger-card') !== false, 'All category ceremony rows should use ledger-card rhythm.');
$assert(substr_count($hub_template, 'aat-dossier-command-band') >= 2, 'Premium and generic category headers should both render command bands.');

foreach (array(
    '.aat-generic-category-dossier',
    '.aat-category-person-strip',
    '.aat-category-person-chip',
    '.aat-inner-route-system',
    '@media (max-width: 700px)',
) as $needle) {
    $assert(strpos($css, $needle) !== false, "CSS should define {$needle}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscars inner page visual rhythm contract OK.\n";
