/**
 * CommandPalette — Overlay command palette (Ctrl+K / Cmd+K).
 *
 * Fuzzy-searches registered commands and navigates on selection.
 */

import { type JSX, useCallback, useEffect, useRef, type Ref } from 'react';
import { __ } from '@wordpress/i18n';

/* eslint-disable jsx-a11y/no-noninteractive-element-interactions, jsx-a11y/click-events-have-key-events */
import { useCommandPalette } from '../../hooks/useCommandPalette';
import type { Command } from '../../stores/commandStore';

export function CommandPalette(): JSX.Element {
	const {
		query,
		results,
		selectedIndex,
		close,
		setQuery,
		selectNext,
		selectPrev,
		executeSelected,
	} = useCommandPalette();

	const inputRef = useRef< HTMLInputElement >( null );
	const listRef = useRef< HTMLUListElement >( null );

	useEffect( () => {
		inputRef.current?.focus();
	}, [] );

	// Scroll selected item into view.
	useEffect( () => {
		const item = listRef.current?.children[ selectedIndex ] as HTMLElement | undefined;
		item?.scrollIntoView( { block: 'nearest' } );
	}, [ selectedIndex ] );

	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent ) => {
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					selectNext();
					break;
				case 'ArrowUp':
					e.preventDefault();
					selectPrev();
					break;
				case 'Enter':
					e.preventDefault();
					executeSelected();
					break;
				case 'Escape':
					e.preventDefault();
					close();
					break;
			}
		},
		[ selectNext, selectPrev, executeSelected, close ]
	);

	const handleOverlayClick = useCallback(
		( e: React.MouseEvent ) => {
			if ( e.target === e.currentTarget ) {
				close();
			}
		},
		[ close ]
	);

	return (
		<div
			className="nvoos-pro-spa-command-palette-overlay"
			onClick={ handleOverlayClick }
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Command palette', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-command-palette">
				<div className="nvoos-pro-spa-command-palette__input-wrap">
					<input
						ref={ inputRef }
						type="text"
						className="nvoos-pro-spa-command-palette__input"
						placeholder={ __( 'Type a command…', 'nvoos-pro-spa' ) }
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						onKeyDown={ handleKeyDown }
						aria-label={ __( 'Search commands', 'nvoos-pro-spa' ) }
						autoComplete="off"
					/>
				</div>

				<div className="nvoos-pro-spa-command-palette__results">
					{ results.length === 0 ? (
						<div className="nvoos-pro-spa-command-palette__empty">
							{ __( 'No matching commands', 'nvoos-pro-spa' ) }
						</div>
					) : (
						<CommandList
							ref={ listRef }
							results={ results }
							selectedIndex={ selectedIndex }
							onSelect={ executeSelected }
						/>
					) }
				</div>
			</div>
		</div>
	);
}

// ── Command list ──────────────────────────────────────────────────────────────

interface CommandListProps {
	results: Command[];
	selectedIndex: number;
	onSelect: () => void;
	ref?: Ref< HTMLUListElement >;
}

function CommandList( { results, selectedIndex, onSelect, ref }: CommandListProps ): JSX.Element {
	const grouped = groupByCategory( results );

	return (
		<ul ref={ ref } className="nvoos-pro-spa-command-palette__list" role="listbox">
			{ Object.entries( grouped ).map( ( [ category, commands ] ) => (
				<li key={ category } className="nvoos-pro-spa-command-palette__group">
					<div className="nvoos-pro-spa-command-palette__group-label">
						{ categoryLabel( category ) }
					</div>
					<ul role="group">
						{ commands.map( ( cmd, idx ) => {
							const globalIdx = results.indexOf( cmd );
							return (
								<li
									key={ cmd.id }
									className={ [
										'nvoos-pro-spa-command-palette__item',
										globalIdx === selectedIndex
											? 'nvoos-pro-spa-command-palette__item--selected'
											: '',
									]
										.filter( Boolean )
										.join( ' ' ) }
									role="option"
									aria-selected={ globalIdx === selectedIndex }
									onClick={ onSelect }
								>
									<span className="nvoos-pro-spa-command-palette__item-label">
										{ cmd.label }
									</span>
									{ cmd.description && (
										<span className="nvoos-pro-spa-command-palette__item-desc">
											{ cmd.description }
										</span>
									) }
								</li>
							);
						} ) }
					</ul>
				</li>
			) ) }
		</ul>
	);
}



// ── Helpers ───────────────────────────────────────────────────────────────────

function groupByCategory( commands: Command[] ): Record< string, Command[] > {
	const groups: Record< string, Command[] > = {};
	for ( const cmd of commands ) {
		const cat = cmd.category || 'other';
		if ( ! groups[ cat ] ) {
			groups[ cat ] = [];
		}
		groups[ cat ].push( cmd );
	}
	return groups;
}

function categoryLabel( category: string ): string {
	switch ( category ) {
		case 'navigation':
			return __( 'Navigation', 'nvoos-pro-spa' );
		case 'action':
			return __( 'Actions', 'nvoos-pro-spa' );
		case 'tool':
			return __( 'Tools', 'nvoos-pro-spa' );
		case 'thread':
			return __( 'Threads', 'nvoos-pro-spa' );
		default:
			return category;
	}
}
