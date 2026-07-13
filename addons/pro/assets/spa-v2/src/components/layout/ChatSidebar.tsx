/**
 * ChatSidebar — Left sidebar with two tabs: Chats and Media.
 *
 * Architecture: **Chats-first** — the "Chats" tab is the default
 * and the primary way to start, switch, and delete chat sessions. The "Media"
 * tab provides a browsable WordPress Media Library with grid/list views.
 *
 * Transcripts data is received from the parent Layout component.
 */

import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type { TranscriptSession } from '../../api/transcripts';
import { useAssistantStore } from '../../stores/assistantStore';
import { useUIStore } from '../../stores/uiStore';
import { useModelStore } from '../../stores/modelStore';
import { AssistantsClient, type AssistantRecord } from '../../api/assistants';
import { readProSpaConfig } from '../../api/config';
import { MediaTab } from '../media/MediaTab';
import type { MediaItem } from '../../api/media';
import { VectorStoreIndicator } from '../shared/VectorStoreIndicator';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Format an ISO-ish date string to a short display form (e.g. "09-Jul-26"). */
function formatShortDate( iso: string | undefined ): string {
	if ( ! iso ) return '';
	const d = new Date( iso );
	if ( isNaN( d.getTime() ) ) return '';
	const months = [
		'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
		'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
	];
	const day = String( d.getDate() ).padStart( 2, '0' );
	const mon = months[ d.getMonth() ];
	const year = String( d.getFullYear() ).slice( -2 );
	return `${ day }-${ mon }-${ year }`;
}

/** Return at most `maxWords` words from `text`, trimmed. */
function truncateWords( text: string | undefined, maxWords: number ): string {
	if ( ! text ) return '';
	const words = text.trim().split( /\s+/ );
	if ( words.length <= maxWords ) return text.trim();
	return words.slice( 0, maxWords ).join( ' ' ) + '\u2026';
}

/** Build a descriptive conversation title from session metadata. */
function buildConversationTitle( s: TranscriptSession ): string {
	const name = s.assistant_title || s.assistant_model || '';
	const date = formatShortDate( s.started_at || s.updated_at );
	const preview = truncateWords( s.preview, 5 );

	const parts: string[] = [];
	if ( name ) parts.push( name );
	if ( date ) parts.push( date );
	if ( preview ) parts.push( preview );

	if ( parts.length === 0 ) {
		return s.session_key.slice( 0, 16 ) + '\u2026';
	}

	return parts.join( ' \u2014 ' );
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface ChatSidebarProps {
	/** Transcript sessions list (null = not yet loaded). */
	sessions: TranscriptSession[] | null;
	/** Currently active transcript session key. */
	activeSessionKey: string;
	/** Human-readable message when the transcripts backend is unavailable. */
	unavailableMessage: string | null;
	/** Transcripts-related error message. */
	error: string | null;
	/** Select (switch to) a transcript session. */
	onSelectSession: ( sessionKey: string ) => Promise< void >;
	/** Delete a transcript session. */
	onDeleteSession: ( sessionKey: string ) => Promise< void >;
	/** Start a brand-new transcript session. */
	onNewSession: () => void;

	/** WordPress REST nonce. */
	nonce: string;

	/** Base URL for the assistants REST endpoint. When provided, an
	 *  assistant selector dropdown appears above the tab bar. */
	assistantsEndpoint?: string;

	/** WordPress REST API root URL (for the Media Library tab). */
	apiRoot?: string;

	/** Full WordPress REST API URL for the media endpoint (e.g. wp/v2/media). */
	mediaEndpoint?: string;
}

type SidebarTab = 'conversations' | 'media';

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export function ChatSidebar( props: ChatSidebarProps ): JSX.Element {
	const {
		sessions,
		activeSessionKey,
		unavailableMessage,
		error,
		onSelectSession,
		onDeleteSession,
		onNewSession,
		nonce,
		assistantsEndpoint,
		apiRoot,
		mediaEndpoint,
	} = props;

	// ---- assistant store ----
	const assistantId = useAssistantStore( ( s ) => s.assistantId );
	const assistants = useAssistantStore( ( s ) => s.assistants );
	const setActiveAssistant = useAssistantStore( ( s ) => s.setActiveAssistant );
	const setAssistants = useAssistantStore( ( s ) => s.setAssistants );

	// ---- model store (sync model when assistant changes) ----
	const setModel = useModelStore( ( s ) => s.setModel );
	const setAvailableModels = useModelStore( ( s ) => s.setAvailableModels );

	// ---- UI store (for mobile sidebar close) ----
	const setSidebarOpen = useUIStore( ( s ) => s.setSidebarOpen );

	// ---- fetch assistants on mount / when endpoint changes ----
	const [ assistantsError, setAssistantsError ] = useState< string | null >( null );
	const [ assistantsLoaded, setAssistantsLoaded ] = useState(
		() => assistants.length > 0
	);

	useEffect( () => {
		if ( ! assistantsEndpoint ) return;
		let cancelled = false;
		setAssistantsError( null );
		// Keep loaded=true if we already have pre-loaded assistants.
		if ( assistants.length === 0 ) {
			setAssistantsLoaded( false );
		}
		const runtime = readProSpaConfig();
		const client = new AssistantsClient( {
			endpoint: assistantsEndpoint,
			nonce: runtime?.nonce ?? nonce,
		} );
		client.list().then( ( result ) => {
			if ( cancelled ) return;
			setAssistants( result.assistants );
			setAssistantsLoaded( true );

			// Populate available models from assistant configs.
			const models = result.assistants
				.filter( ( a ) => a.provider && a.model )
				.map( ( a ) => ( { provider: a.provider!, model: a.model! } ) );
			if ( models.length > 0 ) {
				setAvailableModels( models );
			}
		} ).catch( ( err: unknown ) => {
			if ( cancelled ) return;
			const msg = err instanceof Error ? err.message : String( err ?? '' );
			setAssistantsError( msg || __( 'Failed to load assistants.', 'nvoos-pro-spa' ) );
			setAssistantsLoaded( true );
			console.error( '[Pro SPA] Assistants fetch failed:', err );
		} );
		return () => { cancelled = true; };
	}, [ assistantsEndpoint, nonce, setAssistants, setAvailableModels ] );

	// ---- sync model store when assistantId changes ----
	const prevAssistantIdRef = useRef( assistantId );
	useEffect( () => {
		const prev = prevAssistantIdRef.current;
		prevAssistantIdRef.current = assistantId;
		// Only sync when assistantId actually changes (not on mount).
		if ( prev === assistantId || assistantId <= 0 ) return;
		const selected = assistants.find( ( a ) => a.id === assistantId );
		if ( selected?.provider && selected?.model ) {
			setModel( { provider: selected.provider, model: selected.model } );
		}
	}, [ assistantId, assistants, setModel ] );

	// ---- sync model on initial load when assistants are fetched ----
	const hasSyncedInitialModel = useRef( false );
	useEffect( () => {
		if ( hasSyncedInitialModel.current ) return;
		if ( assistantId <= 0 || assistants.length === 0 ) return;
		const current = assistants.find( ( a ) => a.id === assistantId );
		if ( current?.provider && current?.model ) {
			setModel( { provider: current.provider, model: current.model } );
			hasSyncedInitialModel.current = true;
		}
	}, [ assistantId, assistants, setModel ] );

	// ---- active tab ----
	const [ activeTab, setActiveTab ] = useState< SidebarTab >( 'conversations' );

	// ---- derived ----
	const safeSessions: TranscriptSession[] = useMemo(
		() => ( Array.isArray( sessions ) ? sessions : [] ),
		[ sessions ]
	);

	// Prepend a virtual entry for the current (unsaved) session so it
	// appears in the sidebar immediately on page load — matching the
	// legacy chat client behaviour where a new conversation line is
	// always visible.
	const hasActiveSession = useMemo(
		() => safeSessions.some( ( s ) => s.session_key === activeSessionKey ),
		[ safeSessions, activeSessionKey ]
	);

	const displaySessions: TranscriptSession[] = useMemo( () => {
		if ( hasActiveSession || sessions === null || unavailableMessage || error ) {
			return safeSessions;
		}
		// sessions has loaded (not null) and the active key is not in it.
		// Insert a placeholder so the user sees a "New Conversation" line.
		const virtual: TranscriptSession = {
			session_key: activeSessionKey,
			turn_count: 0,
		};
		return [ virtual, ...safeSessions ];
	}, [ safeSessions, hasActiveSession, sessions, unavailableMessage, error, activeSessionKey ] );

	// ---- tab keyboard navigation ----
	const TAB_ORDER: SidebarTab[] = [ 'conversations', 'media' ];
	const handleTabKeyDown = useCallback(
			( tab: SidebarTab ) =>
				( e: React.KeyboardEvent< HTMLButtonElement > ): void => {
					const idx = TAB_ORDER.indexOf( tab );
					if ( e.key === 'ArrowRight' ) {
						e.preventDefault();
						setActiveTab( TAB_ORDER[ ( idx + 1 ) % TAB_ORDER.length ] );
					}
					if ( e.key === 'ArrowLeft' ) {
						e.preventDefault();
						setActiveTab( TAB_ORDER[ ( idx - 1 + TAB_ORDER.length ) % TAB_ORDER.length ] );
					}
				},
			[]
		);

	// ---- callbacks ----
		const handleTabChange = useCallback(
			( tab: SidebarTab ) => {
				setActiveTab( tab );
			},
			[]
		);

		const handleSessionClick = useCallback(
			( key: string ) => {
				void onSelectSession( key );
			},
			[ onSelectSession ]
		);

		const handleSessionDelete = useCallback(
			( key: string ) => {
				void onDeleteSession( key );
			},
			[ onDeleteSession ]
		);

		// ---- media selection (for attaching to chat messages) ----
		const [ selectedMediaIds, setSelectedMediaIds ] = useState< Set< number > >( new Set() );
		const insertMediaToChat = useUIStore( ( s ) => s.insertMediaToChat );
		const handleAttachMedia = useCallback(
			( item: MediaItem ) => {
				setSelectedMediaIds( ( prev ) => {
					const next = new Set( prev );
					if ( next.has( item.id ) ) {
						next.delete( item.id );
					} else {
						next.add( item.id );
					}
					return next;
				} );
			},
			[]
		);

		// Insert selected media IDs into the chat composer and clear selection.
		const handleInsertSelectedMedia = useCallback( () => {
			if ( selectedMediaIds.size === 0 ) {
				return;
			}
			insertMediaToChat( [ ...selectedMediaIds ] );
			setSelectedMediaIds( new Set() );
		}, [ selectedMediaIds, insertMediaToChat ] );

		// ---- render ----
	return (
		<aside
			className="nvoos-pro-spa-sidebar"
			id="nvoos-pro-spa-sidebar"
			aria-label={ __( 'Chat sidebar', 'nvoos-pro-spa' ) }
		>
			{/* Close button (mobile) */}
			<button
				type="button"
				className="nvoos-pro-spa-sidebar__close"
				onClick={ () => setSidebarOpen( false ) }
				aria-label={ __( 'Close sidebar', 'nvoos-pro-spa' ) }
			>
				×
			</button>
			{/* ---- assistant selector ---- */}
			{ assistantsEndpoint && (<>
				<div className="nvoos-pro-spa-sidebar__assistant-select">
					<label
						htmlFor="nvoos-pro-spa-sidebar-assistant"
						className="nvoos-pro-spa-screen-reader-only"
					>
						{ __( 'Select assistant', 'nvoos-pro-spa' ) }
					</label>
					{ assistants.length > 0 ? (
						<select
							id="nvoos-pro-spa-sidebar-assistant"
							value={ assistantId }
							onChange={ ( e ) => {
								const id = parseInt( e.target.value, 10 );
								if ( id > 0 ) {
									setActiveAssistant( id );
									// Sync model store with the selected assistant's model.
									const selected = assistants.find( ( a ) => a.id === id );
									if ( selected?.provider && selected?.model ) {
										setModel( { provider: selected.provider, model: selected.model } );
									}
								}
							} }
							aria-label={ __( 'Select assistant', 'nvoos-pro-spa' ) }
						>
							<option value="0" disabled>
								{ __( '— Select an assistant —', 'nvoos-pro-spa' ) }
							</option>
							{ assistants.map( ( a ) => (
								<option key={ a.id } value={ a.id }>
									{ a.title }{ a.model ? ` — ${ a.provider ?? '' }/${ a.model }` : '' }
								</option>
							) ) }
						</select>
					) : assistantsError ? (
						<p className="nvoos-pro-spa-sidebar__assistant-error" role="alert">
							{ assistantsError }
						</p>
					) : ! assistantsLoaded ? (
						<p className="nvoos-pro-spa-sidebar__assistant-loading">
							{ __( 'Loading assistants…', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<p className="nvoos-pro-spa-sidebar__assistant-empty">
							{ __( 'No assistants found.', 'nvoos-pro-spa' ) }
						</p>
					) }
				</div>
				<VectorStoreIndicator apiRoot={ apiRoot ?? '' } nonce={ nonce } assistantId={ assistantId } />
			</> ) }

			{/* ---- tab bar ---- */}
			<div className="nvoos-pro-spa-sidebar__tabs" role="tablist">
				<button
					type="button"
					className={ [
						'nvoos-pro-spa-sidebar__tab',
						activeTab === 'conversations'
							? 'nvoos-pro-spa-sidebar__tab--active'
							: '',
					]
						.filter( Boolean )
						.join( ' ' ) }
					role="tab"
					aria-selected={ activeTab === 'conversations' }
					aria-controls="nvoos-pro-spa-sidebar-panel-conversations"
					id="nvoos-pro-spa-sidebar-tab-conversations"
					onClick={ () => handleTabChange( 'conversations' ) }
					onKeyDown={ handleTabKeyDown( 'conversations' ) }
				>
					{ __( 'Chats', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					className={ [
						'nvoos-pro-spa-sidebar__tab',
						activeTab === 'media'
							? 'nvoos-pro-spa-sidebar__tab--active'
							: '',
					]
						.filter( Boolean )
						.join( ' ' ) }
					role="tab"
					aria-selected={ activeTab === 'media' }
					aria-controls="nvoos-pro-spa-sidebar-panel-media"
					id="nvoos-pro-spa-sidebar-tab-media"
					onClick={ () => handleTabChange( 'media' ) }
					onKeyDown={ handleTabKeyDown( 'media' ) }
				>
					{ __( 'Media', 'nvoos-pro-spa' ) }
				</button>
			</div>

			{ /* ---- Conversations tab panel ---- */ }
			<div
				className="nvoos-pro-spa-sidebar__panel"
				id="nvoos-pro-spa-sidebar-panel-conversations"
				role="tabpanel"
				aria-labelledby="nvoos-pro-spa-sidebar-tab-conversations"
				hidden={ activeTab !== 'conversations' }
			>
				<div className="nvoos-pro-spa-sidebar__actions">
					<button
						type="button"
						className="nvoos-pro-spa-sidebar__new-btn"
						onClick={ onNewSession }
					>
						{ __( '+ New Chat', 'nvoos-pro-spa' ) }
					</button>
				</div>

				{ unavailableMessage && (
					<p className="nvoos-pro-spa-sidebar__notice">
						{ unavailableMessage }
					</p>
				) }

				{ error && (
					<p
						className="nvoos-pro-spa-sidebar__error"
						role="alert"
					>
						{ error }
					</p>
				) }

				<ul
					className="nvoos-pro-spa-sidebar__list"
					aria-label={ __(
						'Conversation sessions',
						'nvoos-pro-spa'
					) }
				>
					{ displaySessions.map( ( s ) => {
						const isActive = s.session_key === activeSessionKey;
						const isSaved = safeSessions.some( ( saved ) => saved.session_key === s.session_key );
						return (
							<li
								key={ s.session_key }
								className={ [
									'nvoos-pro-spa-sidebar__item',
									isActive
										? 'nvoos-pro-spa-sidebar__item--active'
										: '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								<button
									type="button"
									className="nvoos-pro-spa-sidebar__item-btn"
									onClick={ () =>
										handleSessionClick( s.session_key )
									}
									aria-current={
										isActive ? 'true' : undefined
									}
									aria-label={
										isActive
											? __(
													'Active conversation',
													'nvoos-pro-spa'
											  )
											: __(
													'Open conversation',
													'nvoos-pro-spa'
											  )
									}
								>
									<span className="nvoos-pro-spa-sidebar__item-title">
									{ ! isSaved
										? __( 'New Conversation', 'nvoos-pro-spa' )
										: buildConversationTitle( s ) }
									</span>
									{ s.turn_count !== undefined && (
										<span className="nvoos-pro-spa-sidebar__item-meta">
											{ sprintf(
												/* translators: %d: number of turns */
												__( '%d turns', 'nvoos-pro-spa' ),
												s.turn_count
											) }
										</span>
									) }
								</button>
								{ isSaved && (
									<button
										type="button"
										className="nvoos-pro-spa-sidebar__item-delete"
										onClick={ () =>
											handleSessionDelete(
												s.session_key
											)
										}
										aria-label={ __(
											'Delete conversation',
											'nvoos-pro-spa'
										) }
									>
										×
									</button>
								) }
							</li>
						);
					} ) }
					{ displaySessions.length === 0 &&
						! unavailableMessage &&
						! error && (
							<li className="nvoos-pro-spa-sidebar__empty">
								{ __(
									'No conversations yet.',
									'nvoos-pro-spa'
								) }
							</li>
						) }
				</ul>
			</div>

			{/* ---- Media tab panel ---- */}
			<div
				className="nvoos-pro-spa-sidebar__panel"
				id="nvoos-pro-spa-sidebar-panel-media"
				role="tabpanel"
				aria-labelledby="nvoos-pro-spa-sidebar-tab-media"
				hidden={ activeTab !== 'media' }
			>
				{ mediaEndpoint ? (
					<>
						<MediaTab
							mediaEndpoint={ mediaEndpoint }
							nonce={ nonce }
							selectedIds={ selectedMediaIds }
							onAttach={ handleAttachMedia }
						/>
						{ selectedMediaIds.size > 0 && (
							<div className="nvoos-pro-spa-sidebar__media-actions">
								<span className="nvoos-pro-spa-sidebar__media-count">
									{ sprintf(
										/* translators: %d: number of selected media items */
										__( '%d selected', 'nvoos-pro-spa' ),
										selectedMediaIds.size
									) }
								</span>
								<button
									type="button"
									className="nvoos-pro-spa-sidebar__media-insert-btn"
									onClick={ handleInsertSelectedMedia }
								>
									{ __( 'Insert into chat', 'nvoos-pro-spa' ) }
								</button>
							</div>
						) }
					</>
				) : (
					<p className="nvoos-pro-spa-sidebar__notice">
						{ __( 'Media Library is not available.', 'nvoos-pro-spa' ) }
					</p>
				) }
			</div>
		</aside>
	);
}
