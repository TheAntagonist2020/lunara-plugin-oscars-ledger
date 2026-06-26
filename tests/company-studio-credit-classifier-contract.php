<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/person-portrait-import-admin.php',
    'assets/css/admin.css',
    'README.md',
    'readme.txt',
    'docs/staging/specs/2026-06-26-company-studio-credit-resolver.md',
    'docs/staging/plans/2026-06-26-company-studio-credit-resolver.md',
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
$docs = implode("\n", array_filter(array(
    is_string($source['README.md']) ? $source['README.md'] : '',
    is_string($source['readme.txt']) ? $source['readme.txt'] : '',
    is_string($source['docs/staging/specs/2026-06-26-company-studio-credit-resolver.md']) ? $source['docs/staging/specs/2026-06-26-company-studio-credit-resolver.md'] : '',
    is_string($source['docs/staging/plans/2026-06-26-company-studio-credit-resolver.md']) ? $source['docs/staging/plans/2026-06-26-company-studio-credit-resolver.md'] : '',
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

$schema = $method_slice($plugin, 'private function maybe_create_company_credit_row_reviews_table', 'private function maybe_create_omdb_reviews_table');
$handler = $method_slice($plugin, 'public function handle_profile_image_batch_cli', 'private function normalize_profile_image_person_credit_stage_cli_args');
$classifier = $method_slice($plugin, 'private function normalize_profile_image_company_credit_audit_cli_args', 'private function get_company_credit_review_filter_labels');
$review_storage = $method_slice($plugin, 'private function get_company_credit_review_filter_labels', 'private function get_person_credit_review_states');

foreach (array(
    'Version: 2.7.61',
    "define('AAT_VERSION', '2.7.61')",
    'Stable tag: 2.7.61',
    'Current baseline: `2.7.61`',
    'company/studio credit resolver',
) as $needle) {
    $assert(stripos($plugin . $docs, $needle) !== false, "Version/docs marker should exist: {$needle}");
}

$assert($schema !== '', 'Company/studio review table schema should be inspectable.');
$assert($handler !== '', 'CLI handler should be inspectable.');
$assert($classifier !== '', 'Company/studio classifier should be inspectable.');
$assert($review_storage !== '', 'Company/studio private review storage should be inspectable.');

foreach (array(
    'get_company_credit_row_reviews_table_name',
    'maybe_create_company_credit_row_reviews_table',
    'aat_company_credit_row_reviews',
    'source_award_id mediumint(9) unsigned NOT NULL',
    'category_slug varchar(191) NOT NULL DEFAULT',
    'credit_labels text',
    'review_state varchar(32) NOT NULL DEFAULT',
    'entity_kind varchar(32) NOT NULL DEFAULT',
    'proposed_nominee_ids text',
    'display_label_override text',
    'correction_note text',
    'PRIMARY KEY  (source_award_id)',
    'KEY entity_kind (entity_kind)',
) as $needle) {
    $assert(strpos($plugin . $schema, $needle) !== false, "Schema should persist company/studio review metadata: {$needle}");
}

foreach (array(
    'maybe_create_company_credit_row_reviews_table($charset_collate)',
    '$this->maybe_create_company_credit_row_reviews_table();',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Schema upgrade path should create company/studio review table: {$needle}");
}

foreach (array(
    'company-credit-audit',
    'normalize_profile_image_company_credit_audit_cli_args',
    'build_profile_image_company_credit_audit',
    'Company and studio credit classifier audit complete',
) as $needle) {
    $assert(strpos($handler, $needle) !== false, "CLI handler should route read-only company/studio audit mode: {$needle}");
}

foreach (array(
    'get_company_credit_row_review_states',
    'needs_review',
    'ready_to_apply',
    'department_label_only',
    'source_gap',
    'applied',
    'ignore_accept',
    'get_company_credit_entity_kinds',
    'company',
    'department',
    'mixed',
    'source_gap',
    'person',
    'slot_mismatch',
) as $needle) {
    $assert(strpos($classifier, $needle) !== false, "Company/studio states and entity kinds should include {$needle}.");
}

foreach (array(
    'validate_company_credit_row_nominee_id_slots',
    'is_company_entity_id',
    'aat_company_credit_row_bad_company_id',
    'must be a valid IMDb co ID',
) as $needle) {
    $assert(strpos($classifier, $needle) !== false, "Company/studio validation should accept only co ID slots: {$needle}");
}

foreach (array(
    'company_credit_label_has_department_signal',
    'company_credit_label_has_company_signal',
    'classify_company_credit_source_row',
    'slot_mismatch',
    'label_count',
    'source_id_count',
    'company_id_count',
    'person_id_count',
    'department_label_count',
    'missing_source_nominee_ids',
    'label_id_mismatch',
    'write_profile_image_company_credit_audit_csv',
) as $needle) {
    $assert(strpos($classifier, $needle) !== false, "Classifier should report company/studio source-row buckets: {$needle}");
}

foreach (array(
    '$wpdb->update',
    '$wpdb->insert',
    '$wpdb->replace',
    'wp_update_post',
    'update_post_meta',
    'delete_post_meta',
    'media_handle_sideload',
    'download_url',
    'DELETE FROM',
    'INSERT INTO',
    'UPDATE ',
) as $forbidden) {
    $assert(strpos($classifier, $forbidden) === false, "Company/studio classifier must remain read-only: {$forbidden}");
}

foreach (array(
    'get_company_credit_review_filter_labels',
    'sanitize_company_credit_review_filter',
    'get_company_credit_entity_filter_labels',
    'sanitize_company_credit_entity_filter',
    'replace_company_credit_row_review_record',
    'save_company_credit_row_review_record_from_request',
    'company_credit_company_id_has_public_profile',
    'build_company_credit_row_preview_from_request',
    'build_company_credit_row_preview',
    'get_company_credit_row_review_records_for_source_ids',
    'get_default_company_credit_row_review_record',
    'get_company_credit_review_queue_rows',
    'display_label_override',
    'required_confirmation',
    'confirmation_missing',
    'unknown_company',
    'route-backed',
    'validate_company_credit_row_nominee_id_slots',
    '$wpdb->replace',
    'maybe_create_company_credit_row_reviews_table',
) as $needle) {
    $assert(strpos($review_storage, $needle) !== false, "Private company/studio review layer should include {$needle}.");
}

foreach (array(
    '$wpdb->update',
    '$wpdb->insert',
    'wp_update_post',
    'update_post_meta',
    'delete_post_meta',
    'media_handle_sideload',
    'download_url',
    'DELETE FROM',
    'INSERT INTO',
    'UPDATE ',
) as $forbidden) {
    $assert(strpos($review_storage, $forbidden) === false, "Company/studio review layer must not mutate source rows or media: {$forbidden}");
}

foreach (array(
    'aat_company_credit_row_review_nonce',
    'aat_company_credit_row_source_award_id',
    'aat_company_credit_row_review_state',
    'aat_company_credit_row_entity_kind',
    'aat_company_credit_row_proposed_nominee_ids',
    'aat_company_credit_row_display_label_override',
    'aat_company_credit_row_review_note',
    'aat_company_credit_row_preview_nonce',
    'aat_company_credit_row_preview_source_award_id',
    'aat_company_credit_row_preview_confirm_source_id',
    'Company / Studio Credits',
    'Save company/studio review',
    'Preview validation only',
) as $needle) {
    $assert(strpos($template, $needle) !== false, "Admin template should render private company/studio review controls: {$needle}");
}

foreach (array(
    'aat-company-credit-review',
    'aat-company-credit-review-grid',
    'aat-company-credit-review-card',
    'aat-company-credit-slots',
    'aat-company-credit-preview-gate',
    'aat-company-credit-preview-form',
    'aat-company-credit-preview-slots',
) as $needle) {
    $assert(strpos($template . $admin_css, $needle) !== false, "Admin CSS/template should style company/studio review lane: {$needle}");
}

$assert(strpos($plugin . $template, 'aat_company_credit_row_apply') === false, 'Company/studio lane must not expose an apply path yet.');
$assert(strpos($plugin . $template, 'check_admin_referer(\'aat_company_credit_row_preview\'') !== false, 'Company/studio preview should be nonce protected.');
$assert(strpos($review_storage, '$wpdb->update') === false, 'Company/studio preview/review storage must not update source Oscar rows.');

foreach (array(
    'company-credit-audit',
    'wp_aat_company_credit_row_reviews',
    'Company / Studio Credits',
    'preview-only validation',
    'non-route-backed company IDs',
    'slot-pairing',
    'read-only classifier',
    'no source mutation',
) as $needle) {
    $assert(stripos($docs, $needle) !== false, "Spec/plan/docs should describe the company/studio resolver contract: {$needle}");
}

if (!empty($failures)) {
    fwrite(STDERR, "Company/studio credit classifier contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Company/studio credit classifier contract passed.\n";
