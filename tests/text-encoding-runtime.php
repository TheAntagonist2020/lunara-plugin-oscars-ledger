<?php
/**
 * 2.8.17: AAT_Text::repair() turns every known shape of encoding damage back
 * into the original text, and leaves clean text alone.
 *
 * Run: php tests/text-encoding-runtime.php
 */
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-aat-text.php';

$checks = 0;
$fail = array();
function te($ok, $msg) { global $checks, $fail; ++$checks; if (!$ok) { $fail[] = $msg; } }

// Simulate the damage exactly as it happens on the site.
function as_cp1252($utf8) { return mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'); }
function double_encode($utf8) {
    // UTF-8 bytes read as Windows-1252 (MySQL latin1), then written as UTF-8.
    $map = array(0x80=>0x20AC,0x81=>0x81,0x82=>0x201A,0x83=>0x192,0x84=>0x201E,0x85=>0x2026,0x86=>0x2020,0x87=>0x2021,0x88=>0x2C6,0x89=>0x2030,0x8A=>0x160,0x8B=>0x2039,0x8C=>0x152,0x8D=>0x8D,0x8E=>0x17D,0x8F=>0x8F,0x90=>0x90,0x91=>0x2018,0x92=>0x2019,0x93=>0x201C,0x94=>0x201D,0x95=>0x2022,0x96=>0x2013,0x97=>0x2014,0x98=>0x2DC,0x99=>0x2122,0x9A=>0x161,0x9B=>0x203A,0x9C=>0x153,0x9D=>0x9D,0x9E=>0x17E,0x9F=>0x178);
    $out = '';
    foreach (str_split($utf8) as $ch) {
        $b = ord($ch);
        $cp = ($b >= 0x80 && $b <= 0x9F) ? $map[$b] : $b;
        $out .= mb_chr($cp, 'UTF-8');
    }
    return $out;
}
function clean($s) { return strpos($s, 'â€') === false && strpos($s, "\u{FFFD}") === false && !preg_match('/Ã[\x{80}-\x{BF}]/u', $s); }

$fixture = 'It’s café — “quote” 🎬 Kieślowski';

te(AAT_Text::repair($fixture) === $fixture, 'Clean UTF-8 (curly quotes, accents, emoji, Polish) is returned unchanged.');
te(AAT_Text::repair(double_encode($fixture)) === $fixture, 'Double-encoded text is restored exactly, emoji and closing ” (byte 0x9D) included.');
te(AAT_Text::repair(double_encode(double_encode($fixture))) === $fixture, 'Text double-encoded twice is restored.');
$cp = as_cp1252('It’s café — “quote”');
te(AAT_Text::repair($cp) === 'It’s café — “quote”', 'Pure Windows-1252 bytes become UTF-8.');
$mixed = 'Penélope' . "\x92" . 's — film';
te(AAT_Text::repair($mixed) === 'Penélope’s — film', 'A stray Windows-1252 byte in a valid UTF-8 row is fixed without double-encoding the rest (the old whole-string bug).');
$half = 'Kieślowski’s 🎬 and Bakerâ€™s';
te(AAT_Text::repair($half) === 'Kieślowski’s 🎬 and Baker’s', 'Mojibake next to genuine characters is repaired piece by piece.');
te(AAT_Text::repair(double_encode('Baker’s')) === 'Baker’s', 'Chrome session fixture: "Baker’s" from the 97th write-up.');
te(AAT_Text::repair(double_encode('97th Academy Awards — March 2, 2025')) === '97th Academy Awards — March 2, 2025', 'Chrome session fixture: the 97th headline em dash.');
te(AAT_Text::repair(double_encode('Chloé Zhao')) === 'Chloé Zhao' && AAT_Text::repair('Ã‰douard') === 'Édouard', 'Accented names are restored.');
te(AAT_Text::repair(double_encode("Non\u{00A0}breaking")) === "Non\u{00A0}breaking", 'Non-breaking spaces ("Â ") are restored.');
foreach (array('Édouard Manet', 'Café Society', 'São Paulo', 'Ångström', 'Naïve', '50¢ – £5', '© 2026 Lunara') as $real) {
    te(AAT_Text::repair($real) === $real, "Genuine text is never altered: {$real}");
}
te(clean(AAT_Text::repair(double_encode('“Oppenheimer” — Nolan’s ✨ résumé'))), 'Repaired output has no mojibake markers.');
te(AAT_Text::looks_damaged('Bakerâ€™s') && !AAT_Text::looks_damaged($fixture), 'looks_damaged() spots mojibake and passes clean text.');
te(AAT_Text::repair('') === '' && AAT_Text::repair(null) === '', 'Empty input is safe.');
te(preg_match('//u', AAT_Text::repair("\xFF\xFE broken")) === 1, 'Output is always valid UTF-8.');

if ($fail) { fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $fail) . "\n"); exit(1); }
echo "Text encoding runtime passed: {$checks} checks.\n";
