<?php
/**
 * 2.8.13 speed pass two: cheaper misses, and pages that are safe to cache.
 *
 * Run: php tests/speed-pass-two-contract.php
 */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$api = file_get_contents($root . '/includes/class-aat-read-api.php');
$entity = file_get_contents($root . '/templates/entity-page.php');
$hub = file_get_contents($root . '/templates/hub-page.php');
$fail = array();
$check = function ($ok, $msg) use (&$fail) { if (!$ok) { $fail[] = $msg; } };

// Links never echo the visitor's own query string into a cacheable page.
$check(preg_match('/(add|remove)_query_arg\(\s*\'[a-z]+\'(\s*,\s*\'[a-z]+\')?\s*\)/', $hub . $entity) === 0, 'Every add_query_arg/remove_query_arg in the Oscars templates has a canonical base URL.');
$check(strpos($hub, "add_query_arg('ledger', 'full', \$aat->get_ceremony_url(\$ceremony))") !== false, 'The full-ballot link is built from the ceremony URL.');

// Ceremonies past the last one on record are 404s.
$check(strpos($hub, '$ceremony > $aat_max_ceremony') !== false, 'A ceremony number beyond the maximum is a 404.');

// The category ledger no longer builds a review map nobody reads.
$fl = substr($plugin, strpos($plugin, 'public function get_category_decade_ledger('), 12000);
$check(strpos($fl, 'get_review_permalink_map_for_title_ids') === false, 'The category ledger does no review lookups.');

// Review cards resolve images only for the cards that are shown.
$fc = substr($hub, strpos($hub, '$aat_build_hub_review_cards = function'), 6000);
$check(strpos($fc, 'array_slice($cards, 0, $limit)') < strpos($fc, 'get_title_visual_package'), 'Review-card images are built after the cut to the limit.');

// Read-API answers survive deploys that do not change their shape.
$check(strpos($api, 'const RESPONSE_SCHEMA = 1;') !== false && strpos($api, 'array(self::RESPONSE_SCHEMA, self::stamp(), $labels, $parts)') !== false, 'The read-API cache key uses RESPONSE_SCHEMA, not AAT_VERSION.');
$check(strpos($api, "\$data['plugin_version'] = AAT_VERSION;") !== false, '/status always reports the running plugin version.');

if ($fail) { fwrite(STDERR, implode("\n", $fail) . "\n"); exit(1); }
echo "Speed pass two contract OK.\n";
