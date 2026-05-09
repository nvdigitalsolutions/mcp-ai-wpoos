/**
 * NV oOS Chat SPA — sessions sidebar.
 *
 * Renders the list of saved transcripts for the current user/assistant
 * with click-to-load + delete + "new chat" controls. Designed to fail
 * soft: when the underlying CCT is unavailable, the sidebar collapses
 * to a quiet empty state instead of breaking the chat surface.
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX } from 'react';
import type { TranscriptSession } from '../api/transcripts';

interface TranscriptsSidebarProps {
	sessions: TranscriptSession[] | null;
	activeSessionKey: string;
	unavailableMessage: string | null;
	error: string | null;
	isCollapsed: boolean;
	onToggleCollapsed: () => void;
	onSelect: ( sessionKey: string ) => void;
	onDelete: ( sessionKey: string ) => void;
	onNew: () => void;
}

export function TranscriptsSidebar( props: TranscriptsSidebarProps ): JSX.Element {
	const {
		sessions,
		activeSessionKey,
		unavailableMessage,
		error,
		isCollapsed,
		onToggleCollapsed,
		onSelect,
		onDelete,
		onNew,
	} = props;

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
				{ ! isCollapsed && (
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
					{ error && (
						<p className="nvoos-chat-spa-sidebar-error" role="alert">
							{ error }
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
					{ ! unavailableMessage && sessions !== null && sessions.length === 0 && (
						<p className="nvoos-chat-spa-sidebar-empty">
							{ __( 'No saved conversations yet.', 'nvoos-chat-spa' ) }
						</p>
					) }
					{ ! unavailableMessage && Array.isArray( sessions ) && sessions.length > 0 && (
						<ul className="nvoos-chat-spa-sidebar-list">
							{ sessions.map( ( session ) => (
								<SessionRow
									key={ session.session_key }
									session={ session }
									isActive={ session.session_key === activeSessionKey }
									onSelect={ onSelect }
									onDelete={ onDelete }
								/>
							) ) }
						</ul>
					) }
				</div>
			) }
		</aside>
	);
}

interface SessionRowProps {
	session: TranscriptSession;
	isActive: boolean;
	onSelect: ( sessionKey: string ) => void;
	onDelete: ( sessionKey: string ) => void;
}

function SessionRow( { session, isActive, onSelect, onDelete }: SessionRowProps ): JSX.Element {
	const turnCount = typeof session.turn_count === 'number' ? session.turn_count : 0;
	const stamp =
		session.last_created || session.completed_at || session.first_created || session.started_at;
	const label = stamp ? formatStamp( stamp ) : session.session_key;

	const handleDelete = ( e: React.MouseEvent< HTMLButtonElement > ) => {
		e.stopPropagation();
		// `confirm()` is intentionally synchronous — matches the legacy chat
		// UI's delete-flow and keeps the bundle free of a modal dependency.
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
				<span className="nvoos-chat-spa-sidebar-item-label">{ label }</span>
				<span className="nvoos-chat-spa-sidebar-item-meta">
					{ sprintf(
						/* translators: %d: number of turns in the conversation. */
						__( '%d turns', 'nvoos-chat-spa' ),
						turnCount
					) }
				</span>
			</button>
			<button
				type="button"
				className="nvoos-chat-spa-sidebar-item-delete"
				aria-label={ __( 'Delete conversation', 'nvoos-chat-spa' ) }
				onClick={ handleDelete }
			>
				×
			</button>
		</li>
	);
}

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
