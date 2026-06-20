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

$plugin_source = file_get_contents($root . '/academy-awards-table.php');
$approved_start = strpos($plugin_source, 'public function get_approved_ceremony_writeup');
$approved_end = strpos($plugin_source, 'public function render_shortcode', $approved_start);
$approved_function = $approved_start !== false && $approved_end !== false ? substr($plugin_source, $approved_start, $approved_end - $approved_start) : '';
$assert(strpos($approved_function, 'decode_ceremony_writeup_text_fields') !== false, 'Approved public write-up accessor should decode public text before templates escape it.');
$assert(strpos($approved_function, 'body_hex') !== false, 'Approved public write-up accessor should decode body text from database hex bytes.');

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
