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

// One media shape for the whole grid. Posters and portraits are both 2:3, so the
// box fits either with no crop; the .aat-hub-page block is what actually applies.
$assert(preg_match('/\.aat-winner-circle-media \{[^}]*aspect-ratio: 2 \/ 3;/s', $css) === 1, 'The base winner media rule should use the 2:3 shape that the hub-page rule enforces.');
$assert(preg_match('/\.aat-winner-circle-media \{[^}]*aspect-ratio: 16 \/ 10;/s', $css) === 0, 'The old 16/10 media box should be gone.');
$assert(preg_match('/\.aat-hub-page \.aat-winner-circle-media,[^{]*\{[^}]*aspect-ratio: 2 \/ 3;/s', $css) === 1, 'The effective hub-page media rule should keep one aspect ratio.');
$assert(preg_match('/\.aat-winner-circle-media \{[^}]*box-sizing: border-box;/s', $css) === 1, 'The media box needs border-box, or the padded plaque grows wider than the image boxes.');
$assert(preg_match('/\.aat-winner-circle-media\.is-plaque \{[^}]*min-width: 0;/s', $css) === 1, 'The plaque needs min-width:0 so long award names cannot stretch the column.');
$assert(preg_match('/\.aat-winner-circle-media\.is-portrait img[^}]*object-position: center 18%;/s', $css) === 1, 'Portraits should crop from the top so faces survive.');
$assert(preg_match('/\.aat-winner-circle-media\.is-plaque \{[^}]*align-items: center;/s', $css) === 1, 'The plaque should be centred in the media box.');

// Phones first: one column of 40+ full-height cards is an endless scroll, so the
// phone layout is thumbnail-left/text-right with no forced card height.
$assert(preg_match('/@media \(max-width: 720px\)/', $css) === 1, 'The phone breakpoint should exist.');
$phone_block = '';
$phone_marker = strpos($css, 'grid-template-columns: 104px minmax(0, 1fr);');
if ($phone_marker !== false) {
    $phone_open = strrpos(substr($css, 0, $phone_marker), '@media (max-width: 720px)');
    $assert($phone_open !== false, 'The phone winner-card rules should live in the 720px breakpoint.');
    if ($phone_open !== false) {
        $phone_block = substr($css, $phone_open, $phone_marker - $phone_open + 4000);
    }
}
$assert(strpos($phone_block, 'grid-template-columns: 104px minmax(0, 1fr);') !== false, 'Phone cards should put the media in a fixed left column.');
$assert(preg_match('/\.aat-hub-page \.aat-winner-circle-card,[^{]*\{[^}]*min-height: 0;/s', $phone_block) === 1, 'Phone cards should drop the desktop minimum height.');
$assert(strpos($phone_block, 'grid-row: 2 / span 3;') !== false, 'The phone media box should span the text rows beside it.');
$assert(preg_match('/\.aat-hub-page \.aat-winner-circle-title,[^{]*\{\s*grid-column: 2;/s', $phone_block) === 1, 'Phone text should sit in the second column.');

// The theme sets body.aat-shell-page .aat-hub-chip to inline-flex, which beats the
// plugin's stacked chip. On a phone that squeezed title and counts into one row and
// broke words mid-letter ("DUN/E", "BELFAS/T", "NOMINATION/S").
$assert(strpos($phone_block, 'body.aat-shell-page .aat-hub-chip-rich') !== false, 'The phone rules should out-specify the theme chip rule.');
$assert(preg_match('/body\.aat-shell-page \.aat-hub-chip-rich \{[^}]*display: grid !important;/s', $phone_block) === 1, 'Rich chips should stack on phones.');
$assert(preg_match('/body\.aat-shell-page \.aat-hub-chip-rich > \* \{[^}]*word-break: normal;/s', $phone_block) === 1, 'Chip contents should never break inside a word.');
$assert(preg_match('/body\.aat-shell-page \.aat-hub-chip-rich > \* \{[^}]*hyphens: none;/s', $phone_block) === 1, 'Chip contents should not hyphenate.');

// Counts read as English: "1 win", not "1 wins".
$hub_tpl = file_get_contents($root . '/templates/hub-page.php');
$assert(strpos($hub_tpl, "_n('%s win', '%s wins'") !== false, 'Win counts should use singular/plural forms.');
$assert(strpos($hub_tpl, "_n('%s nomination', '%s nominations'") !== false, 'Nomination counts should use singular/plural forms.');
$assert(strpos($hub_tpl, "esc_html__('wins', 'academy-awards-table')") === false, 'The hard-coded plural "wins" label should be gone.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Winner circle media contract OK.\n";
