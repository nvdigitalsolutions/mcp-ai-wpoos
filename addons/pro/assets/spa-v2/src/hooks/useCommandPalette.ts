/**
 * useCommandPalette — Hook for the command palette overlay.
 */

import { useState, useCallback, useEffect } from 'react';
import { useCommandStore, type Command } from '../stores/commandStore';

export interface UseCommandPaletteReturn {
	isOpen: boolean;
	query: string;
	results: Command[];
	selectedIndex: number;

	open: () => void;
	close: () => void;
	toggle: () => void;
	setQuery: ( q: string ) => void;
	selectNext: () => void;
	selectPrev: () => void;
	executeSelected: () => void;
}

export function useCommandPalette(): UseCommandPaletteReturn {
	const commands = useCommandStore( ( s ) => s.commands );

	const [ isOpen, setIsOpen ] = useState< boolean >( false );
	const [ query, setQuery ] = useState< string >( '' );
	const [ selectedIndex, setSelectedIndex ] = useState< number >( 0 );

	// Filter commands based on query.
	const results = query
		? commands.filter(
				( c ) =>
					c.label.toLowerCase().includes( query.toLowerCase() ) ||
					( c.description?.toLowerCase().includes( query.toLowerCase() ) ?? false )
		  )
		: commands;

	// Reset selection when results change.
	useEffect( () => {
		setSelectedIndex( 0 );
	}, [ results.length ] );

	const open = useCallback( () => {
		setIsOpen( true );
		setQuery( '' );
		setSelectedIndex( 0 );
	}, [] );

	const close = useCallback( () => {
		setIsOpen( false );
		setQuery( '' );
	}, [] );

	const toggle = useCallback( () => {
		if ( isOpen ) {
			close();
		} else {
			open();
		}
	}, [ isOpen, open, close ] );

	const selectNext = useCallback( () => {
		setSelectedIndex( ( prev ) =>
			results.length > 0 ? ( prev + 1 ) % results.length : 0
		);
	}, [ results.length ] );

	const selectPrev = useCallback( () => {
		setSelectedIndex( ( prev ) =>
			results.length > 0 ? ( prev - 1 + results.length ) % results.length : 0
		);
	}, [ results.length ] );

	const executeSelected = useCallback( () => {
		const selected = results[ selectedIndex ];
		if ( selected ) {
			selected.handler();
			close();
		}
	}, [ results, selectedIndex, close ] );

	// Global keyboard shortcut: Ctrl+K / Cmd+K.
	useEffect( () => {
		const handler = ( e: KeyboardEvent ) => {
			if ( ( e.ctrlKey || e.metaKey ) && e.key === 'k' ) {
				e.preventDefault();
				toggle();
			}
			if ( e.key === 'Escape' && isOpen ) {
				close();
			}
		};
		window.addEventListener( 'keydown', handler );
		return () => window.removeEventListener( 'keydown', handler );
	}, [ toggle, isOpen, close ] );

	return {
		isOpen,
		query,
		results,
		selectedIndex,
		open,
		close,
		toggle,
		setQuery,
		selectNext,
		selectPrev,
		executeSelected,
	};
}
