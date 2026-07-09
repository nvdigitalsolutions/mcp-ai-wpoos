/**
 * Pro SPA v2 — Slash Commands Drawer
 *
 * Slide-in panel showing registered slash commands organized by category,
 * with search. Clicking inserts the command into the composer.
 *
 * Accessibility:
 *   - role="dialog" aria-modal="false" (non-blocking — chat stays live)
 *   - ESC closes, toggle button re-gets focus
 *
 * @since 2.1.0
 */

import { __, sprintf } from '@wordpress/i18n';
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
	SlashCommandsClient,
	type SlashCommand,
} from '../../api/slashCommands';

// ── Types ──────────────────────────────────────────────────────────────────

export interface SlashCommandsDrawerProps {
	endpoint: string;
	nonce: string;
	isOpen: boolean;
	onClose: () => void;
	/** Called when the user clicks a command. */
	onInsertPayload: ( payload: string, autoSubmit?: boolean ) => void;
	/** The toggle button ref — focus returns here on close. */
	toggleRef: React.RefObject< HTMLButtonElement | null >;
}

// ── Component ──────────────────────────────────────────────────────────────

export function SlashCommandsDrawer( {
	endpoint,
	nonce,
	isOpen,
	onClose,
	onInsertPayload,
	toggleRef,
}: SlashCommandsDrawerProps ): JSX.Element | null {
	const client = useMemo(
		() => new SlashCommandsClient( { endpoint, nonce } ),
		[ endpoint, nonce ]
	);

	const [ commands, setCommands ] = useState< SlashCommand[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ search, setSearch ] = useState( '' );

	const drawerRef = useRef< HTMLDivElement | null >( null );
	const searchRef = useRef< HTMLInputElement | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	// ── Data fetching ────────────────────────────────────────────────────────

	const fetchCommands = useCallback( async () => {
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		setLoading( true );
		setError( null );

		try {
			const data = await client.list( search, controller.signal );
			if ( ! controller.signal.aborted ) {
				setCommands( data.commands );
			}
		} catch ( err ) {
			if ( ! controller.signal.aborted ) {
				setError(
					err instanceof Error ? err.message : __( 'Failed to load slash commands.', 'nvoos-pro-spa' )
				);
			}
		} finally {
			if ( ! controller.signal.aborted ) {
				setLoading( false );
			}
		}
	}, [ client, search ] );

	useEffect( () => {
		if ( isOpen ) {
			fetchCommands();
			setTimeout( () => searchRef.current?.focus(), 100 );
		}

		return () => {
			abortRef.current?.abort();
		};
	}, [ isOpen, fetchCommands ] );

	// ── Keyboard ─────────────────────────────────────────────────────────────

	const handleKeyDown = useCallback(
		( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
				toggleRef.current?.focus();
			}
		},
		[ onClose, toggleRef ]
	);

	// ── Click handler ────────────────────────────────────────────────────────

	const handleCommandClick = useCallback(
		( command: SlashCommand, shiftKey: boolean ) => {
			// Insert "/command " into the composer.
			const payload = `/${ command.command } `;
			onInsertPayload( payload, shiftKey );
		},
		[ onInsertPayload ]
	);

	// ── Group by category ────────────────────────────────────────────────────

	const grouped = useMemo( () => {
		if ( ! commands ) {
			return null;
		}

		const groups: Record< string, SlashCommand[] > = {};

		for ( const cmd of commands ) {
			const cat = cmd.category || __( 'General', 'nvoos-pro-spa' );
			if ( ! groups[ cat ] ) {
				groups[ cat ] = [];
			}
			groups[ cat ].push( cmd );
		}

		// Sort categories: System first, then alphabetical.
		const sorted: Record< string, SlashCommand[] > = {};
		const keys = Object.keys( groups );
		const systemIdx = keys.indexOf( __( 'System', 'nvoos-pro-spa' ) );
		if ( systemIdx > 0 ) {
			keys.splice( systemIdx, 1 );
			keys.unshift( __( 'System', 'nvoos-pro-spa' ) );
		}
		for ( const k of keys.sort( ( a, b ) => {
			// Keep System at top.
			if ( a === __( 'System', 'nvoos-pro-spa' ) ) return -1;
			if ( b === __( 'System', 'nvoos-pro-spa' ) ) return 1;
			return a.localeCompare( b );
		} ) ) {
			sorted[ k ] = groups[ k ];
		}

		return sorted;
	}, [ commands ] );

	// ── Not open ─────────────────────────────────────────────────────────────

	if ( ! isOpen ) {
		return null;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<div
			ref={ drawerRef }
			className="nvoos-pro-spa-slash-commands-drawer"
			role="dialog"
			aria-modal="false"
			aria-label={ __( 'Slash Commands', 'nvoos-pro-spa' ) }
			onKeyDown={ handleKeyDown }
		>
			{/* Header */}
			<div className="nvoos-pro-spa-slash-commands-drawer-header">
				<h2 className="nvoos-pro-spa-slash-commands-drawer-title">
					{ __( 'Slash Commands', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-slash-commands-drawer-close"
					onClick={ onClose }
					aria-label={ __( 'Close slash commands', 'nvoos-pro-spa' ) }
				>
					✕
				</button>
			</div>

			{/* Search */}
			<div className="nvoos-pro-spa-slash-commands-drawer-search">
				<input
					ref={ searchRef }
					type="search"
					className="nvoos-pro-spa-slash-commands-drawer-search-input"
					placeholder={ __( 'Search commands…', 'nvoos-pro-spa' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					aria-label={ __( 'Search slash commands', 'nvoos-pro-spa' ) }
				/>
			</div>

			{/* Status messages (ARIA live) */}
			<div
				className="nvoos-pro-spa-screen-reader-only"
				role="status"
				aria-live="polite"
			>
				{ loading && __( 'Loading slash commands…', 'nvoos-pro-spa' ) }
				{ error &&
					sprintf(
						/* translators: %s: error message */
						__( 'Error: %s', 'nvoos-pro-spa' ),
						error
					) }
			</div>

			{/* Body */}
			<div className="nvoos-pro-spa-slash-commands-drawer-body">
				{ loading && (
					<div className="nvoos-pro-spa-slash-commands-drawer-loading">
						{ __( 'Loading…', 'nvoos-pro-spa' ) }
					</div>
				) }

				{ error && (
					<div className="nvoos-pro-spa-slash-commands-drawer-error">
						<p>{ error }</p>
						<button
							type="button"
							className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
							onClick={ fetchCommands }
						>
							{ __( 'Retry', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				{ ! loading && ! error && commands && commands.length === 0 && (
					<div className="nvoos-pro-spa-slash-commands-drawer-empty">
						{ search
							? __( 'No commands match your search.', 'nvoos-pro-spa' )
							: __( 'No slash commands available.', 'nvoos-pro-spa' ) }
					</div>
				) }

				{ grouped && (
					<>
						{ Object.entries( grouped ).map( ( [ category, items ] ) => (
							<section
								key={ category }
								className="nvoos-pro-spa-slash-commands-drawer-section"
							>
								<h3 className="nvoos-pro-spa-slash-commands-drawer-section-title">
									{ category }
								</h3>
								{ items.map( ( cmd ) => (
									<button
										key={ cmd.command }
										type="button"
										className="nvoos-pro-spa-slash-commands-drawer-item"
										title={ sprintf(
											/* translators: 1: command usage, 2: description */
											__( '%1$s — %2$s (Shift+Click to submit)', 'nvoos-pro-spa' ),
											cmd.usage,
											cmd.description || ''
										) }
										onClick={ ( e ) =>
											handleCommandClick( cmd, e.shiftKey )
										}
									>
										<code className="nvoos-pro-spa-slash-commands-drawer-item-code">
											{ cmd.usage }
										</code>
										{ cmd.description && (
											<span className="nvoos-pro-spa-slash-commands-drawer-item-desc">
												{ cmd.description }
											</span>
										) }
									</button>
								) ) }
							</section>
						) ) }
					</>
				) }
			</div>
		</div>
	);
}
