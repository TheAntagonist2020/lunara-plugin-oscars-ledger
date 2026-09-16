<?php
/**
 * WordPress 6.8.3 shortcode parser fixture, unmodified functions.
 * https://github.com/WordPress/WordPress/blob/6.8.3/wp-includes/shortcodes.php
 * Copyright the WordPress contributors. GPL-2.0-or-later.
 */
function get_shortcode_regex( $tagnames = null ) {
	global $shortcode_tags;

	if ( empty( $tagnames ) ) {
		$tagnames = array_keys( $shortcode_tags );
	}
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

	/*
	 * WARNING! Do not change this regex without changing do_shortcode_tag() and strip_shortcode_tag().
	 * Also, see shortcode_unautop() and shortcode.js.
	 */

	// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
	return '\\['                             // Opening bracket.
		. '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
		. "($tagregexp)"                     // 2: Shortcode name.
		. '(?![\\w-])'                       // Not followed by word character or hyphen.
		. '('                                // 3: Unroll the loop: Inside the opening shortcode tag.
		.     '[^\\]\\/]*'                   // Not a closing bracket or forward slash.
		.     '(?:'
		.         '\\/(?!\\])'               // A forward slash not followed by a closing bracket.
		.         '[^\\]\\/]*'               // Not a closing bracket or forward slash.
		.     ')*?'
		. ')'
		. '(?:'
		.     '(\\/)'                        // 4: Self closing tag...
		.     '\\]'                          // ...and closing bracket.
		. '|'
		.     '\\]'                          // Closing bracket.
		.     '(?:'
		.         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
		.             '[^\\[]*+'             // Not an opening bracket.
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
		.                 '[^\\[]*+'         // Not an opening bracket.
		.             ')*+'
		.         ')'
		.         '\\[\\/\\2\\]'             // Closing shortcode tag.
		.     ')?'
		. ')'
		. '(\\]?)';                          // 6: Optional second closing bracket for escaping shortcodes: [[tag]].
	// phpcs:enable
}

function get_shortcode_atts_regex() {
	return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
}

function shortcode_parse_atts( $text ) {
	$atts    = array();
	$pattern = get_shortcode_atts_regex();
	$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );
	if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
		foreach ( $match as $m ) {
			if ( ! empty( $m[1] ) ) {
				$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
			} elseif ( ! empty( $m[3] ) ) {
				$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
			} elseif ( ! empty( $m[5] ) ) {
				$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
			} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
				$atts[] = stripcslashes( $m[7] );
			} elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
				$atts[] = stripcslashes( $m[8] );
			} elseif ( isset( $m[9] ) ) {
				$atts[] = stripcslashes( $m[9] );
			}
		}

		// Reject any unclosed HTML elements.
		foreach ( $atts as &$value ) {
			if ( str_contains( $value, '<' ) ) {
				if ( 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
					$value = '';
				}
			}
		}
	}

	return $atts;
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		if ( array_key_exists( $name, $atts ) ) {
			$out[ $name ] = $atts[ $name ];
		} else {
			$out[ $name ] = $default;
		}
	}

	if ( $shortcode ) {
		/**
		 * Filters shortcode attributes.
		 *
		 * If the third parameter of the shortcode_atts() function is present then this filter is available.
		 * The third parameter, $shortcode, is the name of the shortcode.
		 *
		 * @since 3.6.0
		 * @since 4.4.0 Added the `$shortcode` parameter.
		 *
		 * @param array  $out       The output array of shortcode attributes.
		 * @param array  $pairs     The supported attributes and their defaults.
		 * @param array  $atts      The user defined shortcode attributes.
		 * @param string $shortcode The shortcode name.
		 */
		$out = apply_filters( "shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode );
	}

	return $out;
}

function has_shortcode( $content, $tag ) {
	if ( ! str_contains( $content, '[' ) ) {
		return false;
	}

	if ( shortcode_exists( $tag ) ) {
		preg_match_all( '/' . get_shortcode_regex() . '/', $content, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return false;
		}

		foreach ( $matches as $shortcode ) {
			if ( $tag === $shortcode[2] ) {
				return true;
			} elseif ( ! empty( $shortcode[5] ) && has_shortcode( $shortcode[5], $tag ) ) {
				return true;
			}
		}
	}
	return false;
}

function shortcode_exists( $tag ) {
	global $shortcode_tags;
	return array_key_exists( $tag, $shortcode_tags );
}
