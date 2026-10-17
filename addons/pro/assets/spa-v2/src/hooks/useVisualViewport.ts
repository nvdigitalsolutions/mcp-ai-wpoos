/**
 * Pro SPA v2 — visual-viewport keyboard inset hook (Proposal 080, Phase 0).
 *
 * Reports how many pixels of the viewport are covered by the on-screen
 * keyboard. Returns 0 on desktop and in browsers that honor
 * `interactive-widget=resizes-content` (there the layout viewport already
 * shrinks, so the page never extends under the keyboard). On iOS Safari —
 * which keeps the layout viewport and pans the visual viewport instead —
 * it returns the keyboard height so the chat UI can pad itself above it.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.3.0
 */

import { useEffect, useState } from 'react';

/** Ignore tiny insets (rounding noise, browser chrome) below this. */
const MIN_INSET_PX = 40;

/**
 * Subscribe to the visual viewport and return the keyboard inset in px.
 *
 * @return Keyboard inset (0 when nothing is covered).
 */
export function useVisualViewport(): number {
	const [ inset, setInset ] = useState( 0 );

	useEffect( () => {
		if (
			typeof window === 'undefined' ||
			typeof window.visualViewport === 'undefined' ||
			! window.visualViewport
		) {
			return;
		}
		const vv = window.visualViewport;

		let raf = 0;
		const update = () => {
			if ( raf ) {
				return;
			}
			raf = window.requestAnimationFrame( () => {
				raf = 0;
				const overlap = Math.round( window.innerHeight - vv.height );
				setInset( overlap > MIN_INSET_PX ? overlap : 0 );
			} );
		};

		update();
		vv.addEventListener( 'resize', update );
		vv.addEventListener( 'scroll', update );
		window.addEventListener( 'resize', update );

		return () => {
			vv.removeEventListener( 'resize', update );
			vv.removeEventListener( 'scroll', update );
			window.removeEventListener( 'resize', update );
			if ( raf ) {
				window.cancelAnimationFrame( raf );
			}
		};
	}, [] );

	return inset;
}
