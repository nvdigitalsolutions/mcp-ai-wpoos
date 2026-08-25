/**
 * Layout — Root layout component wrapping the SPA in HashRouter.
 *
 * Reads runtime config from window.NVOOS_PRO_SPA, instantiates the
 * shared `useTranscripts` hook once, and passes it to both the
 * ChatSidebar and ChatPage so there is only one source of truth for
 * the active conversation session.
 */

import { type JSX, useCallback, useMemo } from 'react';
import { HashRouter } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

import { readProSpaConfig } from '../../api/config';
import { useAssistantStore } from '../../stores/assistantStore';
import { useUIStore } from '../../stores/uiStore';
import { useTranscripts } from '../../hooks/useTranscripts';
import { ChatSidebar } from './ChatSidebar';
import { RightPanel } from './RightPanel';
import { StatusBar } from './StatusBar';

import { AppRouter } from '../../router';

// ---------------------------------------------------------------------------
// Inner component — rendered inside HashRouter
// ---------------------------------------------------------------------------

interface LayoutContentProps {
	transcriptsEndpoint: string;
	assistantsEndpoint: string;
	nonce: string;
	/** Server-provided default assistant — fallback only. */
	assistantId: number;
	apiRoot: string;
	mediaEndpoint: string;
}

function LayoutContent( props: LayoutContentProps ): JSX.Element {
	const { transcriptsEndpoint, assistantsEndpoint, nonce, assistantId, apiRoot, mediaEndpoint } = props;

	// ---- active assistant (sidebar selector owns it) ----
	// ChatPage binds the chat transport to the same id, so transcripts have to
	// follow the selector too — otherwise conversations are listed under one
	// assistant while new turns are saved against another.
	const storedAssistantId = useAssistantStore( ( s ) => s.assistantId );
	const setActiveAssistant = useAssistantStore( ( s ) => s.setActiveAssistant );
	const assistants = useAssistantStore( ( s ) => s.assistants );
	const activeAssistantId = storedAssistantId > 0 ? storedAssistantId : assistantId;

	// Opening a conversation moves the selector to the assistant that owns it.
	// Ignored for assistants the selector has no option for (deleted, draft, or
	// virtual team ids) so the dropdown never ends up blank.
	const handleSessionAssistantChange = useCallback(
		( nextAssistantId: number ) => {
			if (
				assistants.length > 0 &&
				! assistants.some( ( a ) => a.id === nextAssistantId )
			) {
				return;
			}
			setActiveAssistant( nextAssistantId );
		},
		[ assistants, setActiveAssistant ]
	);

	// ---- transcripts (conversation sessions) — single source of truth ----
	const transcripts = useTranscripts( {
		endpoint: transcriptsEndpoint,
		nonce,
		assistantId: activeAssistantId,
		onSessionAssistantChange: handleSessionAssistantChange,
	} );

	// ---- UI store (sidebar / right-panel toggles / theme) ----
	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );
	const toggleSidebar = useUIStore( ( s ) => s.toggleSidebar );
	const setSidebarOpen = useUIStore( ( s ) => s.setSidebarOpen );
	const rightPanelOpen = useUIStore( ( s ) => s.rightPanelOpen );
	const theme = useUIStore( ( s ) => s.theme );

	// ---- UI store (sidebar / right-panel toggles / theme) ----
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
				nonce={ nonce }
				assistantsEndpoint={ assistantsEndpoint }
				apiRoot={ apiRoot }
				mediaEndpoint={ mediaEndpoint }
			/>

			{ /* ---- main content ---- */ }
			<main
				className="nvoos-pro-spa-layout__main"
				id="nvoos-pro-spa-main-content"
				role="main"
			>
				{ /* Mobile sidebar toggle (visible only on small screens) */ }
				<button
					type="button"
					className="nvoos-pro-spa-mobile-sidebar-toggle"
					onClick={ toggleSidebar }
					aria-label={
						sidebarOpen
							? __( 'Close sidebar', 'nvoos-pro-spa' )
							: __( 'Open sidebar', 'nvoos-pro-spa' )
					}
					aria-expanded={ sidebarOpen }
				>
					<span className="nvoos-pro-spa-mobile-sidebar-toggle__icon">
						☰
					</span>
				</button>

				<AppRouter transcripts={ transcripts } />
			</main>

			{ /* ---- right panel ---- */ }
			<RightPanel />

			{ /* ---- fixed bottom status bar ---- */ }
			<StatusBar
				transcriptsEndpoint={ transcriptsEndpoint }
				nonce={ nonce }
			/>

			{/* Mobile sidebar overlay */}
			{ sidebarOpen && (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<div
					className="nvoos-pro-spa-sidebar-overlay"
					onClick={ () => setSidebarOpen( false ) }
					aria-hidden="true"
				/>
			) }

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
		endpoints: { transcripts: transcriptsEndpoint, assistants: assistantsEndpoint, upload: mediaEndpoint },
		nonce,
		config: { assistantId },
		apiUrl,
	} = runtime;

	// Same fallback chain as ChatPage so both bind to the same assistant.
	const defaultAssistantId = assistantId ?? runtime.user?.assistant_id ?? 0;

	return (
		<HashRouter>
			<LayoutContent
				transcriptsEndpoint={ transcriptsEndpoint }
				assistantsEndpoint={ assistantsEndpoint }
				nonce={ nonce }
				assistantId={ defaultAssistantId }
				apiRoot={ apiUrl }
				mediaEndpoint={ mediaEndpoint ?? '' }
			/>
		</HashRouter>
	);
}
