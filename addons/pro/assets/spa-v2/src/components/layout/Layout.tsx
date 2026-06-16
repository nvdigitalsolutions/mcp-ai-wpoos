/**
 * Layout — Root layout component wrapping the SPA in HashRouter.
 *
 * Reads runtime config from window.NVOOS_PRO_SPA, wires transcripts,
 * and renders the 3-column + status-bar chrome.
 */

import { type JSX, useCallback, useMemo } from 'react';
import { HashRouter, useNavigate } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

import { readProSpaConfig } from '../../api/config';
import { useUIStore } from '../../stores/uiStore';
import { useTranscripts } from '../../hooks/useTranscripts';
import { ChatSidebar } from './ChatSidebar';
import { RightPanel } from './RightPanel';
import { StatusBar } from './StatusBar';
import { ToastContainer } from '../shared/Toast';

import { AppRouter } from '../../router';

// ---------------------------------------------------------------------------
// Inner component — rendered inside HashRouter so useNavigate is available
// ---------------------------------------------------------------------------

interface LayoutContentProps {
	transcriptsEndpoint: string;
	threadsEndpoint: string;
	nonce: string;
	assistantId: number;
}

function LayoutContent( props: LayoutContentProps ): JSX.Element {
	const { transcriptsEndpoint, threadsEndpoint, nonce, assistantId } = props;

	// ---- transcripts (conversation sessions) ----
	const transcripts = useTranscripts( {
		endpoint: transcriptsEndpoint,
		nonce,
		assistantId,
	} );

	// ---- UI store (sidebar / right-panel toggles / theme) ----
	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );
	const rightPanelOpen = useUIStore( ( s ) => s.rightPanelOpen );
	const theme = useUIStore( ( s ) => s.theme );

	// ---- thread selection -> navigate to /chat/:id ----
	const navigate = useNavigate();
	const handleSelectThread = useCallback(
		( id: number ) => {
			navigate( `/chat/${ id }` );
		},
		[ navigate ]
	);

	return (
		<div
			className={ [
				'nvoos-pro-spa-layout',
				sidebarOpen ? 'nvoos-pro-spa-layout--sidebar-open' : '',
				rightPanelOpen ? 'nvoos-pro-spa-layout--right-panel-open' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			data-theme={ theme }
		>
			{ /* ---- left sidebar ---- */ }
			<ChatSidebar
				sessions={ transcripts.sessions }
				activeSessionKey={ transcripts.sessionKey }
				unavailableMessage={ transcripts.unavailableMessage }
				error={ transcripts.error }
				onSelectSession={ transcripts.selectSession }
				onDeleteSession={ transcripts.deleteSession }
				onNewSession={ transcripts.startNewSession }
				threadsEndpoint={ threadsEndpoint }
				nonce={ nonce }
				onSelectThread={ handleSelectThread }
			/>

			{ /* ---- main content ---- */ }
			<main
				className="nvoos-pro-spa-layout__main"
				id="nvoos-pro-spa-main-content"
				role="main"
			>
				<AppRouter />
			</main>

			{ /* ---- right panel ---- */ }
			<RightPanel />

			{ /* ---- fixed bottom status bar ---- */ }
			<StatusBar
				threadsEndpoint={ threadsEndpoint }
				nonce={ nonce }
			/>

			{ /* ---- toast notifications ---- */ }
			<ToastContainer />
		</div>
	);
}

// ---------------------------------------------------------------------------
// Public Layout — reads runtime config, renders HashRouter
// ---------------------------------------------------------------------------

export function Layout(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );

	// ---- graceful fallback when the runtime config is missing ----
	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-layout nvoos-pro-spa-layout--missing-config"
				role="alert"
			>
				<p>
					{ __(
						'Runtime configuration not found. Please refresh the page or contact an administrator.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	const {
		endpoints: { transcripts: transcriptsEndpoint, threads: threadsEndpoint },
		nonce,
		config: { assistantId },
	} = runtime;

	return (
		<HashRouter>
			<LayoutContent
				transcriptsEndpoint={ transcriptsEndpoint }
				threadsEndpoint={ threadsEndpoint }
				nonce={ nonce }
				assistantId={ assistantId ?? 0 }
			/>
		</HashRouter>
	);
}
