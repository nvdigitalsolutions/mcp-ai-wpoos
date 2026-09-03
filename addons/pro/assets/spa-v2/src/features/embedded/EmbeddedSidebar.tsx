/**
 * EmbeddedSidebar — Lightweight transcripts list for the [nvoos_pro_spa]
 * shortcode (config.showSidebar = true, default).
 *
 * Intentionally slim compared to the admin ChatSidebar: conversations only,
 * no media tab, no threads browse, no assistant selector. Sessions are
 * owned by useTranscripts in EmbeddedApp.
 */

import { type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import { useUIStore } from '../../stores/uiStore';
import { useTranscripts } from '../../hooks/useTranscripts';
import type { TranscriptSession } from '../../api/transcripts';

interface EmbeddedSidebarProps {
	transcripts: ReturnType< typeof useTranscripts >;
}

/**
 * Format a session timestamp for display, tolerating provider drift.
 */
function formatSessionTime( raw: unknown ): string {
	if ( typeof raw !== 'string' && typeof raw !== 'number' ) {
		return '';
	}
	const parsed = Date.parse( String( raw ) );
	if ( ! Number.isFinite( parsed ) ) {
		return String( raw );
	}
	try {
		return new Date( parsed ).toLocaleString();
	} catch {
		return String( raw );
	}
}

function sessionLabel( session: TranscriptSession ): string {
	const ts = session.last_created || session.updated_at || session.started_at;
	const time = formatSessionTime( ts );
	if ( session.preview ) {
		return time ? `${ session.preview } — ${ time }` : session.preview;
	}
	return time || session.session_key;
}

export function EmbeddedSidebar( { transcripts }: EmbeddedSidebarProps ): JSX.Element {
	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );
	const toggleSidebar = useUIStore( ( s ) => s.toggleSidebar );

	return (
		<aside
			className="nvoos-pro-spa-embedded__sidebar"
			aria-label={ __( 'Conversations', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-embedded__sidebar-header">
				<h2 className="nvoos-pro-spa-embedded__sidebar-title">
					{ __( 'Conversations', 'nvoos-pro-spa' ) }
				</h2>
				<button
					type="button"
					className="nvoos-pro-spa-embedded__new-btn"
					onClick={ transcripts.startNewSession }
				>
					{ __( 'New', 'nvoos-pro-spa' ) }
				</button>
			</div>

			{ transcripts.error && (
				<p className="nvoos-pro-spa-embedded__sidebar-error" role="alert">
					{ transcripts.error }
				</p>
			) }

			{ transcripts.unavailableMessage && (
				<p className="nvoos-pro-spa-embedded__sidebar-empty">
					{ transcripts.unavailableMessage }
				</p>
			) }

			{ ! transcripts.error &&
				! transcripts.unavailableMessage &&
				transcripts.sessions !== null &&
				transcripts.sessions.length === 0 && (
					<p className="nvoos-pro-spa-embedded__sidebar-empty">
						{ __( 'No conversations yet.', 'nvoos-pro-spa' ) }
					</p>
				) }

			{ Array.isArray( transcripts.sessions ) && transcripts.sessions.length > 0 && (
				<ul className="nvoos-pro-spa-embedded__sidebar-list">
					{ transcripts.sessions.map( ( session ) => {
						const isActive = session.session_key === transcripts.sessionKey;
						return (
							<li
								key={ session.session_key }
								className={ [
									'nvoos-pro-spa-embedded__session',
									isActive ? 'nvoos-pro-spa-embedded__session--active' : '',
								]
									.filter( Boolean )
									.join( ' ' ) }
							>
								<button
									type="button"
									className="nvoos-pro-spa-embedded__session-select"
									onClick={ () => void transcripts.selectSession( session.session_key ) }
									aria-current={ isActive ? 'true' : undefined }
								>
									<span className="nvoos-pro-spa-embedded__session-title">
										{ sessionLabel( session ) }
									</span>
									{ typeof session.turn_count === 'number' && (
										<span className="nvoos-pro-spa-embedded__session-meta">
											{ session.turn_count }{ ' ' }
											{ __( 'turns', 'nvoos-pro-spa' ) }
										</span>
									) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-embedded__session-delete"
									aria-label={ __( 'Delete conversation', 'nvoos-pro-spa' ) }
									onClick={ () => {
										// eslint-disable-next-line no-alert
										if ( window.confirm( __( 'Delete this conversation?', 'nvoos-pro-spa' ) ) ) {
											void transcripts.deleteSession( session.session_key );
										}
									} }
								>
									×
								</button>
							</li>
						);
					} ) }
				</ul>
			) }

			<button
				type="button"
				className="nvoos-pro-spa-embedded__sidebar-toggle"
				onClick={ toggleSidebar }
				aria-expanded={ sidebarOpen }
				aria-label={
					sidebarOpen
						? __( 'Close conversations', 'nvoos-pro-spa' )
						: __( 'Open conversations', 'nvoos-pro-spa' )
				}
			>
				☰
			</button>
		</aside>
	);
}
