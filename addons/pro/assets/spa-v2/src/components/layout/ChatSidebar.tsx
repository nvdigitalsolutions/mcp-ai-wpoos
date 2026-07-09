/**
 * ChatSidebar — Left sidebar with two tabs: Conversations (transcripts) and Threads.
 *
 * Architecture: **Conversations-first** — the "Conversations" tab is the default
 * and the primary way to start, switch, and delete chat sessions. The "Threads"
 * tab provides a read-only browse view of thread-manager threads.
 *
 * Transcripts data is received from the parent Layout component.
 * Threads data is fetched internally via the read-only `useThreads` hook.
 */

import { type JSX, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { useThreads } from '../../hooks/useThreads';
import type { TranscriptSession } from '../../api/transcripts';
import type { ThreadSummary } from '../../api/threads';
import { useAssistantStore } from '../../stores/assistantStore';
import { useUIStore } from '../../stores/uiStore';
import { useModelStore } from '../../stores/modelStore';
import { AssistantsClient, type AssistantRecord } from '../../api/assistants';
import { readProSpaConfig } from '../../api/config';

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

	/** Base URL for the threads REST endpoint (passed to useThreads). */
	threadsEndpoint: string;
	/** WordPress REST nonce. */
	nonce: string;

	/** Navigate to chat page when a thread is selected. */
	onSelectThread?: ( id: number ) => void;

	/** Base URL for the assistants REST endpoint. When provided, an
	 *  assistant selector dropdown appears above the tab bar. */
	assistantsEndpoint?: string;
}

type SidebarTab = 'conversations' | 'threads';

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
		threadsEndpoint,
		nonce,
		onSelectThread,
		assistantsEndpoint,
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

	// ---- threads (read-only browse) ----
	const {
		threads,
		activeThreadId,
		isLoading: threadsLoading,
		error: threadsError,
		unavailable: threadsUnavailable,
		selectThread,
		deselectThread,
	} = useThreads( { endpoint: threadsEndpoint, nonce } );

	// ---- derived ----
	const safeSessions: TranscriptSession[] = useMemo(
		() => ( Array.isArray( sessions ) ? sessions : [] ),
		[ sessions ]
	);

	const safeThreads: ThreadSummary[] = useMemo(
		() => ( Array.isArray( threads ) ? threads : [] ),
		[ threads ]
	);

	// ---- tab keyboard navigation ----
	const handleTabKeyDown = useCallback(
		( tab: SidebarTab ) =>
			( e: React.KeyboardEvent< HTMLButtonElement > ): void => {
				if ( e.key === 'ArrowRight' ) {
					e.preventDefault();
					setActiveTab( tab === 'conversations' ? 'threads' : 'conversations' );
				}
				if ( e.key === 'ArrowLeft' ) {
					e.preventDefault();
					setActiveTab( tab === 'threads' ? 'conversations' : 'threads' );
				}
			},
		[]
	);

	// ---- callbacks ----
	const handleTabChange = useCallback(
		( tab: SidebarTab ) => {
			setActiveTab( tab );
			// Deselect thread when switching to conversations tab.
			if ( tab === 'conversations' ) {
				deselectThread();
			}
		},
		[ deselectThread ]
	);

	const handleSessionClick = useCallback(
		( key: string ) => {
			// When selecting a conversation, deselect any active thread.
			deselectThread();
			void onSelectSession( key );
		},
		[ onSelectSession, deselectThread ]
	);

	const handleSessionDelete = useCallback(
		( key: string ) => {
			void onDeleteSession( key );
		},
		[ onDeleteSession ]
	);

	const handleThreadClick = useCallback(
		async ( id: number ) => {
			await selectThread( id );
			onSelectThread?.( id );
		},
		[ selectThread, onSelectThread ]
	);

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
			{ assistantsEndpoint && (
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
			) }

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
					{ __( 'Conversations', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					className={ [
						'nvoos-pro-spa-sidebar__tab',
						activeTab === 'threads'
							? 'nvoos-pro-spa-sidebar__tab--active'
							: '',
					]
						.filter( Boolean )
						.join( ' ' ) }
					role="tab"
					aria-selected={ activeTab === 'threads' }
					aria-controls="nvoos-pro-spa-sidebar-panel-threads"
					id="nvoos-pro-spa-sidebar-tab-threads"
					onClick={ () => handleTabChange( 'threads' ) }
					onKeyDown={ handleTabKeyDown( 'threads' ) }
				>
					{ __( 'Threads', 'nvoos-pro-spa' ) }
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
					{ safeSessions.map( ( s ) => {
						const isActive = s.session_key === activeSessionKey;
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
										{ buildConversationTitle( s ) }
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
							</li>
						);
					} ) }
					{ safeSessions.length === 0 &&
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

			{ /* ---- Threads tab panel (read-only) ---- */ }
			<div
				className="nvoos-pro-spa-sidebar__panel"
				id="nvoos-pro-spa-sidebar-panel-threads"
				role="tabpanel"
				aria-labelledby="nvoos-pro-spa-sidebar-tab-threads"
				hidden={ activeTab !== 'threads' }
			>
				{ threadsUnavailable && (
					<p className="nvoos-pro-spa-sidebar__notice">
						{ __( 'Threads are not available.', 'nvoos-pro-spa' ) }
					</p>
				) }

				{ threadsLoading && (
					<p className="nvoos-pro-spa-sidebar__loading">
						{ __( 'Loading threads…', 'nvoos-pro-spa' ) }
					</p>
				) }

				{ threadsError && (
					<p
						className="nvoos-pro-spa-sidebar__error"
						role="alert"
					>
						{ threadsError }
					</p>
				) }

				<ul
					className="nvoos-pro-spa-sidebar__list"
					aria-label={ __( 'Threads', 'nvoos-pro-spa' ) }
				>
					{ safeThreads.map( ( t ) => {
						const isActive = t.id === activeThreadId;
						return (
							<li
								key={ t.id }
								className={ [
									'nvoos-pro-spa-sidebar__item',
									isActive
										? 'nvoos-pro-spa-sidebar__item--active'
										: '',
									t.status === 'archived'
										? 'nvoos-pro-spa-sidebar__item--archived'
										: '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								<button
									type="button"
									className="nvoos-pro-spa-sidebar__item-btn"
									onClick={ () =>
										handleThreadClick( t.id )
									}
									aria-current={
										isActive ? 'true' : undefined
									}
								>
									<span className="nvoos-pro-spa-sidebar__item-title">
										{ t.title ||
											`Thread #${ t.id }` }
									</span>
									{ t.message_count !== undefined && (
										<span className="nvoos-pro-spa-sidebar__item-meta">
											{ sprintf(
												/* translators: %d: number of messages */
												__( '%d msgs', 'nvoos-pro-spa' ),
												t.message_count
											) }
										</span>
									) }
								</button>
							</li>
						);
					} ) }
					{ safeThreads.length === 0 &&
						! threadsLoading &&
						! threadsUnavailable &&
						! threadsError && (
							<li className="nvoos-pro-spa-sidebar__empty">
								{ __(
									'No active threads.',
									'nvoos-pro-spa'
								) }
							</li>
						) }
				</ul>
			</div>
		</aside>
	);
}
