<?php

$root = dirname(__DIR__);
$files = array(
    'templates/hub-page.php',
    'templates/entity-page.php',
);

$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ($files as $relative_path) {
    $path = $root . '/' . $relative_path;
    $source = file_get_contents($path);

    $assert(is_string($source) && $source !== '', "{$relative_path} should be readable.");
    $assert(strpos($source, 'aat-related-review-card') !== false, "{$relative_path} should render related-review cards.");
    $assert(strpos($source, 'has-media') !== false, "{$relative_path} should mark related-review cards that have media.");
    $assert(strpos($source, 'has-no-media') !== false, "{$relative_path} should mark related-review cards without media.");
    $assert(strpos($source, 'aat_related_review_has_media') !== false, "{$relative_path} should compute a related-review media guard before rendering media.");

    preg_match_all('/<a class="aat-related-review-media".*?<\/a>/s', $source, $media_blocks);
    foreach ($media_blocks[0] as $index => $media_block) {
        $assert(
            strpos($media_block, 'aat-filmography-poster-placeholder') === false,
            "{$relative_path} media block {$index} should not render a label-only poster placeholder."
        );
    }
}

$css = file_get_contents($root . '/assets/css/academy-awards-table.css');
$assert(strpos($css, '.aat-related-review-card.has-no-media') !== false, 'CSS should style text-led related-review cards.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Related review media guards OK.\n";
