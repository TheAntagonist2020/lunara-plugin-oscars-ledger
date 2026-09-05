<?php
/**
 * Landing section composer contract (2.7.83).
 *
 * The [academy_awards] landing template captures every top-level block into
 * $aat_landing_sections and re-emits it through the aat_landing_route_sections
 * filter, mirroring the Ceremony/Category/entity composers. This contract
 * holds the block keys, their order, the single filter call, and the branch
 * boundaries so a consumer (the theme's /oscars/ portal drops two duplicate
 * blocks) can rely on the shape.
 *
 * Run: php tests/landing-section-composer-contract.php
 */

$root = dirname(__DIR__);
$path = $root . '/templates/table-display.php';
$failures = array();
$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$template = is_file($path) ? file_get_contents($path) : '';
$assert(is_string($template) && $template !== '', 'templates/table-display.php should be readable.');

$branch_start = strpos($template, "<?php if (\$layout === 'full' && !\$autoload_table) : ?>");
$assert($branch_start !== false, 'The landing branch guard must remain the composer boundary.');

$landing = $branch_start === false ? '' : substr($template, $branch_start);
// The branch closes at the first else-branch AFTER the landing footer; earlier
// else-branches belong to the poster fallbacks inside Poster Highlights.
$footer_marker = strpos($landing, '<div class="aat-footer aat-database-landing-footer">');
$else_pos = $footer_marker === false ? false : strpos($landing, '<?php else : ?>', $footer_marker);
$assert($else_pos !== false, 'The landing branch must still end at the explorer else-branch after the landing footer.');
$landing_branch = $else_pos === false ? $landing : substr($landing, 0, $else_pos);

preg_match_all("/\\\$aat_landing_sections\\['([a-z-]+)'\\]\\s*=\\s*ob_get_clean\\(\\)/", $landing_branch, $matches);
$keys = isset($matches[1]) ? $matches[1] : array();
$expected = array('landing-header', 'landing-metrics', 'ceremony-marquee', 'poster-highlights', 'winner-circle', 'landing-footer');
$assert($keys === $expected, 'Landing composer keys must be captured in order: ' . implode(', ', $expected) . '; found: ' . implode(', ', $keys) . '.');

$assert(substr_count($landing_branch, "\$aat_landing_sections = array();") === 1, 'The landing composer must initialise its section map exactly once.');
$assert(substr_count($landing_branch, "apply_filters('aat_landing_route_sections', \$aat_landing_sections, \$aat_landing_context)") === 1, 'The landing composer must emit through aat_landing_route_sections exactly once.');
$assert(substr_count($template, 'aat_landing_route_sections') === 2, 'aat_landing_route_sections must appear only in the composer comment and the emit line.');

$open_pos = strpos($landing_branch, "\$aat_landing_sections = array();");
$emit_pos = strpos($landing_branch, "apply_filters('aat_landing_route_sections'");
$header_pos = strpos($landing_branch, '<div class="aat-header aat-database-landing-header aat-ledger-command">');
$footer_pos = strpos($landing_branch, '<div class="aat-footer aat-database-landing-footer">');
$assert($open_pos !== false && $header_pos !== false && $open_pos < $header_pos, 'The composer must open before the landing header block.');
$assert($emit_pos !== false && $footer_pos !== false && $footer_pos < $emit_pos, 'The composer must emit after the landing footer block.');

// Each duplicate-prone block keeps its wrapper class so a consumer can target it by key and a reader can find it by class.
foreach (array(
    'ceremony-marquee'  => '<section class="aat-hub-section aat-ceremony-marquee">',
    'poster-highlights' => '<div class="aat-hub-section aat-ceremony-gallery-section">',
    'winner-circle'     => '<div class="aat-hub-section aat-winner-circle-section is-hero-latest is-marquee-latest">',
) as $key => $marker) {
    $capture = strpos($landing_branch, "\$aat_landing_sections['{$key}']");
    $marker_pos = strpos($landing_branch, $marker);
    $assert($marker_pos !== false && $capture !== false && $marker_pos < $capture, "The {$key} block must render inside its own capture.");
}

// Captures must balance: one ob_start() before each capture, none left open at the emit.
$assert(substr_count($landing_branch, 'ob_start();') === substr_count($landing_branch, 'ob_get_clean();'), 'Every landing capture must open and close exactly once.');

if (!empty($failures)) {
    fwrite(STDERR, "Landing section composer contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Landing section composer contract passed.\n";
