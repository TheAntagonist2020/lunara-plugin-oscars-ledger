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

$premium_map_start = strpos($hub_template, '$premium_category_profiles = array(');
$premium_map_end = $premium_map_start !== false ? strpos($hub_template, '$premium_category_profile = isset', $premium_map_start) : false;
$premium_map_source = ($premium_map_start !== false && $premium_map_end !== false)
    ? substr($hub_template, $premium_map_start, $premium_map_end - $premium_map_start)
    : '';

$premium_profile_keys = array();
if ($premium_map_source !== '') {
    preg_match_all("/^\\s*'([^']+)'\\s*=>\\s*array\\(/m", $premium_map_source, $premium_matches);
    $premium_profile_keys = array_fill_keys(array_map('strtoupper', $premium_matches[1] ?? array()), true);
}

$bundled_category_keys = array();
$csv_path = $root . '/data/oscars.csv';
$csv = fopen($csv_path, 'r');
if ($csv) {
    $headers = fgetcsv($csv, 0, "\t");
    $canonical_index = is_array($headers) ? array_search('CanonicalCategory', $headers, true) : false;
    if ($canonical_index !== false) {
        while (($row = fgetcsv($csv, 0, "\t")) !== false) {
            $canonical = strtoupper(trim((string) ($row[$canonical_index] ?? '')));
            if ($canonical !== '') {
                $bundled_category_keys[$canonical] = true;
            }
        }
    }
    fclose($csv);
}

$missing_premium_profiles = array_values(array_diff(array_keys($bundled_category_keys), array_keys($premium_profile_keys)));
sort($missing_premium_profiles);

$assert(strpos($plugin, 'public function get_name_entity_link_by_label($label)') !== false, 'Plugin should expose a label-to-name-entity resolver.');
$assert(strpos($plugin, "'MUSIC (Original Song Score or Adaptation Score)' => 'Song Score and Adaptation Score'") !== false, 'Legacy song-score category should expose a polished display label.');
$assert(strpos($plugin, "'CASTING' => 'Casting'") !== false, 'Casting should expose a polished display label.');
$assert(strpos($plugin, "'DIRECTING (Dramatic Picture)' => 'Dramatic Picture Directing'") !== false, 'Dramatic Picture Directing should expose a polished display label.');
$assert(strpos($plugin, "'DIRECTING (Comedy Picture)' => 'Comedy Picture Directing'") !== false, 'Comedy Picture Directing should expose a polished display label.');
$assert(strpos($plugin, "'UNIQUE AND ARTISTIC PICTURE' => 'Unique and Artistic Picture'") !== false, 'Unique and Artistic Picture should expose a polished display label.');
$assert(strpos($plugin, "'ASSISTANT DIRECTOR' => 'Assistant Director'") !== false, 'Assistant Director should expose a polished display label.');
$assert(strpos($plugin, "'DANCE DIRECTION' => 'Dance Direction'") !== false, 'Dance Direction should expose a polished display label.');
$assert(strpos($plugin, "'HONORARY AWARD' => 'Honorary Award'") !== false, 'Honorary Award should expose a polished display label.');
$assert(strpos($plugin, "'SPECIAL ACHIEVEMENT AWARD (Visual Effects)' => 'Special Achievement Award: Visual Effects'") !== false, 'Special Achievement Award visual-effects branch should expose a polished display label.');
$assert(strpos($plugin, "'SCIENTIFIC AND TECHNICAL AWARD (Technical Achievement Award)' => 'Scientific and Technical Award: Technical Achievement Award'") !== false, 'Technical Achievement Award should expose a polished display label.');
$assert(strpos($plugin, "'SCIENTIFIC OR TECHNICAL AWARD (Class I)' => 'Scientific or Technical Award: Class I'") !== false, 'Class I SciTech award should expose a polished display label.');
$assert(strpos($plugin, "'WRITING (Original Story)' => 'Original Story'") !== false, 'Original Story category should expose a polished display label.');
$assert(strpos($plugin, "'WRITING (Title Writing)' => 'Title Writing'") !== false, 'Title Writing category should expose a polished display label.');
$assert(strpos($plugin, "'SHORT SUBJECT (Comedy)' => 'Comedy Short Subject'") !== false, 'Comedy Short Subject should expose a polished display label.');
$assert(strpos($plugin, "'SHORT SUBJECT (Novelty)' => 'Novelty Short Subject'") !== false, 'Novelty Short Subject should expose a polished display label.');
$assert(strpos($plugin, "'SHORT SUBJECT (Color)' => 'Color Short Subject'") !== false, 'Color Short Subject should expose a polished display label.');
$assert(strpos($plugin, "'SHORT SUBJECT (One-reel)' => 'One-reel Short Subject'") !== false, 'One-reel Short Subject should expose a polished display label.');
$assert(strpos($plugin, "'SHORT SUBJECT (Two-reel)' => 'Two-reel Short Subject'") !== false, 'Two-reel Short Subject should expose a polished display label.');
$assert(strpos($hub_template, '$aat_build_person_link_items') !== false, 'Hub template should build linked person chip items.');
$assert(strpos($hub_template, 'aat-inner-route-system') !== false, 'Category shell should expose the shared inner route system hook.');
$assert(strpos($hub_template, 'aat-generic-category-dossier') !== false, 'Non-premium categories should have a dossier-grade hook.');
$assert(strpos($hub_template, '$hub_id_breadcrumb_label') !== false, 'Hub breadcrumbs should use polished display labels instead of raw route IDs.');
$assert(strpos($hub_template, '$aat_humanize_category_label') !== false, 'Hub template should humanize all-caps category labels.');
$assert(strpos($hub_template, '$hub_id_breadcrumb_label = $aat_humanize_category_label($aat->format_category_display($breadcrumb_category));') !== false, 'Category breadcrumbs should resolve slugs into humanized category display labels.');
$assert(strpos($hub_template, '$aat->ordinal((int) $hub_id)') !== false, 'Ceremony breadcrumbs should render ordinal ceremony labels.');
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
$assert(strpos($hub_template, "'SOUND RECORDING' => array(") !== false, 'Sound Recording should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Sound Recording Dossier') !== false, 'Sound Recording should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-sound-recording-dossier') !== false, 'Sound Recording should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SOUND EDITING' => array(") !== false, 'Sound Editing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Sound Editing Dossier') !== false, 'Sound Editing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-sound-editing-dossier') !== false, 'Sound Editing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'ASSISTANT DIRECTOR' => array(") !== false, 'Assistant Director should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Assistant Director Dossier') !== false, 'Assistant Director should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-assistant-director-dossier') !== false, 'Assistant Director should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'DANCE DIRECTION' => array(") !== false, 'Dance Direction should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Dance Direction Dossier') !== false, 'Dance Direction should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-dance-direction-dossier') !== false, 'Dance Direction should expose a route-scoped visual hook.');
$assert(strpos($hub_template, 'aat-archive-specials-dossier') !== false, 'Archive-specials routes should expose a shared visual-system hook.');
$assert(strpos($hub_template, "'HONORARY AWARD' => array(") !== false, 'Honorary Award should be promoted into the archive-specials dossier map.');
$assert(strpos($hub_template, 'Honorary Award Dossier') !== false, 'Honorary Award should have an archive-specials dossier heading.');
$assert(strpos($hub_template, 'aat-honorary-award-dossier') !== false, 'Honorary Award should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SCIENTIFIC AND TECHNICAL AWARD (TECHNICAL ACHIEVEMENT AWARD)' => array(") !== false, 'Technical Achievement Award should be promoted into the archive-specials dossier map.');
$assert(strpos($hub_template, 'Scientific and Technical Award: Technical Achievement Award Dossier') !== false, 'Technical Achievement Award should have an archive-specials dossier heading.');
$assert(strpos($hub_template, 'aat-scitech-technical-achievement-dossier') !== false, 'Technical Achievement Award should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SPECIAL ACHIEVEMENT AWARD (VISUAL EFFECTS)' => array(") !== false, 'Special Achievement Visual Effects should be promoted into the archive-specials dossier map.');
$assert(strpos($hub_template, 'aat-special-achievement-visual-effects-dossier') !== false, 'Special Achievement Visual Effects should expose a route-scoped visual hook.');
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
$assert(strpos($hub_template, "'MUSIC (ORIGINAL SONG SCORE OR ADAPTATION SCORE)' => array(") !== false, 'Song Score and Adaptation Score should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Song Score and Adaptation Score Dossier') !== false, 'Song Score and Adaptation Score should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-music-adaptation-score-dossier') !== false, 'Song Score and Adaptation Score should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'CASTING' => array(") !== false, 'Casting should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Casting Dossier') !== false, 'Casting should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-casting-dossier') !== false, 'Casting should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'DIRECTING (DRAMATIC PICTURE)' => array(") !== false, 'Dramatic Picture Directing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Dramatic Picture Directing Dossier') !== false, 'Dramatic Picture Directing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-directing-dramatic-picture-dossier') !== false, 'Dramatic Picture Directing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'DIRECTING (COMEDY PICTURE)' => array(") !== false, 'Comedy Picture Directing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Comedy Picture Directing Dossier') !== false, 'Comedy Picture Directing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-directing-comedy-picture-dossier') !== false, 'Comedy Picture Directing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'UNIQUE AND ARTISTIC PICTURE' => array(") !== false, 'Unique and Artistic Picture should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Unique and Artistic Picture Dossier') !== false, 'Unique and Artistic Picture should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-unique-artistic-picture-dossier') !== false, 'Unique and Artistic Picture should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'WRITING (ORIGINAL STORY)' => array(") !== false, 'Original Story should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Original Story Dossier') !== false, 'Original Story should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-writing-original-story-dossier') !== false, 'Original Story should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'WRITING (TITLE WRITING)' => array(") !== false, 'Title Writing should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Title Writing Dossier') !== false, 'Title Writing should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-writing-title-writing-dossier') !== false, 'Title Writing should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT SUBJECT (COMEDY)' => array(") !== false, 'Comedy Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Comedy Short Subject Dossier') !== false, 'Comedy Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-subject-comedy-dossier') !== false, 'Comedy Short Subject should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT SUBJECT (NOVELTY)' => array(") !== false, 'Novelty Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Novelty Short Subject Dossier') !== false, 'Novelty Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-subject-novelty-dossier') !== false, 'Novelty Short Subject should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT SUBJECT (COLOR)' => array(") !== false, 'Color Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Color Short Subject Dossier') !== false, 'Color Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-subject-color-dossier') !== false, 'Color Short Subject should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT SUBJECT (ONE-REEL)' => array(") !== false, 'One-reel Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'One-reel Short Subject Dossier') !== false, 'One-reel Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-subject-one-reel-dossier') !== false, 'One-reel Short Subject should expose a route-scoped visual hook.');
$assert(strpos($hub_template, "'SHORT SUBJECT (TWO-REEL)' => array(") !== false, 'Two-reel Short Subject should be promoted into the premium category dossier map.');
$assert(strpos($hub_template, 'Two-reel Short Subject Dossier') !== false, 'Two-reel Short Subject should have a premium dossier heading.');
$assert(strpos($hub_template, 'aat-short-subject-two-reel-dossier') !== false, 'Two-reel Short Subject should expose a route-scoped visual hook.');
$assert(count($bundled_category_keys) >= 60, 'Bundled Oscars CSV should expose the canonical category set.');
$assert(count($missing_premium_profiles) === 0, 'Every bundled category should resolve to a premium dossier profile. Missing: ' . implode(', ', $missing_premium_profiles));
$assert(strpos($hub_template, 'aat_category_era_visual_limit') !== false, 'Premium category era browser should expose a bounded visual-density filter.');
$assert(strpos($hub_template, 'aat-era-chapter-media-grid') !== false, 'Premium category era browser should render verified visuals as a poster grid.');
$assert(strpos($hub_template, 'count($era_spotlights) >= $era_visual_limit') !== false, 'Premium category era browser should collect more than one verified visual when available.');
$assert(strpos($css, '.aat-era-chapter-media-grid') !== false, 'Premium category era poster grid should be styled in the public stylesheet.');
$assert(strpos($css, 'grid-template-columns: repeat(4, minmax(0, 1fr))') !== false, 'Premium category era poster grid should become a dense mobile strip.');

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
