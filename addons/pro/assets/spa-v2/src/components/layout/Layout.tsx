/**
 * Layout — Root layout component wrapping the SPA in HashRouter.
 *
 * Reads runtime config from window.NVOOS_PRO_SPA, instantiates the
 * shared `useTranscripts` hook once, and passes it to both the
 * ChatSidebar and ChatPage so there is only one source of truth for
 * the active conversation session.
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

import { AppRouter } from '../../router';

// ---------------------------------------------------------------------------
// Inner component — rendered inside HashRouter so useNavigate is available
// ---------------------------------------------------------------------------

interface LayoutContentProps {
	transcriptsEndpoint: string;
	threadsEndpoint: string;
	assistantsEndpoint: string;
	nonce: string;
	assistantId: number;
}

function LayoutContent( props: LayoutContentProps ): JSX.Element {
	const { transcriptsEndpoint, threadsEndpoint, assistantsEndpoint, nonce, assistantId } = props;

	// ---- transcripts (conversation sessions) — single source of truth ----
	const transcripts = useTranscripts( {
		endpoint: transcriptsEndpoint,
		nonce,
		assistantId,
	} );

	// ---- UI store (sidebar / right-panel toggles / theme) ----
	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );
	const rightPanelOpen = useUIStore( ( s ) => s.rightPanelOpen );
	const theme = useUIStore( ( s ) => s.theme );

	// ---- thread selection callback (no URL navigation needed) ----
	const navigate = useNavigate();
	const handleSelectThread = useCallback(
		( threadId: number ) => {
			// Navigate to the chat route so ChatPage is rendered.
			// Thread messages are loaded by the sidebar and injected
			// into ChatPage via chatSpoke.setMessages() in the router.
			navigate( '/chat' );
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
				assistantsEndpoint={ assistantsEndpoint }
			/>

			{ /* ---- main content ---- */ }
			<main
				className="nvoos-pro-spa-layout__main"
				id="nvoos-pro-spa-main-content"
				role="main"
			>
				<AppRouter transcripts={ transcripts } />
			</main>

			{ /* ---- right panel ---- */ }
			<RightPanel />

			{ /* ---- fixed bottom status bar ---- */ }
			<StatusBar
				transcriptsEndpoint={ transcriptsEndpoint }
				nonce={ nonce }
			/>

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
		endpoints: { transcripts: transcriptsEndpoint, threads: threadsEndpoint, assistants: assistantsEndpoint },
		nonce,
		config: { assistantId },
	} = runtime;

	return (
		<HashRouter>
			<LayoutContent
				transcriptsEndpoint={ transcriptsEndpoint }
				threadsEndpoint={ threadsEndpoint }
				assistantsEndpoint={ assistantsEndpoint }
				nonce={ nonce }
				assistantId={ assistantId ?? 0 }
			/>
		</HashRouter>
	);
}
