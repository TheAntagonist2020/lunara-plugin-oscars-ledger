<?php
/**
 * 2.8.18: the ceremony "Categories Decided" record never prints winners over
 * categories ("44/28"), and uploaded posters reach card backdrops.
 *
 * Run: php tests/ceremony-record-contract.php
 */
$root = dirname(__DIR__);
$main = file_get_contents($root . '/academy-awards-table.php');
$hub = file_get_contents($root . '/templates/hub-page.php');
$fail = function ($msg) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); };

if (preg_match('/%\d\$s\/%\d\$s/', $hub) || strpos($hub, "winner_categories'] ?? 0))); ?>/<?php") !== false) {
    $fail('The ceremony record must not render as winners/categories.');
}
if (substr_count($hub, 'esc_html($winner_record_label)') !== 2 || strpos($hub, '%1$s of %2$s') === false) {
    $fail('Both ceremony record cards use the "X of Y" / "All Y" label.');
}
if (strpos($main, "'winner_categories' => count(array_unique(array_column(\$winner_rows, 'canonical_category')))") === false) {
    $fail('The rollup counts categories with a winner, not winner rows.');
}
if (!preg_match("/\\\$out\['poster_html'\] = \\\$poster_html;.{0,300}\\\$out\['poster_url'\] = \\\$poster_src;/s", $main)) {
    $fail('An uploaded poster also fills poster_url for card backdrops.');
}
echo "Ceremony record contract passed: 4 checks (no x/y counts anywhere in the hub).\n";
