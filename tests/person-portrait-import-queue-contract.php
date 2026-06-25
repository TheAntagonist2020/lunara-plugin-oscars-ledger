<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
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
$admin_css = is_string($source['assets/css/admin.css']) ? $source['assets/css/admin.css'] : '';
$readme = (is_string($source['README.md']) ? $source['README.md'] : '') . "\n" . (is_string($source['readme.txt']) ? $source['readme.txt'] : '');

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

$render_method = $method_slice($plugin, 'public function render_person_portrait_import_admin_page', 'private function get_person_portrait_import_queue_rows');
$queue_method = $method_slice($plugin, 'private function get_person_portrait_import_queue_rows', 'private function build_person_portrait_import_queue_row');
$row_method = $method_slice($plugin, 'private function build_person_portrait_import_queue_row', 'private function import_tmdb_person_profile_portrait');
$import_method = $method_slice($plugin, 'private function import_tmdb_person_profile_portrait', 'private function find_existing_person_portrait_attachment');
$existing_method = $method_slice($plugin, 'private function find_existing_person_portrait_attachment', 'public function get_poster_attachment_id_for_title');

foreach (array(
    "Version: 2.7.42",
    "define('AAT_VERSION', '2.7.42')",
    "'academy-awards-person-portraits'",
    'render_person_portrait_import_admin_page',
    'person-portrait-import-admin.php',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should include portrait import queue marker: {$needle}");
}

$assert($render_method !== '', 'Render method should be inspectable.');
$assert($queue_method !== '', 'Queue method should be inspectable.');
$assert($row_method !== '', 'Queue row method should be inspectable.');
$assert($import_method !== '', 'Import method should be inspectable.');
$assert($existing_method !== '', 'Existing attachment method should be inspectable.');

foreach (array(
    "current_user_can('manage_options')",
    "check_admin_referer('aat_person_portrait_import'",
    "sanitize_key",
    "sanitize_textarea_field",
    'include AAT_PLUGIN_DIR . \'templates/person-portrait-import-admin.php\'',
) as $needle) {
    $assert(strpos($render_method, $needle) !== false, "Render method should protect/admin-normalize queue: {$needle}");
}

foreach (array(
    'candidate_external',
    'needs_attention',
    'ready',
    'get_tmdb_person_data_for_imdb_id',
    'resolve_profile_attachment_for_person',
    'profile_full',
    'profile_path',
) as $needle) {
    $assert(strpos($queue_method . $row_method, $needle) !== false, "Queue should expose portrait states and TMDb profile-only candidates: {$needle}");
}

foreach (array(
    'profile_path',
    'profile_full',
    'download_url',
    'media_handle_sideload',
    'wp_update_post',
    'update_post_meta',
    '_aat_person_imdb_id',
    '_aat_person_portrait_source',
    '_aat_tmdb_profile_path',
    '_aat_person_portrait_verified',
    'delete_transient',
) as $needle) {
    $assert(strpos($import_method, $needle) !== false, "Import method should use verified person-profile media metadata: {$needle}");
}

foreach (array(
    'backdrop_full',
    'backdrop_path',
    'poster_path',
    'poster_full',
) as $forbidden) {
    $assert(strpos($import_method, $forbidden) === false, "Import method must not import contextual/title art: {$forbidden}");
}

foreach (array(
    '_aat_person_imdb_id',
    '_aat_tmdb_profile_path',
    '_aat_person_portrait_source',
    'tmdb-person-profile',
) as $needle) {
    $assert(strpos($existing_method, $needle) !== false, "Duplicate lookup should use plugin-owned person portrait metadata: {$needle}");
}

foreach (array(
    'aat-person-portrait-import',
    'aat_person_portrait_import_nonce',
    'candidate_external',
    'Import verified portrait',
    'No profile import available',
    'TMDb profile image',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should render guarded portrait queue UI: {$needle}");
}

foreach (array(
    '.aat-person-portrait-import',
    '.aat-person-portrait-thumb',
    '.aat-person-portrait-state',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style portrait import queue: {$needle}");
}

foreach (array(
    'Person Portrait Import Queue',
    'one verified TMDb person profile image at a time',
    '2.7.42',
) as $needle) {
    $assert(strpos($readme, $needle) !== false, "Docs should describe the portrait import queue: {$needle}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Person portrait import queue contract OK.\n";
