<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
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
$docs = (is_string($source['README.md']) ? $source['README.md'] : '') . "\n" . (is_string($source['readme.txt']) ? $source['readme.txt'] : '');

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

$handler = $method_slice($plugin, 'public function handle_profile_image_batch_cli', 'private function normalize_profile_image_person_credit_audit_cli_args');
$normalizer = $method_slice($plugin, 'private function normalize_profile_image_person_credit_audit_cli_args', 'private function build_profile_image_person_credit_audit');
$builder = $method_slice($plugin, 'private function build_profile_image_person_credit_audit', 'private function profile_image_person_credit_audit_sample_rows');
$writer = $method_slice($plugin, 'private function write_profile_image_person_credit_audit_csv', 'private function normalize_profile_image_coverage_cli_args');

foreach (array(
    'Version: 2.7.80',
    "define('AAT_VERSION', '2.7.80')",
    'person-credit-audit',
    'build_profile_image_person_credit_audit',
    'write_profile_image_person_credit_audit_csv',
    'person_credit_unresolved',
    'person_credit_linked',
) as $needle) {
    $assert(strpos($plugin, $needle) !== false, "Plugin should expose person-credit reconciliation marker: {$needle}");
}

$assert($handler !== '', 'CLI handler slice should be inspectable.');
$assert($normalizer !== '', 'Person-credit audit normalizer should be inspectable.');
$assert($builder !== '', 'Person-credit audit builder should be inspectable.');
$assert($writer !== '', 'Person-credit audit CSV writer should be inspectable.');

foreach (array(
    "'person-credit-audit'",
    'normalize_profile_image_person_credit_audit_cli_args',
    'build_profile_image_person_credit_audit',
    'Person credit reconciliation audit complete',
) as $needle) {
    $assert(strpos($handler, $needle) !== false, "CLI handler should route person-credit audit mode: {$needle}");
}

foreach (array(
    'category',
    'sample',
    'output-csv',
    'state',
    'sanitize_title',
    'person_credit_audit_output_not_private',
) as $needle) {
    $assert(strpos($normalizer, $needle) !== false, "Normalizer should sanitize bounded private audit options: {$needle}");
}

foreach (array(
    'split_visible_person_credit_labels',
    'get_name_entity_link_by_label',
    'person_credit_linked',
    'person_credit_unresolved',
    'source_nominee_ids',
    'label_index',
    'missing_source_nominee_ids',
    'label_id_mismatch',
    'output_csv',
) as $needle) {
    $assert(strpos($builder, $needle) !== false, "Audit builder should classify public-credit reconciliation rows: {$needle}");
}

foreach (array(
    'source_award_id',
    'ceremony',
    'category',
    'credit_label',
    'state',
    'resolved_id',
    'source_nominee_ids',
    'fputcsv',
) as $needle) {
    $assert(strpos($writer, $needle) !== false, "CSV writer should preserve operational reconciliation fields: {$needle}");
}

foreach (array(
    'wp_update_post',
    'update_post_meta',
    'delete_post_meta',
    'media_handle_sideload',
    'download_url',
    'DELETE FROM',
    'UPDATE ',
    'INSERT INTO',
) as $forbidden) {
    $assert(strpos($builder . $writer, $forbidden) === false, "Person-credit audit must be read-only against data/media: {$forbidden}");
}

foreach (array(
    'person-credit-audit',
    'unresolved person credit',
    'read-only',
    '2.7.80',
) as $needle) {
    $assert(strpos($docs, $needle) !== false, "Docs should describe the private read-only person-credit audit: {$needle}");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Person credit reconciliation audit contract OK.\n";
