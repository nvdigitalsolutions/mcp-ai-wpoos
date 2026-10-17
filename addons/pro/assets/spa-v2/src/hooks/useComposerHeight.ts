/**
 * Pro SPA v2 — persisted composer height hook (Proposal 080, Phase 2).
 *
 * Holds the user's manually resized composer height in px. `null` means
 * "auto" (the composer sizes itself from its content). Values are clamped
 * to [MIN_COMPOSER_HEIGHT, 60% of the viewport / MAX_COMPOSER_HEIGHT] and
 * persisted to localStorage so the choice survives reloads.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.3.0
 */

import { useCallback, useState } from 'react';

const STORAGE_KEY = 'nvoos-pro-spa.composerHeight';

/** Minimum composer height in px (never smaller than the toolbar). */
export const MIN_COMPOSER_HEIGHT = 72;

/** Absolute ceiling in px, in addition to the 60% viewport cap. */
const MAX_COMPOSER_HEIGHT = 600;

/** Composer may never exceed this fraction of the viewport height. */
const MAX_VIEWPORT_FRACTION = 0.6;

/**
 * The largest composer height the current viewport allows.
 *
 * @return Max height in px.
 */
export function maxComposerHeight(): number {
	const innerHeight =
		typeof window !== 'undefined' ? window.innerHeight : 800;
	return Math.max(
		MIN_COMPOSER_HEIGHT,
		Math.min( MAX_COMPOSER_HEIGHT, Math.round( innerHeight * MAX_VIEWPORT_FRACTION ) )
	);
}

/**
 * Clamp a composer height to the allowed range.
 *
 * @param value Raw height in px.
 * @return Clamped height in px.
 */
export function clampComposerHeight( value: number ): number {
	if ( ! Number.isFinite( value ) ) {
		return maxComposerHeight();
	}
	return Math.min( Math.max( Math.round( value ), MIN_COMPOSER_HEIGHT ), maxComposerHeight() );
}

/**
 * Read the persisted composer height (clamped).
 *
 * @return Height in px, or null when unset/unavailable.
 */
function readStored(): number | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		if ( ! raw ) {
			return null;
		}
		const parsed = Number( raw );
		return Number.isFinite( parsed ) ? clampComposerHeight( parsed ) : null;
	} catch {
		return null;
	}
}

/**
 * Composer height state with clamping + localStorage persistence.
 *
 * @return Current height (null = auto) and a setter (null resets to auto).
 */
export function useComposerHeight(): {
	height: number | null;
	setHeight: ( value: number | null ) => void;
} {
	const [ height, setHeightState ] = useState< number | null >( readStored );

	const setHeight = useCallback( ( value: number | null ) => {
		const next = value === null ? null : clampComposerHeight( value );
		setHeightState( next );
		try {
			if ( next === null ) {
				window.localStorage.removeItem( STORAGE_KEY );
			} else {
				window.localStorage.setItem( STORAGE_KEY, String( next ) );
			}
		} catch {
			// Private mode / storage disabled — keep the in-memory value.
		}
	}, [] );

	return { height, setHeight };
}
