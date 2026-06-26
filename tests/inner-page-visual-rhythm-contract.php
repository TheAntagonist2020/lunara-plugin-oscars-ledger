<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/hub-page.php',
    'assets/css/academy-awards-table.css',
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

$plugin = $source['academy-awards-table.php'];
$hub_template = $source['templates/hub-page.php'];
$css = $source['assets/css/academy-awards-table.css'];

$assert(strpos($plugin, 'public function get_name_entity_link_by_label($label)') !== false, 'Plugin should expose a label-to-name-entity resolver.');
$assert(strpos($hub_template, '$aat_build_person_link_items') !== false, 'Hub template should build linked person chip items.');
$assert(strpos($hub_template, 'aat-inner-route-system') !== false, 'Category shell should expose the shared inner route system hook.');
$assert(strpos($hub_template, 'aat-generic-category-dossier') !== false, 'Non-premium categories should have a dossier-grade hook.');
$assert(strpos($hub_template, 'aat-category-person-strip') !== false, 'Category rows should render a linked person/craft strip.');
$assert(strpos($hub_template, 'aat-category-ceremony-row aat-ledger-card') !== false, 'All category ceremony rows should use ledger-card rhythm.');
$assert(substr_count($hub_template, 'aat-dossier-command-band') >= 2, 'Premium and generic category headers should both render command bands.');
$assert(strpos($hub_template, '$aat_is_department_credit_label') !== false, 'Department-style technical credits should be detected before label fallback linking.');
$assert(strpos($hub_template, '$aat_person_history_action_meta') !== false, 'History actions should resolve company/person/producer labels from the target route.');
$assert(strpos($hub_template, "__('Company History', 'academy-awards-table')") !== false, 'Company history links should not be rendered as Person History.');
$assert(strpos($hub_template, "'company-history'") !== false, 'Company history actions should expose a scoped action kind.');
$assert(strpos($hub_template, 'aat-department-credit-label') !== false, 'Department-style credit text should receive a deliberate public presentation hook.');
$assert(strpos($hub_template, "'SOUND MIXING' => array(") !== false, 'Sound Mixing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Sound Mixing Dossier') !== false, 'Sound Mixing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-sound-mixing-dossier') !== false, 'Sound Mixing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SOUND EDITING' => array(") !== false, 'Sound Editing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Sound Editing Dossier') !== false, 'Sound Editing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-sound-editing-dossier') !== false, 'Sound Editing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'COSTUME DESIGN (BLACK-AND-WHITE)' => array(") !== false, 'Black-and-White Costume Design should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Black-and-White Costume Design Dossier') !== false, 'Black-and-White Costume Design should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-costume-monochrome-dossier') !== false, 'Black-and-White Costume Design should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'COSTUME DESIGN (COLOR)' => array(") !== false, 'Color Costume Design should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Color Costume Design Dossier') !== false, 'Color Costume Design should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-costume-color-dossier') !== false, 'Color Costume Design should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'MAKEUP AND HAIRSTYLING' => array(") !== false, 'Makeup and Hairstyling should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Makeup and Hairstyling Dossier') !== false, 'Makeup and Hairstyling should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-makeup-dossier') !== false, 'Makeup and Hairstyling should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'ART DIRECTION' => array(") !== false, 'Art Direction should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Art Direction Dossier') !== false, 'Art Direction should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-art-direction-dossier') !== false, 'Art Direction should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'ART DIRECTION (BLACK-AND-WHITE)' => array(") !== false, 'Black-and-White Art Direction should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Black-and-White Art Direction Dossier') !== false, 'Black-and-White Art Direction should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-art-direction-monochrome-dossier') !== false, 'Black-and-White Art Direction should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'ART DIRECTION (COLOR)' => array(") !== false, 'Color Art Direction should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Color Art Direction Dossier') !== false, 'Color Art Direction should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-art-direction-color-dossier') !== false, 'Color Art Direction should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT FILM (ANIMATED)' => array(") !== false, 'Animated Short Film should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Animated Short Film Dossier') !== false, 'Animated Short Film should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-film-animated-dossier') !== false, 'Animated Short Film should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT FILM (LIVE ACTION)' => array(") !== false, 'Live Action Short Film should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Live Action Short Film Dossier') !== false, 'Live Action Short Film should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-film-live-action-dossier') !== false, 'Live Action Short Film should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'DOCUMENTARY (SHORT SUBJECT)' => array(") !== false, 'Documentary Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Documentary Short Subject Dossier') !== false, 'Documentary Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-documentary-short-dossier') !== false, 'Documentary Short Subject should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'CINEMATOGRAPHY (BLACK-AND-WHITE)' => array(") !== false, 'Black-and-White Cinematography should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Black-and-White Cinematography Dossier') !== false, 'Black-and-White Cinematography should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-cinematography-monochrome-dossier') !== false, 'Black-and-White Cinematography should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'CINEMATOGRAPHY (COLOR)' => array(") !== false, 'Color Cinematography should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Color Cinematography Dossier') !== false, 'Color Cinematography should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-cinematography-color-dossier') !== false, 'Color Cinematography should expose a route-scoped visual hook.');

foreach (array(
    '.aat-generic-category-dossier',
    '.aat-category-person-strip',
    '.aat-category-person-chip',
    '.aat-inner-route-system',
    '.aat-department-credit-label',
    '.aat-winner-circle-action.is-kind-company-history',
    '@media (max-width: 700px)',
) as $needle) {
    $assert(strpos($css, $needle) !== false, "CSS should define {$needle}.");
}

foreach (array(
    'Generic category dossier final mobile guard',
    '.aat-container .aat-generic-category-dossier .aat-dossier-command-card strong',
    'body .aat-container .aat-category-dossier.aat-generic-category-dossier',
    'overflow-wrap: anywhere !important',
) as $needle) {
    $assert(strpos($css, $needle) !== false, "Generic category mobile guard should include {$needle}.");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Oscars inner page visual rhythm contract OK.\n";
