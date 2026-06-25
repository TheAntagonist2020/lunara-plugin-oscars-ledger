<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/entity-page.php',
    'templates/poster-admin.php',
    'tools/person-portrait-batch-audit.php',
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
    $source[$relative_path] = is_file($path) ? file_get_contents($path) : false;
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$plugin = is_string($source['academy-awards-table.php']) ? $source['academy-awards-table.php'] : '';
$entity_template = is_string($source['templates/entity-page.php']) ? $source['templates/entity-page.php'] : '';
$poster_admin = is_string($source['templates/poster-admin.php']) ? $source['templates/poster-admin.php'] : '';
$batch_script = is_string($source['tools/person-portrait-batch-audit.php']) ? $source['tools/person-portrait-batch-audit.php'] : '';

$method_slice = function ($haystack, $start, $end) {
    $start_pos = strpos($haystack, $start);
    if ($start_pos === false) {
        return '';
    }
    $end_pos = strpos($haystack, $end, $start_pos + strlen($start));
    if ($end_pos === false) {
        return substr($haystack, $start_pos);
    }
    return substr($haystack, $start_pos, $end_pos - $start_pos);
};

$person_visual_method = $method_slice($plugin, 'public function get_person_visual_package', 'private function resolve_profile_attachment_for_person');
$person_audit_method = $method_slice($plugin, 'private function get_person_profile_attachment_audit', 'public function get_poster_attachment_id_for_title');

$assert($person_visual_method !== '', 'Person visual package method should be inspectable.');
$assert($person_audit_method !== '', 'Person profile audit method should be inspectable.');

foreach (array(
    "'visual_source'] = 'tmdb-contextual-title'",
    "'visual_state'] = 'contextual-fallback'",
    'aat-person-portrait-fallback"' . "' . " . '$backdrop_style',
) as $forbidden) {
    $assert(strpos($person_visual_method, $forbidden) === false, "Person visual package should not promote contextual title art as a portrait: {$forbidden}");
}

foreach (array(
    "'visual_source'] = 'tmdb-contextual-title'",
    "'visual_state'] = 'contextual-fallback'",
    "__('Contextual fallback'",
) as $forbidden) {
    $assert(strpos($person_audit_method, $forbidden) === false, "Person audit should not treat title backdrops as person portraits: {$forbidden}");
}

foreach (array(
    '$aat_person_visual_state',
    'no-portrait',
    'is-person-fallback',
) as $needle) {
    $assert(strpos($entity_template, $needle) !== false, "Entity template should still expose honest no-portrait hooks: {$needle}");
}

foreach (array(
    'No portrait',
    'No portrait match',
    'visual_state',
    'visual_source',
) as $needle) {
    $assert(strpos($poster_admin, $needle) !== false, "Poster admin should keep private portrait audit signal: {$needle}");
}

foreach (array(
    'person-portrait-batch-audit',
    '--batch-size',
    '--offset',
    '--state',
    '$wpdb->prefix . \'aat_entities\'',
    'DRY RUN',
    'needs_attention',
    'profile_url',
) as $needle) {
    $assert(strpos($batch_script, $needle) !== false, "Batch audit script should expose safe batch workflow piece: {$needle}");
}

foreach (array(
    'media_handle_sideload',
    'wp_insert_attachment',
    'update_post_meta',
    'delete_post_meta',
) as $forbidden_mutation) {
    $assert(strpos($batch_script, $forbidden_mutation) === false, "Batch audit script should not mutate media or post meta by default: {$forbidden_mutation}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Person nominee portrait batch contract OK.\n";
