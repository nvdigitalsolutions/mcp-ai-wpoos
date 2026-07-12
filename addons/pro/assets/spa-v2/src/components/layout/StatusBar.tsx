/**
 * StatusBar — Fixed bottom bar showing connection status, model, profile,
 * and localStorage session storage usage.
 *
 * Connection health is derived from the transcripts endpoint availability.
 *
 * @since 2.1.0  Replaces conversation count with localStorage quota display.
 */

import { type JSX, useMemo } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { useUIStore } from '../../stores/uiStore';
import { useModelStore } from '../../stores/modelStore';
import { useTranscripts } from '../../hooks/useTranscripts';

export interface StatusBarProps {
	/** Base URL for the transcripts REST endpoint. */
	transcriptsEndpoint: string;
	/** WordPress REST nonce. */
	nonce: string;
}

/**
 * Derive a human-readable connection status from the transcripts hook state.
 * Returns `'connected'`, `'loading'`, or `'error'`.
 */
function deriveConnectionStatus(
	sessions: unknown[] | null,
	error: string | null
): 'connected' | 'loading' | 'error' {
	if ( error ) {
		return 'error';
	}
	if ( sessions === null ) {
		return 'loading';
	}
	return 'connected';
}

export function StatusBar( props: StatusBarProps ): JSX.Element {
	const { transcriptsEndpoint, nonce } = props;

	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );
	const toggleSidebar = useUIStore( ( s ) => s.toggleSidebar );

	const model = useModelStore( ( s ) => s.model );
	const profile = useModelStore( ( s ) => s.profile );

	// Use transcripts to derive connection status.
	const {
		sessions,
		error: transcriptsError,
		refreshList: refreshTranscripts,
	} = useTranscripts( {
		endpoint: transcriptsEndpoint,
		nonce,
		assistantId: 0,
	} );

	// ---- derived ----
	const connectionStatus = useMemo(
		() => deriveConnectionStatus( sessions, transcriptsError ),
		[ sessions, transcriptsError ]
	);

	const connectionLabel: Record< string, string > = useMemo(
		() => ( {
			connected: __( 'Connected', 'nvoos-pro-spa' ),
			loading: __( 'Connecting…', 'nvoos-pro-spa' ),
			error: __( 'Disconnected', 'nvoos-pro-spa' ),
		} ),
		[]
	);

	const modelLabel = useMemo(
		() => `${ model.provider } / ${ model.model }`,
		[ model ]
	);

	// ── localStorage quota (v2.1.0) ────────────────────────────────────
	const storageUsed = useMemo( () => {
		if ( typeof window === 'undefined' ) return 0;
		let total = 0;
		try {
			for ( let i = 0; i < localStorage.length; i++ ) {
				const key = localStorage.key( i );
				if (
					key?.startsWith( 'wp_mcp_ai_' ) ||
					key?.startsWith( 'nvoos-chat-spa' ) ||
					key?.startsWith( 'nvoos-pro-spa' )
				) {
					total += ( localStorage.getItem( key ) ?? '' ).length;
				}
			}
		} catch {
			// Ignore storage access errors.
		}
		return total;
	}, [ sessions ] );

	// ---- render ----
	return (
		<footer
			className="nvoos-pro-spa-status-bar"
			id="nvoos-pro-spa-status-bar"
			role="contentinfo"
			aria-label={ __( 'Status bar', 'nvoos-pro-spa' ) }
		>
			{ /* ---- sidebar toggle ---- */ }
			<button
				type="button"
				className="nvoos-pro-spa-status-bar__toggle-sidebar"
				onClick={ toggleSidebar }
				aria-label={
					sidebarOpen
						? __( 'Close sidebar', 'nvoos-pro-spa' )
						: __( 'Open sidebar', 'nvoos-pro-spa' )
				}
				aria-expanded={ sidebarOpen }
			>
				<span className="nvoos-pro-spa-status-bar__toggle-icon">
					☰
				</span>
			</button>

			{ /* ---- connection indicator ---- */ }
			<div
				className={ [
					'nvoos-pro-spa-status-bar__indicator',
					`nvoos-pro-spa-status-bar__indicator--${ connectionStatus }`,
				].join( ' ' ) }
				role="status"
				aria-live="polite"
				aria-label={ connectionLabel[ connectionStatus ] }
			>
				<span className="nvoos-pro-spa-status-bar__dot" aria-hidden="true" />
				<span className="nvoos-pro-spa-status-bar__connection-label">
					{ connectionLabel[ connectionStatus ] }
				</span>
			</div>

			{ /* ---- model ---- */ }
			<span className="nvoos-pro-spa-status-bar__item">
				<span className="nvoos-pro-spa-status-bar__label">
					{ __( 'Model', 'nvoos-pro-spa' ) }
					{ ': ' }
				</span>
				<span className="nvoos-pro-spa-status-bar__value">
					{ modelLabel }
				</span>
			</span>

			{ /* ---- profile ---- */ }
			<span className="nvoos-pro-spa-status-bar__item">
				<span className="nvoos-pro-spa-status-bar__label">
					{ __( 'Profile', 'nvoos-pro-spa' ) }
					{ ': ' }
				</span>
				<span className="nvoos-pro-spa-status-bar__value">
					{ profile }
				</span>
			</span>

			{ /* ---- session storage (v2.1.0) ---- */ }
			<span className="nvoos-pro-spa-status-bar__item">
				<span className="nvoos-pro-spa-status-bar__label">
					{ __( 'Sessions', 'nvoos-pro-spa' ) }
					{ ': ' }
				</span>
				<span className="nvoos-pro-spa-status-bar__value">
					{ storageUsed > 1_048_576
						? sprintf(
							/* translators: %s: storage used in MB (e.g. "2.3 MB") */
							__( '%s MB', 'nvoos-pro-spa' ),
							( storageUsed / 1_048_576 ).toFixed( 1 )
						  )
						: sprintf(
							/* translators: %s: storage used in KB (e.g. "156 KB") */
							__( '%s KB', 'nvoos-pro-spa' ),
							Math.round( storageUsed / 1024 )
						  ) }
				</span>
			</span>

			{ /* ---- error detail (shown only on error) ---- */ }
			{ connectionStatus === 'error' && transcriptsError && (
				<span
					className="nvoos-pro-spa-status-bar__error"
					role="alert"
				>
					{ transcriptsError }
					<button
						type="button"
						className="nvoos-pro-spa-status-bar__retry"
						onClick={ () => void refreshTranscripts() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</span>
			) }
		</footer>
	);
}
