<?php

$root = dirname(__DIR__);
$files = array(
    'academy-awards-table.php',
    'templates/hub-page.php',
    'templates/entity-page.php',
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
    $source[$relative_path] = file_get_contents($path);
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$assert(strpos($source['academy-awards-table.php'], 'Version: 2.7.31') !== false, 'Plugin header should report 2.7.31.');
$assert(strpos($source['academy-awards-table.php'], "define('AAT_VERSION', '2.7.31')") !== false, 'AAT_VERSION should report 2.7.31.');
$assert(strpos($source['README.md'], 'Current baseline: `2.7.31`') !== false, 'README should report 2.7.31.');
$assert(strpos($source['readme.txt'], 'Stable tag: 2.7.31') !== false, 'readme stable tag should report 2.7.31.');
$assert(strpos($source['readme.txt'], '= 2.7.31 =') !== false, 'readme changelog should include 2.7.31.');

foreach (array('templates/hub-page.php', 'templates/entity-page.php') as $relative_path) {
    $assert(strpos($source[$relative_path], 'aat_get_related_review_limit') !== false, "{$relative_path} should use a bounded related-review limit helper.");
    $assert(strpos($source[$relative_path], 'lunara_oscars_related_reviews_count') !== false, "{$relative_path} should read the related-review count theme mod.");
    $assert(strpos($source[$relative_path], 'lunara_oscars_related_reviews_treatment') !== false, "{$relative_path} should read the related-review treatment theme mod.");
    $assert(strpos($source[$relative_path], 'aat-related-treatment-') !== false, "{$relative_path} should render a related-review treatment class.");
    $assert(strpos($source[$relative_path], 'has-no-media') !== false, "{$relative_path} must preserve the text-led media guard.");
}

$assert(strpos($source['templates/entity-page.php'], 'array_slice($aat_related_reviews, 0, 6)') === false, 'Entity related reviews should no longer hard-limit to 6.');
$assert(substr_count($source['templates/hub-page.php'], '$aat_build_hub_review_cards($') >= 2, 'Hub should still build ceremony and category related-review cards.');
$assert(strpos($source['templates/hub-page.php'], '$aat_build_hub_review_cards($ceremony_titles, $aat_get_related_review_limit())') !== false, 'Ceremony related reviews should use the bounded helper.');
$assert(strpos($source['templates/hub-page.php'], '$aat_build_hub_review_cards($category_titles, $aat_get_related_review_limit())') !== false, 'Category related reviews should use the bounded helper.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Related review studio controls OK.\n";

