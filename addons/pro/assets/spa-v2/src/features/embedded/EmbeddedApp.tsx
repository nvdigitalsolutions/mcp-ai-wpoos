/**
 * EmbeddedApp — Router-free, chat-first mount for the [nvoos_pro_spa]
 * shortcode.
 *
 * Renders the existing ChatPage (AgentPanel + drawers + HITL + memory) with
 * an optional lightweight transcripts sidebar. No HashRouter, no command
 * palette, no admin routes — safe to embed on public pages.
 *
 * Guests (config.guest) skip transcripts entirely: the guest token surface
 * has no transcripts/memory/threads endpoints and the sidebar is hidden.
 */

import { useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import { readProSpaConfig } from '../../api/config';
import { useBootstrap } from '../../hooks/useBootstrap';
import { useTranscripts, type UseTranscriptsReturn } from '../../hooks/useTranscripts';
import { useUIStore } from '../../stores/uiStore';
import { ChatPage } from '../chat/ChatPage';
import { ToastContainer } from '../../components/shared/Toast';
import { EmbeddedSidebar } from './EmbeddedSidebar';

/**
 * EmbeddedApp — public entry for shortcode mounts.
 */
export function EmbeddedApp(): JSX.Element {
	const { loading, error } = useBootstrap();
	const runtime = useMemo( () => readProSpaConfig(), [] );

	if ( loading ) {
		return (
			<div
				className="nvoos-pro-spa-embedded nvoos-pro-spa-embedded--loading"
				role="status"
				aria-label={ __( 'Loading AI assistant', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-loading__spinner" aria-hidden="true" />
				<p>{ __( 'Loading…', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="nvoos-pro-spa-embedded nvoos-pro-spa-embedded--error" role="alert">
				<h2>{ __( 'Failed to load AI assistant', 'nvoos-pro-spa' ) }</h2>
				<p>{ error }</p>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-embedded nvoos-pro-spa-embedded--error"
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

	return <EmbeddedContent runtime={ runtime } />;
}

interface EmbeddedContentProps {
	runtime: NonNullable< ReturnType< typeof readProSpaConfig > >;
}

function EmbeddedContent( { runtime }: EmbeddedContentProps ): JSX.Element {
	const endpoints = runtime.endpoints;
	const nonce = runtime.nonce ?? '';
	const assistantId = runtime.config?.assistantId ?? runtime.user?.assistant_id ?? 0;
	const isGuest = !!runtime.config?.guest;
	const showSidebar = runtime.config?.showSidebar !== false && ! isGuest;

	const theme = useUIStore( ( s ) => s.theme );
	const sidebarOpen = useUIStore( ( s ) => s.sidebarOpen );

	// Guests have no transcripts endpoints — the hook is disabled and
	// ChatPage simply runs conversation-first (session key only).
	const transcripts: UseTranscriptsReturn = useTranscripts( {
		endpoint: endpoints.transcripts ?? '',
		nonce,
		assistantId,
		disabled: isGuest || ! endpoints.transcripts,
	} );

	const style = runtime.config?.height
		? { height: runtime.config.height }
		: undefined;

	return (
		<div
			className={ [
				'nvoos-pro-spa-layout',
				'nvoos-pro-spa-embedded__layout',
				showSidebar && ! sidebarOpen
					? 'nvoos-pro-spa-embedded__layout--sidebar-hidden'
					: '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			data-theme={ theme }
			style={ style }
		>
			{ showSidebar && <EmbeddedSidebar transcripts={ transcripts } /> }

			<main
					className="nvoos-pro-spa-embedded__main"
					id="nvoos-pro-spa-embedded-main"
					role="main"
				>
					<ChatPage transcripts={ transcripts } />
				</main>

				<ToastContainer />
			</div>
		);
	}
