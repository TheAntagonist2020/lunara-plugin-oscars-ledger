<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/image-integrity-admin.php',
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
$template = is_string($source['templates/image-integrity-admin.php']) ? $source['templates/image-integrity-admin.php'] : '';
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

$render_method = $method_slice($plugin, 'public function render_image_integrity_admin_page', 'public function render_omdb_audit_admin_page');
$builder_method = $method_slice($plugin, 'private function build_image_integrity_console_data', 'private function build_image_integrity_poster_rows');
$poster_method = $method_slice($plugin, 'private function build_image_integrity_poster_rows', 'private function build_image_integrity_portrait_rows');
$portrait_method = $method_slice($plugin, 'private function build_image_integrity_portrait_rows', 'private function get_image_integrity_row_sort_weight');

foreach (array(
    "Version: 2.7.89",
    "define('AAT_VERSION', '2.7.89')",
    "'academy-awards-image-integrity'",
    'render_image_integrity_admin_page',
    'templates/image-integrity-admin.php',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should include Image Integrity marker: {$needle}");
}

$assert($render_method !== '', 'Image Integrity render method should be inspectable.');
$assert($builder_method !== '', 'Image Integrity data builder should be inspectable.');
$assert($poster_method !== '', 'Poster integrity builder should be inspectable.');
$assert($portrait_method !== '', 'Portrait integrity builder should be inspectable.');

foreach (array(
    "current_user_can('manage_options')",
    'sanitize_image_integrity_bucket',
    'sanitize_image_integrity_section',
    'sanitize_image_integrity_focus',
    'integrity_focus',
    'include AAT_PLUGIN_DIR . \'templates/image-integrity-admin.php\'',
) as $needle) {
    $assert(strpos($render_method . $builder_method, $needle) !== false, "Admin surface should be private and normalized: {$needle}");
}

foreach (array(
    'get_image_integrity_triage_focus_labels',
    'fix_first',
    'Fix First',
    'review_pack_rows',
    'review_pack_limit',
    'priority_label',
    'impact_label',
    'triage_reason',
    'get_image_integrity_entity_impact',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Image Integrity should expose private triage priority context: {$needle}");
}

foreach (array(
    "'needs_review' => __('Needs Review'",
    "'ready' => __('Ready'",
    "'missing' => __('Missing'",
    "'wrong_match' => __('Wrong Match'",
    "'accepted' => __('Accepted'",
    "'resolved' => __('Resolved'",
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Normalized Image Integrity bucket should exist: {$needle}");
}

foreach (array(
    'aat_omdb_poster_reviews',
    'aat_posters',
    'get_omdb_poster_review_states',
    'get_image_integrity_bucket_for_poster_state',
    'poster_state',
    'wrong_match',
    'resolved',
) as $needle) {
    $assert(strpos($poster_method . $plugin, $needle) !== false, "Poster integrity should reuse poster metadata: {$needle}");
}

foreach (array(
    'get_person_profile_attachment_audit',
    'get_existing_person_portrait_adoption_rows',
    'get_image_integrity_bucket_for_portrait_state',
    'get_image_integrity_bucket_for_person_visual_state',
    'existing_review_state',
    'manual_review',
) as $needle) {
    $assert(strpos($portrait_method . $plugin, $needle) !== false, "Portrait integrity should reuse portrait metadata: {$needle}");
}

foreach (array(
    'aat-image-integrity-admin',
    'aat-image-integrity-review-pack',
    'Fix First Review Pack',
    'Top 25',
    'aat-image-integrity-triage-rail',
    'aat-image-integrity-triage-card',
    'aat-image-integrity-priority',
    'aat-image-integrity-bucket-card',
    'aat-image-integrity-row',
    'Fix First',
    'Poster Library',
    'Person Portrait Queue',
    'No private review metadata renders on public routes.',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Template should render the private integrity console: {$needle}");
}

foreach (array(
    '.aat-image-integrity-admin',
    '.aat-image-integrity-review-pack',
    '.aat-image-integrity-review-pack-grid',
    '.aat-image-integrity-review-pack-card',
    '.aat-image-integrity-triage-rail',
    '.aat-image-integrity-triage-card',
    '.aat-image-integrity-priority',
    '.aat-image-integrity-bucket-grid',
    '.aat-image-integrity-thumb',
    '.aat-image-integrity-state',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style Image Integrity console: {$needle}");
}

foreach (array(
    'Image Integrity Console',
    '2.7.89',
    'Fix First',
    'Fix First Review Pack',
    'Academy Awards > Image Integrity',
) as $needle) {
    $assert(strpos($readme, $needle) !== false, "Docs should describe the Image Integrity Console: {$needle}");
}

$public_files = array('templates/entity-page.php', 'templates/hub-page.php', 'assets/css/academy-awards-table.css');
foreach ($public_files as $relative_path) {
    $path = $root . '/' . $relative_path;
    $public_source = is_file($path) ? file_get_contents($path) : '';
    $assert(strpos((string) $public_source, 'aat-image-integrity') === false, "{$relative_path} should not expose private image-integrity hooks publicly.");
    $assert(strpos((string) $public_source, 'image_integrity') === false, "{$relative_path} should not expose private image-integrity state publicly.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Image Integrity Console contract OK.\n";
