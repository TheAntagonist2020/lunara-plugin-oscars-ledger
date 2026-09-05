<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
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
    $source[$relative_path] = is_file($path) ? file_get_contents($path) : false;
    $assert(is_string($source[$relative_path]) && $source[$relative_path] !== '', "{$relative_path} should be readable.");
}

$plugin = is_string($source['academy-awards-table.php']) ? $source['academy-awards-table.php'] : '';
$docs = implode("\n", array_filter(array(
    is_string($source['README.md']) ? $source['README.md'] : '',
    is_string($source['readme.txt']) ? $source['readme.txt'] : '',
)));

$method_slice = function ($haystack, $start, $end) {
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

foreach (array(
    'Version: 2.7.83',
    "define('AAT_VERSION', '2.7.83')",
    'Stable tag: 2.7.83',
    'Current baseline: `2.7.83`',
) as $needle) {
    $assert(stripos($plugin . $docs, $needle) !== false, "Version marker should exist: {$needle}");
}

// The four public read-path accessors must exist.
$reviewed_ids = $method_slice($plugin, 'public function get_reviewed_award_title_post_ids(', 'public function get_title_award_context(');
$title_context = $method_slice($plugin, 'public function get_title_award_context(', 'public function get_category_first_ceremony(');
$category_debut = $method_slice($plugin, 'public function get_category_first_ceremony(', 'public function get_route_context(');
$route_context = $method_slice($plugin, 'public function get_route_context(', 'private function clear_oscars_read_api_caches(');
$invalidator = $method_slice($plugin, 'private function clear_oscars_read_api_caches(', 'private function build_cache_hash(');

$assert(strpos($plugin, 'public function get_reviewed_award_title_post_ids($limit = 12, $offset = 0)') !== false, 'get_reviewed_award_title_post_ids( $limit, $offset ) should be a public accessor.');
$assert(strpos($plugin, 'public function get_title_award_context($imdb_id, $args = array())') !== false, 'get_title_award_context( $imdb_id, $args ) should be a public accessor.');
$assert(strpos($plugin, 'public function get_category_first_ceremony($canonical_category)') !== false, 'get_category_first_ceremony( $canonical_category ) should be a public accessor.');
$assert(strpos($plugin, 'public function get_route_context()') !== false, 'get_route_context() should be a public accessor.');
$assert($invalidator !== '', 'Read-API cache invalidator should be discoverable.');

// Reviewed-posts accessor: prepared join over the review filters, pooled
// transient cache, no hard-coded post type or meta key.
$assert(strpos($reviewed_ids, 'query_reviewed_award_title_post_ids') !== false, 'Reviewed-posts accessor should delegate to the prepared join.');
$reviewed_query = $method_slice($plugin, 'private function query_reviewed_award_title_post_ids(', 'public function get_title_award_context(');
$assert(strpos($reviewed_query, '$wpdb->prepare(') !== false, 'Reviewed-posts join must go through $wpdb->prepare.');
$assert(strpos($reviewed_query, '$this->get_review_post_type()') !== false, 'Reviewed-posts join should build on the aat_review_post_type filter.');
$assert(strpos($reviewed_query, '$this->get_review_imdb_meta_key()') !== false, 'Reviewed-posts join should build on the aat_review_imdb_meta_key filter.');
$assert(strpos($reviewed_query, "p.post_status = 'publish'") !== false, 'Reviewed-posts join should stay limited to published posts.');
$assert(strpos($reviewed_query, "aa.film_id != ''") !== false, 'Reviewed-posts join should ignore empty award film IDs.');
$assert(strpos($reviewed_query, 'ORDER BY p.post_date DESC') !== false, 'Reviewed-posts join should keep the theme newest-first ordering.');
$assert(strpos($reviewed_ids, "'aat_reviewed_award_post_ids_v1'") !== false, 'Reviewed-posts accessor should use its pooled transient key.');
$assert(strpos($reviewed_ids, 'get_transient($cache_key)') !== false, 'Reviewed-posts accessor should read its pooled transient.');
$assert(strpos($reviewed_ids, "set_transient(\$cache_key, \$pool, 12 * HOUR_IN_SECONDS)") !== false, 'Reviewed-posts pool should be cached for ~12h.');

// Title award context: prepared per-title query, transient cached ~12h.
$assert(strpos($title_context, '$wpdb->prepare(') !== false, 'Title award context must go through $wpdb->prepare.');
$assert(strpos($title_context, "aat_title_award_context_v1_") !== false, 'Title award context should use its versioned transient key.');
$assert(strpos($title_context, 'get_transient($cache_key)') !== false, 'Title award context should read from its transient.');
$assert(strpos($title_context, 'set_transient($cache_key, $rows, 12 * HOUR_IN_SECONDS)') !== false, 'Title award context rows should be cached for ~12h.');
$assert(strpos($title_context, "ORDER BY ceremony DESC, winner DESC, canonical_category ASC") !== false, 'Title award context should keep the theme ordering.');
$assert(strpos($title_context, "'preferred_categories'") !== false, 'Title award context should honor preferred_categories.');
$assert(strpos($title_context, "'max_categories'") !== false, 'Title award context should honor max_categories.');

// Category debut: prepared MIN(ceremony) query, transient cached ~12h.
$assert(strpos($category_debut, '$wpdb->prepare(') !== false, 'Category debut lookup must go through $wpdb->prepare.');
$assert(strpos($category_debut, 'SELECT MIN(ceremony) FROM $table_name WHERE canonical_category = %s') !== false, 'Category debut lookup should port the MIN(ceremony) query.');
$assert(strpos($category_debut, "get_transient(\$cache_key)") !== false, 'Category debut lookup should read from its transient map.');
$assert(strpos($category_debut, 'set_transient($cache_key, $map, 12 * HOUR_IN_SECONDS)') !== false, 'Category debut map should be cached for ~12h.');
$assert(strpos($category_debut, "'aat_category_first_ceremony_v1'") !== false, 'Category debut map should use its versioned transient key.');

// Route context: already-sanitized aat_* query vars only, no raw request parsing.
$assert(strpos($route_context, "get_query_var('aat_entity')") !== false, 'Route context should read the sanitized aat_entity query var.');
$assert(strpos($route_context, "get_query_var('aat_hub')") !== false, 'Route context should read the sanitized aat_hub query var.');
foreach (array('portal', 'ceremony', 'category', 'title', 'person', 'company', 'none') as $kind) {
    $assert(strpos($route_context, "'kind' => '{$kind}'") !== false, "Route context should be able to report kind '{$kind}'.");
}
$assert(strpos($route_context, "array('ceremonies', 'categories', 'about')") !== false && strpos($route_context, "'kind' => \$hub") !== false, 'Route context should report the ceremonies/categories/about index kinds.');
foreach (array('$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE') as $superglobal) {
    $assert(strpos($route_context, $superglobal) === false, "Route context must not re-parse raw request data via {$superglobal}.");
}
$assert(strpos($route_context, '$wpdb') === false, 'Route context should not run database queries.');

// Invalidation: the read-API caches are dropped alongside every hub
// transient invalidation site.
$assert(strpos($invalidator, "delete_transient('aat_reviewed_award_post_ids_v1')") !== false, 'Invalidator should drop the reviewed-posts pool.');
$assert(strpos($invalidator, "delete_transient('aat_category_first_ceremony_v1')") !== false, 'Invalidator should drop the category debut map.');
$assert(strpos($invalidator, "_transient_aat_title_award_context_v1_") !== false, 'Invalidator should sweep the per-title award context transients.');
$hub_invalidation_sites = substr_count($plugin, "delete_transient('aat_hub_ceremony_grid_v2');");
$paired_sites = substr_count($plugin, "delete_transient('aat_hub_category_grid_v2');\n            \$this->clear_oscars_read_api_caches();")
    + substr_count($plugin, "delete_transient('aat_hub_category_grid_v2');\n        \$this->clear_oscars_read_api_caches();");
$assert($hub_invalidation_sites > 0, 'Hub grid invalidation sites should exist.');
$assert($paired_sites === $hub_invalidation_sites, 'Every hub grid invalidation site should also clear the read-API caches.');
$assert(substr_count($plugin, '$this->clear_oscars_read_api_caches();') >= $hub_invalidation_sites + 1, 'The hub-stats-only invalidation site should also clear the read-API caches.');

// The read API must stay inert on default render paths: no nonces or
// cookies anywhere in the new accessor block.
$api_block = $method_slice($plugin, 'public function get_reviewed_award_title_post_ids(', 'private function build_cache_hash(');
foreach (array('wp_create_nonce', 'wp_nonce_field', 'setcookie', '$_COOKIE') as $forbidden) {
    $assert(strpos($api_block, $forbidden) === false, "Read-path API must not use {$forbidden}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscars read-path API contract OK.\n";
