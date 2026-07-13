/**
 * Pro SPA v2 — Inline command autocomplete hook.
 *
 * Watches the composer textarea for "/" triggers and manages
 * the autocomplete dropdown state: fetching, filtering, selection,
 * and keyboard navigation.
 *
 * Mirrors the legacy chat-client command-autocomplete.js behaviour.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.2.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';

// ── Types ──────────────────────────────────────────────────────────────────

export interface AutoCompleteCommand {
	command: string;
	description?: string;
	usage?: string;
}

export interface UseCommandAutocompleteResult {
	/** Whether the autocomplete dropdown should be visible. */
	isOpen: boolean;
	/** Filtered commands matching the current query. */
	matches: AutoCompleteCommand[];
	/** Current selection index (-1 = none). */
	selectedIndex: number;
	/** Close the dropdown; returns true if it was visible. */
	close: () => boolean;
	/** Select a command by name: replaces "/…" in the input and closes the dropdown. */
	selectCommand: ( cmd: AutoCompleteCommand ) => string;
	/** Handle keydown for keyboard navigation (returns true if consumed). */
	handleKeyDown: ( e: React.KeyboardEvent< HTMLTextAreaElement > ) => boolean;
	/** Ref to attach to the textarea for positioning. */
	inputRef: React.RefObject< HTMLTextAreaElement | null >;
}

// ── Constants ──────────────────────────────────────────────────────────────

const SLASH_TRIGGER = '/';
const MAX_RESULTS = 8;

// ── Fuzzy match ────────────────────────────────────────────────────────────

function fuzzyMatch( str: string, pattern: string ): boolean {
	let pi = 0;
	let si = 0;
	const s = str.toLowerCase();
	const p = pattern.toLowerCase();
	while ( pi < p.length && si < s.length ) {
		if ( s[ si ] === p[ pi ] ) {
			pi++;
		}
		si++;
	}
	return pi === p.length;
}

function fuzzyFilter(
	commands: AutoCompleteCommand[],
	query: string,
): AutoCompleteCommand[] {
	if ( ! query ) {
		return commands.slice( 0, MAX_RESULTS );
	}
	const q = query.toLowerCase();
	return commands
		.filter( ( cmd ) => {
			const name = cmd.command.toLowerCase();
			const desc = ( cmd.description ?? '' ).toLowerCase();
			const searchText = name + ' ' + desc;
			return searchText.includes( q ) || fuzzyMatch( name, q );
		} )
		.slice( 0, MAX_RESULTS );
}

// ── Slash-range helpers ────────────────────────────────────────────────────

/**
 * Given the current input value and cursor position, find the range of
 * the "/command" token that is being typed.  Returns null if the
 * cursor is not inside a slash command.
 */
function getSlashRange(
	value: string,
	cursorPos: number | null,
): { start: number; query: string } | null {
	if ( cursorPos === null ) return null;

	// Walk back from cursor to find the "/" that starts this token.
	let start = cursorPos;
	while ( start > 0 && value[ start - 1 ] !== ' ' && value[ start - 1 ] !== '\n' ) {
		start--;
	}

	if ( value[ start ] !== SLASH_TRIGGER ) return null;

	// The token must be at word-start (preceded by space/start-of-input).
	if ( start > 0 && value[ start - 1 ] !== ' ' && value[ start - 1 ] !== '\n' ) {
		return null;
	}

	// Don't show autocomplete if there's already a space after the command.
	const afterCursor = value.slice( cursorPos );
	if ( afterCursor.includes( ' ' ) || afterCursor.includes( '\n' ) ) {
		return null;
	}

	const query = value.slice( start + 1, cursorPos );
	return { start, query };
}

// ── Hook ───────────────────────────────────────────────────────────────────

export function useCommandAutocomplete(
	endpoint: string,
	nonce: string,
	currentInput: string,
	cursorPos: number | null,
	onInsertText: ( text: string ) => void,
	isStreaming: boolean,
): UseCommandAutocompleteResult {
	const [ commands, setCommands ] = useState< AutoCompleteCommand[] >( [] );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ selectedIndex, setSelectedIndex ] = useState( 0 );

	const cachedEndpoint = useRef( endpoint );
	const inputRef = useRef< HTMLTextAreaElement | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	// ── Fetch commands on mount ──────────────────────────────────────────
	useEffect( () => {
		if ( ! endpoint || isStreaming ) return;

		cachedEndpoint.current = endpoint;
		abortRef.current?.abort();
		const ac = new AbortController();
		abortRef.current = ac;

		fetch( endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': nonce,
			},
			signal: ac.signal,
		} )
			.then( ( r ) => r.json() )
			.then( ( data: { commands?: AutoCompleteCommand[] } ) => {
				if ( ! ac.signal.aborted ) {
					const list = ( data.commands ?? [] ).map( ( c ) => ( {
						command: c.command,
						description: c.description,
						usage: c.usage,
					} ) );
					setCommands( list );

					if ( typeof console !== 'undefined' && console.info ) {
						console.info(
							'[NV oOS Pro SPA] Autocomplete initialized',
							{ commandCount: list.length },
						);
					}
				}
			} )
			.catch( () => {
				// Silently ignore fetch errors — autocomplete is non-critical.
			} );

		return () => ac.abort();
	}, [ endpoint, nonce, isStreaming ] );

	// ── Derive open / matches from current input ─────────────────────────
	const slash = getSlashRange( currentInput, cursorPos );
	const shouldOpen =
		!! slash &&
		! isStreaming &&
		commands.length > 0 &&
		slash.query.length < 50; // Don't autocomplete very long tokens.

	const matches = shouldOpen
		? fuzzyFilter( commands, slash.query )
		: [];

	// Open / close side-effect.
	useEffect( () => {
		if ( shouldOpen && matches.length > 0 ) {
			setIsOpen( true );
			setSelectedIndex( 0 );
		} else {
			setIsOpen( false );
			setSelectedIndex( -1 );
		}
	}, [ shouldOpen, matches.length ] );

	// ── Close ────────────────────────────────────────────────────────────
	const close = useCallback( (): boolean => {
		if ( ! isOpen ) return false;
		setIsOpen( false );
		setSelectedIndex( -1 );
		return true;
	}, [ isOpen ] );

	// ── Select ───────────────────────────────────────────────────────────
	const selectCommand = useCallback(
		( cmd: AutoCompleteCommand ): string => {
			const s = getSlashRange( currentInput, cursorPos );
			if ( ! s ) {
				close();
				return currentInput;
			}

			const before = currentInput.slice( 0, s.start );
			const after = currentInput.slice( cursorPos ?? s.start + s.query.length + 1 );
			const newText = `${ before }/${ cmd.command } ${ after }`;

			onInsertText( newText );
			close();
			return newText;
		},
		[ currentInput, cursorPos, onInsertText, close ],
	);

	// ── Keyboard ─────────────────────────────────────────────────────────
	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent< HTMLTextAreaElement > ): boolean => {
			if ( ! isOpen ) return false;

			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					setSelectedIndex( ( i ) =>
						Math.min( i + 1, matches.length - 1 ),
					);
					return true;

				case 'ArrowUp':
					e.preventDefault();
					setSelectedIndex( ( i ) => Math.max( i - 1, 0 ) );
					return true;

				case 'Enter':
				case 'Tab':
					if (
						selectedIndex >= 0 &&
						selectedIndex < matches.length
					) {
						e.preventDefault();
						selectCommand( matches[ selectedIndex ] );
						return true;
					}
					return false;

				case 'Escape':
					e.preventDefault();
					close();
					return true;

				default:
					return false;
			}
		},
		[ isOpen, matches, selectedIndex, selectCommand, close ],
	);

	return {
		isOpen,
		matches,
		selectedIndex,
		close,
		selectCommand,
		handleKeyDown,
		inputRef,
	};
}
