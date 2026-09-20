<?php

$root = dirname(__DIR__);
$hub = file_get_contents($root . '/templates/hub-page.php');
$css = file_get_contents($root . '/assets/css/academy-awards-table.css');
$failures = array();

$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

// get_title_visual_package() returns ready-made markup in poster_html with poster_url
// EMPTY. Gating the card on poster_url alone hid the image on every winner card.
$assert(
    strpos($hub, "<article class=\"aat-winner-circle-card<?php echo \$is_latest_ceremony ? ' is-hero-latest' : ''; ?><?php echo !empty(\$winner_visual['poster_url']) ? ' has-hero-media' : ''; ?>\">") === false,
    'Winner cards must not decide their media class from poster_url alone.'
);
$assert(
    preg_match('/if \(empty\(\$winner_visual\[\'poster_html\'\]\) && empty\(\$winner_visual\[\'poster_url\'\]\) && !empty\(\$winner_entry\[\'film_id\'\]\)\)/', $hub) === 1,
    'The poster fallback should treat poster_html and poster_url as equally valid.'
);
$assert(
    preg_match('/\$winner_media_html = \'\';/', $hub) === 1,
    'Winner cards should resolve one media HTML string.'
);

// Person-led categories (acting, directing) have portraits but no poster of their own.
$assert(strpos($hub, '$aat_get_person_visual = function($entry, $size') !== false, 'A person portrait helper should exist for winner rows.');
$assert(strpos($hub, 'get_person_visual_package') !== false, 'The portrait helper should call get_person_visual_package.');
$assert(strpos($hub, "strpos((string) \$winner_entry['primary_url'], '/oscars/name/') !== false") !== false, 'Person-led winners should be detected from the primary entity URL.');
$assert(strpos($hub, "\$winner_portrait['portrait_url']") !== false, 'Winner cards should render the resolved portrait.');

// Honorary/Sci-Tech winners have neither film nor portrait: they still get the same box.
$assert(strpos($hub, "\$winner_media_class .= ' is-plaque';") !== false, 'Winner cards without media should fall back to a plaque, not an empty slot.');
$assert(strpos($hub, 'aat-winner-circle-plaque-label') !== false, 'The plaque should label the award.');

// One media shape for the whole grid.
$assert(preg_match('/\.aat-winner-circle-media \{[^}]*aspect-ratio: 3 \/ 4;/s', $css) === 1, 'Winner media should use one aspect ratio for posters, portraits and plaques.');
$assert(preg_match('/\.aat-winner-circle-media \{[^}]*aspect-ratio: 16 \/ 10;/s', $css) === 0, 'The old 16/10 media box should be gone.');
$assert(preg_match('/\.aat-winner-circle-media\.is-portrait img[^}]*object-position: center 18%;/s', $css) === 1, 'Portraits should crop from the top so faces survive.');
$assert(preg_match('/\.aat-winner-circle-media\.is-plaque \{[^}]*align-items: center;/s', $css) === 1, 'The plaque should be centred in the media box.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Winner circle media contract OK.\n";
