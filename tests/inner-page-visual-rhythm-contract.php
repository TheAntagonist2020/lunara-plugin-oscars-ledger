<?php

$root = dirname(__DIR__);

$files = array(
    'academy-awards-table.php',
    'templates/hub-page.php',
    'templates/entity-page.php',
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
$entity_template = $source['templates/entity-page.php'];
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
$assert(strpos($hub_template, "'key'   => 'nominee_rows'") !== false, 'Premium category era browser should use verified nominee visuals to fill remaining era slots.');
$assert(strpos($hub_template, "foreach (\$era_visual_sources as \$era_visual_source)") !== false, 'Premium category era browser should collect winner visuals before nominee field visuals.');
$assert(strpos($hub_template, 'aat-era-chapter-media-label') !== false, 'Premium category era visuals should label winner versus nominee field sources.');
$assert(strpos($hub_template, '$category_scale_class =') !== false, 'Premium category pages should compute a route-scale class from category depth.');
$assert(strpos($hub_template, 'aat-category-scale-marathon') !== false, 'Long-running category pages should expose a marathon scale hook.');
$assert(strpos($hub_template, 'is-count-<?php echo esc_attr((string) $era_visual_count); ?>') !== false, 'Era media grids should expose verified visual-count hooks.');
$assert(strpos($hub_template, '$ceremony_race_highlight_cards') !== false, 'Ceremony pages should build verified major-race visual highlight cards.');
$assert(strpos($hub_template, 'aat-ceremony-race-highlights') !== false, 'Ceremony pages should render a race-highlights visual briefing strip.');
$assert(strpos($hub_template, 'aat-ceremony-race-strip') !== false, 'Ceremony pages should render major-race cards inside a bounded strip.');
$assert(strpos($hub_template, 'aat-ceremony-race-card') !== false, 'Ceremony race highlights should expose card-level hooks.');
$assert(strpos($hub_template, 'is-major-race') !== false, 'Ceremony race highlight cards should identify major-race cards.');
$assert(strpos($hub_template, '$ceremony_pulse_cards') !== false, 'Ceremony pages should build a reader-path pulse board from existing ceremony data.');
$assert(strpos($hub_template, 'aat-ceremony-momentum') !== false, 'Ceremony pages should render a compact momentum module below race highlights.');
$assert(strpos($hub_template, 'aat-ceremony-momentum-grid') !== false, 'Ceremony momentum modules should expose a card grid hook.');
$assert(strpos($hub_template, 'aat-ceremony-momentum-visuals') !== false, 'Ceremony momentum modules should reuse verified race visuals when available.');
$assert(strpos($hub_template, 'id="ceremony-major-races"') !== false, 'Ceremony major-race sections should expose a direct reader-path anchor.');
$assert(strpos($css, '.aat-major-race-feature > .aat-major-race-copy:only-child') !== false, 'Major-race cards without poster media should let winner copy span the full feature width.');
$assert(strpos($css, '.aat-major-race-title .aat-hub-inline-link-title') !== false, 'Major-race winner titles should receive scoped readable wrapping rules.');
$assert(strpos($hub_template, 'id="ceremony-best-picture-nominees"') !== false, 'Ceremony Best Picture nominee sections should expose a direct reader-path anchor.');
$assert(strpos($hub_template, 'aat-ceremony-ledger-command') !== false, 'Ceremony ballot ledgers should expose a command rail before the category run.');
$assert(strpos($hub_template, 'aat-ceremony-ledger-jump-strip') !== false, 'Ceremony ballot ledgers should expose a category jump strip.');
$assert(strpos($hub_template, 'is-ledger-chapter') !== false, 'Ceremony ballot category groups should identify as ledger chapters.');
$assert(strpos($hub_template, 'aat-ceremony-ballot-group-visuals') !== false, 'Ceremony ballot groups should render verified visual interruption when title visuals exist.');
$assert(strpos($hub_template, 'aat-ceremony-ballot-visual') !== false, 'Ceremony ballot visual cards should expose a public hook.');
$assert(strpos($hub_template, 'aat-ceremony-ballot-media') !== false, 'Ceremony ballot rows should expose verified title media when available.');
$assert(strpos($hub_template, 'is-full-ledger') !== false, 'Ceremony full ballot mode should expose a scoped full-ledger hook.');
$assert(strpos($hub_template, 'aat-ceremony-full-ledger-brief') !== false, 'Ceremony full ballot mode should render a research-view command brief.');
$assert(strpos($hub_template, 'aat-ceremony-full-ledger-stat') !== false, 'Ceremony full ballot command briefs should expose compact research stats.');
$assert(strpos($hub_template, 'aat-ceremony-ballot-row-index') !== false, 'Ceremony full ballot rows should expose a scan-friendly row index.');
$assert(strpos($hub_template, 'aat-ceremony-ballot-depth') !== false, 'Ceremony full ballot rows should expose row-depth metadata.');
$assert(strpos($hub_template, '$ceremony_exit_cards') !== false, 'Ceremony pages should build a dedicated visual exit lane from ceremony title highlights.');
$assert(strpos($hub_template, 'aat-ceremony-exit-lane') !== false, 'Ceremony highlights should expose a scoped exit-lane hook.');
$assert(strpos($hub_template, 'aat-ceremony-exit-feature') !== false, 'Ceremony highlights should render a featured visual title card.');
$assert(strpos($hub_template, 'aat-ceremony-exit-feature-media') !== false, 'Ceremony highlights should expose a feature media chamber.');
$assert(strpos($hub_template, 'aat-ceremony-exit-rail') !== false, 'Ceremony highlights should render the remaining title highlights as a visual rail.');
$assert(strpos($hub_template, 'aat-ceremony-exit-card') !== false, 'Ceremony highlight rail cards should expose card-level hooks.');
$assert(strpos($entity_template, '$profile_dossier_cards') !== false, 'Oscar entity pages should build profile dossier cards.');
$assert(strpos($entity_template, 'aat-profile-dossier-strip') !== false, 'Oscar entity pages should render a profile dossier strip.');
$assert(strpos($entity_template, 'aat-profile-dossier-track') !== false, 'Oscar entity pages should render a bounded dossier track.');
$assert(strpos($entity_template, 'is-title-touchpoint') !== false, 'Title entity pages should expose result-card touchpoints.');
$assert(strpos($entity_template, 'is-film-touchpoint') !== false, 'Person and company entity pages should expose related-film touchpoints.');
$assert(strpos($entity_template, 'has-no-media') !== false, 'Profile dossier cards should render intentional text-led states when media is unavailable.');
$assert(strpos($entity_template, '$profile_reader_path_cards') !== false, 'Oscar title/person pages should build reader-path destination cards.');
$assert(strpos($entity_template, 'aat-profile-reader-path') !== false, 'Oscar title/person pages should render a scoped reader-path module.');
$assert(strpos($entity_template, 'aat-profile-reader-path-grid') !== false, 'Oscar title/person reader paths should expose a bounded grid hook.');
$assert(strpos($entity_template, 'aat-profile-reader-path-card') !== false, 'Oscar title/person reader paths should expose card-level hooks.');
$assert(strpos($entity_template, 'is-title-reader-path') !== false, 'Title destination files should expose a scoped title reader-path hook.');
$assert(strpos($entity_template, 'is-person-reader-path') !== false, 'Person destination files should expose a scoped person reader-path hook.');
$assert(strpos($entity_template, '$category_key = strtoupper($cat);') !== false, 'Entity pages should normalize category labels case-insensitively before map lookup.');
$assert(strpos($css, '.aat-era-chapter-media-grid') !== false, 'Premium category era poster grid should be styled in the public stylesheet.');
$assert(strpos($css, 'grid-template-columns: repeat(4, minmax(0, 1fr))') !== false, 'Premium category era poster grid should become a dense mobile strip.');
$assert(strpos($css, '.aat-category-scale-brief') !== false, 'Brief category dossiers should receive scaled-down spacing and command cards.');
$assert(strpos($css, '.aat-category-scale-marathon') !== false, 'Long-running category dossiers should receive denser ledger-card sizing.');
$assert(strpos($css, '.aat-era-chapter-media-grid.is-count-4') !== false, 'Era poster grids should resize intentionally when four verified visuals exist.');
$assert(strpos($css, '.aat-era-chapter-media-label') !== false, 'Era visual source labels should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-race-highlights') !== false, 'Ceremony race-highlights strip should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-race-strip') !== false, 'Ceremony race-highlights strip should have a public layout rule.');
$assert(strpos($css, '.aat-ceremony-race-card') !== false, 'Ceremony race-highlight cards should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-race-media') !== false, 'Ceremony race-highlight media chambers should be explicitly sized.');
$assert(strpos($css, '.aat-ceremony-momentum') !== false, 'Ceremony momentum modules should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-momentum-card') !== false, 'Ceremony momentum cards should have deliberate public styling.');
$assert(strpos($css, '.aat-ceremony-momentum-visuals') !== false, 'Ceremony momentum verified-visual strips should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-momentum-thumb') !== false, 'Ceremony momentum thumbnails should be explicitly sized.');
$assert(strpos($css, '.aat-ceremony-ledger-command') !== false, 'Ceremony ledger command rails should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ledger-jump-strip') !== false, 'Ceremony ledger jump strips should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ballot-group.is-ledger-chapter') !== false, 'Ceremony ledger chapters should receive deliberate public styling.');
$assert(strpos($css, '.aat-ceremony-ballot-group-visuals') !== false, 'Ceremony ledger verified visuals should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ballot-media') !== false, 'Ceremony ballot row media should be explicitly sized in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ballot-ledger.is-full-ledger') !== false, 'Ceremony full ballot mode should receive scoped public styling.');
$assert(strpos($css, '.aat-ceremony-full-ledger-brief') !== false, 'Ceremony full ballot research briefs should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ballot-row-index') !== false, 'Ceremony full ballot row indexes should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-ballot-depth') !== false, 'Ceremony full ballot depth metadata should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-exit-lane') !== false, 'Ceremony exit lanes should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-exit-feature') !== false, 'Ceremony exit feature cards should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-ceremony-exit-feature-media') !== false, 'Ceremony exit feature media should be explicitly sized.');
$assert(strpos($css, '.aat-ceremony-exit-rail') !== false, 'Ceremony exit rails should have a public layout rule.');
$assert(strpos($css, '.aat-ceremony-exit-card.has-no-media') !== false, 'Ceremony exit rail text-led cards should have deliberate styling.');
$assert(strpos($css, '.aat-profile-dossier-strip') !== false, 'Profile dossier strip should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-profile-dossier-track') !== false, 'Profile dossier track should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-profile-dossier-card.has-no-media') !== false, 'Profile dossier text-led cards should have deliberate styling.');
$assert(strpos($css, 'scroll-snap-type: x proximity') !== false, 'Profile dossier mobile track should remain horizontally contained.');
$assert(strpos($css, '.aat-profile-reader-path') !== false, 'Profile reader-path modules should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-profile-reader-path-grid') !== false, 'Profile reader paths should have a public layout rule.');
$assert(strpos($css, '.aat-profile-reader-path-card') !== false, 'Profile reader-path cards should be styled in the public stylesheet.');
$assert(strpos($css, '.aat-profile-reader-path-card.has-no-media') !== false, 'Profile reader-path text-led cards should have deliberate styling.');

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
