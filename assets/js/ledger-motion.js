/**
 * Ledger Motion (2.8.8): plays the Nomination Ring and the Career Arc.
 *
 * The server renders the finished picture. This script hides it (.is-armed)
 * and, when the section is a third into view, plays it back once
 * (.is-playing): the CSS carries the choreography, the script only counts
 * the tally up in step. Nothing plays under prefers-reduced-motion (the phone
 * caption still works), and a section already on screen at load plays immediately.
 */
( function () {
	'use strict';

	var still = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	var phone = window.matchMedia ? window.matchMedia( '(max-width: 640px)' ) : null;

	function countUp( el, from, to, delay, duration ) {
		if ( to <= from ) {
			el.textContent = String( to );
			return;
		}
		el.textContent = String( from );
		var start = 0;
		var step = function ( now ) {
			if ( ! start ) {
				start = now;
			}
			var p = Math.min( 1, ( now - start ) / duration );
			el.textContent = String( Math.round( from + ( to - from ) * p ) );
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			}
		};
		window.setTimeout( function () {
			window.requestAnimationFrame( step );
		}, delay );
	}

	/**
	 * On a narrow screen the arc scrolls inside its frame. While it plays, the
	 * frame pans with the career, first nomination to last; any touch, wheel or
	 * key hands control straight back to the reader. The right edge fades while
	 * there is more to see.
	 */
	function edge( stage ) {
		var more = stage.scrollLeft + stage.clientWidth < stage.scrollWidth - 4;
		stage.classList.toggle( 'has-more', more );
	}

	function pan( stage ) {
		if ( ! stage || stage.scrollWidth <= stage.clientWidth + 4 ) {
			return;
		}
		var stopped = false;
		var stop = function () { stopped = true; };
		[ 'pointerdown', 'wheel', 'touchstart', 'keydown' ].forEach( function ( type ) {
			stage.addEventListener( type, stop, { passive: true, once: true } );
		} );
		var max = stage.scrollWidth - stage.clientWidth;
		var start = 0;
		var duration = 1700;
		var step = function ( now ) {
			if ( stopped ) {
				return;
			}
			if ( ! start ) {
				start = now;
			}
			var p = Math.min( 1, ( now - start ) / duration );
			var eased = p < 0.5 ? 2 * p * p : 1 - Math.pow( -2 * p + 2, 2 ) / 2;
			stage.scrollLeft = max * eased;
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			}
		};
		window.setTimeout( function () {
			window.requestAnimationFrame( step );
		}, 250 );
	}

	function play( section ) {
		var ignite = parseFloat( getComputedStyle( section ).getPropertyValue( '--ignite' ) ) || 2;
		var isRing = section.classList.contains( 'aat-ledger-motion--ring' );
		var spokes = section.querySelectorAll( '.aat-motion-spoke' ).length;
		var wins = section.querySelectorAll( '.aat-motion-spoke.is-win' ).length;
		var winStep = ( parseFloat( getComputedStyle( section ).getPropertyValue( '--wstep' ) ) || ( isRing ? 0.26 : 0.22 ) ) * 1000;

		// Two frames: commit the armed state, then let the transitions run.
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				section.classList.add( 'is-playing' );
			} );
		} );

		if ( ! isRing ) {
			pan( section.querySelector( '.aat-motion-stage' ) );
		}

		section.querySelectorAll( '[data-aat-count]' ).forEach( function ( el ) {
			var to = parseInt( el.getAttribute( 'data-aat-count' ), 10 ) || 0;
			if ( 'ignite' === el.getAttribute( 'data-aat-count-phase' ) ) {
				countUp( el, 0, to, ignite * 1000, Math.max( 1, wins ) * winStep );
			} else {
				countUp( el, 0, to, 350, isRing ? spokes * 90 + 300 : 1500 );
			}
		} );
	}

	/**
	 * 2.8.11: phones hide the labels, so a dot alone says nothing. There the
	 * first tap on a dot names it in a caption under the drawing, with a link
	 * into its race; a second tap on the same dot (or the link) opens the race.
	 * Wider screens keep their labels and one-click links.
	 */
	function caption( section ) {
		if ( ! section.querySelector( '.aat-motion-link' ) ) {
			return;
		}
		var isRing = section.classList.contains( 'aat-ledger-motion--ring' );
		var box = document.createElement( 'div' );
		box.className = 'aat-motion-caption';
		box.setAttribute( 'role', 'status' );
		box.setAttribute( 'aria-live', 'polite' );
		var title = document.createElement( 'span' );
		title.className = 'aat-motion-caption-title';
		title.textContent = isRing ? 'Tap any dot to see the award.' : 'Tap any dot to see the nomination.';
		var meta = document.createElement( 'span' );
		meta.className = 'aat-motion-caption-meta';
		var go = document.createElement( 'a' );
		go.className = 'aat-motion-caption-go';
		go.textContent = 'Open the race \u2192';
		go.hidden = true;
		box.appendChild( title );
		box.appendChild( meta );
		box.appendChild( go );
		var stage = section.querySelector( '.aat-motion-stage' );
		stage.parentNode.insertBefore( box, stage.nextSibling );

		var current = null;
		section.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.aat-motion-link' ) : null;
			if ( ! link || ! phone || ! phone.matches || link === current ) {
				return; // Desktop, or the second tap: follow the link.
			}
			event.preventDefault();
			if ( current ) {
				current.classList.remove( 'is-selected' );
			}
			current = link;
			link.classList.add( 'is-selected' );
			box.classList.add( 'has-pick' );
			box.classList.toggle( 'is-win', !! link.closest( '.is-win' ) );
			title.textContent = link.getAttribute( 'data-aat-cap' ) || '';
			meta.textContent = link.getAttribute( 'data-aat-cap-meta' ) || '';
			go.href = link.getAttribute( 'href' ) || link.getAttribute( 'xlink:href' ) || '';
			go.hidden = ! go.href;
		} );
	}

	function boot() {
		var sections = document.querySelectorAll( '[data-aat-motion]' );
		if ( ! sections.length ) {
			return;
		}
		Array.prototype.forEach.call( sections, caption );
		if ( still ) {
			return; // Reduced motion: the finished picture stays, captions still work.
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return; // The finished picture stays.
		}
		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					observer.unobserve( entry.target );
					play( entry.target );
				}
			} );
		}, { threshold: 0.3 } );

		sections.forEach( function ( section ) {
			var stage = section.querySelector( '.aat-motion-stage' );
			if ( stage && section.classList.contains( 'aat-ledger-motion--arc' ) ) {
				edge( stage );
				stage.addEventListener( 'scroll', function () { edge( stage ); }, { passive: true } );
				window.addEventListener( 'resize', function () { edge( stage ); } );
			}
			section.classList.add( 'is-armed' );
			section.querySelectorAll( '[data-aat-count]' ).forEach( function ( el ) {
				el.textContent = '0';
			} );
			observer.observe( section );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
