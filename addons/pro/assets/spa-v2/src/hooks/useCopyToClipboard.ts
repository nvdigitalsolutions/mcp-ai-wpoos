/**
 * useCopyToClipboard — Hook for copying text to clipboard.
 */

import { useState, useCallback } from 'react';

export interface UseCopyToClipboardReturn {
	copied: boolean;
	copy: ( text: string ) => Promise< void >;
}

export function useCopyToClipboard(): UseCopyToClipboardReturn {
	const [ copied, setCopied ] = useState< boolean >( false );

	const copy = useCallback( async ( text: string ) => {
		try {
			await navigator.clipboard.writeText( text );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Fallback for older browsers.
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		}
	}, [] );

	return { copied, copy };
}
