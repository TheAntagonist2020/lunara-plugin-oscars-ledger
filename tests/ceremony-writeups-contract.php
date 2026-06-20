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
$assert(trim((string) ($records[98]['source_notes'] ?? '')) !== '', 'Private source notes should be retained.');

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
