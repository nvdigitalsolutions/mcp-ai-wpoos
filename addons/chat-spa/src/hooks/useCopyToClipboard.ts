/**
 * NV oOS Chat SPA — clipboard utility hook.
 *
 * Provides a `copy` function that writes text to the system clipboard and
 * returns a boolean indicating success.  Uses the modern async Clipboard
 * API when available with a fallback to the legacy `document.execCommand`
 * approach for older browsers.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useState } from 'react';

export interface UseCopyToClipboardReturn {
	/** Copy text to clipboard. Returns true on success. */
	copy: ( text: string ) => Promise< boolean >;
	/** True while the "Copied!" feedback is showing. */
	justCopied: boolean;
	/** Trigger a brief "Copied!" visual state (auto-clears after 2 s). */
	markCopied: () => void;
}

export function useCopyToClipboard(): UseCopyToClipboardReturn {
	const [ justCopied, setJustCopied ] = useState( false );

	const markCopied = useCallback( () => {
		setJustCopied( true );
		setTimeout( () => setJustCopied( false ), 2000 );
	}, [] );

	const copy = useCallback(
		async ( text: string ): Promise< boolean > => {
			try {
				if ( navigator.clipboard?.writeText ) {
					await navigator.clipboard.writeText( text );
					markCopied();
					return true;
				}
			} catch {
				// Fall through to legacy approach.
			}
			return fallbackCopy( text, markCopied );
		},
		[ markCopied ]
	);

	return { copy, justCopied, markCopied };
}

/**
 * Fallback copy using a temporary textarea + execCommand.
 * Necessary for older browsers and some sandboxed contexts.
 */
function fallbackCopy(
	text: string,
	onSuccess: () => void
): boolean {
	const textarea = document.createElement( 'textarea' );
	textarea.value = text;
	textarea.style.position = 'fixed';
	textarea.style.left = '-9999px';
	textarea.style.top = '-9999px';
	document.body.appendChild( textarea );
	textarea.focus();
	textarea.select();
	let succeeded = false;
	try {
		succeeded = document.execCommand( 'copy' );
	} catch {
		// Silently fail.
	}
	document.body.removeChild( textarea );
	if ( succeeded ) {
		onSuccess();
	}
	return succeeded;
}
