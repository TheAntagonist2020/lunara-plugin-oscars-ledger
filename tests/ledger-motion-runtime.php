<?php
/**
 * Ledger Motion (2.8.8): the Nomination Ring and the Career Arc render from
 * entity rows, in their finished state, with the full record for screen
 * readers. WordPress is stubbed; the renderer is the real one.
 *
 * Run: php tests/ledger-motion-runtime.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'AAT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'AAT_PLUGIN_URL', 'https://example.test/plugin/' );
define( 'AAT_VERSION', '2.8.14' );

$checks = 0;
function lm_assert( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['lm_actions'] = array();
function add_action( $hook, $cb ) { $GLOBALS['lm_actions'][ $hook ][] = $cb; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function wp_list_pluck( $list, $field ) { return array_map( static function ( $r ) use ( $field ) { return $r[ $field ]; }, (array) $list ); }

require AAT_PLUGIN_DIR . 'includes/class-aat-ledger-motion.php';

$fmt = static function ( $cat ) {
	$map = array( 'BEST PICTURE' => 'Best Picture', 'DIRECTING' => 'Best Director', 'ACTRESS IN A LEADING ROLE' => 'Best Actress' );
	return $map[ $cat ] ?? ucwords( strtolower( $cat ) );
};
function lm_row( $cer, $year, $cat, $won, $film = 'A Film' ) {
	return array( 'ceremony' => $cer, 'year' => $year, 'category' => $cat, 'canonical_category' => $cat, 'winner' => $won ? 1 : 0, 'film' => $film );
}

lm_assert( ! empty( $GLOBALS['lm_actions']['wp_enqueue_scripts'] ), 'Assets are registered on wp_enqueue_scripts.' );

// ---- Records ---------------------------------------------------------------
$records = AAT_Ledger_Motion::records(
	array( lm_row( 70, '1997', 'DIRECTING', true ), lm_row( 0, '', 'SOUND', false ), lm_row( 70, '1997', '', false ), lm_row( 6, '1932/33', 'BEST PICTURE', false, 'Film One|Film Two' ) ),
	$fmt
);
lm_assert( 2 === count( $records ), 'Rows without a ceremony or category are skipped.' );
lm_assert( 1933 !== $records[1]['year'] && 1932 === $records[1]['year'] && '1932/33' === $records[1]['year_label'], 'A split year keeps its printed label and sorts by its first year.' );
lm_assert( 'Film One / Film Two' === $records[1]['film'], 'Pipe-joined films read as one title line.' );
lm_assert( $records[1]['picture'] && ! $records[0]['picture'], 'Best Picture is recognised.' );

// ---- Wrap and pacing -------------------------------------------------------------
lm_assert( array( 'Best Picture' ) === AAT_Ledger_Motion::wrap( 'Best Picture' ), 'Short labels stay on one line.' );
lm_assert( 2 === count( AAT_Ledger_Motion::wrap( 'Best Supporting Actress In A Leading Role' ) ), 'Long labels wrap to at most two lines.' );
lm_assert( 0.26 === AAT_Ledger_Motion::win_step( 3, 0.26 ) && 0.1 === AAT_Ledger_Motion::win_step( 26, 0.22 ), 'Win pacing keeps the whole ignition near 2.6 seconds.' );

// ---- Ring ------------------------------------------------------------------------
$film_rows = array(
	lm_row( 70, '1997', 'SOUND', true ),
	lm_row( 70, '1997', 'ACTRESS IN A LEADING ROLE', false ),
	lm_row( 70, '1997', 'BEST PICTURE', true ),
	lm_row( 70, '1997', 'DIRECTING', true ),
);
$ring = AAT_Ledger_Motion::render( 'title', $film_rows, array( 'label' => 'Titanic <b>', 'image_url' => 'https://example.test/p.jpg', 'format_category' => $fmt ) );
lm_assert( false !== strpos( $ring, 'aat-ledger-motion--ring' ) && false !== strpos( $ring, 'data-aat-motion' ), 'A film with four nominations gets the ring.' );
lm_assert( 4 === substr_count( $ring, 'class="aat-motion-spoke ' ), 'One spoke per nomination.' );
lm_assert( 3 === substr_count( $ring, 'class="aat-motion-spoke is-win"' ) && 3 === substr_count( $ring, 'aat-motion-pulse' ), 'Each win is marked and pulses.' );
preg_match_all( '/<tspan[^>]*>([^<]+)<\/tspan>/', $ring, $labels );
lm_assert( 'Best Picture' === $labels[1][0], 'Best Picture leads at twelve o\'clock.' );
lm_assert( false !== strpos( $ring, '--w:0' ) && false !== strpos( $ring, '--w:2' ) && false === strpos( $ring, '--w:3' ), 'Wins are numbered in ring order for ignition.' );
lm_assert( false === strpos( $ring, '<b>' ) && false !== strpos( $ring, 'Titanic &lt;b&gt;' ), 'The title is escaped everywhere.' );
lm_assert( false !== strpos( $ring, '<image href="https://example.test/p.jpg"' ) && false !== strpos( $ring, 'clip-path="url(#aat-ring-' ), 'The poster sits in the clipped centre.' );
lm_assert( 4 === substr_count( $ring, '<li>' ) && false !== strpos( $ring, '<li>1997: Best Picture — won</li>' ), 'Screen readers get the full record.' );
lm_assert( false !== strpos( $ring, 'data-aat-count="4"' ) && false !== strpos( $ring, 'data-aat-count="3" data-aat-count-phase="ignite"' ), 'The tally carries both counts, finished.' );
lm_assert( 1 === preg_match( '/--ignite:[\d.]+s;--wstep:[\d.]+s/', $ring ), 'Ignition timing is set on the section.' );
lm_assert( false !== strpos( $ring, 'with its 3 wins lit in gold' ), 'The description names the wins.' );

$no_poster = AAT_Ledger_Motion::render( 'title', $film_rows, array( 'label' => 'Titanic', 'image_url' => '', 'format_category' => $fmt ) );
lm_assert( false === strpos( $no_poster, '<image' ) && false !== strpos( $no_poster, 'aat-motion-core-title' ), 'Without a poster the title fills the centre.' );
lm_assert( '' === AAT_Ledger_Motion::render( 'title', array_slice( $film_rows, 0, 2 ), array( 'label' => 'X', 'format_category' => $fmt ) ), 'Two nominations are too few for a ring.' );
$zero = AAT_Ledger_Motion::render( 'title', array( lm_row( 1, '1928', 'SOUND', false ), lm_row( 1, '1928', 'DIRECTING', false ), lm_row( 1, '1928', 'BEST PICTURE', false ) ), array( 'label' => 'Z', 'format_category' => $fmt ) );
lm_assert( false !== strpos( $zero, 'Every nomination Z received.' ) && 0 === substr_count( $zero, 'is-win' ), 'A film without wins has no gold and says so plainly.' );
lm_assert( '' === AAT_Ledger_Motion::render( 'company', $film_rows, array( 'label' => 'Co', 'format_category' => $fmt ) ), 'Companies get no motion section.' );

// ---- Arc ---------------------------------------------------------------------------
$person_rows = array(
	lm_row( 51, '1978', 'ACTRESS IN A SUPPORTING ROLE', false, 'The Deer Hunter' ),
	lm_row( 52, '1979', 'ACTRESS IN A SUPPORTING ROLE', true, 'Kramer vs. Kramer' ),
	lm_row( 55, '1982', 'ACTRESS IN A LEADING ROLE', true, 'Sophie\'s Choice' ),
	lm_row( 55, '1982', 'DIRECTING', false, 'Sophie\'s Choice' ),
	lm_row( 84, '2011', 'ACTRESS IN A LEADING ROLE', true, 'The Iron Lady' ),
);
$arc = AAT_Ledger_Motion::render( 'name', $person_rows, array( 'label' => 'Meryl Streep', 'format_category' => $fmt ) );
lm_assert( false !== strpos( $arc, 'aat-ledger-motion--arc' ), 'A person across several ceremonies gets the arc.' );
lm_assert( 5 === substr_count( $arc, 'class="aat-motion-spoke ' ) && 4 === substr_count( $arc, 'class="aat-motion-stem"' ), 'One node per nomination, one stem per ceremony.' );
lm_assert( false !== strpos( $arc, '>1978</text>' ) && false !== strpos( $arc, '>2011</text>' ), 'The first and last years are always labelled.' );
lm_assert( 3 === substr_count( $arc, 'aat-motion-win-title' ) && false !== strpos( $arc, '>Kramer vs. Kramer</text>' ) && false !== strpos( $arc, 'Sophie&#039;s Choice' ), 'Each win names its film.' );
lm_assert( false !== strpos( $arc, '<li>1982: Best Actress, Sophie&#039;s Choice — won</li>' ), 'The screen-reader record includes the film.' );
lm_assert( false !== strpos( $arc, '1978 to 2011' ) && false !== strpos( $arc, 'the 3 wins in gold' ), 'The description gives the span and the wins.' );
lm_assert( '' === AAT_Ledger_Motion::render( 'name', array( $person_rows[2], $person_rows[3] ), array( 'label' => 'One Night', 'format_category' => $fmt ) ), 'A single ceremony is not an arc.' );

$many = array();
for ( $i = 0; $i < 12; $i++ ) {
	$many[] = lm_row( 10 + $i, (string) ( 1937 + $i ), 'SHORT SUBJECT', true, 'Short ' . $i );
}
$crowded = AAT_Ledger_Motion::render( 'name', $many, array( 'label' => 'Walt', 'format_category' => $fmt ) );
lm_assert( 0 === substr_count( $crowded, 'aat-motion-win-title' ), 'More than eight wins are not titled, to avoid a crowd of labels.' );

// ---- Links (2.8.9) ----------------------------------------------------------------
$race = static function ( $ceremony, $category ) {
	return 'https://example.test/oscars/ceremony/' . $ceremony . '/?ledger=full#ceremony-category-' . strtolower( str_replace( ' ', '-', $category ) );
};
$linked = AAT_Ledger_Motion::render( 'title', $film_rows, array( 'label' => 'Titanic', 'image_url' => '', 'format_category' => $fmt, 'race_url' => $race ) );
lm_assert( 4 === substr_count( $linked, '<a class="aat-motion-link"' ) && 4 === substr_count( $linked, '</a></g>' ), 'Every ring spoke is a link, closed inside its spoke.' );
lm_assert( false !== strpos( $linked, 'href="https://example.test/oscars/ceremony/70/?ledger=full#ceremony-category-best-picture"' ), 'A spoke opens its race on the ceremony\'s full ballot.' );
lm_assert( false !== strpos( $linked, 'aria-label="1997: Best Picture — won. Open the full race."' ), 'Each link names its race and result.' );
lm_assert( 4 === substr_count( $linked, 'class="aat-motion-hit"' ) && false !== strpos( $linked, 'role="group"' ), 'Linked spokes get a hit target and the drawing becomes a group.' );
lm_assert( false !== strpos( $linked, 'Select any nomination to open its full race.' ) && false === strpos( $ring, 'Select any' ), 'The description invites the click only when there is one.' );
lm_assert( false === strpos( $linked, 'aat-motion-record' ) && false !== strpos( $ring, 'aat-motion-record' ), 'The hidden list steps aside only when the links carry the record.' );
$linked_arc = AAT_Ledger_Motion::render( 'name', $person_rows, array( 'label' => 'Meryl Streep', 'format_category' => $fmt, 'race_url' => $race ) );
lm_assert( 5 === substr_count( $linked_arc, '<a class="aat-motion-link"' ) && false !== strpos( $linked_arc, 'aria-label="1982: Best Actress, Sophie&#039;s Choice — won. Open the full race."' ), 'Every arc node is a link that names its film.' );
lm_assert( false !== strpos( $linked, 'data-aat-cap="Best Picture" data-aat-cap-meta="1997 · Won"' ), 'Each ring link carries its phone caption.' );
lm_assert( false !== strpos( $linked_arc, 'data-aat-cap-meta="1982 · Sophie&#039;s Choice · Won"' ), 'Arc captions name the film.' );
$partial = AAT_Ledger_Motion::render( 'title', $film_rows, array( 'label' => 'T', 'format_category' => $fmt, 'race_url' => static function ( $c, $cat ) { return 'SOUND' === $cat ? '' : 'https://example.test/x'; } ) );
lm_assert( 3 === substr_count( $partial, '<a class="aat-motion-link"' ) && false !== strpos( $partial, 'role="img"' ) && false !== strpos( $partial, 'aat-motion-record' ), 'A spoke without a race stays plain, and the list stays.' );

// ---- Template and assets -------------------------------------------------------------
$template = file_get_contents( AAT_PLUGIN_DIR . 'templates/entity-page.php' );
lm_assert( false !== strpos( $template, "\$aat_sections['ledger-motion'] = ob_get_clean()" ), 'The entity template captures the motion section.' );
lm_assert( false !== strpos( $template, "'race_url' => function" ) && false !== strpos( $template, "add_query_arg('ledger', 'full', \$url) . '#ceremony-category-' . sanitize_title(" ), 'The template links spokes to the ceremony ballot\'s category anchors.' );
lm_assert( strpos( $template, "\$aat_sections['stats-bar']" ) < strpos( $template, "\$aat_sections['ledger-motion']" ) && strpos( $template, "\$aat_sections['ledger-motion']" ) < strpos( $template, "\$aat_sections['crossroads']" ), 'It sits between the stats bar and the crossroads.' );
$css = file_get_contents( AAT_PLUGIN_DIR . 'assets/css/ledger-motion.css' );
lm_assert( 1 === preg_match( '/@media \(max-width: 640px\)[^}]*\{[^@]*\.aat-ledger-motion--ring \.aat-motion-svg \{ width: 180%; max-width: none; margin: -11% 0 -13% -40%; \}/s', $css ), 'On phones the ring is enlarged and centred, and escapes the theme\'s svg max-width.' );
lm_assert( false !== strpos( $css, 'prefers-reduced-motion: reduce' ) && false !== strpos( $css, '.aat-motion-record' ), 'Styles honour reduced motion and hide the record visually.' );
$js = file_get_contents( AAT_PLUGIN_DIR . 'assets/js/ledger-motion.js' );
lm_assert( false !== strpos( $js, "'(max-width: 640px)'" ) && false !== strpos( $js, 'event.preventDefault()' ) && false !== strpos( $js, "data-aat-cap" ) && false !== strpos( $js, 'Array.prototype.forEach.call( sections, caption )' ), 'On phones the first tap names a dot in a caption; captions are set up even under reduced motion.' );
lm_assert( false !== strpos( $css, '.aat-motion-caption { display: none; }' ) && false !== strpos( $css, '.aat-motion-aura,' ), 'The caption shows only on phones, and decoration ignores taps.' );
lm_assert( false !== strpos( $js, "prefers-reduced-motion: reduce" ) && false !== strpos( $js, 'IntersectionObserver' ), 'The script stands down under reduced motion and plays on view.' );

echo "Ledger Motion runtime passed: {$checks} checks.\n";
