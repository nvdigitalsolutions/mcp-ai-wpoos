/**
 * Pro SPA v2 — Keyboard shortcuts help modal.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useEffect, useRef } from 'react';

export interface KeyboardShortcutsHelpProps { isOpen: boolean; onClose: () => void; }

function isMac(): boolean { try { return navigator.platform.toUpperCase().indexOf( 'MAC' ) >= 0; } catch { return false; } }

export function KeyboardShortcutsHelp( { isOpen, onClose }: KeyboardShortcutsHelpProps ): JSX.Element | null {
	const modKey = isMac() ? 'Cmd' : 'Ctrl';
	const dialogRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( ! isOpen || ! dialogRef.current ) return;
		const d = dialogRef.current;
		const focusable = d.querySelectorAll< HTMLElement >( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		const first = focusable[ 0 ], last = focusable[ focusable.length - 1 ];
		first?.focus();
		const onKeyDown = ( e: KeyboardEvent ) => {
			if ( e.key !== 'Tab' ) return;
			if ( e.shiftKey ) { if ( document.activeElement === first && last ) { e.preventDefault(); last.focus(); } }
			else { if ( document.activeElement === last && first ) { e.preventDefault(); first.focus(); } }
		};
		d.addEventListener( 'keydown', onKeyDown );
		return () => d.removeEventListener( 'keydown', onKeyDown );
	}, [ isOpen ] );

	if ( ! isOpen ) return null;

	const shortcuts = [
		{ keys: `${ modKey } + S`, desc: __( 'Save conversation', 'nvoos-pro-spa' ) },
		{ keys: `${ modKey } + E`, desc: __( 'Export conversation', 'nvoos-pro-spa' ) },
		{ keys: `${ modKey } + N`, desc: __( 'Start new conversation', 'nvoos-pro-spa' ) },
		{ keys: `${ modKey } + /`, desc: __( 'Show this help', 'nvoos-pro-spa' ) },
		{ keys: 'Escape', desc: __( 'Close modals', 'nvoos-pro-spa' ) },
	];

	return (
		<div className="nvoos-pro-spa-shortcuts-help" role="dialog" aria-modal="true" aria-label={ __( 'Keyboard shortcuts', 'nvoos-pro-spa' ) } ref={ dialogRef }>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
			<div className="nvoos-pro-spa-shortcuts-help__overlay" onClick={ onClose } />
			<div className="nvoos-pro-spa-shortcuts-help__modal">
				<h2 className="nvoos-pro-spa-shortcuts-help__title">{ __( 'Keyboard Shortcuts', 'nvoos-pro-spa' ) }</h2>
				<button type="button" className="nvoos-pro-spa-shortcuts-help__close" aria-label={ __( 'Close', 'nvoos-pro-spa' ) } onClick={ onClose }>&times;</button>
				<ul className="nvoos-pro-spa-shortcuts-help__list">
					{ shortcuts.map( ( s ) => (
						<li key={ s.keys } className="nvoos-pro-spa-shortcuts-help__item">
							<kbd className="nvoos-pro-spa-shortcuts-help__key">{ s.keys }</kbd>
							<span className="nvoos-pro-spa-shortcuts-help__desc">{ s.desc }</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
