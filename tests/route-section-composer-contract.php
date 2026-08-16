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

$source = array();
foreach ($files as $relative_path) {
    $path = $root . '/' . $relative_path;
    $source[$relative_path] = is_file($path) ? file_get_contents($path) : false;
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$hub_template = is_string($source['templates/hub-page.php']) ? $source['templates/hub-page.php'] : '';
$entity_template = is_string($source['templates/entity-page.php']) ? $source['templates/entity-page.php'] : '';

$branch_slice = function ($haystack, $start, $end) {
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

$section_keys = function ($haystack) {
    preg_match_all("/\\\$aat_sections\\['([a-z-]+)'\\]\\s*=\\s*ob_get_clean\\(\\)/", $haystack, $matches);
    return isset($matches[1]) ? $matches[1] : array();
};

// Both composer filters must exist, applied over the collected section map
// with the route context as the second argument.
$assert(substr_count($hub_template, "apply_filters('aat_hub_route_sections', \$aat_sections, \$aat_route_context)") === 2, 'Hub template should apply aat_hub_route_sections in exactly the ceremony and category branches.');
$assert(substr_count($entity_template, "apply_filters('aat_entity_route_sections', \$aat_sections, \$aat_route_context)") === 1, 'Entity template should apply aat_entity_route_sections exactly once.');
$assert(strpos($hub_template, "implode('', apply_filters('aat_hub_route_sections'") !== false, 'Hub composer should re-emit sections via implode so default output stays byte-identical.');
$assert(strpos($entity_template, "implode('', apply_filters('aat_entity_route_sections'") !== false, 'Entity composer should re-emit sections via implode so default output stays byte-identical.');
$assert(strpos($hub_template, '$aat_route_context = $aat->get_route_context()') !== false, 'Hub composer should derive its route context from the plugin route helper.');
$assert(strpos($entity_template, '$aat_route_context = $aat->get_route_context()') !== false, 'Entity composer should derive its route context from the plugin route helper.');

// Ceremony branch: default section-key order must match the current template order.
$ceremony_branch = $branch_slice($hub_template, "elseif (\$hub === 'ceremony') :", "elseif (\$hub === 'category') :");
$assert($ceremony_branch !== '', 'Ceremony hub branch should be discoverable.');
$expected_ceremony_sections = array(
    'dossier-hero',
    'neighbor-nav',
    'editorial-writeup',
    'thesis',
    'marquee',
    'best-picture-nominees',
    'stats-bar',
    'major-races',
    'ballot-ledger',
    'gallery',
    'related-reviews',
    'category-chips',
    'winner-circle',
    'explorer-callout',
    'table-shell',
);
$ceremony_sections = $section_keys($ceremony_branch);
$assert($ceremony_sections === $expected_ceremony_sections, 'Ceremony composer sections should keep the current template order: ' . implode(', ', $expected_ceremony_sections) . ' (got: ' . implode(', ', $ceremony_sections) . ').');
$assert(strpos($ceremony_branch, "\$aat_sections = array(); \$aat_route_context = \$aat->get_route_context(); ob_start();") !== false, 'Ceremony composer should initialize its section map before the first section.');

// Category branch: default section-key order must match the current template order.
$category_branch = $branch_slice($hub_template, "elseif (\$hub === 'category') :", '// Unknown hub');
$assert($category_branch !== '', 'Category hub branch should be discoverable.');
$expected_category_sections = array(
    'hero',
    'latest-winner',
    'stats-bar',
    'history',
    'gallery',
    'related-reviews',
    'explorer-callout',
    'table-shell',
);
$category_sections = $section_keys($category_branch);
$assert($category_sections === $expected_category_sections, 'Category composer sections should keep the current template order: ' . implode(', ', $expected_category_sections) . ' (got: ' . implode(', ', $category_sections) . ').');
$assert(strpos($category_branch, "\$aat_sections = array(); \$aat_route_context = \$aat->get_route_context(); ob_start();") !== false, 'Category composer should initialize its section map before the first section.');

// Index and About branches must stay out of the composer.
$ceremonies_index_branch = $branch_slice($hub_template, "if (\$hub === 'ceremonies') :", "elseif (\$hub === 'ceremony') :");
$assert($ceremonies_index_branch !== '', 'Hub index/about branches should be discoverable.');
$assert(strpos($ceremonies_index_branch, '$aat_sections') === false, 'Ceremonies/categories index and About branches must not run the section composer.');

// Entity template: default section-key order must match the current template order.
$expected_entity_sections = array(
    'breadcrumbs',
    'hero',
    'latest-result',
    'stats-bar',
    'crossroads',
    'review-module',
    'oscar-history',
    'filmography',
    'related-reviews',
    'footer',
);
$entity_sections = $section_keys($entity_template);
$assert($entity_sections === $expected_entity_sections, 'Entity composer sections should keep the current template order: ' . implode(', ', $expected_entity_sections) . ' (got: ' . implode(', ', $entity_sections) . ').');

// Composer paths must stay anonymous and cache-safe: no nonces, no cookies,
// and no user-conditional output anywhere in either template.
foreach (array(
    'wp_create_nonce',
    'wp_nonce_field',
    'setcookie',
    '$_COOKIE',
    'is_user_logged_in',
    'wp_get_current_user',
) as $forbidden) {
    $assert(strpos($hub_template, $forbidden) === false, "Hub composer paths must not use {$forbidden}.");
    $assert(strpos($entity_template, $forbidden) === false, "Entity composer paths must not use {$forbidden}.");
}

// Every capture boundary must balance: one ob_start per collected section.
foreach (array('templates/hub-page.php' => $hub_template, 'templates/entity-page.php' => $entity_template) as $label => $template) {
    $assert(substr_count($template, 'ob_start();') === substr_count($template, 'ob_get_clean()'), "{$label} composer output buffers should be balanced.");
}

// The 404 fallbacks stay non-cacheable and untouched by the composer.
$assert(strpos($hub_template, 'status_header(404)') !== false, 'Hub 404 handling should remain intact.');
$assert(strpos($hub_template, 'nocache_headers()') !== false, 'Hub 404 responses should remain non-cacheable.');
$assert(strpos($entity_template, 'status_header(404)') !== false, 'Entity 404 handling should remain intact.');
$assert(strpos($entity_template, 'nocache_headers()') !== false, 'Entity 404 responses should remain non-cacheable.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscar route section composer contract OK.\n";
