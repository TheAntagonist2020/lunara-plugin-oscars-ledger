<?php
/**
 * Ledger Motion (2.8.8): the Oscar record drawn, then played.
 *
 * Film profiles get the Nomination Ring: the film at the centre, one spoke per
 * nomination, the wins lit in gold one envelope at a time. Person profiles get
 * the Career Arc: every ceremony on a time axis, nominations rising above their
 * year, the wins lit in gold.
 *
 * The SVG is rendered server-side in its finished state, so the picture is
 * complete without JavaScript and for readers who prefer reduced motion; an
 * ordered list carries the same record for screen readers. assets/js/
 * ledger-motion.js only arms and plays the sequence. Geometry is computed here,
 * so the script never measures the page.
 *
 * @package Academy_Awards_Table
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AAT_Ledger_Motion {

	/** Ring: shown from this many nominations, up to the cap. */
	const RING_MIN = 3;
	const RING_MAX = 30;

	/** Arc: needs at least this many distinct ceremonies. */
	const ARC_MIN_CEREMONIES = 2;
	const ARC_MAX = 90;

	/**
	 * Register the asset loader.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Load the motion sheet and script on film and person profiles only.
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		$entity = (string) get_query_var( 'aat_entity' );
		if ( 'title' !== $entity && 'name' !== $entity ) {
			return;
		}
		$css = AAT_PLUGIN_DIR . 'assets/css/ledger-motion.css';
		$js  = AAT_PLUGIN_DIR . 'assets/js/ledger-motion.js';
		wp_enqueue_style(
			'aat-ledger-motion',
			AAT_PLUGIN_URL . 'assets/css/ledger-motion.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : AAT_VERSION
		);
		wp_enqueue_script(
			'aat-ledger-motion',
			AAT_PLUGIN_URL . 'assets/js/ledger-motion.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : AAT_VERSION,
			true
		);
		wp_script_add_data( 'aat-ledger-motion', 'strategy', 'defer' );
	}

	/**
	 * Normalise entity rows into ordered nomination records.
	 *
	 * @param array<int,array<string,mixed>> $rows            Entity rows.
	 * @param callable                       $format_category ( string $category, int $ceremony ) => string.
	 * @return array<int,array<string,mixed>>
	 */
	public static function records( $rows, $format_category ) {
		$records = array();
		foreach ( (array) $rows as $index => $row ) {
			$ceremony = (int) ( $row['ceremony'] ?? 0 );
			$category = trim( (string) ( $row['canonical_category'] ?? $row['category'] ?? '' ) );
			if ( $ceremony <= 0 || '' === $category ) {
				continue;
			}
			$year_raw = trim( (string) ( $row['year'] ?? '' ) );
			$year     = preg_match( '/(\d{4})/', $year_raw, $m ) ? (int) $m[1] : 0;
			$film     = trim( (string) preg_replace( '/\s*\|\s*/', ' / ', (string) ( $row['film'] ?? '' ) ) );
			$records[] = array(
				'index'      => (int) $index,
				'ceremony'   => $ceremony,
				'year'       => $year,
				'year_label' => '' !== $year_raw ? $year_raw : (string) $year,
				'category'   => $category,
				'label'      => (string) call_user_func( $format_category, $category, $ceremony ),
				'won'        => ! empty( $row['winner'] ) && 1 === (int) $row['winner'],
				'film'       => $film,
				'picture'    => (bool) preg_match( '/\b(BEST|OUTSTANDING)\s+(MOTION\s+)?PICTURE\b|^BEST PICTURE$/i', $category ),
			);
		}
		return $records;
	}

	/**
	 * Section markup for an entity, or '' when the record is too thin to draw.
	 *
	 * @param string              $entity  title|name|company.
	 * @param array               $rows    Entity rows.
	 * @param array<string,mixed> $args    label, image_url, format_category.
	 * @return string
	 */
	public static function render( $entity, $rows, $args ) {
		$records = self::records( $rows, $args['format_category'] );
		$race    = $args['race_url'] ?? null;
		foreach ( $records as $k => $rec ) {
			$records[ $k ]['url'] = is_callable( $race ) ? (string) call_user_func( $race, $rec['ceremony'], $rec['category'] ) : '';
		}
		if ( 'title' === $entity ) {
			return self::render_ring( $records, $args );
		}
		if ( 'name' === $entity ) {
			return self::render_arc( $records, $args );
		}
		return '';
	}

	/**
	 * Split a label into at most two lines of about $width characters.
	 *
	 * @param string $label Label.
	 * @param int    $width Soft line width.
	 * @return array<int,string>
	 */
	public static function wrap( $label, $width = 20 ) {
		$label = trim( (string) $label );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $label ) <= $width : strlen( $label ) <= $width ) {
			return array( $label );
		}
		$words = preg_split( '/\s+/', $label );
		$first = '';
		while ( $words ) {
			$next = '' === $first ? $words[0] : $first . ' ' . $words[0];
			if ( '' !== $first && strlen( $next ) > $width ) {
				break;
			}
			$first = $next;
			array_shift( $words );
		}
		$rest = implode( ' ', $words );
		return '' === $rest ? array( $first ) : array( $first, $rest );
	}

	/**
	 * Seconds between wins lighting: $max each, the whole ignition kept near 2.6s.
	 *
	 * @param int   $wins Win count.
	 * @param float $max  Longest step.
	 * @return float
	 */
	public static function win_step( $wins, $max ) {
		return $wins > 0 ? round( min( $max, 2.6 / $wins ), 3 ) : $max;
	}

	/**
	 * Number helper for SVG attributes.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function n( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Open a spoke's link to its race, or nothing when it has no URL.
	 *
	 * @param array<string,mixed> $rec   Nomination.
	 * @param string              $label Accessible name.
	 * @return string
	 */
	private static function link_open( $rec, $label ) {
		if ( empty( $rec['url'] ) ) {
			return '';
		}
		return '<a class="aat-motion-link" href="' . esc_url( $rec['url'] ) . '" aria-label="' . esc_attr( $label . '. ' . __( 'Open the full race.', 'academy-awards-table' ) ) . '">';
	}

	/**
	 * A spoke's accessible name: year, category, film where asked, result.
	 *
	 * @param array<string,mixed> $rec       Nomination.
	 * @param bool                $with_film Include the film.
	 * @return string
	 */
	private static function spoke_name( $rec, $with_film ) {
		$line  = $rec['year_label'] . ': ' . $rec['label'];
		$line .= $with_film && '' !== $rec['film'] ? ', ' . $rec['film'] : '';
		return $line . ' — ' . ( $rec['won'] ? __( 'won', 'academy-awards-table' ) : __( 'nominated', 'academy-awards-table' ) );
	}

	/**
	 * Whether every nomination links to its race.
	 *
	 * @param array<int,array<string,mixed>> $records Nominations.
	 * @return bool
	 */
	private static function all_linked( $records ) {
		foreach ( $records as $rec ) {
			if ( empty( $rec['url'] ) ) {
				return false;
			}
		}
		return (bool) $records;
	}

	/**
	 * The Nomination Ring for a film.
	 *
	 * @param array<int,array<string,mixed>> $records Nominations.
	 * @param array<string,mixed>            $args    Render arguments.
	 * @return string
	 */
	public static function render_ring( $records, $args ) {
		$count = count( $records );
		if ( $count < self::RING_MIN || $count > self::RING_MAX ) {
			return '';
		}

		// Best Picture leads at twelve o'clock; the rest keep the ceremony's own order.
		usort(
			$records,
			static function ( $a, $b ) {
				if ( $a['ceremony'] !== $b['ceremony'] ) {
					return $a['ceremony'] - $b['ceremony'];
				}
				if ( $a['picture'] !== $b['picture'] ) {
					return $a['picture'] ? -1 : 1;
				}
				return $a['index'] - $b['index'];
			}
		);

		$wins  = count( array_filter( wp_list_pluck( $records, 'won' ) ) );
		$label = (string) ( $args['label'] ?? '' );
		$uid   = 'aat-ring-' . substr( md5( $label . $count . $wins ), 0, 8 );

		$w  = 960;
		$h  = 620;
		$cx = 480;
		$cy = 304;
		$r0 = 78;   // Poster.
		$r1 = 100;  // Spokes start on the ring.
		$r2 = 204;  // Spokes end.
		$rn = 212;  // Nodes.
		$rl = 232;  // Labels.

		$spoke_step = 0.09; // Seconds between spokes.
		$ignite     = round( 0.35 + $count * $spoke_step + 0.7, 2 );
		$wins_total = count( array_filter( wp_list_pluck( $records, 'won' ) ) );
		$wstep      = self::win_step( $wins_total, 0.26 );

		$svg  = '<svg class="aat-motion-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="' . ( self::all_linked( $records ) ? 'group' : 'img' ) . '" aria-labelledby="' . esc_attr( $uid ) . '-title" focusable="false">';
		$svg .= '<title id="' . esc_attr( $uid ) . '-title">' . esc_html(
			sprintf(
				/* translators: 1: film title, 2: nominations, 3: wins. */
				__( '%1$s: %2$d nominations, %3$d wins', 'academy-awards-table' ),
				$label,
				$count,
				$wins
			)
		) . '</title>';
		$svg .= '<defs><clipPath id="' . esc_attr( $uid ) . '-clip"><circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r0 . '"/></clipPath>';
		$svg .= '<radialGradient id="' . esc_attr( $uid ) . '-glow"><stop offset="0" stop-color="#e0c481" stop-opacity="0.35"/><stop offset="1" stop-color="#e0c481" stop-opacity="0"/></radialGradient></defs>';
		$svg .= '<circle class="aat-motion-aura" cx="' . $cx . '" cy="' . $cy . '" r="' . ( $r2 + 20 ) . '" fill="url(#' . esc_attr( $uid ) . '-glow)"/>';
		$svg .= '<circle class="aat-motion-orbit" cx="' . $cx . '" cy="' . $cy . '" r="' . $rn . '" pathLength="1"/>';
		$svg .= '<circle class="aat-motion-ring" cx="' . $cx . '" cy="' . $cy . '" r="' . $r1 . '" pathLength="1"/>';

		$win_order = 0;
		foreach ( array_values( $records ) as $i => $rec ) {
			$angle = -M_PI / 2 + ( 2 * M_PI * $i / $count );
			$cos   = cos( $angle );
			$sin   = sin( $angle );
			$state = $rec['won'] ? 'is-win' : 'is-nom';
			$style = '--i:' . $i . ( $rec['won'] ? ';--w:' . $win_order : '' );

			$link = self::link_open( $rec, self::spoke_name( $rec, false ) );
			$svg .= '<g class="aat-motion-spoke ' . $state . '" style="' . esc_attr( $style ) . '">' . $link;
			$svg .= '<title>' . esc_html( $rec['label'] . ( $rec['won'] ? ' — ' . __( 'won', 'academy-awards-table' ) : ' — ' . __( 'nominated', 'academy-awards-table' ) ) ) . '</title>';
			$svg .= '<line class="aat-motion-line" x1="' . self::n( $cx + $cos * $r1 ) . '" y1="' . self::n( $cy + $sin * $r1 ) . '" x2="' . self::n( $cx + $cos * $r2 ) . '" y2="' . self::n( $cy + $sin * $r2 ) . '" pathLength="1"/>';
			if ( $rec['won'] ) {
				$svg .= '<circle class="aat-motion-pulse" cx="' . self::n( $cx + $cos * $rn ) . '" cy="' . self::n( $cy + $sin * $rn ) . '" r="9"/>';
			}
			if ( $link ) {
				$svg .= '<circle class="aat-motion-hit" cx="' . self::n( $cx + $cos * $rn ) . '" cy="' . self::n( $cy + $sin * $rn ) . '" r="18"/>';
			}
			$svg .= '<circle class="aat-motion-node" cx="' . self::n( $cx + $cos * $rn ) . '" cy="' . self::n( $cy + $sin * $rn ) . '" r="8"/>';

			// Label placement: beside the node, reading away from the centre.
			$lx     = $cx + $cos * $rl;
			$ly     = $cy + $sin * $rl;
			$lines  = self::wrap( $rec['label'] );
			$anchor = abs( $cos ) < 0.22 ? 'middle' : ( $cos > 0 ? 'start' : 'end' );
			$lh     = 16;
			if ( 'middle' === $anchor ) {
				$ly = $sin < 0 ? $ly - ( count( $lines ) - 1 ) * $lh : $ly + 10;
			} else {
				$ly = $ly + 5 - ( count( $lines ) - 1 ) * $lh / 2;
			}
			$svg .= '<text class="aat-motion-label" x="' . self::n( $lx ) . '" y="' . self::n( $ly ) . '" text-anchor="' . $anchor . '">';
			foreach ( $lines as $k => $line ) {
				$svg .= '<tspan x="' . self::n( $lx ) . '"' . ( $k ? ' dy="' . $lh . '"' : '' ) . '>' . esc_html( $line ) . '</tspan>';
			}
			$svg .= '</text>' . ( $link ? '</a>' : '' ) . '</g>';

			if ( $rec['won'] ) {
				++$win_order;
			}
		}

		// The film at the centre: its poster in a gold-rimmed disc, or its title.
		$image = trim( (string) ( $args['image_url'] ?? '' ) );
		$svg  .= '<g class="aat-motion-core">';
		$svg  .= '<circle class="aat-motion-core-bg" cx="' . $cx . '" cy="' . $cy . '" r="' . $r0 . '"/>';
		if ( '' !== $image ) {
			$svg .= '<image href="' . esc_url( $image ) . '" x="' . ( $cx - $r0 ) . '" y="' . ( $cy - $r0 * 1.5 + 12 ) . '" width="' . ( $r0 * 2 ) . '" height="' . ( $r0 * 3 ) . '" preserveAspectRatio="xMidYMid slice" clip-path="url(#' . esc_attr( $uid ) . '-clip)"/>';
		} else {
			$svg .= '<text class="aat-motion-core-title" x="' . $cx . '" y="' . ( $cy + 5 ) . '" text-anchor="middle">' . esc_html( self::wrap( $label, 14 )[0] ) . '</text>';
		}
		$svg .= '<circle class="aat-motion-core-rim" cx="' . $cx . '" cy="' . $cy . '" r="' . $r0 . '"/>';
		$svg .= '</g></svg>';

		$description = $wins > 0
			? sprintf(
				/* translators: 1: film title, 2: wins. */
				_n( 'Every nomination %1$s received, with its win lit in gold.', 'Every nomination %1$s received, with its %2$d wins lit in gold.', $wins, 'academy-awards-table' ),
				$label,
				$wins
			)
			: sprintf(
				/* translators: %s: film title. */
				__( 'Every nomination %s received.', 'academy-awards-table' ),
				$label
			);

		return self::section(
			'ring',
			__( 'The Nomination Ring', 'academy-awards-table' ),
			$description,
			$svg,
			$count,
			$wins,
			$ignite,
			$wstep,
			$records,
			false
		);
	}

	/**
	 * The Career Arc for a person.
	 *
	 * @param array<int,array<string,mixed>> $records Nominations.
	 * @param array<string,mixed>            $args    Render arguments.
	 * @return string
	 */
	public static function render_arc( $records, $args ) {
		$records = array_values(
			array_filter(
				$records,
				static function ( $r ) {
					return $r['year'] > 0;
				}
			)
		);
		$count = count( $records );
		if ( $count < 2 || $count > self::ARC_MAX ) {
			return '';
		}

		usort(
			$records,
			static function ( $a, $b ) {
				return $a['ceremony'] !== $b['ceremony'] ? $a['ceremony'] - $b['ceremony'] : $a['index'] - $b['index'];
			}
		);

		$by_ceremony = array();
		foreach ( $records as $rec ) {
			$by_ceremony[ $rec['ceremony'] ][] = $rec;
		}
		if ( count( $by_ceremony ) < self::ARC_MIN_CEREMONIES ) {
			return '';
		}

		$years     = wp_list_pluck( $records, 'year' );
		$min_year  = min( $years );
		$max_year  = max( $years );
		$span      = max( 1, $max_year - $min_year );
		$max_stack = max( array_map( 'count', $by_ceremony ) );
		$wins      = count( array_filter( wp_list_pluck( $records, 'won' ) ) );
		$label     = (string) ( $args['label'] ?? '' );
		$uid       = 'aat-arc-' . substr( md5( $label . $count . $wins ), 0, 8 );

		$w      = 960;
		$left   = 64;
		$right  = 896;
		$step   = 28;
		$axis_y = 56 + $max_stack * $step + 24 + ( $wins > 0 && $wins <= 8 ? 30 : 0 );
		$h      = $axis_y + 56;
		$ignite = 2.1;
		$wstep  = self::win_step( $wins, 0.22 );
		$label_wins = $wins > 0 && $wins <= 8;
		$win_labels = array();

		$svg  = '<svg class="aat-motion-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="' . ( self::all_linked( $records ) ? 'group' : 'img' ) . '" aria-labelledby="' . esc_attr( $uid ) . '-title" focusable="false">';
		$svg .= '<title id="' . esc_attr( $uid ) . '-title">' . esc_html(
			sprintf(
				/* translators: 1: name, 2: nominations, 3: wins, 4: first year, 5: last year. */
				__( '%1$s: %2$d nominations and %3$d wins, %4$d to %5$d', 'academy-awards-table' ),
				$label,
				$count,
				$wins,
				$min_year,
				$max_year
			)
		) . '</title>';
		$svg .= '<line class="aat-motion-axis" x1="' . $left . '" y1="' . $axis_y . '" x2="' . $right . '" y2="' . $axis_y . '" pathLength="1"/>';

		$win_order  = 0;
		$last_label = -1000;
		$ticks      = array();
		$i          = 0;
		foreach ( $by_ceremony as $ceremony => $group ) {
			$t    = ( $group[0]['year'] - $min_year ) / $span;
			$x    = $left + $t * ( $right - $left );
			$top  = $axis_y - 30 - ( count( $group ) - 1 ) * $step;
			$svg .= '<line class="aat-motion-stem" style="--t:' . self::n( $t ) . '" x1="' . self::n( $x ) . '" y1="' . $axis_y . '" x2="' . self::n( $x ) . '" y2="' . self::n( $top ) . '" pathLength="1"/>';
			foreach ( $group as $k => $rec ) {
				$y     = $axis_y - 30 - $k * $step;
				$state = $rec['won'] ? 'is-win' : 'is-nom';
				$style = '--t:' . self::n( $t ) . ';--k:' . $k . ';--i:' . $i . ( $rec['won'] ? ';--w:' . $win_order : '' );
				$tip   = trim( $rec['year_label'] . ' · ' . $rec['label'] . ( '' !== $rec['film'] ? ' · ' . $rec['film'] : '' ) . ' — ' . ( $rec['won'] ? __( 'won', 'academy-awards-table' ) : __( 'nominated', 'academy-awards-table' ) ) );
				$link  = self::link_open( $rec, self::spoke_name( $rec, true ) );
				$svg  .= '<g class="aat-motion-spoke ' . $state . '" style="' . esc_attr( $style ) . '">' . $link . '<title>' . esc_html( $tip ) . '</title>';
				if ( $rec['won'] ) {
					$svg .= '<circle class="aat-motion-pulse" cx="' . self::n( $x ) . '" cy="' . $y . '" r="9"/>';
				}
				if ( $link ) {
					$svg .= '<circle class="aat-motion-hit" cx="' . self::n( $x ) . '" cy="' . $y . '" r="13"/>';
				}
				$svg .= '<circle class="aat-motion-node" cx="' . self::n( $x ) . '" cy="' . $y . '" r="8"/>' . ( $link ? '</a>' : '' ) . '</g>';
				if ( $rec['won'] ) {
					if ( $label_wins && '' !== $rec['film'] ) {
						$win_labels[ $ceremony ]['films'][] = $rec['film'];
						$win_labels[ $ceremony ]['x']       = $x;
						$win_labels[ $ceremony ]['top']     = $top;
						$win_labels[ $ceremony ]['t']       = $t;
						$win_labels[ $ceremony ]['w']       = $win_order;
					}
					++$win_order;
				}
				++$i;
			}
			$ticks[] = array( 'x' => $x, 'label' => (string) $group[0]['year'], 't' => $t );
		}

		// Year labels: every ceremony that has room, always the first and the last.
		$last_index = count( $ticks ) - 1;
		foreach ( $ticks as $n => $tick ) {
			$show = 0 === $n || $n === $last_index || ( $tick['x'] - $last_label >= 46 && $ticks[ $last_index ]['x'] - $tick['x'] >= 46 );
			$svg .= '<line class="aat-motion-tick" style="--t:' . self::n( $tick['t'] ) . '" x1="' . self::n( $tick['x'] ) . '" y1="' . ( $axis_y - 5 ) . '" x2="' . self::n( $tick['x'] ) . '" y2="' . ( $axis_y + 5 ) . '"/>';
			if ( $show ) {
				$svg       .= '<text class="aat-motion-year" style="--t:' . self::n( $tick['t'] ) . '" x="' . self::n( $tick['x'] ) . '" y="' . ( $axis_y + 26 ) . '" text-anchor="middle">' . esc_html( $tick['label'] ) . '</text>';
				$last_label = $tick['x'];
			}
		}
		// What was won, named above its column; neighbours alternate height so titles never collide.
		$prev_x = -1000;
		$lift   = 0;
		foreach ( $win_labels as $wl ) {
			$lift  = ( $wl['x'] - $prev_x < 170 ) ? ( $lift ? 0 : 1 ) : 0;
			$films = array_values( array_unique( $wl['films'] ) );
			$text  = self::wrap( implode( ' / ', $films ), 24 )[0];
			$y     = $wl['top'] - 18 - $lift * 18;
			$svg  .= '<text class="aat-motion-win-title" style="--t:' . self::n( $wl['t'] ) . ';--w:' . (int) $wl['w'] . '" x="' . self::n( $wl['x'] ) . '" y="' . self::n( $y ) . '" text-anchor="middle">' . esc_html( $text ) . '</text>';
			$prev_x = $wl['x'];
		}
		$svg .= '</svg>';

		$description = sprintf(
			/* translators: 1: name, 2: first year, 3: last year, 4: wins. */
			_n( '%1$s at the Oscars, %2$d to %3$d: each nomination above its year, the win in gold.', '%1$s at the Oscars, %2$d to %3$d: each nomination above its year, the %4$d wins in gold.', max( 1, $wins ), 'academy-awards-table' ),
			$label,
			$min_year,
			$max_year,
			$wins
		);
		if ( 0 === $wins ) {
			$description = sprintf(
				/* translators: 1: name, 2: first year, 3: last year. */
				__( '%1$s at the Oscars, %2$d to %3$d: each nomination above its year.', 'academy-awards-table' ),
				$label,
				$min_year,
				$max_year
			);
		}

		return self::section(
			'arc',
			__( 'The Career Arc', 'academy-awards-table' ),
			$description,
			$svg,
			$count,
			$wins,
			$ignite,
			$wstep,
			$records,
			true
		);
	}

	/**
	 * Shared section shell: heading, stage, tally and the screen-reader record.
	 */
	private static function section( $kind, $title, $description, $svg, $count, $wins, $ignite, $wstep, $records, $with_film ) {
		$heading_id = 'aat-ledger-motion-' . $kind . '-title';
		if ( self::all_linked( $records ) ) {
			$description .= ' ' . __( 'Select any nomination to open its full race.', 'academy-awards-table' );
		}

		// When every spoke is a link, the links carry the record; otherwise a hidden list does.
		$list = '';
		if ( ! self::all_linked( $records ) ) {
			$list = '<ol class="aat-motion-record">';
			foreach ( $records as $rec ) {
				$list .= '<li>' . esc_html( self::spoke_name( $rec, $with_film ) ) . '</li>';
			}
			$list .= '</ol>';
		}

		$html  = '<section id="ledger-motion" class="aat-entity-section aat-ledger-motion aat-ledger-motion--' . esc_attr( $kind ) . '" data-aat-motion style="--ignite:' . esc_attr( (string) $ignite ) . 's;--wstep:' . esc_attr( (string) $wstep ) . 's" aria-labelledby="' . esc_attr( $heading_id ) . '">';
		$html .= '<div class="aat-section-head"><h2 id="' . esc_attr( $heading_id ) . '" class="aat-section-title">' . esc_html( $title ) . '</h2>';
		$html .= '<p class="aat-section-description">' . esc_html( $description ) . '</p></div>';
		$html .= '<div class="aat-motion-stage" aria-hidden="false">' . $svg . '</div>';
		$html .= '<p class="aat-motion-tally"><span class="aat-motion-tally-num" data-aat-count="' . (int) $count . '">' . esc_html( number_format_i18n( $count ) ) . '</span> ' . esc_html( _n( 'nomination', 'nominations', $count, 'academy-awards-table' ) );
		$html .= ' <span class="aat-motion-tally-sep" aria-hidden="true">·</span> <span class="aat-motion-tally-num is-gold" data-aat-count="' . (int) $wins . '" data-aat-count-phase="ignite">' . esc_html( number_format_i18n( $wins ) ) . '</span> ' . esc_html( _n( 'win', 'wins', $wins, 'academy-awards-table' ) );
		$html .= '</p>' . $list . '</section>';

		return $html;
	}
}

AAT_Ledger_Motion::init();
