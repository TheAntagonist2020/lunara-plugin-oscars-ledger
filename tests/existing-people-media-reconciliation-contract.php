<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md',
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
$spec = is_string($source['docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md']) ? $source['docs/staging/specs/2026-06-25-existing-people-media-reconciliation.md'] : '';

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

$cli_method = $method_slice($plugin, 'public function handle_profile_image_batch_cli', 'private function normalize_profile_image_coverage_cli_args');
$audit_method = $method_slice($plugin, 'private function build_profile_image_existing_media_audit', 'private function get_profile_image_existing_media_attachment_ids');
$folder_method = $method_slice($plugin, 'private function find_profile_image_media_folder_attachment_ids', 'private function profile_image_existing_media_table_exists');
$row_method = $method_slice($plugin, 'private function build_profile_image_existing_media_audit_row', 'private function extract_imdb_name_id_from_profile_media_text');
$csv_method = $method_slice($plugin, 'private function write_profile_image_existing_media_audit_csv', 'private function profile_image_existing_media_cli_samples');

$assert($cli_method !== '', 'Profile image CLI method should be inspectable.');
$assert($audit_method !== '', 'Existing media audit method should be inspectable.');
$assert($folder_method !== '', 'Folder lookup method should be inspectable.');
$assert($row_method !== '', 'Audit row classifier should be inspectable.');
$assert($csv_method !== '', 'Audit CSV writer should be inspectable.');

foreach (array(
    'Version: 2.7.40',
    "define('AAT_VERSION', '2.7.40')",
    "'existing-media-audit'",
    "'folder'",
    "'all-media'",
    "'output-csv'",
    'build_profile_image_existing_media_audit',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose existing media audit controls: {$needle}");
}

foreach (array(
    'already_route_backed',
    'mapped_no_route',
    'reusable_nm_filename',
    'likely_name_match',
    'ambiguous_name_match',
    'needs_manual_review',
    'adoption_candidates',
    'duplicate_person_id_rows',
    'folder_strategy',
    'folder_found',
) as $needle) {
    $assert(strpos($audit_method . $row_method . $spec, $needle) !== false, "Existing media audit should report state bucket: {$needle}");
}

foreach (array(
    'taxonomy:',
    'fbv_attachment_folder',
    'realmedialibrary_posts',
    "p.post_type = 'attachment'",
    "p.post_mime_type LIKE 'image/%%'",
) as $needle) {
    $assert(strpos($folder_method, $needle) !== false, "Folder audit should support Media Library folder storage: {$needle}");
}

foreach (array(
    '_aat_person_imdb_id',
    '_aat_person_portrait_source',
    '_aat_person_portrait_verified',
    '_wp_attached_file',
    '_wp_attachment_image_alt',
    'nm\\d{7,9}',
    'name-exact',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Existing media audit should inspect local attachment identity safely: {$needle}");
}

foreach (array(
    'PEOPLE',
    'Existing media audit is read-only',
    'wp aat profile-images existing-media-audit',
    'manual-review rows',
) as $needle) {
    $assert(strpos($spec . $template . $docs, $needle) !== false, "Spec/admin/docs should explain the PEOPLE folder reconciliation workflow: {$needle}");
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
    $assert(strpos($audit_method . $folder_method . $row_method, $forbidden) === false, "Existing media audit must be read-only and local-only: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Existing PEOPLE media reconciliation contract OK.\n";
