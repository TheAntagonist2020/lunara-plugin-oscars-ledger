<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/entity-page.php',
    'templates/poster-admin.php',
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
$entity_template = $source['templates/entity-page.php'];
$poster_admin = $source['templates/poster-admin.php'];
$css = $source['assets/css/academy-awards-table.css'];

foreach (array(
    "'visual_source' => 'none'",
    "'visual_state' => 'no-portrait'",
    "'portrait_attachment_id' => 0",
    "'portrait_match_strategy' => ''",
    "'portrait_verified' => false",
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Person visual package should expose {$needle}.");
}

foreach (array(
    "['visual_source']",
    "['visual_state']",
    'local-media-library',
    'local-portrait',
    'tmdb-person-profile',
    'tmdb-portrait',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Person visual package should use {$needle}.");
}

$person_visual_start = strpos($plugin, 'public function get_person_visual_package');
$person_visual_end = strpos($plugin, 'private function resolve_profile_attachment_for_person', (int) $person_visual_start);
$person_visual_method = ($person_visual_start !== false && $person_visual_end !== false)
    ? substr($plugin, $person_visual_start, $person_visual_end - $person_visual_start)
    : '';
$assert($person_visual_method !== '', 'Person visual package method should be inspectable.');
foreach (array(
    'tmdb-contextual-title',
    'contextual-fallback',
) as $forbidden) {
    $assert(strpos($person_visual_method, $forbidden) === false, "Person visual package should not use title-context imagery as a portrait state: {$forbidden}.");
}

foreach (array(
    '$aat_person_visual_state',
    '$aat_person_visual_source',
    'has-person-visual-state-',
    'has-person-visual-source-',
    'is-person-fallback',
) as $needle) {
    $assert(strpos($entity_template, $needle) !== false, "Entity template should render person visual state hook {$needle}.");
}

foreach (array(
    '.aat-entity-poster-wrap.is-person-fallback',
    '.aat-entity-hero.has-person-visual-state-no-portrait',
) as $needle) {
    $assert(strpos($css, $needle) !== false, "CSS should style person visual state {$needle}.");
}

foreach (array(
    "'visual_state' =>",
    "'visual_source' =>",
    "'portrait_verified' =>",
    "'portrait_state_label' =>",
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Person portrait audit should expose {$needle}.");
}

foreach (array(
    'Visual State',
    'Source',
    'portrait_state_label',
    'visual_source',
) as $needle) {
    $assert(strpos($poster_admin, $needle) !== false, "Poster admin audit should render {$needle}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Person profile visual integrity contract OK.\n";
