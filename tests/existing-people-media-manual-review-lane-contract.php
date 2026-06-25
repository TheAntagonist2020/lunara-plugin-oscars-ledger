<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md',
    'docs/staging/plans/2026-06-25-existing-portrait-manual-review-lane.md',
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
$spec = is_string($source['docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md']) ? $source['docs/staging/specs/2026-06-25-existing-portrait-manual-review-lane.md'] : '';
$plan = is_string($source['docs/staging/plans/2026-06-25-existing-portrait-manual-review-lane.md']) ? $source['docs/staging/plans/2026-06-25-existing-portrait-manual-review-lane.md'] : '';

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

foreach (array(
    'Version: 2.7.44',
    "define('AAT_VERSION', '2.7.44')",
    "'manual'",
    'manual_review_total',
    'manual_review',
    'needs_manual_review',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose manual-review lane marker: {$needle}");
}

$assert($render_method !== '', 'Render method should be inspectable.');
$assert($adoption_rows_method !== '', 'Existing portrait adoption rows method should be inspectable.');

foreach (array(
    "'manual'",
    '$allowed_adoption_views',
    'sanitize_key',
) as $needle) {
    $assert(strpos($render_method, $needle) !== false, "Render method should allow a sanitized manual adoption view: {$needle}");
}

foreach (array(
    "'manual'",
    'needs_manual_review',
    'manual_review_total',
    'manual_review',
    'match_strategy',
    'detected_person_id',
    'explicit_person_id',
    'wp_get_attachment_image_url',
) as $needle) {
    $assert(strpos($adoption_rows_method, $needle) !== false, "Rows method should return read-only manual-review context: {$needle}");
}

foreach (array(
    'Manual review',
    'Manual review needed',
    'Read-only manual review',
    'No safe IMDb person ID was detected',
    'Manual review rows',
    'Media image',
    'Match strategy',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should render manual-review read-only UI: {$needle}");
}

foreach (array(
    '.aat-person-portrait-adoption-card.is-manual',
    '.aat-person-portrait-manual-review',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style manual-review cards: {$needle}");
}

foreach (array(
    'Manual review lane',
    'read-only',
    'needs_manual_review',
    '2.7.44',
) as $needle) {
    $assert(strpos($docs . $spec . $plan, $needle) !== false, "Docs/spec/plan should describe manual-review lane: {$needle}");
}

$manual_branch_pos = strpos($template, '$is_manual');
if ($manual_branch_pos !== false) {
    $manual_branch = substr($template, $manual_branch_pos, 2600);
    foreach (array(
        'aat_existing_person_portrait_adopt_nonce',
        'aat_existing_person_portrait_duplicate_resolve_nonce',
        'Resolve duplicate with this attachment',
        'Adopt existing portrait',
    ) as $forbidden) {
        $assert(strpos($manual_branch, $forbidden) === false, "Manual-review branch must not render mutation controls: {$forbidden}");
    }
} else {
    $assert(false, 'Template should have an explicit $is_manual branch.');
}

foreach (array(
    'media_handle_sideload',
    'download_url',
    'wp_update_post',
    'api.themoviedb.org',
    'omdbapi.com',
) as $forbidden) {
    $assert(strpos($adoption_rows_method, $forbidden) === false, "Manual-review row builder must not import, fetch, or mutate media: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Existing PEOPLE manual-review lane contract OK.\n";
