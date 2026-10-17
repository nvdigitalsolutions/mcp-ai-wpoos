/**
 * Pro SPA v2 — composer auto-grow hook (Proposal 080, Phase 1).
 *
 * Grows a textarea to fit its content. On browsers that support the
 * native CSS `field-sizing: content` (Chrome 123+, Firefox 152+,
 * Safari 26.2+) this hook does nothing — the stylesheet owns growth and
 * the `max-height` cap. On older engines it falls back to measuring
 * `scrollHeight` on input and container resizes. The JS path only ever
 * sets the *natural* height; the CSS `max-height` cap still decides the
 * used height, so the two mechanisms can never fight.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.3.0
 */

import { useEffect } from 'react';

/**
 * Keep a textarea at its natural content height (capped by CSS).
 *
 * Re-measures when `value` changes so programmatic sets (media inserts,
 * slash commands, edit-message, voice transcription) grow the box too —
 * not just keystrokes.
 *
 * @param ref     Ref to the textarea.
 * @param value   Controlled value of the textarea.
 * @param enabled When false (e.g. the resize divider holds a manual
 *                composer height), the hook stays dormant and clears
 *                any inline height it set earlier so the stylesheet's
 *                height takes over again.
 */
export function useAutoGrowTextarea(
	ref: React.RefObject< HTMLTextAreaElement | null >,
	value: string,
	enabled = true
): void {
	useEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}

		// Manual sizing owns the height — drop any inline height a
		// previous fallback run left so the stylesheet's height wins.
		if ( ! enabled ) {
			el.style.height = '';
			return;
		}

		// Native path — CSS `field-sizing: content` + the `max-height` cap
		// in main.css handle growth and internal scroll. Clear any inline
		// height a previous fallback run may have left behind.
		if (
			typeof window !== 'undefined' &&
			typeof window.CSS !== 'undefined' &&
			typeof window.CSS.supports === 'function' &&
			window.CSS.supports( 'field-sizing', 'content' )
		) {
			el.style.height = '';
			return;
		}

		let raf = 0;
		const resize = () => {
			if ( raf ) {
				return;
			}
			raf = window.requestAnimationFrame( () => {
				raf = 0;
				el.style.height = 'auto';
				el.style.height = `${ el.scrollHeight }px`;
			} );
		};

		resize();
		el.addEventListener( 'input', resize );
		// Covers container width changes (window resize, rotation, sidebar
		// collapse) that re-wrap the text without firing an input event.
		const ro =
			typeof ResizeObserver !== 'undefined'
				? new ResizeObserver( resize )
				: null;
		ro?.observe( el );

		return () => {
			el.removeEventListener( 'input', resize );
			ro?.disconnect();
			if ( raf ) {
				window.cancelAnimationFrame( raf );
			}
		};
	}, [ ref, value, enabled ] );
}
