<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-profile-image-batch-importer.md',
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
$spec = is_string($source['docs/staging/specs/2026-06-25-profile-image-batch-importer.md']) ? $source['docs/staging/specs/2026-06-25-profile-image-batch-importer.md'] : '';

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

$cli_method = $method_slice($plugin, 'public function handle_profile_image_batch_cli', 'private function normalize_profile_image_batch_cli_args');
$normalize_method = $method_slice($plugin, 'private function normalize_profile_image_batch_cli_args', 'private function build_profile_image_batch_plan');
$plan_method = $method_slice($plugin, 'private function build_profile_image_batch_plan', 'private function read_profile_image_batch_results_csv');
$results_csv_method = $method_slice($plugin, 'private function read_profile_image_batch_results_csv', 'private function read_profile_image_batch_missing_csv');
$missing_csv_method = $method_slice($plugin, 'private function read_profile_image_batch_missing_csv', 'private function read_profile_image_batch_csv_rows');
$source_method = $method_slice($plugin, 'private function read_profile_image_batch_source_manifest', 'private function import_manual_person_profile_image');
$import_method = $method_slice($plugin, 'private function import_manual_person_profile_image', 'private function copy_profile_image_source_to_temp_file');
$existing_method = $method_slice($plugin, 'private function find_existing_person_portrait_attachment', 'public function get_poster_attachment_id_for_title');

foreach (array(
    'Version: 2.7.80',
    "define('AAT_VERSION', '2.7.80')",
    "WP_CLI::add_command('aat profile-images'",
    'handle_profile_image_batch_cli',
    'manual-batch-upload',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose manual profile batch importer marker: {$needle}");
}

$assert($cli_method !== '', 'Profile image batch CLI method should be inspectable.');
$assert($normalize_method !== '', 'Profile image batch normalization method should be inspectable.');
$assert($plan_method !== '', 'Profile image batch plan method should be inspectable.');
$assert($results_csv_method !== '', 'Results CSV parser should be inspectable.');
$assert($missing_csv_method !== '', 'Missing CSV parser should be inspectable.');
$assert($source_method !== '', 'Source manifest reader should be inspectable.');
$assert($import_method !== '', 'Manual import method should be inspectable.');
$assert($existing_method !== '', 'Existing attachment lookup should be inspectable.');

foreach (array(
    "'dry-run'",
    "'import'",
    "'source'",
    "'results-csv'",
    "'missing-csv'",
    "'limit'",
    "'offset'",
    "'batch'",
    'WP_CLI::success',
    'WP_CLI::error',
) as $needle) {
    $assert(strpos($cli_method . $normalize_method, $needle) !== false, "CLI should support safe batch controls: {$needle}");
}

foreach (array(
    'source_images',
    'csv_approved',
    'missing_ids',
    'source_in_approved',
    'source_in_missing',
    'source_unknown',
    'already_imported',
    'importable',
    'processed',
    'imported',
    'skipped',
    'errors',
) as $needle) {
    $assert(strpos($cli_method . $plan_method, $needle) !== false, "Importer should report deterministic batch counts: {$needle}");
}

foreach (array(
    'NomineeId',
    'ExpectedName',
    'ImageFile',
    'Status',
    'OK',
    'profiles_missing',
    'NO_PHOTO',
) as $needle) {
    $assert(strpos($results_csv_method . $missing_csv_method . $spec, $needle) !== false, "CSV contract should preserve source roster fields: {$needle}");
}

foreach (array(
    'is_dir',
    'ZipArchive',
    'oscars-profile-images',
    "'.jpg'",
    "'.jpeg'",
    'nm\\d{7,9}',
) as $needle) {
    $assert(strpos($source_method, $needle) !== false, "Source reader should support the supplied image package safely: {$needle}");
}

foreach (array(
    'media_handle_sideload',
    'wp_update_post',
    'update_post_meta',
    '_aat_person_imdb_id',
    '_aat_person_portrait_source',
    '_aat_person_portrait_verified',
    '_aat_person_portrait_batch',
    '_aat_person_portrait_original_file',
    'delete_transient',
    'copy_profile_image_source_to_temp_file',
) as $needle) {
    $assert(strpos($import_method, $needle) !== false, "Manual import should create verified local portrait metadata: {$needle}");
}

foreach (array(
    'download_url',
    'image.tmdb.org',
    'api.themoviedb.org',
    'omdbapi.com',
    'cinemagoer',
    'Cinemagoer',
) as $forbidden) {
    $assert(strpos($cli_method . $normalize_method . $plan_method . $source_method . $import_method, $forbidden) === false, "Manual batch importer must not fetch or scrape external sources: {$forbidden}");
}

foreach (array(
    'manual-batch-upload',
    'tmdb-person-profile',
    '_aat_person_imdb_id',
    '_aat_person_portrait_source',
) as $needle) {
    $assert(strpos($existing_method, $needle) !== false, "Public resolver lookup should recognize manual and legacy portrait metadata: {$needle}");
}

foreach (array(
    'Manual batch upload',
    'WP-CLI',
    'manual-batch-upload',
) as $needle) {
    $assert(strpos($template . $docs, $needle) !== false, "Admin/docs should explain the new private manual batch path: {$needle}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Manual profile image batch import contract OK.\n";
