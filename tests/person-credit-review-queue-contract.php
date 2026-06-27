<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-25-person-credit-review-queue.md',
    'docs/staging/plans/2026-06-25-person-credit-review-queue.md',
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
$docs = implode("\n", array_filter(array(
    is_string($source['README.md']) ? $source['README.md'] : '',
    is_string($source['readme.txt']) ? $source['readme.txt'] : '',
    is_string($source['docs/staging/specs/2026-06-25-person-credit-review-queue.md']) ? $source['docs/staging/specs/2026-06-25-person-credit-review-queue.md'] : '',
    is_string($source['docs/staging/plans/2026-06-25-person-credit-review-queue.md']) ? $source['docs/staging/plans/2026-06-25-person-credit-review-queue.md'] : '',
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

$schema = $method_slice($plugin, 'private function maybe_create_person_credit_reviews_table', 'private function maybe_create_omdb_reviews_table');
$states = $method_slice($plugin, 'private function get_person_credit_review_states', 'private function sanitize_person_credit_review_state');
$saver = $method_slice($plugin, 'private function save_person_credit_review_record_from_request', 'private function get_person_credit_review_records_for_keys');
$source_preview = $method_slice($plugin, 'private function build_person_credit_source_correction_preview', 'private function apply_person_credit_source_correction_from_request');
$source_correction = $method_slice($plugin, 'private function apply_person_credit_source_correction_from_request', 'private function get_person_credit_review_records_for_keys');
$fetcher = $method_slice($plugin, 'private function get_person_credit_review_records_for_keys', 'private function get_default_person_credit_review_record');
$queue = $method_slice($plugin, 'private function get_person_credit_review_queue_rows', 'private function normalize_profile_image_coverage_cli_args');
$admin = $method_slice($plugin, 'public function render_person_portrait_import_admin_page', 'public function render_omdb_audit_admin_page');

foreach (array(
    'Version: 2.7.83',
    "define('AAT_VERSION', '2.7.83')",
    'get_person_credit_reviews_table_name',
    'maybe_create_person_credit_reviews_table',
    'wp_aat_person_credit_reviews',
    'person_credit_reviews',
    'person-credit-review',
) as $needle) {
    $assert(strpos($plugin . $docs . $template, $needle) !== false, "Person-credit review queue marker should exist: {$needle}");
}

$assert($schema !== '', 'Person-credit review table schema should be inspectable.');
$assert($states !== '', 'Person-credit review states should be inspectable.');
$assert($saver !== '', 'Person-credit review saver should be inspectable.');
$assert($source_preview !== '', 'Person-credit source correction preview should be inspectable.');
$assert($source_correction !== '', 'Person-credit source correction action should be inspectable.');
$assert($fetcher !== '', 'Person-credit review fetcher should be inspectable.');
$assert($queue !== '', 'Person-credit review queue builder should be inspectable.');
$assert($admin !== '', 'Person Portrait Queue admin handler should be inspectable.');

foreach (array(
    'review_key varchar(191) NOT NULL',
    'source_award_id mediumint(9) unsigned NOT NULL DEFAULT 0',
    'label_index smallint(5) unsigned NOT NULL DEFAULT 0',
    'category_slug varchar(191) NOT NULL DEFAULT',
    'credit_label varchar(500) NOT NULL DEFAULT',
    'review_state varchar(32) NOT NULL DEFAULT',
    'proposed_person_id varchar(32) NOT NULL DEFAULT',
    'correction_note text',
    'reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
    'PRIMARY KEY  (review_key)',
) as $needle) {
    $assert(strpos($schema, $needle) !== false, "Schema should persist private review metadata: {$needle}");
}

foreach (array(
    'needs_review',
    'candidate_found',
    'ready_to_correct',
    'source_gap',
    'resolved',
    'ignore_accept',
) as $needle) {
    $assert(strpos($states, $needle) !== false, "Review states should include {$needle}.");
}

foreach (array(
    'current_user_can',
    'manage_options',
    'aat_person_credit_review_key',
    'aat_person_credit_review_state',
    'aat_person_credit_proposed_person_id',
    'aat_person_credit_review_note',
    'sanitize_textarea_field',
    'replace_person_credit_review_record',
    'reviewer_user_id',
) as $needle) {
    $assert(strpos($saver, $needle) !== false, "Saver should validate and persist one private review row: {$needle}");
}

foreach (array(
    'review_key',
    'get_person_credit_review_records_for_keys',
    'get_default_person_credit_review_record',
    'person_credit_review_filter',
    'proposed_person_id',
    'correction_note',
    'review_state_label',
    'source_correction_preview',
    'build_person_credit_source_correction_preview',
) as $needle) {
    $assert(strpos($queue . $fetcher, $needle) !== false, "Queue should merge audit rows with saved review records: {$needle}");
}

foreach (array(
    'aat_person_credit_review_nonce',
    'aat_person_credit_review',
    'save_person_credit_review_record_from_request',
    'aat_person_credit_source_correction_nonce',
    'aat_person_credit_source_correction',
    'apply_person_credit_source_correction_from_request',
    'person_credit_category',
    'person_credit_review_state',
    'person_credit_rows',
    'person_credit_summary',
) as $needle) {
    $assert(strpos($admin, $needle) !== false, "Admin handler should route person-credit review controls: {$needle}");
}

foreach (array(
    'current_user_can',
    'manage_options',
    'aat_person_credit_source_confirm',
    'aat_person_credit_source_confirm_person_id',
    'get_person_credit_review_records_for_keys',
    'build_person_credit_source_correction_preview',
    '$wpdb->update',
    "'nominee_ids'",
    'START TRANSACTION',
    'ROLLBACK',
    'COMMIT',
    'rebuild_reporting_tables',
    'clear_awards_runtime_caches',
    "'resolved'",
) as $needle) {
    $assert(strpos($source_correction, $needle) !== false, "Source correction should be one-row guarded and rebuild projections: {$needle}");
}

foreach (array(
    'multi_credit_needs_full_row',
    'label_mismatch',
    'existing_ids_present',
    'candidate_found',
    'ready_to_correct',
    'visible_label_count',
    'normalize_person_credit_label_for_compare',
) as $needle) {
    $assert(strpos($source_preview, $needle) !== false, "Source correction preview should block unsafe rows: {$needle}");
}

foreach (array(
    'Person credit review',
    'aat-person-credit-review',
    'aat_person_credit_review_key',
    'aat_person_credit_review_state',
    'aat_person_credit_proposed_person_id',
    'aat_person_credit_review_note',
    'aat_person_credit_source_correction_nonce',
    'aat_person_credit_source_review_key',
    'aat_person_credit_source_proposed_person_id',
    'aat_person_credit_source_confirm_person_id',
    'Apply one-row source correction',
    'source_nominee_ids',
    'private note',
) as $needle) {
    $assert(stripos($template, $needle) !== false, "Admin template should render the private review lane: {$needle}");
}

foreach (array(
    'wp_update_post',
    'update_post_meta',
    'delete_post_meta',
    'media_handle_sideload',
    'download_url',
    'DELETE FROM',
    'UPDATE academy_awards',
    'INSERT INTO academy_awards',
) as $forbidden) {
    $assert(strpos($saver . $queue, $forbidden) === false, "Review queue must not mutate Oscar/media data: {$forbidden}");
}

foreach (array(
    'person-credit review',
    'private',
    'read-only',
    'deferred correction',
    'one-row',
    'source correction',
    '2.7.83',
) as $needle) {
    $assert(stripos($docs, $needle) !== false, "Docs/spec should describe the private review queue: {$needle}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Person credit review queue contract OK.\n";
