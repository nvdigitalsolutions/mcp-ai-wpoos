/**
 * Pro SPA v2 — Keyboard shortcuts hook.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { useCallback, useEffect, useState } from 'react';

export interface ShortcutHandlers {
	onSave?: () => void; onExport?: () => void;
	onNewChat?: () => void; onHelp?: () => void; onEscape?: () => void;
}

export interface UseKeyboardShortcutsResult {
	isHelpOpen: boolean; toggleHelp: () => void; closeHelp: () => void;
}

function isMac(): boolean {
	try { return navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0; } catch { return false; }
}

export function useKeyboardShortcuts( handlers: ShortcutHandlers ): UseKeyboardShortcutsResult {
	const [ isHelpOpen, setHelpOpen ] = useState( false );
	const toggleHelp = useCallback( () => setHelpOpen( ( o ) => ! o ), [] );
	const closeHelp = useCallback( () => setHelpOpen( false ), [] );

	useEffect( () => {
		const mac = isMac();
		const onKeyDown = ( e: KeyboardEvent ) => {
			const target = e.target as HTMLElement | null;
			const isInput = target && ( target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable );
			if ( e.key === 'Escape' ) {
				if ( isHelpOpen ) { e.preventDefault(); setHelpOpen( false ); return; }
				handlers.onEscape?.(); return;
			}
			if ( isInput ) return;
			const mod = mac ? e.metaKey : e.ctrlKey;
			if ( ! mod ) return;
			switch ( e.key?.toLowerCase() ) {
				case 's': if ( ! e.shiftKey ) { e.preventDefault(); handlers.onSave?.(); } break;
				case 'e': if ( ! e.shiftKey ) { e.preventDefault(); handlers.onExport?.(); } break;
				case 'n': if ( ! e.shiftKey ) { e.preventDefault(); handlers.onNewChat?.(); } break;
				case '/': e.preventDefault(); setHelpOpen( ( o ) => ! o ); break;
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ handlers, isHelpOpen ] );

	return { isHelpOpen, toggleHelp, closeHelp };
}
