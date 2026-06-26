<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md',
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
$spec = is_string($source['docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md']) ? $source['docs/staging/specs/2026-06-25-existing-portrait-duplicate-group-review.md'] : '';

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

foreach (array(
    'Version: 2.7.72',
    "define('AAT_VERSION', '2.7.72')",
    'duplicate_groups',
    'duplicate_group_review_total',
    'duplicate_group_review',
    'duplicate_group_person_id',
    'duplicate_group_candidates',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose grouped duplicate-review marker: {$needle}");
}

foreach (array(
    "'duplicate_groups'",
    'duplicate_group_review_total',
    'duplicate_group_review',
    'duplicate_group_candidates',
    'count($duplicate_group_candidates) < 2',
    'array_slice($duplicate_group_review_rows',
) as $needle) {
    $assert(strpos($adoption_rows_method, $needle) !== false, "Adoption rows should build paged duplicate-group review rows: {$needle}");
}

foreach (array(
    'Duplicate groups',
    'Duplicate group review',
    'Choose from this duplicate group',
    'aat-person-portrait-duplicate-group-card',
    'aat-person-portrait-duplicate-group-candidates',
    'aat-person-portrait-duplicate-group-option',
    'duplicate_confirm_person_id',
    'Resolve duplicate with this attachment',
    'typed-confirmation duplicate resolver',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should render grouped duplicate review UI: {$needle}");
}

foreach (array(
    '.aat-person-portrait-duplicate-group-card',
    '.aat-person-portrait-duplicate-group-candidates',
    '.aat-person-portrait-duplicate-group-option',
    '.aat-person-portrait-duplicate-group-resolver',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style grouped duplicate review UI: {$needle}");
}

foreach (array(
    '$allow_duplicate',
    '$confirm_person_id',
    'aat_existing_portrait_duplicate_confirmation',
    'aat_existing_portrait_duplicate_group_mismatch',
) as $needle) {
    $assert(strpos($adopt_method, $needle) !== false, "Duplicate resolver safety must remain present: {$needle}");
}

foreach (array(
    'Duplicate groups',
    'duplicate-group review',
    'typed-confirmation',
    '2.7.72',
) as $needle) {
    $assert(strpos($docs . $spec, $needle) !== false, "Docs/spec should describe grouped duplicate review: {$needle}");
}

foreach (array(
    'bulk duplicate',
    'bulk_duplicate',
    'duplicate_bulk',
    'adopt all duplicates',
) as $forbidden) {
    $assert(stripos($adoption_rows_method . "\n" . $adopt_method . "\n" . $template, $forbidden) === false, "Grouped duplicate review must not add unsafe behavior: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Existing PEOPLE duplicate group review contract OK.\n";
