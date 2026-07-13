/**
 * Pro SPA v2 — Inline Command Autocomplete Dropdown.
 *
 * Renders a positioned popover below the composer textarea showing
 * matching slash commands as the user types "/".
 *
 * Keyboard: ↑↓ navigate, Enter/Tab select, Escape close.
 * Mouse: click to select, hover highlights.
 *
 * Mirrors the legacy chat-client command-autocomplete.js behaviour.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.2.0
 */

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import type {
	AutoCompleteCommand,
	UseCommandAutocompleteResult,
} from '../../hooks/useCommandAutocomplete';

export interface CommandAutocompleteProps {
	autocomplete: UseCommandAutocompleteResult;
}

export function CommandAutocomplete( {
	autocomplete,
}: CommandAutocompleteProps ): JSX.Element | null {
	const { isOpen, matches, selectedIndex, selectCommand, close } =
		autocomplete;

	const listRef = useRef< HTMLUListElement | null >( null );
	const popoverRef = useRef< HTMLDivElement | null >( null );
	const [ pos, setPos ] = useState< { top?: number; bottom?: number; left: number; width: number } >( { bottom: 0, left: 0, width: 280 } );

	// Position the popover above the composer textarea.
	useLayoutEffect( () => {
		if ( ! isOpen ) return;
		const textarea = document.getElementById( 'nvoos-pro-spa-composer-input' );
		if ( ! textarea ) return;
		const rect = textarea.getBoundingClientRect();
		const width = Math.max( 280, rect.width );
		const gap = 4;
		// Preferred: position above the textarea so it's always visible.
		if ( rect.top > 280 ) {
			setPos( { bottom: window.innerHeight - rect.top + gap, left: rect.left, width } );
		} else {
			// Not enough room above — fall back to below.
			setPos( { top: rect.bottom + gap, left: rect.left, width } );
		}
	}, [ isOpen, matches ] );

	// Local hover override for selected index.
	const [ hoverIndex, setHoverIndex ] = useState< number | null >( null );
	const activeIndex = hoverIndex !== null ? hoverIndex : selectedIndex;

	// Scroll selected item into view.
	useEffect( () => {
		if ( ! isOpen || ! listRef.current ) return;
		const item = listRef.current.children[ activeIndex ] as
			| HTMLElement
			| undefined;
		item?.scrollIntoView( { block: 'nearest' } );
	}, [ isOpen, activeIndex, matches ] );

	// Close on click outside.
	useEffect( () => {
		if ( ! isOpen ) return;

		const handler = ( e: MouseEvent ) => {
			const popover = listRef.current?.parentElement;
			if (
				popover &&
				e.target instanceof Node &&
				! popover.contains( e.target )
			) {
				close();
			}
		};

		// Delay so the "/" click that opened it doesn't close it immediately.
		const timer = setTimeout(
			() => document.addEventListener( 'mousedown', handler ),
			100,
		);
		return () => {
			clearTimeout( timer );
			document.removeEventListener( 'mousedown', handler );
		};
	}, [ isOpen, close ] );

	const handleItemClick = useCallback(
		( cmd: AutoCompleteCommand ) => {
			selectCommand( cmd );
		},
		[ selectCommand ],
	);

	// Reset hover when matches change.
	useEffect( () => { setHoverIndex( null ); }, [ matches ] );

	if ( ! isOpen || matches.length === 0 ) return null;

	return (
		<div
			ref={ popoverRef }
			className="nvoos-pro-spa-autocomplete"
			role="listbox"
			aria-label={ __( 'Command suggestions', 'nvoos-pro-spa' ) }
			style={ {
				position: 'fixed',
				...( pos.top !== undefined ? { top: pos.top } : {} ),
				...( pos.bottom !== undefined ? { bottom: pos.bottom } : {} ),
				left: pos.left,
				width: pos.width,
			} }
		>
			<ul ref={ listRef } className="nvoos-pro-spa-autocomplete__list">
				{ matches.map( ( cmd, idx ) => (
					<li
						key={ cmd.command }
						className={ [
							'nvoos-pro-spa-autocomplete__item',
							idx === activeIndex
								? 'nvoos-pro-spa-autocomplete__item--selected'
								: '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						role="option"
						aria-selected={ idx === activeIndex }
						onMouseDown={ ( e ) => {
							// mousedown fires before blur, so we use it
							// to prevent the textarea blur from closing
							// the dropdown before selection registers.
							e.preventDefault();
							handleItemClick( cmd );
						} }
						onMouseEnter={ () => setHoverIndex( idx ) }
						onMouseLeave={ () => setHoverIndex( null ) }
					>
						<code className="nvoos-pro-spa-autocomplete__cmd">
							/{ cmd.command }
						</code>
						{ cmd.description && (
							<span className="nvoos-pro-spa-autocomplete__desc">
								{ cmd.description }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<div className="nvoos-pro-spa-autocomplete__footer">
				<span className="nvoos-pro-spa-autocomplete__hint">
					{ __( '↑↓ navigate', 'nvoos-pro-spa' ) }
				</span>
				<span className="nvoos-pro-spa-autocomplete__hint">
					{ __( '↵ select', 'nvoos-pro-spa' ) }
				</span>
				<span className="nvoos-pro-spa-autocomplete__hint">
					{ __( 'esc close', 'nvoos-pro-spa' ) }
				</span>
			</div>
		</div>
	);
}
