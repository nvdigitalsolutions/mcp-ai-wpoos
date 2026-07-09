/**
 * NV oOS Chat SPA — Keyboard shortcuts help modal.
 *
 * Displays a modal overlay listing the available keyboard shortcuts.
 * Automatically detects macOS vs Windows to show the correct modifier
 * key name (Cmd vs Ctrl).
 *
 * Accessibility:
 *   - role="dialog" + aria-modal="true"
 *   - Focus is trapped inside the modal
 *   - Overlay click and close button dismiss
 *   - Escape key dismiss (handled by useKeyboardShortcuts)
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useEffect, useRef } from 'react';

export interface KeyboardShortcutsHelpProps {
	isOpen: boolean;
	onClose: () => void;
}

function isMac(): boolean {
	if ( typeof navigator === 'undefined' ) return false;
	try {
		return navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0;
	} catch {
		return false;
	}
}

interface ShortcutDef {
	keys: string;
	description: string;
}

export function KeyboardShortcutsHelp( {
	isOpen,
	onClose,
}: KeyboardShortcutsHelpProps ): JSX.Element | null {
	const modKey = isMac() ? 'Cmd' : 'Ctrl';
	const dialogRef = useRef< HTMLDivElement | null >( null );

	// Trap focus inside the modal when open.
	useEffect( () => {
		if ( ! isOpen || ! dialogRef.current ) return;
		const dialog = dialogRef.current;
		const focusable = dialog.querySelectorAll< HTMLElement >(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		first?.focus();

		const onKeyDown = ( e: KeyboardEvent ) => {
			if ( e.key !== 'Tab' ) return;
			if ( e.shiftKey ) {
				if ( document.activeElement === first && last ) {
					e.preventDefault();
					last.focus();
				}
			} else {
				if ( document.activeElement === last && first ) {
					e.preventDefault();
					first.focus();
				}
			}
		};

		dialog.addEventListener( 'keydown', onKeyDown );
		return () => dialog.removeEventListener( 'keydown', onKeyDown );
	}, [ isOpen ] );

	if ( ! isOpen ) return null;

	const shortcuts: ShortcutDef[] = [
		{ keys: `${ modKey } + S`, description: __( 'Save conversation', 'nvoos-chat-spa' ) },
		{ keys: `${ modKey } + E`, description: __( 'Export conversation', 'nvoos-chat-spa' ) },
		{ keys: `${ modKey } + N`, description: __( 'Start new conversation', 'nvoos-chat-spa' ) },
		{ keys: `${ modKey } + /`, description: __( 'Show this help', 'nvoos-chat-spa' ) },
		{ keys: 'Escape', description: __( 'Close modals or clear status', 'nvoos-chat-spa' ) },
	];

	return (
		<div
			className="nvoos-chat-spa-shortcuts-help"
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Keyboard shortcuts', 'nvoos-chat-spa' ) }
			ref={ dialogRef }
		>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
			<div
				className="nvoos-chat-spa-shortcuts-help-overlay"
				onClick={ onClose }
			/>
			<div className="nvoos-chat-spa-shortcuts-help-modal">
				<h2 className="nvoos-chat-spa-shortcuts-help-title">
					{ __( 'Keyboard Shortcuts', 'nvoos-chat-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-chat-spa-shortcuts-help-close"
					aria-label={ __( 'Close', 'nvoos-chat-spa' ) }
					onClick={ onClose }
				>
					&times;
				</button>
				<ul className="nvoos-chat-spa-shortcuts-help-list">
					{ shortcuts.map( ( s ) => (
						<li key={ s.keys } className="nvoos-chat-spa-shortcuts-help-item">
							<kbd className="nvoos-chat-spa-shortcuts-help-key">{ s.keys }</kbd>
							<span className="nvoos-chat-spa-shortcuts-help-desc">
								{ s.description }
							</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
