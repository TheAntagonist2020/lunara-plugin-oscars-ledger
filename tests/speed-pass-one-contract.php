<?php
/**
 * 2.8.12 speed pass one: longer cache life for anonymous Oscars routes, a boot
 * probe, and no wasted per-request work in the render path.
 *
 * Run: php tests/speed-pass-one-contract.php
 */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$entity = file_get_contents($root . '/templates/entity-page.php');
$hub = file_get_contents($root . '/templates/hub-page.php');
$fail = array();
$check = function ($ok, $msg) use (&$fail) { if (!$ok) { $fail[] = $msg; } };

$check(strpos($plugin, "define('AAT_BOOT_MS'") !== false, 'The boot probe is recorded when the plugin loads.');
$check(strpos($plugin, 'const VIRTUAL_ROUTE_MAX_AGE = 900;') !== false, 'Anonymous Oscars routes keep a 900s cache life.');
$check(strpos($plugin, "header('Cache-Control: public, max-age=' . \$max_age)") !== false && strpos($plugin, "apply_filters('aat_virtual_route_max_age'") !== false, 'The cache life is sent as Cache-Control and is filterable.');
$check(strpos($plugin, 'if (is_user_logged_in()) {') !== false, 'Logged-in visitors are not sent the long cache life.');
$check(strpos($plugin, "header('Server-Timing: aat-boot;dur='") !== false, 'Server-Timing reports aat-boot and aat-route.');

// Portrait resolution checks its caches before the uncached explicit join.
$fn = substr($plugin, strpos($plugin, 'private function resolve_profile_attachment_for_person('), 4000);
$check(strpos($fn, 'get_transient($cache_key)') < strpos($fn, 'find_existing_person_portrait_attachment('), 'Portrait caches are read before the explicit portrait query.');

// The projection self-check runs only on a cache miss.
$fy = substr($plugin, strpos($plugin, 'public function get_ceremony_year('), 1500);
$check(strpos($fy, 'get_transient($cache_key)') < strpos($fy, 'ensure_projection_data_available()'), 'get_ceremony_year reads its cache before the COUNT(*) self-check.');
$fm = substr($plugin, strpos($plugin, 'public function get_max_ceremony('), 800);
$check(strpos($fm, "get_transient('aat_max_ceremony_v1')") < strpos($fm, 'ensure_projection_data_available()'), 'get_max_ceremony reads its cache before the COUNT(*) self-check.');

// Title context remembers a miss.
$ft = substr($plugin, strpos($plugin, 'public function get_title_context_for_imdb_id('), 1500);
$check(strpos($ft, 'set_transient($cache_key, $empty, HOUR_IN_SECONDS)') !== false, 'A title with no row is remembered instead of rescanned.');

// Excerpts that were never printed are no longer built.
$check(strpos($entity . $hub, 'get_the_excerpt(') === false, 'No template builds an excerpt it never prints.');

if ($fail) { fwrite(STDERR, implode("\n", $fail) . "\n"); exit(1); }
echo "Speed pass one contract OK.\n";
