<?php

$root = dirname(__DIR__);
$read = static function ($relative_path) use ($root) {
    $contents = file_get_contents($root . '/' . $relative_path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, $relative_path . " should be readable.\n");
        exit(1);
    }
    return $contents;
};

$plugin = $read('academy-awards-table.php');
$template = $read('templates/hub-page.php');
$ceremony_css = $read('assets/css/ceremony-dossier.css');
$hub_css = $read('assets/css/hub-polish.css');
$ceremony_js = $read('assets/js/ceremony-dossier.js');
$failures = array();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($plugin, "Version: 2.7.82") !== false, 'Plugin header should report 2.7.82.');
$assert(strpos($plugin, "define('AAT_VERSION', '2.7.82')") !== false, 'Runtime version should report 2.7.82.');
$assert(strpos($plugin, "'aat-hub-polish'") !== false, 'Hub polish should be enqueued as an external style.');
$assert(strpos($plugin, "'aat-ceremony-dossier'") !== false, 'Ceremony dossier assets should be enqueued externally.');
$assert(strpos($plugin, "if (\$hub === 'ceremony')") !== false, 'Ceremony assets must stay route-scoped.');
$assert(preg_match("/wp_enqueue_style\\(\\s*'aat-ceremony-dossier',[\\s\\S]*?array\\('aat-styles'\\)/", $plugin) === 1, 'Ceremony CSS must depend directly on the base Oscars stylesheet.');
$assert(preg_match('/wp_enqueue_style\\(\\s*\'aat-hub-polish\',[\\s\\S]*?\\$hub_polish_dependencies/', $plugin) === 1, 'Shared hub polish must preserve the dynamic post-Ceremony dependency.');
$assert(strpos($plugin, "'aat-ceremony-dossier',\n                    AAT_PLUGIN_URL . 'assets/css/ceremony-dossier.css',\n                    array('aat-hub-polish')") === false, 'Ceremony and hub styles must never form a circular dependency.');
$assert(strpos($plugin, "dequeue_virtual_page_bloat") !== false, 'Virtual-page bloat removal should remain registered.');
$assert(strpos($plugin, "'aat_virtual_page_unused_style_handles'") !== false, 'Unused style handles should remain filterable.');

foreach (array('wp-block-library', 'global-styles', 'jetpack_likes', 'jetpack-global-styles-frontend-style', 'ai_summarization') as $handle) {
    $assert(strpos($plugin, "'{$handle}'") !== false, "{$handle} should remain in the virtual-page unused-style allowlist.");
}

$assert(strpos($template, 'body .aat-container .aat-ceremony-dossier{') === false, 'Ceremony CSS must not return to the HTML template.');
$assert(strpos($template, '/* Dossier reveal-on-scroll.') === false, 'Ceremony reveal code must not return to the HTML template.');
$assert(strpos($template, '.aat-hub-film-grid .aat-filmography-card{') === false, 'Shared hub polish must not return to the HTML template.');

$assert(strpos($ceremony_css, 'body .aat-container .aat-ceremony-dossier') !== false, 'Ceremony stylesheet is missing the dossier root.');
$assert(strpos($hub_css, '.aat-hub-film-grid .aat-filmography-card') !== false, 'Hub stylesheet is missing shared film cards.');
$assert(strpos($hub_css, '.aat-category-history .aat-decade-pill') !== false, 'Hub stylesheet is missing the category hit-area contract.');
$assert(strpos($ceremony_js, 'IntersectionObserver') !== false, 'Ceremony reveal script is incomplete.');
$assert(strpos($ceremony_js, 'prefers-reduced-motion: reduce') !== false, 'Ceremony reveal script must preserve reduced-motion behavior.');
$assert(strpos($ceremony_js, 'section:not(.aat-ceremony-dossier-hero)') !== false, 'The LCP hero must stay outside reveal hiding.');
$assert(strpos($template, "'loading'       => 'eager'") !== false, 'The local Ceremony hero poster must load eagerly.');
$assert(strpos($template, "'fetchpriority' => 'high'") !== false, 'The local Ceremony hero poster must receive high fetch priority.');
$assert(strpos($template, 'loading="eager" fetchpriority="high"') !== false, 'The fallback Ceremony hero poster must preserve LCP priority.');

$assert(strlen($ceremony_css) < 17000, 'Ceremony CSS exceeds its 17 KB source budget.');
$assert(strlen($hub_css) < 7000, 'Hub polish exceeds its 7 KB source budget.');
$assert(strlen($ceremony_js) < 2000, 'Ceremony JavaScript exceeds its 2 KB source budget.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Ceremony payload hygiene contract OK.\n";
