/**
 * NV oOS Chat SPA — root component.
 *
 * Uses the Vercel AI SDK UI layer (@ai-sdk/react `useChat`) on the React side
 * only. The hook talks to the existing NV oOS REST chat endpoint
 * (`mcp-ai/v1/chat-client`) via a custom `fetch` that translates NV oOS's
 * native SSE frames into the AI SDK Data Stream Protocol (see
 * `./sse-adapter.ts`).
 *
 * No Node server is introduced; the WordPress PHP layer remains the
 * orchestrator and AI provider gateway.
 */

import { useChat } from '@ai-sdk/react';
import { __ } from '@wordpress/i18n';
import { type FormEvent } from 'react';
import { readChatSpaConfig, type ChatSpaConfig } from './api/config';
import { createChatFetch } from './sse-adapter';
import { MessageView } from './components/MessageView';

interface AppProps {
	config: ChatSpaConfig;
}

export function App( { config }: AppProps ) {
	const runtime = readChatSpaConfig();

	if ( ! runtime ) {
		return (
			<div className="nvoos-chat-spa-app nvoos-chat-spa-app--error">
				<p>
					{ __(
						'NV oOS Chat is unavailable: localized configuration is missing.',
						'nvoos-chat-spa'
					) }
				</p>
			</div>
		);
	}

	const endpoint = runtime.endpoints.chatClient;
	const customFetch = createChatFetch( {
		endpoint,
		nonce: runtime.nonce,
		assistantId: config.assistantId ?? 0,
		guest: !! config.guest,
	} );

	const { messages, input, handleInputChange, handleSubmit, status, error, stop } = useChat( {
		api: endpoint,
		// `fetch` is exposed by `useChat` so callers can plug in custom transports.
		// We use it to bridge NV oOS's native SSE protocol into the AI SDK Data
		// Stream Protocol that `useChat` expects.
		fetch: customFetch as typeof globalThis.fetch,
		streamProtocol: 'data',
	} );

	const onSubmit = ( e: FormEvent< HTMLFormElement > ) => {
		e.preventDefault();
		if ( ! input.trim() ) {
			return;
		}
		handleSubmit( e );
	};

	const isStreaming = status === 'streaming' || status === 'submitted';

	return (
		<div className="nvoos-chat-spa-app" data-theme={ config.theme ?? 'auto' }>
			<div className="nvoos-chat-spa-messages" role="log" aria-live="polite">
				{ messages.length === 0 && (
					<p className="nvoos-chat-spa-empty">
						{ __( 'Start a conversation…', 'nvoos-chat-spa' ) }
					</p>
				) }
				{ messages.map( ( m ) => (
					<MessageView
						key={ m.id }
						message={ m as Parameters< typeof MessageView >[ 0 ][ 'message' ] }
					/>
				) ) }
				{ error && (
					<div className="nvoos-chat-spa-message nvoos-chat-spa-message--error">
						{ String( error.message || error ) }
					</div>
				) }
			</div>
			<form className="nvoos-chat-spa-composer" onSubmit={ onSubmit }>
				<label className="screen-reader-text" htmlFor="nvoos-chat-spa-input">
					{ __( 'Message', 'nvoos-chat-spa' ) }
				</label>
				<input
					id="nvoos-chat-spa-input"
					className="nvoos-chat-spa-input"
					type="text"
					value={ input }
					onChange={ handleInputChange }
					placeholder={ __( 'Type a message…', 'nvoos-chat-spa' ) }
					disabled={ isStreaming }
					autoComplete="off"
				/>
				{ isStreaming ? (
					<button
						type="button"
						className="nvoos-chat-spa-stop"
						onClick={ () => stop() }
					>
						{ __( 'Stop', 'nvoos-chat-spa' ) }
					</button>
				) : (
					<button
						type="submit"
						className="nvoos-chat-spa-send"
						disabled={ ! input.trim() }
					>
						{ __( 'Send', 'nvoos-chat-spa' ) }
					</button>
				) }
			</form>
		</div>
	);
}
