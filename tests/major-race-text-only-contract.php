<?php
/**
 * 2.8.17: Four Races cards with no poster give the winner's name the full
 * card width, and names never break inside a word.
 *
 * Run: php tests/major-race-text-only-contract.php
 */
$root = dirname(__DIR__);
$hub = file_get_contents($root . '/templates/hub-page.php');
$css = file_get_contents($root . '/assets/css/academy-awards-table.css');
$fail = array();
if (strpos($hub, "<div class=\"aat-major-race-feature<?php echo empty(\$feature_visual['poster_url']) ? ' is-text-only' : ''; ?>\">") === false) {
    $fail[] = 'A race card with no poster is marked is-text-only.';
}
if (!preg_match('/\.aat-major-race-feature\.is-text-only,\s*\.aat-major-race-card\.is-best-picture \.aat-major-race-feature\.is-text-only \{\s*grid-template-columns: minmax\(0, 1fr\);/', $css)) {
    $fail[] = 'A text-only race card uses a single full-width column (Best Picture included).';
}
$title = substr($css, strpos($css, '.aat-major-race-title {'), 500);
if (strpos($title, 'word-break: normal;') === false || strpos($title, 'overflow-wrap: break-word;') === false) {
    $fail[] = 'Race winner names break between words, never inside one.';
}
if ($fail) { fwrite(STDERR, implode("\n", $fail) . "\n"); exit(1); }
echo "Major race text-only contract OK.\n";
