/**
 * SearchBox — header search input with Cmd-K shortcut and dropdown results.
 *
 * Queries the REST API; falls back to the local FlexSearch adapter
 * when the network request fails.
 *
 * @since 1.0.0
 */

import { useEffect, useRef, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { fetchSearch } from '../api/manifest-client';
import { localSearch } from '../search/flexsearch-adapter';
import type { SearchResult } from '../api/manifest-client';

const DEBOUNCE_MS = 200;

export default function SearchBox() {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState<SearchResult[]>( [] );
	const [ open, setOpen ] = useState( false );
	const [ activeIdx, setActiveIdx ] = useState( -1 );
	const inputRef = useRef<HTMLInputElement>( null );
	const dropdownRef = useRef<HTMLDivElement>( null );
	const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>( null );
	const navigate = useNavigate();

	// Cmd-K / Ctrl-K shortcut.
	useEffect( () => {
		function onKeyDown( e: KeyboardEvent ) {
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 'k' ) {
				e.preventDefault();
				inputRef.current?.focus();
				inputRef.current?.select();
			}
		}
		window.addEventListener( 'keydown', onKeyDown );
		return () => window.removeEventListener( 'keydown', onKeyDown );
	}, [] );

	// Close dropdown when clicking outside.
	useEffect( () => {
		function onPointerDown( e: PointerEvent ) {
			if (
				inputRef.current && ! inputRef.current.contains( e.target as Node ) &&
				dropdownRef.current && ! dropdownRef.current.contains( e.target as Node )
			) {
				setOpen( false );
			}
		}
		window.addEventListener( 'pointerdown', onPointerDown );
		return () => window.removeEventListener( 'pointerdown', onPointerDown );
	}, [] );

	const doSearch = useCallback( async ( q: string ) => {
		if ( ! q.trim() ) {
			setResults( [] );
			setOpen( false );
			return;
		}

		try {
			const resp = await fetchSearch( q );
			setResults( resp.results );
			setOpen( true );
		} catch {
			// Fall back to local index.
			const local = await localSearch( q );
			setResults(
				local.map( ( r ) => ( { slug: r.slug, title: r.title, excerpt: r.excerpt, score: r.score } ) )
			);
			setOpen( true );
		}
	}, [] );

	function handleChange( e: React.ChangeEvent<HTMLInputElement> ) {
		const q = e.target.value;
		setQuery( q );
		setActiveIdx( -1 );

		if ( debounceTimer.current ) {
			clearTimeout( debounceTimer.current );
		}
		debounceTimer.current = setTimeout( () => doSearch( q ), DEBOUNCE_MS );
	}

	function handleKeyDown( e: React.KeyboardEvent<HTMLInputElement> ) {
		if ( ! open ) {
			return;
		}
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setActiveIdx( ( i ) => Math.min( i + 1, results.length - 1 ) );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setActiveIdx( ( i ) => Math.max( i - 1, -1 ) );
		} else if ( e.key === 'Enter' && activeIdx >= 0 ) {
			e.preventDefault();
			const r = results[ activeIdx ];
			if ( r ) {
				selectResult( r.slug );
			}
		} else if ( e.key === 'Escape' ) {
			setOpen( false );
		}
	}

	function selectResult( slug: string ) {
		setQuery( '' );
		setResults( [] );
		setOpen( false );
		navigate( `/${ slug }` );
	}

	return (
		<div className="dh-search-input-wrap" role="search">
			<input
				ref={ inputRef }
				type="search"
				className="dh-search-input"
				placeholder="Search docs…"
				aria-label="Search documentation"
				value={ query }
				onChange={ handleChange }
				onKeyDown={ handleKeyDown }
				onFocus={ () => { if ( results.length ) setOpen( true ); } }
				autoComplete="off"
				spellCheck={ false }
			/>
			<span className="dh-search-shortcut" aria-hidden="true">⌘K</span>

			{ open && (
				<div
					ref={ dropdownRef }
					className="dh-search-dropdown"
					role="listbox"
					aria-label="Search results"
				>
					{ results.length === 0 ? (
						<div className="dh-search-empty">No results found.</div>
					) : (
						results.map( ( r, idx ) => (
							<div
								key={ r.slug }
								className={ `dh-search-result-item${ activeIdx === idx ? ' dh-active' : '' }` }
								role="option"
								tabIndex={ -1 }
								aria-selected={ activeIdx === idx }
								onClick={ () => selectResult( r.slug ) }
								onKeyDown={ ( e ) => {
									if ( e.key === 'Enter' || e.key === ' ' ) {
										e.preventDefault();
										selectResult( r.slug );
									}
								} }
								onMouseEnter={ () => setActiveIdx( idx ) }
							>
								<div className="dh-search-result-title">{ r.title }</div>
								{ r.excerpt && (
									<div className="dh-search-result-excerpt">{ r.excerpt }</div>
								) }
							</div>
						) )
					) }
				</div>
			) }
		</div>
	);
}
