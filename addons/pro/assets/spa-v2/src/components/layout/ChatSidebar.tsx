/**
 * ChatSidebar — Left sidebar with two tabs: Conversations (transcripts) and Threads.
 *
 * Transcripts data is received from the parent Layout component.
 * Threads data is fetched internally via the useThreads hook.
 */

import { type JSX, useCallback, useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';

import { useThreads } from '../../hooks/useThreads';
import type { TranscriptSession } from '../../api/transcripts';
import type { ThreadSummary } from '../../api/threads';

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
	} = props;

	// ---- active tab ----
	const [ activeTab, setActiveTab ] = useState< SidebarTab >( 'conversations' );

	// ---- threads (fetched internally) ----
	const {
		threads,
		activeThreadId,
		loading: threadsLoading,
		error: threadsError,
		setActiveThread,
		createThread,
		archiveThread,
	} = useThreads( { endpoint: threadsEndpoint, nonce } );

	// ---- derived ----
	const safeSessions: TranscriptSession[] = useMemo(
		() => ( Array.isArray( sessions ) ? sessions : [] ),
		[ sessions ]
	);

	const activeThread = useMemo(
		() => threads.find( ( t ) => t.id === activeThreadId ) ?? null,
		[ threads, activeThreadId ]
	);

	// ---- callbacks ----
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

	const handleThreadClick = useCallback(
		( id: number ) => {
			setActiveThread( id );
			onSelectThread?.( id );
		},
		[ setActiveThread, onSelectThread ]
	);

	const handleNewThread = useCallback( () => {
		void createThread();
	}, [ createThread ] );

	const handleArchiveThread = useCallback(
		( id: number ) => {
			void archiveThread( id );
		},
		[ archiveThread ]
	);

	// ---- render ----
	return (
		<aside
			className="nvoos-pro-spa-sidebar"
			id="nvoos-pro-spa-sidebar"
			role="complementary"
			aria-label={ __( 'Chat sidebar', 'nvoos-pro-spa' ) }
		>
			{ /* ---- tab bar ---- */ }
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
					onClick={ () => setActiveTab( 'conversations' ) }
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
					onClick={ () => setActiveTab( 'threads' ) }
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
					role="list"
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
										{ s.assistant_model ??
											s.session_key.slice( 0, 16 ) +
												'…' }
									</span>
									{ s.turn_count !== undefined && (
										<span className="nvoos-pro-spa-sidebar__item-meta">
											{ s.turn_count }{ ' ' }
											{ __(
												'turns',
												'nvoos-pro-spa'
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

			{ /* ---- Threads tab panel ---- */ }
			<div
				className="nvoos-pro-spa-sidebar__panel"
				id="nvoos-pro-spa-sidebar-panel-threads"
				role="tabpanel"
				aria-labelledby="nvoos-pro-spa-sidebar-tab-threads"
				hidden={ activeTab !== 'threads' }
			>
				<div className="nvoos-pro-spa-sidebar__actions">
					<button
						type="button"
						className="nvoos-pro-spa-sidebar__new-btn"
						onClick={ handleNewThread }
					>
						{ __( '+ New Thread', 'nvoos-pro-spa' ) }
					</button>
				</div>

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
					role="list"
					aria-label={ __( 'Threads', 'nvoos-pro-spa' ) }
				>
					{ threads.map( ( t ) => {
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
											__( 'Untitled', 'nvoos-pro-spa' ) }
									</span>
									{ t.message_count !== undefined && (
										<span className="nvoos-pro-spa-sidebar__item-meta">
											{ t.message_count }{ ' ' }
											{ __(
												'msgs',
												'nvoos-pro-spa'
											) }
										</span>
									) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-sidebar__item-delete"
									onClick={ () =>
										handleArchiveThread( t.id )
									}
									aria-label={ __(
										'Archive thread',
										'nvoos-pro-spa'
									) }
								>
									×
								</button>
							</li>
						);
					} ) }
					{ threads.length === 0 &&
						! threadsLoading &&
						! threadsError && (
							<li className="nvoos-pro-spa-sidebar__empty">
								{ __(
									'No threads yet.',
									'nvoos-pro-spa'
								) }
							</li>
						) }
				</ul>
			</div>
		</aside>
	);
}
