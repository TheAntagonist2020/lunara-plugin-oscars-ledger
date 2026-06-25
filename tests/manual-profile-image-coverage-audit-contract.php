<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-profile-image-coverage-audit.md',
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
$template = is_string($source['templates/person-portrait-import-admin.php']) ? $source['templates/person-portrait-import-admin.php'] : '';
$docs = (is_string($source['README.md']) ? $source['README.md'] : '') . "\n" . (is_string($source['readme.txt']) ? $source['readme.txt'] : '');
$spec = is_string($source['docs/staging/specs/2026-06-25-profile-image-coverage-audit.md']) ? $source['docs/staging/specs/2026-06-25-profile-image-coverage-audit.md'] : '';

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

$cli_method = $method_slice($plugin, 'public function handle_profile_image_batch_cli', 'private function profile_image_batch_cli_report');
$coverage_method = $method_slice($plugin, 'private function build_profile_image_coverage_audit', 'private function get_profile_image_coverage_id_set');
$id_set_method = $method_slice($plugin, 'private function get_profile_image_coverage_id_set', 'private function profile_image_batch_cli_report');

$assert($cli_method !== '', 'Profile image CLI method should be inspectable.');
$assert($coverage_method !== '', 'Coverage audit method should be inspectable.');
$assert($id_set_method !== '', 'Coverage ID helper should be inspectable.');

foreach (array(
    "'coverage'",
    "'results-csv'",
    "'batch'",
    "'sample'",
    'build_profile_image_coverage_audit',
) as $needle) {
    $assert(strpos($cli_method, $needle) !== false, "CLI should expose coverage mode and controls: {$needle}");
}

foreach (array(
    'approved_ids',
    'people_ids',
    'entity_ids',
    'imported_ids',
    'route_backed_approved',
    'approved_without_people',
    'approved_without_entity',
    'approved_in_people_without_entity',
    'imported_without_entity',
    'route_backed_imported',
    'people_table_available',
    'samples',
) as $needle) {
    $assert(strpos($coverage_method, $needle) !== false, "Coverage audit should report source/entity/media buckets: {$needle}");
}

foreach (array(
    'tmdb_profile_results.csv',
    'Status=OK',
    'approved portrait IDs absent from source people',
    'imported-media/no-route',
) as $needle) {
    $assert(strpos($spec . $template . $docs, $needle) !== false, "Spec/admin/docs should describe the coverage audit: {$needle}");
}

foreach (array(
    'media_handle_sideload',
    'wp_update_post',
    'update_post_meta',
    'delete_transient',
    'download_url',
    'api.themoviedb.org',
    'omdbapi.com',
    'cinemagoer',
    'Cinemagoer',
) as $forbidden) {
    $assert(strpos($coverage_method . $id_set_method, $forbidden) === false, "Coverage audit must be read-only and local-only: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Manual profile image coverage audit contract OK.\n";
