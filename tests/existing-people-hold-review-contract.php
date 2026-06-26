<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-26-existing-people-hold-review.md',
    'docs/staging/plans/2026-06-26-existing-people-hold-review.md',
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
$spec = is_string($source['docs/staging/specs/2026-06-26-existing-people-hold-review.md']) ? $source['docs/staging/specs/2026-06-26-existing-people-hold-review.md'] : '';
$plan = is_string($source['docs/staging/plans/2026-06-26-existing-people-hold-review.md']) ? $source['docs/staging/plans/2026-06-26-existing-people-hold-review.md'] : '';

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
$review_helpers = $method_slice($plugin, 'private function get_person_portrait_existing_review_states', 'private function get_existing_person_portrait_adoption_rows');

foreach (array(
    'Version: 2.7.61',
    "define('AAT_VERSION', '2.7.61')",
    'get_person_portrait_existing_reviews_table_name',
    'maybe_create_person_portrait_existing_reviews_table',
    'aat_person_portrait_existing_reviews',
    'attachment_person',
    'get_person_portrait_existing_review_states',
    'get_person_portrait_existing_issue_types',
    'save_person_portrait_existing_review_record_from_request',
    'aat_existing_person_portrait_review',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose hold-review workflow marker: {$needle}");
}

$assert($render_method !== '', 'Render method should be inspectable.');
$assert($adoption_rows_method !== '', 'Existing portrait adoption rows method should be inspectable.');
$assert($adopt_method !== '', 'Existing portrait adoption method should be inspectable.');
$assert($review_helpers !== '', 'Existing portrait review helpers should be inspectable.');

foreach (array(
    'Approved To Adopt',
    'Wrong Person Or Label',
    'Not A Person',
    'Needs Better Source',
    'Reject / Ignore',
    'Missing Expected Name',
    'Expected Label Mismatch',
    'Expected Source Gap',
    'Suspicious Label',
    'Manual Note',
    'SHOW TABLES LIKE %s',
    '$wpdb->replace',
    'get_current_existing_person_portrait_candidate_row',
    'duplicate-specific visual review',
    'aat_existing_portrait_review_bad_state',
    'aat_existing_portrait_review_bad_issue_type',
) as $needle) {
    $assert(strpos($review_helpers, $needle) !== false, "Review helpers should define bounded private states and validation: {$needle}");
}

foreach (array(
    "check_admin_referer('aat_existing_person_portrait_review'",
    'save_person_portrait_existing_review_record_from_request',
    "'hold_review'",
    "'needs_review'",
    "'needs_source'",
    "'wrong_label'",
    "'approved'",
    'get_person_portrait_existing_review_states',
    'get_person_portrait_existing_issue_types',
) as $needle) {
    $assert(strpos($render_method, $needle) !== false, "Render method should handle the hold-review POST and filters: {$needle}");
}

foreach (array(
    'get_person_portrait_existing_review_records',
    'get_default_person_portrait_existing_review_record',
    'existing_review_counts',
    'existing_needs_source_total',
    'existing_wrong_label_total',
    'existing_hold_review_total',
    'existing_approved_total',
    'existing_review_is_approved',
    "array('all', 'hold_review', 'needs_review', 'needs_source', 'wrong_label', 'approved', 'ready', 'duplicates', 'duplicate_groups', 'manual')",
) as $needle) {
    $assert(strpos($adoption_rows_method, $needle) !== false, "Adoption rows should merge private review state: {$needle}");
}

foreach (array(
    'get_person_portrait_existing_review_records',
    'get_default_person_portrait_existing_review_record',
    'aat_existing_portrait_review_required',
    'Approve this existing PEOPLE portrait review before adoption.',
    'aat_existing_portrait_confirmation',
) as $needle) {
    $assert(strpos($adopt_method, $needle) !== false, "Adoption method should require approved review plus typed confirmation: {$needle}");
}

foreach (array(
    'Existing PEOPLE hold review',
    'Save hold review',
    'Approved To Adopt',
    'Adoption locked until this private review is saved as Approved To Adopt.',
    'aat_existing_person_portrait_review_nonce',
    'existing_review_attachment_id',
    'existing_review_person_id',
    'existing_review_state',
    'existing_review_issue_type',
    'existing_review_note',
    'Hold review',
    'Needs source',
    'Wrong labels',
    'Source needed: keep this held until an exact, externally verified portrait source is available.',
    'Approved to adopt',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Template should render the private hold-review lane: {$needle}");
}

foreach (array(
    '.aat-person-portrait-adoption-card.is-review-hold',
    '.aat-person-portrait-adoption-card.is-review-state-needs_better_source',
    '.aat-person-portrait-adoption-card.is-review-state-wrong_person_or_label',
    '.aat-person-portrait-adoption-card.is-approved',
    '.aat-person-portrait-existing-review-alert',
    '.aat-person-portrait-existing-review',
    '.aat-person-portrait-existing-review-form',
) as $needle) {
    $assert(strpos($admin_css, $needle) !== false, "Admin CSS should style hold-review cards: {$needle}");
}

foreach (array(
    'Existing PEOPLE Hold Review',
    'wp_aat_person_portrait_existing_reviews',
    'Approved To Adopt',
    'aat_existing_portrait_review_required',
    '2.7.61',
) as $needle) {
    $assert(strpos($docs . $spec . $plan, $needle) !== false, "Docs/spec/plan should describe hold-review workflow: {$needle}");
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
    $assert(strpos($review_helpers, $forbidden) === false, "Hold-review helpers must not import, fetch, or scrape media: {$forbidden}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Existing PEOPLE hold-review contract OK.\n";
