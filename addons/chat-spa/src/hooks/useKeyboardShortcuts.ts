/**
 * NV oOS Chat SPA — Keyboard shortcuts hook.
 *
 * Registers a global keydown listener that fires action callbacks when
 * the user presses recognised shortcut combinations.  Shortcuts are
 * suppressed while the user is typing in an input or textarea (except
 * for Escape, which always works).
 *
 * Modifier key is auto-detected: Cmd on macOS, Ctrl everywhere else.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useEffect, useState } from 'react';

export interface ShortcutHandlers {
	/** Ctrl/Cmd+S — save conversation. */
	onSave?: () => void;
	/** Ctrl/Cmd+E — export conversation. */
	onExport?: () => void;
	/** Ctrl/Cmd+N — start new conversation. */
	onNewChat?: () => void;
	/** Ctrl/Cmd+/ — toggle shortcuts help modal. */
	onHelp?: () => void;
	/** Escape — close modals or clear status. */
	onEscape?: () => void;
}

export interface UseKeyboardShortcutsResult {
	/** Whether the shortcuts help modal is currently open. */
	isHelpOpen: boolean;
	/** Toggle the help modal. */
	toggleHelp: () => void;
	/** Close the help modal. */
	closeHelp: () => void;
}

function isMac(): boolean {
	if ( typeof navigator === 'undefined' ) return false;
	try {
		return navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0;
	} catch {
		return false;
	}
}

/**
 * Hook: keyboard shortcuts.
 *
 * Call once per app mount.  Handlers should be stable (memoised) to
 * avoid re-binding listeners on every render.
 */
export function useKeyboardShortcuts(
	handlers: ShortcutHandlers
): UseKeyboardShortcutsResult {
	const [ isHelpOpen, setHelpOpen ] = useState( false );

	const toggleHelp = useCallback( () => {
		setHelpOpen( ( o ) => ! o );
	}, [] );

	const closeHelp = useCallback( () => {
		setHelpOpen( false );
	}, [] );

	useEffect( () => {
		if ( typeof document === 'undefined' ) return;

		const mac = isMac();

		const onKeyDown = ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement | null;
			const isInput =
				target &&
				( target.tagName === 'INPUT' ||
					target.tagName === 'TEXTAREA' ||
					target.isContentEditable );

			// Escape always works, even in inputs.
			if ( event.key === 'Escape' || event.keyCode === 27 ) {
				if ( isHelpOpen ) {
					event.preventDefault();
					setHelpOpen( false );
					return;
				}
				if ( handlers.onEscape ) {
					handlers.onEscape();
				}
				return;
			}

			// Don't trigger shortcuts when the user is typing.
			if ( isInput ) {
				return;
			}

			const modKey = mac ? event.metaKey : event.ctrlKey;
			if ( ! modKey ) return;

			const key = event.key?.toLowerCase();

			switch ( key ) {
				case 's':
					if ( ! event.shiftKey ) {
						event.preventDefault();
						handlers.onSave?.();
					}
					break;
				case 'e':
					if ( ! event.shiftKey ) {
						event.preventDefault();
						handlers.onExport?.();
					}
					break;
				case 'n':
					if ( ! event.shiftKey ) {
						event.preventDefault();
						handlers.onNewChat?.();
					}
					break;
				case '/':
					event.preventDefault();
					setHelpOpen( ( o ) => ! o );
					break;
			}
		};

		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ handlers, isHelpOpen ] );

	return { isHelpOpen, toggleHelp, closeHelp };
}
