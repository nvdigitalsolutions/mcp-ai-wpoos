/**
 * NV oOS Chat SPA — saved messages (bookmark) hook.
 *
 * Persists bookmarked message content to localStorage so the user can
 * save individual assistant responses for later reference.  The saved
 * state is per-message, keyed by a content hash so the same text is
 * recognised even if the message id changes (e.g. after a session reload).
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useMemo } from 'react';

const SAVED_KEY = 'nvoos-chat-spa.saved-messages';

interface SavedEntry {
	content: string;
	savedAt: number;
}

function hashContent( text: string ): string {
	// Simple djb2 hash — sufficient for deduplication within a session.
	let hash = 5381;
	for ( let i = 0; i < text.length; i++ ) {
		hash = ( ( hash << 5 ) + hash + text.charCodeAt( i ) ) | 0;
	}
	return 's' + ( hash >>> 0 ).toString( 36 );
}

function loadSaved(): Map< string, SavedEntry > {
	if ( typeof window === 'undefined' ) {
		return new Map();
	}
	try {
		const raw = window.localStorage.getItem( SAVED_KEY );
		if ( ! raw ) return new Map();
		const parsed: Record< string, SavedEntry > = JSON.parse( raw );
		return new Map( Object.entries( parsed ) );
	} catch {
		return new Map();
	}
}

function persistSaved( map: Map< string, SavedEntry > ): void {
	if ( typeof window === 'undefined' ) return;
	try {
		window.localStorage.setItem(
			SAVED_KEY,
			JSON.stringify( Object.fromEntries( map ) )
		);
	} catch {
		// Ignore quota errors.
	}
}

export function useSavedMessages() {
	// Load once per mount.  For a SPA this is fine; if multi-tab support
	// becomes necessary we can add a storage event listener.
	const saved = useMemo( () => loadSaved(), [] );

	const isSaved = useCallback(
		( content: string ): boolean => {
			const key = hashContent( content );
			return saved.has( key );
		},
		[ saved ]
	);

	const save = useCallback(
		( content: string ): void => {
			const key = hashContent( content );
			if ( saved.has( key ) ) return;
			saved.set( key, { content, savedAt: Date.now() } );
			persistSaved( saved );
		},
		[ saved ]
	);

	const unsave = useCallback(
		( content: string ): void => {
			const key = hashContent( content );
			saved.delete( key );
			persistSaved( saved );
		},
		[ saved ]
	);

	const toggle = useCallback(
		( content: string ): boolean => {
			if ( isSaved( content ) ) {
				unsave( content );
				return false;
			}
			save( content );
			return true;
		},
		[ isSaved, save, unsave ]
	);

	return { isSaved, save, unsave, toggle };
}
