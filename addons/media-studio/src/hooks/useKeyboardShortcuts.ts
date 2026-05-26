/**
 * Keyboard shortcuts hook.
 *
 * Registers keybindings scoped to the editor surface. Shortcuts follow
 * industry conventions (Ctrl/Cmd modifier on Mac).
 *
 * @since 0.2.0
 */

import { useEffect } from 'react';

export interface Shortcut {
	/** Key value (e.g. 'z', 's', 'r'). Case-insensitive. */
	key: string;
	/** Require Ctrl (or Cmd on Mac). */
	ctrl?: boolean;
	/** Require Shift. */
	shift?: boolean;
	/** Handler. Return `true` to indicate the shortcut was handled. */
	handler: () => void;
	/** Human-readable label (for accessibility announcements). */
	label: string;
}

/**
 * useKeyboardShortcuts — register keyboard bindings for the editor.
 *
 * @param shortcuts Array of shortcut definitions.
 * @param enabled   Whether shortcuts are active (e.g. disable when a modal is open).
 */
export function useKeyboardShortcuts(
	shortcuts: Shortcut[],
	enabled: boolean = true,
) {
	useEffect( () => {
		if ( ! enabled ) {
			return;
		}

		const handleKeyDown = ( e: KeyboardEvent ) => {
			// Ignore when focus is in an input/textarea/select.
			const tag = ( e.target as HTMLElement )?.tagName?.toLowerCase();
			if ( tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'button' ) {
				return;
			}

			const ctrl = e.ctrlKey || e.metaKey;

			for ( const sc of shortcuts ) {
				if (
					e.key.toLowerCase() === sc.key.toLowerCase() &&
					( sc.ctrl ?? false ) === ctrl &&
					( sc.shift ?? false ) === e.shiftKey
				) {
					e.preventDefault();
					sc.handler();
					return;
				}
			}
		};

		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [ shortcuts, enabled ] );
}
