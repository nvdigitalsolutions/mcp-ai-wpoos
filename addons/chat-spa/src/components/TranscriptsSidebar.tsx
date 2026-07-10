/**
 * NV oOS Chat SPA — sessions sidebar with Threads tab.
 *
 * Renders the list of saved transcripts for the current user/assistant
 * alongside a "Threads" tab that lists active threads from the thread
 * manager. Designed to fail soft: when the underlying CCT or threads
 * route is unavailable, the sidebar collapses to a quiet empty state
 * instead of breaking the chat surface.
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX, useRef, useState, useEffect } from 'react';
import type { TranscriptSession } from '../api/transcripts';
import type { ThreadSummary } from '../api/threads';

type SidebarTab = 'transcripts' | 'threads';

interface TranscriptsSidebarProps {
	// ── Transcripts props (existing) ──────────────────────────────
	sessions: TranscriptSession[] | null;
	activeSessionKey: string;
	unavailableMessage: string | null;
	transcriptError: string | null;
	isCollapsed: boolean;
	onToggleCollapsed: () => void;
	onSelect: ( sessionKey: string ) => void;
	onDelete: ( sessionKey: string ) => void;
	onNew: () => void;

	// ── Threads props (new) ───────────────────────────────────────
	threads: ThreadSummary[] | null;
	threadsLoading: boolean;
	threadsError: string | null;
	threadsUnavailable: boolean;
	activeThreadId: number | null;
	activeTab: SidebarTab;
	onTabChange: ( tab: SidebarTab ) => void;
	onSelectThread: ( threadId: number ) => void;
	onDeselectThread: () => void;

	// ── Search + pagination + title edit (GAP-17/18/19: v0.9.0) ──
	searchTerm?: string;
	onSearchChange?: ( term: string ) => void;
	hasMore?: boolean;
	onLoadMore?: () => void;
	onUpdateTitle?: ( sessionKey: string, title: string ) => void;
}

export function TranscriptsSidebar( props: TranscriptsSidebarProps ): JSX.Element {
	const {
		sessions,
		activeSessionKey,
		unavailableMessage,
		transcriptError,
		isCollapsed,
		onToggleCollapsed,
		onSelect,
		onDelete,
		onNew,
		threads,
		threadsLoading,
		threadsError,
		threadsUnavailable,
		activeThreadId,
		activeTab,
		onTabChange,
		onSelectThread,
		onDeselectThread,
		searchTerm,
		onSearchChange,
		hasMore,
		onLoadMore,
		onUpdateTitle,
	} = props;

	const isTranscriptsTab = activeTab === 'transcripts';

	// Prepend a virtual entry for the current (unsaved) session so it
	// appears in the sidebar immediately on page load — matching the
	// legacy chat client behaviour where a new conversation line is
	// always visible.
	const safeSessions: TranscriptSession[] = Array.isArray( sessions ) ? sessions : [];
	const hasActiveSession = safeSessions.some( ( s ) => s.session_key === activeSessionKey );
	const displaySessions: TranscriptSession[] = (
		! hasActiveSession &&
		sessions !== null &&
		! unavailableMessage &&
		! transcriptError
	) ? [ { session_key: activeSessionKey, turn_count: 0 }, ...safeSessions ] : safeSessions;

	return (
		<aside
			className={ `nvoos-chat-spa-sidebar${
				isCollapsed ? ' nvoos-chat-spa-sidebar--collapsed' : ''
			}` }
			aria-label={ __( 'Saved conversations', 'nvoos-chat-spa' ) }
		>
			<div className="nvoos-chat-spa-sidebar-header">
				<button
					type="button"
					className="nvoos-chat-spa-sidebar-toggle"
					aria-expanded={ ! isCollapsed }
					aria-controls="nvoos-chat-spa-sidebar-list"
					onClick={ onToggleCollapsed }
				>
					{ isCollapsed
						? __( 'Show conversations', 'nvoos-chat-spa' )
						: __( 'Hide conversations', 'nvoos-chat-spa' ) }
				</button>
				{ ! isCollapsed && isTranscriptsTab && (
					<button
						type="button"
						className="nvoos-chat-spa-sidebar-new"
						onClick={ onNew }
					>
						{ __( 'New chat', 'nvoos-chat-spa' ) }
					</button>
				) }
			</div>

			{ ! isCollapsed && (
				<div className="nvoos-chat-spa-sidebar-body" id="nvoos-chat-spa-sidebar-list">
					{ /* ── Tab bar ─────────────────────────────────────────── */ }
					<div className="nvoos-chat-spa-sidebar-tabs" role="tablist">
						<button
							type="button"
							role="tab"
							aria-selected={ isTranscriptsTab }
							className={ `nvoos-chat-spa-sidebar-tab${
								isTranscriptsTab ? ' nvoos-chat-spa-sidebar-tab--active' : ''
							}` }
							onClick={ () => onTabChange( 'transcripts' ) }
						>
							{ __( 'Conversations', 'nvoos-chat-spa' ) }
						</button>
						<button
							type="button"
							role="tab"
							aria-selected={ ! isTranscriptsTab }
							className={ `nvoos-chat-spa-sidebar-tab${
								! isTranscriptsTab ? ' nvoos-chat-spa-sidebar-tab--active' : ''
							}` }
							onClick={ () => onTabChange( 'threads' ) }
						>
							{ __( 'Threads', 'nvoos-chat-spa' ) }
						</button>
					</div>

					{ /* ── Transcripts panel ──────────────────────────────── */ }
					{ isTranscriptsTab && (
						<div className="nvoos-chat-spa-sidebar-panel" role="tabpanel">
							{ /* Search input (GAP-17: v0.9.0) */ }
							{ onSearchChange && (
								<input
									type="search"
									className="nvoos-chat-spa-sidebar-search"
									placeholder={ __( 'Search conversations…', 'nvoos-chat-spa' ) }
									value={ searchTerm ?? '' }
									onChange={ ( e ) => onSearchChange( e.target.value ) }
									aria-label={ __( 'Search conversations', 'nvoos-chat-spa' ) }
								/>
							) }
							{ transcriptError && (
								<p className="nvoos-chat-spa-sidebar-error" role="alert">
									{ transcriptError }
								</p>
							) }
							{ unavailableMessage && (
								<p className="nvoos-chat-spa-sidebar-empty">{ unavailableMessage }</p>
							) }
							{ ! unavailableMessage && sessions === null && (
								<p className="nvoos-chat-spa-sidebar-empty">
									{ __( 'Loading…', 'nvoos-chat-spa' ) }
								</p>
							) }
							{ ! unavailableMessage && sessions !== null && displaySessions.length === 0 && (
								<p className="nvoos-chat-spa-sidebar-empty">
									{ __( 'No saved conversations yet.', 'nvoos-chat-spa' ) }
								</p>
							) }
							{ ! unavailableMessage && displaySessions.length > 0 && (
								<>
								<ul className="nvoos-chat-spa-sidebar-list">
									{ displaySessions.map( ( session ) => {
										const isSaved = safeSessions.some( ( s ) => s.session_key === session.session_key );
										return (
											<SessionRow
												key={ session.session_key }
												session={ session }
												isActive={ session.session_key === activeSessionKey }
												onSelect={ onSelect }
												onDelete={ onDelete }
												onUpdateTitle={ onUpdateTitle }
												isVirtual={ ! isSaved }
											/>
										);
									} ) }
								</ul>
								{ /* Load more (GAP-19: v0.9.0) */ }
								{ hasMore && onLoadMore && (
									<button
										type="button"
										className="nvoos-chat-spa-sidebar-load-more"
										onClick={ onLoadMore }
									>
										{ __( 'Load more…', 'nvoos-chat-spa' ) }
									</button>
								) }
								</>
							) }
						</div>
					) }

					{ /* ── Threads panel ─────────────────────────────────── */ }
					{ ! isTranscriptsTab && (
						<div className="nvoos-chat-spa-sidebar-panel" role="tabpanel">
							{ threadsError && (
								<p className="nvoos-chat-spa-sidebar-error" role="alert">
									{ threadsError }
								</p>
							) }
							{ threadsUnavailable && (
								<p className="nvoos-chat-spa-sidebar-empty">
									{ __( 'Threads are not available.', 'nvoos-chat-spa' ) }
								</p>
							) }
							{ ! threadsUnavailable && threadsLoading && threads === null && (
								<p className="nvoos-chat-spa-sidebar-empty">
									{ __( 'Loading threads…', 'nvoos-chat-spa' ) }
								</p>
							) }
							{ ! threadsUnavailable && threads !== null && threads.length === 0 && (
								<p className="nvoos-chat-spa-sidebar-empty">
									{ __( 'No active threads.', 'nvoos-chat-spa' ) }
								</p>
							) }
							{ ! threadsUnavailable && Array.isArray( threads ) && threads.length > 0 && (
								<ul className="nvoos-chat-spa-sidebar-list">
									{ threads.map( ( thread ) => (
										<ThreadRow
											key={ thread.id }
											thread={ thread }
											isActive={ thread.id === activeThreadId }
											onSelect={ onSelectThread }
										/>
									) ) }
								</ul>
							) }
						</div>
					) }
				</div>
			) }
		</aside>
	);
}

// ── Session row (existing) ──────────────────────────────────────────

interface SessionRowProps {
	session: TranscriptSession;
	isActive: boolean;
	onSelect: ( sessionKey: string ) => void;
	onDelete: ( sessionKey: string ) => void;
	onUpdateTitle?: ( sessionKey: string, title: string ) => void;
	/** True when this is a virtual placeholder for a not-yet-saved session. */
	isVirtual?: boolean;
}

function SessionRow( { session, isActive, onSelect, onDelete, onUpdateTitle, isVirtual = false }: SessionRowProps ): JSX.Element {
	const [ editing, setEditing ] = useState( false );
	const [ titleDraft, setTitleDraft ] = useState( '' );
	const inputRef = useRef< HTMLInputElement | null >( null );

	// Focus the input when editing starts.
	useEffect( () => {
		if ( editing && inputRef.current ) {
			inputRef.current.focus();
		}
	}, [ editing ] );
	const turnCount = typeof session.turn_count === 'number' ? session.turn_count : 0;
	const stamp =
		session.last_created || session.completed_at || session.first_created || session.started_at;
	const label = stamp ? formatStamp( stamp ) : session.session_key;

	const handleDelete = ( e: React.MouseEvent< HTMLButtonElement > ) => {
		e.stopPropagation();
		// eslint-disable-next-line no-alert
		if ( window.confirm( __( 'Delete this conversation?', 'nvoos-chat-spa' ) ) ) {
			onDelete( session.session_key );
		}
	};

	return (
		<li
			className={ `nvoos-chat-spa-sidebar-item${
				isActive ? ' nvoos-chat-spa-sidebar-item--active' : ''
			}` }
		>
			<button
				type="button"
				className="nvoos-chat-spa-sidebar-item-select"
				onClick={ () => onSelect( session.session_key ) }
				aria-current={ isActive ? 'true' : undefined }
			>
				{ editing && onUpdateTitle ? (
					<input
						ref={ inputRef }
						className="nvoos-chat-spa-sidebar-item-title-input"
						value={ titleDraft }
						onChange={ ( e ) => setTitleDraft( e.target.value ) }
						onBlur={ () => {
							setEditing( false );
							if ( titleDraft.trim() && titleDraft !== label ) {
								onUpdateTitle( session.session_key, titleDraft.trim() );
							}
						} }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								( e.target as HTMLInputElement ).blur();
							} else if ( e.key === 'Escape' ) {
								setEditing( false );
							}
						} }
						onClick={ ( e ) => e.stopPropagation() }
					/>
				) : (
					<span
						className="nvoos-chat-spa-sidebar-item-label"
						title={ onUpdateTitle ? __( 'Double-click to rename', 'nvoos-chat-spa' ) : undefined }
						onDoubleClick={ onUpdateTitle ? () => {
							setTitleDraft( label );
							setEditing( true );
						} : undefined }
					>
						{ isVirtual ? __( 'New Conversation', 'nvoos-chat-spa' ) : label }
					</span>
				) }
					<span className="nvoos-chat-spa-sidebar-item-meta">
						{ isVirtual
							? __( '0 turns', 'nvoos-chat-spa' )
							: sprintf(
								/* translators: %d: number of turns in the conversation. */
								__( '%d turns', 'nvoos-chat-spa' ),
								turnCount
							) }
					</span>
				</button>
				{ ! isVirtual && (
					<button
						type="button"
						className="nvoos-chat-spa-sidebar-item-delete"
						aria-label={ __( 'Delete conversation', 'nvoos-chat-spa' ) }
						onClick={ handleDelete }
					>
						×
					</button>
				) }
		</li>
	);
}

// ── Thread row (new) ────────────────────────────────────────────────

interface ThreadRowProps {
	thread: ThreadSummary;
	isActive: boolean;
	onSelect: ( threadId: number ) => void;
}

function ThreadRow( { thread, isActive, onSelect }: ThreadRowProps ): JSX.Element {
	const msgCount = typeof thread.message_count === 'number' ? thread.message_count : 0;
	const stamp = thread.updated_at || thread.created_at;
	const label = thread.title || `Thread #${ thread.id }`;
	const modelLabel = thread.model_name && thread.model_name !== 'Default'
		? thread.model_name
		: '';

	return (
		<li
			className={ `nvoos-chat-spa-sidebar-item${
				isActive ? ' nvoos-chat-spa-sidebar-item--active' : ''
			}` }
		>
			<button
				type="button"
				className="nvoos-chat-spa-sidebar-item-select"
				onClick={ () => onSelect( thread.id ) }
				aria-current={ isActive ? 'true' : undefined }
			>
				<span className="nvoos-chat-spa-sidebar-item-label">{ label }</span>
				<span className="nvoos-chat-spa-sidebar-item-meta">
					{ modelLabel && (
						<span className="nvoos-chat-spa-sidebar-item-model">{ modelLabel }</span>
					) }
					{ stamp && (
						<span className="nvoos-chat-spa-sidebar-item-stamp">
							{ modelLabel ? ' · ' : '' }
							{ formatStamp( stamp ) }
						</span>
					) }
					{ msgCount > 0 && (
						<span className="nvoos-chat-spa-sidebar-item-count">
							{ ' · ' }
							{ sprintf(
								/* translators: %d: number of messages. */
								__( '%d msgs', 'nvoos-chat-spa' ),
								msgCount
							) }
						</span>
					) }
				</span>
			</button>
		</li>
	);
}

// ── Helpers ─────────────────────────────────────────────────────────

function formatStamp( raw: string ): string {
	const time = Date.parse( raw );
	if ( Number.isFinite( time ) ) {
		try {
			return new Date( time ).toLocaleString();
		} catch {
			// Fall through to raw on Intl failure.
		}
	}
	return raw;
}
