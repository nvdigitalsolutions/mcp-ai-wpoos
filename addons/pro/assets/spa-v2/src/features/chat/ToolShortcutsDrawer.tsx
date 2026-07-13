/**
 * Pro SPA v2 — Tool Shortcuts Drawer
 *
 * Slide-in panel showing assistant-scoped tool shortcut cards,
 * organized by category, with search and favorites.
 *
 * Accessibility:
 *   - role="dialog" aria-modal="false" (non-blocking — chat stays live)
 *   - ESC closes, toggle button re-gets focus
 *   - ARIA-live region for status messages
 *
 * @since 2.1.0
 */

import { __, sprintf } from '@wordpress/i18n';

/* eslint-disable jsx-a11y/no-noninteractive-element-interactions */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type JSX,
	type KeyboardEvent,
} from 'react';
import {
	ToolShortcutsClient,
	type ToolShortcut,
} from '../../api/toolShortcuts';

// ── localStorage persistence ──────────────────────────────────────────────

const FAVORITES_KEY_PREFIX = 'nvoos-pro-spa.shortcut-favorites';

function readFavorites( assistantId: number | string ): Set< string > {
	try {
		const raw = localStorage.getItem(
			`${ FAVORITES_KEY_PREFIX }.${ assistantId }`
		);
		if ( raw ) {
			return new Set( JSON.parse( raw ) as string[] );
		}
	} catch {
		// Ignore parse errors.
	}
	return new Set();
}

function persistFavorites(
	assistantId: number | string,
	favorites: Set< string >
): void {
	try {
		localStorage.setItem(
			`${ FAVORITES_KEY_PREFIX }.${ assistantId }`,
			JSON.stringify( [ ...favorites ] )
		);
	} catch {
		// Storage full or unavailable.
	}
}

// ── Types ──────────────────────────────────────────────────────────────────

export interface ToolShortcutsDrawerProps {
	endpoint: string;
	nonce: string;
	assistantId: number | string;
	isOpen: boolean;
	onClose: () => void;
	/** Called when the user clicks a shortcut. */
	onInsertPayload: ( payload: string, autoSubmit?: boolean ) => void;
	/** The toggle button ref — focus returns here on close. */
	toggleRef: React.RefObject< HTMLButtonElement | null >;
}

// ── Component ──────────────────────────────────────────────────────────────

export function ToolShortcutsDrawer( {
	endpoint,
	nonce,
	assistantId,
	isOpen,
	onClose,
	onInsertPayload,
	toggleRef,
}: ToolShortcutsDrawerProps ): JSX.Element | null {
	const client = useMemo(
		() => new ToolShortcutsClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const [ shortcuts, setShortcuts ] = useState< ToolShortcut[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ favorites, setFavorites ] = useState< Set< string > >(
		() => readFavorites( assistantId )
	);

	const drawerRef = useRef< HTMLDivElement | null >( null );
	const searchRef = useRef< HTMLInputElement | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	// ── Data fetching ────────────────────────────────────────────────────────

	const fetchShortcuts = useCallback( async () => {
		if ( ! assistantId ) {
			return;
		}

		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		setLoading( true );
		setError( null );

		try {
			const data = await client.list( assistantId, search, controller.signal );
			if ( ! controller.signal.aborted ) {
				setShortcuts( data.shortcuts );
			}
		} catch ( err ) {
			if ( ! controller.signal.aborted ) {
				setError(
					err instanceof Error ? err.message : __( 'Failed to load tool shortcuts.', 'nvoos-pro-spa' )
				);
			}
		} finally {
			if ( ! controller.signal.aborted ) {
				setLoading( false );
			}
		}
	}, [ client, assistantId, search ] );

	useEffect( () => {
		if ( isOpen ) {
			fetchShortcuts();
			// Focus search input on open.
			setTimeout( () => searchRef.current?.focus(), 100 );
		}

		return () => {
			abortRef.current?.abort();
		};
	}, [ isOpen, fetchShortcuts ] );

	// ── Keyboard ──────────────────────────────────────────────────────────────

	const handleKeyDown = useCallback(
		( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
				toggleRef.current?.focus();
			}
		},
		[ onClose, toggleRef ]
	);

	// ── Favorites ─────────────────────────────────────────────────────────────

	const toggleFavorite = useCallback(
		( id: string ) => {
			setFavorites( ( prev ) => {
				const next = new Set( prev );
				if ( next.has( id ) ) {
					next.delete( id );
				} else {
					next.add( id );
				}
				persistFavorites( assistantId, next );
				return next;
			} );
		},
		[ assistantId ]
	);

	// ── Click handler ─────────────────────────────────────────────────────────

	const handleShortcutClick = useCallback(
		( shortcut: ToolShortcut, shiftKey: boolean ) => {
			onInsertPayload( shortcut.payload, shiftKey );
		},
		[ onInsertPayload ]
	);

	// ── Group by category ─────────────────────────────────────────────────────

	const grouped = useMemo( () => {
		if ( ! shortcuts ) {
			return null;
		}

		const favs: ToolShortcut[] = [];
		const groups: Record< string, ToolShortcut[] > = {};

		for ( const s of shortcuts ) {
			if ( favorites.has( s.id ) ) {
				favs.push( s );
			}
			const cat = s.category || __( 'General', 'nvoos-pro-spa' );
			if ( ! groups[ cat ] ) {
				groups[ cat ] = [];
			}
			groups[ cat ].push( s );
		}

		return { favorites: favs, groups };
	}, [ shortcuts, favorites ] );

	// ── Not open ──────────────────────────────────────────────────────────────

	if ( ! isOpen ) {
		return null;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div
			ref={ drawerRef }
			className="nvoos-pro-spa-tool-shortcuts-drawer"
			role="dialog"
			aria-modal="false"
			aria-label={ __( 'Tool Shortcuts', 'nvoos-pro-spa' ) }
			onKeyDown={ handleKeyDown }
		>
			{/* Header */}
			<div className="nvoos-pro-spa-tool-shortcuts-drawer-header">
				<h2 className="nvoos-pro-spa-tool-shortcuts-drawer-title">
					{ __( 'Tool Shortcuts', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-tool-shortcuts-drawer-close"
					onClick={ onClose }
					aria-label={ __( 'Close tool shortcuts', 'nvoos-pro-spa' ) }
				>
					✕
				</button>
			</div>

			{/* Search */}
			<div className="nvoos-pro-spa-tool-shortcuts-drawer-search">
				<input
					ref={ searchRef }
					type="search"
					className="nvoos-pro-spa-tool-shortcuts-drawer-search-input"
					placeholder={ __( 'Search tools…', 'nvoos-pro-spa' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					aria-label={ __( 'Search tool shortcuts', 'nvoos-pro-spa' ) }
				/>
			</div>

			{/* Status messages (ARIA live) */}
			<div className="nvoos-pro-spa-screen-reader-only" role="status" aria-live="polite">
				{ loading && __( 'Loading tool shortcuts…', 'nvoos-pro-spa' ) }
				{ error && sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'nvoos-pro-spa' ),
					error
				) }
			</div>

			{/* Body */}
			<div className="nvoos-pro-spa-tool-shortcuts-drawer-body">
				{ loading && (
					<div className="nvoos-pro-spa-tool-shortcuts-drawer-loading">
						{ __( 'Loading…', 'nvoos-pro-spa' ) }
					</div>
				) }

				{ error && (
					<div className="nvoos-pro-spa-tool-shortcuts-drawer-error">
						<p>{ error }</p>
						<button
							type="button"
							className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
							onClick={ fetchShortcuts }
						>
							{ __( 'Retry', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				{ ! loading && ! error && shortcuts && shortcuts.length === 0 && (
					<div className="nvoos-pro-spa-tool-shortcuts-drawer-empty">
						{ search
							? __( 'No tools match your search.', 'nvoos-pro-spa' )
							: sprintf(
								/* translators: %d: assistant ID */
								__(
									'No tool shortcuts configured for this assistant. Visit the assistant editor (ID: %d) to add shortcuts.',
									'nvoos-pro-spa'
								),
								Number( assistantId )
							) }
					</div>
				) }

				{ ! assistantId && (
					<div className="nvoos-pro-spa-tool-shortcuts-drawer-empty">
						{ __( 'Select an assistant to browse tools.', 'nvoos-pro-spa' ) }
					</div>
				) }

				{ grouped && (
					<>
						{/* Favorites section */}
						{ grouped.favorites.length > 0 && (
							<section className="nvoos-pro-spa-tool-shortcuts-drawer-section">
								<h3 className="nvoos-pro-spa-tool-shortcuts-drawer-section-title">
									⭐ { __( 'Favorites', 'nvoos-pro-spa' ) }
								</h3>
								{ grouped.favorites.map( ( s ) => (
									<ShortcutCard
										key={ s.id }
										shortcut={ s }
										isFavorite={ true }
										onToggleFavorite={ toggleFavorite }
										onClick={ handleShortcutClick }
									/>
								) ) }
							</section>
						) }

						{/* Category sections */}
						{ Object.entries( grouped.groups ).map(
							( [ category, items ] ) => (
								<section
									key={ category }
									className="nvoos-pro-spa-tool-shortcuts-drawer-section"
								>
									<h3 className="nvoos-pro-spa-tool-shortcuts-drawer-section-title">
										{ category }
									</h3>
									{ items.map( ( s ) => (
										<ShortcutCard
											key={ s.id }
											shortcut={ s }
											isFavorite={ favorites.has( s.id ) }
											onToggleFavorite={ toggleFavorite }
											onClick={ handleShortcutClick }
										/>
									) ) }
								</section>
							)
						) }
					</>
				) }
			</div>
		</div>
	);
}

// ── Shortcut Card ────────────────────────────────────────────────────────────

interface ShortcutCardProps {
	shortcut: ToolShortcut;
	isFavorite: boolean;
	onToggleFavorite: ( id: string ) => void;
	onClick: ( shortcut: ToolShortcut, shiftKey: boolean ) => void;
}

function ShortcutCard( {
	shortcut,
	isFavorite,
	onToggleFavorite,
	onClick,
}: ShortcutCardProps ): JSX.Element {
	return (
		<button
			type="button"
			className="nvoos-pro-spa-tool-shortcuts-drawer-card"
			title={ shortcut.description
				? `${ shortcut.description } (${ __( 'Shift+Click to submit', 'nvoos-pro-spa' ) })`
				: __( 'Shift+Click to submit', 'nvoos-pro-spa' ) }
			onClick={ ( e ) => onClick( shortcut, e.shiftKey ) }
		>
			<span className="nvoos-pro-spa-tool-shortcuts-drawer-card-icon" aria-hidden="true">
				{ shortcut.icon }
			</span>
			<div className="nvoos-pro-spa-tool-shortcuts-drawer-card-text">
				<span className="nvoos-pro-spa-tool-shortcuts-drawer-card-label">
					{ shortcut.label }
				</span>
				{ shortcut.description && (
					<span className="nvoos-pro-spa-tool-shortcuts-drawer-card-desc">
						{ shortcut.description }
					</span>
				) }
			</div>
			<button
				type="button"
				className="nvoos-pro-spa-tool-shortcuts-drawer-card-fav"
				aria-label={
					isFavorite
						? __( 'Remove from favorites', 'nvoos-pro-spa' )
						: __( 'Add to favorites', 'nvoos-pro-spa' )
				}
				onClick={ ( e ) => {
					e.stopPropagation();
					onToggleFavorite( shortcut.id );
				} }
			>
				{ isFavorite ? '★' : '☆' }
			</button>
		</button>
	);
}
