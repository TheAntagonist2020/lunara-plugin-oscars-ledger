<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md',
    'docs/staging/specs/2026-06-25-existing-portrait-duplicate-review.md',
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
$docs = (is_string($source['README.md']) ? $source['README.md'] : '') . "\n" . (is_string($source['readme.txt']) ? $source['readme.txt'] : '');
$spec = (is_string($source['docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md']) ? $source['docs/staging/specs/2026-06-25-existing-portrait-adoption-queue.md'] : '') . "\n" . (is_string($source['docs/staging/specs/2026-06-25-existing-portrait-duplicate-review.md']) ? $source['docs/staging/specs/2026-06-25-existing-portrait-duplicate-review.md'] : '');

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
$adoption_rows_method = $method_slice($plugin, 'private function get_existing_person_portrait_adoption_rows', 'private function adopt_existing_person_portrait_attachment');
$adopt_method = $method_slice($plugin, 'private function adopt_existing_person_portrait_attachment', 'private function get_person_portrait_import_queue_rows');
$existing_lookup_method = $method_slice($plugin, 'private function find_existing_person_portrait_attachment', 'public function get_poster_attachment_id_for_title');

foreach (array(
    'Version: 2.7.45',
    "define('AAT_VERSION', '2.7.45')",
    'get_existing_person_portrait_adoption_rows',
    'adopt_existing_person_portrait_attachment',
    'existing-media-adoption',
    'aat_existing_person_portrait_adopt',
    'aat_existing_person_portrait_duplicate_resolve',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose existing portrait adoption marker: {$needle}");
}

$assert($render_method !== '', 'Render method should be inspectable.');
$assert($adoption_rows_method !== '', 'Existing portrait adoption rows method should be inspectable.');
$assert($adopt_method !== '', 'Existing portrait adoption method should be inspectable.');
$assert($existing_lookup_method !== '', 'Existing portrait lookup method should be inspectable.');

foreach (array(
    "current_user_can('manage_options')",
    "check_admin_referer('aat_existing_person_portrait_adopt'",
    "check_admin_referer('aat_existing_person_portrait_duplicate_resolve'",
    'sanitize_text_field',
    'sanitize_key',
    'absint',
    'sanitize_textarea_field',
    'duplicate_confirm_person_id',
    "'allow_duplicate' => true",
) as $needle) {
    $assert(strpos($render_method, $needle) !== false, "Render method should protect and sanitize adoption POST: {$needle}");
}

foreach (array(
    'build_profile_image_existing_media_audit',
    "'folder' => 'PEOPLE'",
    "'state' => 'reusable_nm_filename'",
    'duplicate_person_id',
    'duplicate_group',
    'adoption_view',
    'ready_adoption_total',
    'duplicate_adoption_total',
    'duplicate_person_total',
    'adoption_candidate',
    'wp_get_attachment_image_url',
    'build_entity_url_from_id',
) as $needle) {
    $assert(strpos($adoption_rows_method, $needle) !== false, "Adoption rows should reuse the existing PEOPLE audit safely: {$needle}");
}

foreach (array(
    'is_imdb_name_entity_id',
    'get_entity_display_name',
    "get_post_type(\$attachment_id) !== 'attachment'",
    'wp_attachment_is_image',
    'build_profile_image_existing_media_audit_row',
    'duplicate_person_id',
    '$allow_duplicate',
    '$confirm_person_id',
    'aat_existing_portrait_duplicate_candidate',
    'aat_existing_portrait_duplicate_confirmation',
    'aat_existing_portrait_duplicate_group_mismatch',
    'Duplicate resolver confirmed',
    'adoption_candidate',
    "update_post_meta(\$attachment_id, '_aat_person_imdb_id'",
    "update_post_meta(\$attachment_id, '_aat_person_portrait_source', 'existing-media-adoption')",
    "update_post_meta(\$attachment_id, '_aat_person_portrait_verified', '1')",
    '_aat_person_portrait_adopted_at',
    '_aat_person_portrait_adopted_by',
    '_aat_person_portrait_adoption_note',
    "delete_transient('aat_person_profile_attachment_v2_' . \$person_id)",
) as $needle) {
    $assert(strpos($adopt_method, $needle) !== false, "Adoption method should validate and write only reviewed portrait metadata: {$needle}");
}

foreach (array(
    'manual-batch-upload',
    'tmdb-person-profile',
    'existing-media-adoption',
) as $needle) {
    $assert(strpos($existing_lookup_method, $needle) !== false, "Public resolver lookup should recognize source: {$needle}");
}

foreach (array(
    'Existing PEOPLE adoption',
    'Adopt existing portrait',
    'aat_existing_person_portrait_adopt_nonce',
    'Duplicate candidate',
    'Duplicate review',
    'Competing PEOPLE images',
    'Manual review required',
    'aat_existing_person_portrait_duplicate_resolve_nonce',
    'duplicate_confirm_person_id',
    'Resolve duplicate with this attachment',
    'typed-confirmation duplicate resolver',
    'existing-media-adoption',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should render guarded existing-media adoption UI: {$needle}");
}

foreach (array(
    '.aat-person-portrait-adoption-grid',
    '.aat-person-portrait-adoption-card',
    '.aat-person-portrait-adoption-card img',
    '.aat-person-portrait-duplicate-review',
    '.aat-person-portrait-duplicate-option',
    '.aat-person-portrait-duplicate-resolver',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style existing portrait adoption lane: {$needle}");
}

foreach (array(
    'Existing PEOPLE adoption',
    'duplicate-review',
    'typed-confirmation resolver',
    'existing-media-adoption',
    '2.7.45',
) as $needle) {
    $assert(strpos($docs . $spec, $needle) !== false, "Docs/spec should describe the existing PEOPLE adoption workflow: {$needle}");
}

foreach (array(
    'media_handle_sideload',
    'download_url',
    'wp_update_post',
    'api.themoviedb.org',
    'omdbapi.com',
    'cinemagoer',
    'Cinemagoer',
) as $forbidden) {
    $assert(strpos($adopt_method, $forbidden) === false, "Existing portrait adoption must not import, fetch, or scrape media: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Existing PEOPLE media adoption queue contract OK.\n";
