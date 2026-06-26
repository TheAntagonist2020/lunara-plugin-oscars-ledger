<?php

$root = dirname(__DIR__);
$helper = $root . '/includes/class-aat-ceremony-writeups.php';

if (!file_exists($helper)) {
    fwrite(STDERR, "Missing helper: {$helper}\n");
    exit(1);
}

require_once $helper;

if (!class_exists('AAT_Ceremony_Writeups')) {
    fwrite(STDERR, "Missing AAT_Ceremony_Writeups class.\n");
    exit(1);
}

$docx = getenv('AAT_CEREMONY_DOCX');
if (!$docx) {
    $docx = 'E:\\Academy Awards Ceremony Guide from the First Ceremony to the 98th Ceremony.docx';
}

$result = AAT_Ceremony_Writeups::parse_docx($docx);
$summary = $result['summary'] ?? array();
$records = $result['records'] ?? array();

$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(($summary['detected'] ?? 0) === 98, 'Expected 98 detected ceremony records.');
$assert(($summary['first'] ?? 0) === 1, 'Expected first ceremony number to be 1.');
$assert(($summary['last'] ?? 0) === 98, 'Expected last ceremony number to be 98.');
$assert(empty($summary['missing']), 'Expected no missing ceremony numbers.');
$assert(empty($summary['duplicates']), 'Expected no duplicate ceremony numbers.');
$assert(isset($records[98]), 'Expected ceremony 98 record.');
$assert(strpos($records[98]['body'] ?? '', 'https://') === false, 'Public body should not include source URLs.');
$assert(!preg_match('/\[\d+\]/', $records[98]['body'] ?? ''), 'Public body should not include bracketed source markers.');
$assert(array_key_exists('source_notes', $records[98]), 'Private source notes should remain separated from the public body.');

$headline_with_cp1252_dash = "98th Academy Awards \x97 March 15, 2026";
$body_with_cp1252_apostrophe = "Paul Thomas Anderson\x92s epic dominated the 98th Academy Awards.";
$assert(AAT_Ceremony_Writeups::normalize_public_text($headline_with_cp1252_dash) === '98th Academy Awards — March 15, 2026', 'Public text normalizer should convert Windows-1252 em dashes to UTF-8.');
$assert(AAT_Ceremony_Writeups::normalize_public_text($body_with_cp1252_apostrophe) === 'Paul Thomas Anderson’s epic dominated the 98th Academy Awards.', 'Public text normalizer should convert Windows-1252 smart apostrophes to UTF-8.');
$assert(preg_match('//u', AAT_Ceremony_Writeups::normalize_public_text($body_with_cp1252_apostrophe)) === 1, 'Normalized public text should be valid UTF-8.');
$body_with_nonbreaking_hyphen = 'long‑awaited Best Director Oscar';
$assert(AAT_Ceremony_Writeups::decode_database_text('long?awaited Best Director Oscar', bin2hex($body_with_nonbreaking_hyphen)) === $body_with_nonbreaking_hyphen, 'Database text decoder should prefer UTF-8 hex bytes over connection-converted text.');
$body_with_common_mojibake = 'Paul Thomas Anderson' . hex2bin('C3A2E282ACE284A2') . 's long' . hex2bin('C3A2E282ACE28098') . 'awaited win ' . hex2bin('C3A2E282ACE2809D') . ' ' . hex2bin('C3A2E282ACC593') . 'Sinners' . hex2bin('C3A2E282ACC29D') . ' and Ren' . hex2bin('C383C2A9') . 'e Zellweger.';
$body_with_common_mojibake_expected = 'Paul Thomas Anderson’s long‑awaited win — “Sinners” and Renée Zellweger.';
$assert(AAT_Ceremony_Writeups::normalize_public_text($body_with_common_mojibake) === $body_with_common_mojibake_expected, 'Public text normalizer should repair common UTF-8 mojibake sequences.');
$assert(AAT_Ceremony_Writeups::decode_database_text('Paul Thomas Anderson?s long?awaited win', bin2hex($body_with_common_mojibake)) === $body_with_common_mojibake_expected, 'Database text decoder should repair mojibake from hex bytes before rendering.');

$plugin_source = file_get_contents($root . '/academy-awards-table.php');
$approved_start = strpos($plugin_source, 'public function get_approved_ceremony_writeup');
$approved_end = strpos($plugin_source, 'public function render_shortcode', $approved_start);
$approved_function = $approved_start !== false && $approved_end !== false ? substr($plugin_source, $approved_start, $approved_end - $approved_start) : '';
$assert(strpos($approved_function, 'decode_ceremony_writeup_text_fields') !== false, 'Approved public write-up accessor should decode public text before templates escape it.');
$assert(strpos($approved_function, 'body_hex') !== false, 'Approved public write-up accessor should decode body text from database hex bytes.');

$admin_start = strpos($plugin_source, 'public function render_ceremony_writeups_admin_page');
$admin_end = strpos($plugin_source, 'private function get_ceremony_writeup_status_labels', $admin_start);
$admin_function = $admin_start !== false && $admin_end !== false ? substr($plugin_source, $admin_start, $admin_end - $admin_start) : '';
$rows_start = strpos($plugin_source, 'private function get_ceremony_writeups_admin_rows');
$rows_end = strpos($plugin_source, 'private function decode_ceremony_writeup_text_fields', $rows_start);
$rows_function = $rows_start !== false && $rows_end !== false ? substr($plugin_source, $rows_start, $rows_end - $rows_start) : '';
$search_start = strpos($plugin_source, 'private function get_ceremony_writeups_search_sql');
$search_end = strpos($plugin_source, 'private function get_ceremony_writeups_admin_counts', $search_start);
$search_function = $search_start !== false && $search_end !== false ? substr($plugin_source, $search_start, $search_end - $search_start) : '';
$assert(strpos($plugin_source, 'private function sanitize_ceremony_writeup_status_filter') !== false, 'Admin queue should sanitize status filters.');
$assert(strpos($plugin_source, 'private function get_ceremony_writeup_filter_state') !== false, 'Admin queue should centralize filter/search request state.');
$assert(strpos($plugin_source, 'private function get_ceremony_writeups_admin_counts') !== false, 'Admin queue should expose status counts.');
$assert(strpos($plugin_source, 'private function get_ceremony_writeups_admin_url') !== false, 'Admin queue should build context-preserving admin URLs.');
$assert(strpos($admin_function, 'aat_ceremony_writeup_status_filter') !== false, 'Admin queue should render a status filter control.');
$assert(strpos($admin_function, 'aat_ceremony_writeup_search') !== false, 'Admin queue should render a search control.');
$assert(strpos($admin_function, 'aat-ceremony-writeups-filter-bar') !== false, 'Admin queue should render a dedicated filter bar.');
$assert(strpos($admin_function, 'aat-ceremony-writeups-counts') !== false, 'Admin queue should render queue counts.');
$assert(strpos($rows_function, '$status_filter') !== false, 'Admin row query should accept a status filter.');
$assert(strpos($rows_function, '$search') !== false, 'Admin row query should accept a search term.');
$assert(strpos($rows_function, '$wpdb->prepare') !== false, 'Filtered/search admin row query should use prepared SQL.');
$assert(strpos($search_function, 'source_notes') !== false, 'Private source notes should be searchable only inside the admin queue.');

$sql = AAT_Ceremony_Writeups::get_create_table_sql('wp_aat_ceremony_writeups', 'DEFAULT CHARSET=utf8mb4');
$assert(strpos($sql, 'CREATE TABLE IF NOT EXISTS wp_aat_ceremony_writeups') !== false, 'Schema SQL should target the write-up table.');
$assert(strpos($sql, 'ceremony_number int(3) NOT NULL') !== false, 'Schema SQL should include ceremony_number.');
$assert(strpos($sql, 'source_notes longtext') !== false, 'Schema SQL should include private source_notes.');
$assert(strpos($sql, 'UNIQUE KEY ceremony_number') !== false, 'Schema SQL should enforce one row per ceremony.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Ceremony write-up contract OK: {$summary['detected']} records parsed.\n";
