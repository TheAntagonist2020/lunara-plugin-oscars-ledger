<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md',
    'docs/staging/plans/2026-06-26-person-credit-full-row-resolver.md',
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
$css = is_string($source['assets/css/admin.css']) ? $source['assets/css/admin.css'] : '';
$docs = implode("\n", array_filter(array(
    is_string($source['README.md']) ? $source['README.md'] : '',
    is_string($source['readme.txt']) ? $source['readme.txt'] : '',
    is_string($source['docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md']) ? $source['docs/staging/specs/2026-06-26-person-credit-full-row-resolver.md'] : '',
    is_string($source['docs/staging/plans/2026-06-26-person-credit-full-row-resolver.md']) ? $source['docs/staging/plans/2026-06-26-person-credit-full-row-resolver.md'] : '',
)));

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

$schema = $method_slice($plugin, 'private function maybe_create_person_credit_row_reviews_table', 'private function maybe_create_omdb_reviews_table');
$states = $method_slice($plugin, 'private function get_person_credit_row_review_states', 'private function get_person_credit_review_key');
$saver = $method_slice($plugin, 'private function replace_person_credit_row_review_record', 'private function get_person_credit_source_award_row');
$fetcher = $method_slice($plugin, 'private function get_person_credit_row_review_records_for_source_ids', 'private function normalize_person_credit_label_for_compare');
$preview = $method_slice($plugin, 'private function build_person_credit_full_row_review_preview', 'private function build_person_credit_source_correction_preview');
$apply = $method_slice($plugin, 'private function apply_person_credit_full_row_source_correction_from_request', 'private function get_person_credit_review_records_for_keys');
$queue = $method_slice($plugin, 'private function get_person_credit_review_queue_rows', 'private function normalize_profile_image_coverage_cli_args');
$admin = $method_slice($plugin, 'public function render_person_portrait_import_admin_page', 'public function render_omdb_audit_admin_page');

foreach (array(
    'Version: 2.7.62',
    "define('AAT_VERSION', '2.7.62')",
    'Stable tag: 2.7.62',
    'Current baseline: `2.7.62`',
    'full-row person-credit resolver',
) as $needle) {
    $assert(stripos($plugin . $docs, $needle) !== false, "Version/docs marker should exist: {$needle}");
}

$assert($schema !== '', 'Full-row review table schema should be inspectable.');
$assert($states !== '', 'Full-row review states should be inspectable.');
$assert($saver !== '', 'Full-row review saver should be inspectable.');
$assert($fetcher !== '', 'Full-row review fetcher should be inspectable.');
$assert($preview !== '', 'Full-row preview should be inspectable.');
$assert($apply !== '', 'Full-row apply action should be inspectable.');
$assert($queue !== '', 'Person-credit queue should be inspectable.');
$assert($admin !== '', 'Person Portrait Queue admin handler should be inspectable.');

foreach (array(
    'get_person_credit_row_reviews_table_name',
    'maybe_create_person_credit_row_reviews_table',
    'aat_person_credit_row_reviews',
    'source_award_id mediumint(9) unsigned NOT NULL',
    'category_slug varchar(191) NOT NULL DEFAULT',
    'credit_labels text',
    'review_state varchar(32) NOT NULL DEFAULT',
    'proposed_nominee_ids text',
    'correction_note text',
    'PRIMARY KEY  (source_award_id)',
    'KEY review_state (review_state)',
) as $needle) {
    $assert(strpos($plugin . $schema, $needle) !== false, "Schema should persist private full-row review metadata: {$needle}");
}

foreach (array(
    'maybe_create_person_credit_row_reviews_table($charset_collate)',
    '$this->maybe_create_person_credit_row_reviews_table();',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Schema upgrade path should create full-row review table: {$needle}");
}

foreach (array(
    'needs_review',
    'ready_to_apply',
    'source_gap',
    'applied',
    'ignore_accept',
) as $needle) {
    $assert(strpos($states, $needle) !== false, "Full-row review states should include {$needle}.");
}

foreach (array(
    'aat_person_credit_row_source_award_id',
    'aat_person_credit_row_review_state',
    'aat_person_credit_row_proposed_nominee_ids',
    'aat_person_credit_row_review_note',
    'get_person_credit_source_row_visible_labels',
    'replace_person_credit_row_review_record',
    'validate_person_credit_row_nominee_id_slots',
    'validate_person_credit_row_nominee_id_slots',
    'reviewer_user_id',
) as $needle) {
    $assert(strpos($saver, $needle) !== false, "Saver should validate and persist one full-row private review: {$needle}");
}

foreach (array(
    'get_person_credit_row_review_records_for_source_ids',
    'get_default_person_credit_row_review_record',
    'full_row_review',
    'full_row_preview',
    'build_person_credit_full_row_review_preview',
    'row_reviewed_total',
) as $needle) {
    $assert(strpos($queue . $fetcher, $needle) !== false, "Queue should merge full-row review state into audit rows: {$needle}");
}

foreach (array(
    'category_mismatch',
    'label_mismatch',
    'source_gap',
    'ready_to_apply',
    'count_mismatch',
    'blank_slots',
    'already_applied',
    'visible_label_count',
    'new_nominee_ids',
) as $needle) {
    $assert(strpos($preview, $needle) !== false, "Preview should block stale/unsafe full-row corrections: {$needle}");
}

foreach (array(
    'current_user_can',
    'manage_options',
    'aat_person_credit_row_apply_confirm',
    'aat_person_credit_row_apply_confirm_source_award_id',
    'build_person_credit_full_row_review_preview',
    '$wpdb->update',
    "'nominee_ids'",
    "array('id' => \$source_award_id)",
    'START TRANSACTION',
    'ROLLBACK',
    'COMMIT',
    'rebuild_reporting_tables',
    'clear_awards_runtime_caches',
    "'review_state' => 'applied'",
) as $needle) {
    $assert(strpos($apply, $needle) !== false, "Apply should be guarded and mutate only one source row: {$needle}");
}

foreach (array(
    'aat_person_credit_row_review_nonce',
    'aat_person_credit_row_review',
    'save_person_credit_row_review_record_from_request',
    'aat_person_credit_row_apply_nonce',
    'aat_person_credit_row_apply',
    'apply_person_credit_full_row_source_correction_from_request',
    'person_credit_row_review_states',
) as $needle) {
    $assert(strpos($admin, $needle) !== false, "Admin handler should route full-row review/apply controls: {$needle}");
}

foreach (array(
    'Full-row resolver',
    'aat-person-credit-full-row-resolver',
    'aat_person_credit_row_source_award_id',
    'aat_person_credit_row_review_state',
    'aat_person_credit_row_proposed_nominee_ids',
    'aat_person_credit_row_review_note',
    'aat_person_credit_row_apply_source_award_id',
    'aat_person_credit_row_apply_confirm_source_award_id',
    'Apply full-row source correction',
    'Ordered nominee_ids',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should expose the private full-row resolver: {$needle}");
}

foreach (array(
    '.aat-person-credit-full-row-resolver',
    '.aat-person-credit-full-row-slots',
    '.aat-person-credit-full-row-resolver.is-ready',
) as $needle) {
    $assert(strpos($css, $needle) !== false, "Admin CSS should style full-row resolver controls: {$needle}");
}

foreach (array(
    'wp_update_post',
    'update_post_meta',
    'delete_post_meta',
    'media_handle_sideload',
    'download_url',
    'DELETE FROM',
    'INSERT INTO academy_awards',
) as $forbidden) {
    $assert(strpos($saver . $queue, $forbidden) === false, "Full-row save/queue must not mutate Oscar/media data: {$forbidden}");
}

foreach (array(
    'private full-row resolver',
    'wp_aat_person_credit_row_reviews',
    'Ready To Apply',
    'exact typed confirmation of `source_award_id`',
    'one source award row at a time',
    'Public HTML never exposes',
) as $needle) {
    $assert(stripos($docs, $needle) !== false, "Spec/plan/docs should describe the full-row resolver contract: {$needle}");
}

if (!empty($failures)) {
    fwrite(STDERR, "Person-credit full-row resolver contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Person-credit full-row resolver contract passed.\n";
