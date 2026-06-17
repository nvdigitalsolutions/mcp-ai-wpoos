/**
 * NV oOS LibreChat — Main App Component
 *
 * Root component that wires the chat UI to the NV oOS REST endpoints.
 * Follows the LibreChat UI patterns: conversation view, message input with
 * attachments, code interpreter panel, artifacts panel.
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

import React, { useState, useRef, useEffect, useCallback } from 'react';

interface AppConfig {
	assistantId: number;
	theme: string;
	height: string;
	guest: boolean;
	features: {
		codeInterpreter: boolean;
		webSearch: boolean;
		speech: boolean;
		artifacts: boolean;
	};
}

interface Endpoints {
	chat: string;
	transcripts: string;
	memory: string;
	codeExecute: string;
	codeResult: string;
	speechTranscribe: string;
	speechSynthesize: string;
}

interface AppProps {
	config: AppConfig;
	apiUrl: string;
	nonce: string;
	endpoints: Endpoints;
}

interface Message {
	id: string;
	role: 'user' | 'assistant' | 'system';
	content: string;
	timestamp: number;
}

const App: React.FC<AppProps> = ( { config, apiUrl, nonce, endpoints } ) => {
	const [ messages, setMessages ] = useState<Message[]>( [] );
	const [ input, setInput ] = useState( '' );
	const [ isStreaming, setIsStreaming ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const messagesEndRef = useRef<HTMLDivElement>( null );

	const themeClass = `nvoos-librechat--${ config.theme || 'dark' }`;

	const scrollToBottom = useCallback( () => {
		messagesEndRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [] );

	useEffect( () => {
		scrollToBottom();
	}, [ messages, scrollToBottom ] );

	const sendMessage = useCallback( async () => {
		const trimmed = input.trim();
		if ( ! trimmed || isStreaming ) {
			return;
		}

		setInput( '' );
		setError( null );
		setIsStreaming( true );

		const userMessage: Message = {
			id: `msg-${ Date.now() }-user`,
			role: 'user',
			content: trimmed,
			timestamp: Date.now(),
		};

		setMessages( ( prev ) => [ ...prev, userMessage ] );

		try {
			const response = await fetch( endpoints.chat, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( {
					messages: [ { role: 'user', content: trimmed } ],
					assistant_id: config.assistantId || 0,
					stream: true,
				} ),
			} );

			if ( ! response.ok ) {
				const errData = await response.json().catch( () => ( {} ) );
				throw new Error( errData.message || `HTTP ${ response.status }` );
			}

			// Stream SSE response.
			const reader = response.body?.getReader();
			if ( ! reader ) {
				throw new Error( 'Streaming not supported' );
			}

			const assistantId = `msg-${ Date.now() }-assistant`;
			setMessages( ( prev ) => [
				...prev,
				{ id: assistantId, role: 'assistant', content: '', timestamp: Date.now() },
			] );

			const decoder = new TextDecoder();
			let buffer = '';

			while ( true ) {
				const { done, value } = await reader.read();
				if ( done ) break;

				buffer += decoder.decode( value, { stream: true } );
				const lines = buffer.split( '\n' );
				buffer = lines.pop() || '';

				for ( const line of lines ) {
					if ( line.startsWith( 'data: ' ) ) {
						const data = line.slice( 6 );
						if ( data === '[DONE]' ) continue;

						try {
							const parsed = JSON.parse( data );
							const chunk =
								parsed.choices?.[ 0 ]?.delta?.content ||
								parsed.content ||
								parsed.message ||
								'';
							if ( chunk ) {
								setMessages( ( prev ) =>
									prev.map( ( m ) =>
										m.id === assistantId
											? { ...m, content: m.content + chunk }
											: m
									)
								);
							}
						} catch {
							// Skip unparseable chunks.
						}
					}
				}
			}
		} catch ( e ) {
			const message = e instanceof Error ? e.message : 'Unknown error';
			setError( message );
		} finally {
			setIsStreaming( false );
		}
	}, [ input, isStreaming, endpoints.chat, nonce, config.assistantId ] );

	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent ) => {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				sendMessage();
			}
		},
		[ sendMessage ]
	);

	return (
		<div className={ `nvoos-librechat ${ themeClass }` }>
			<div className="nvoos-librechat__messages" role="log" aria-live="polite">
				{ messages.length === 0 && (
					<div className="nvoos-librechat__empty">
						<h2>NV oOS LibreChat</h2>
						<p>Ask a question to get started.</p>
					</div>
				) }
				{ messages.map( ( msg ) => (
					<div
						key={ msg.id }
						className={ `nvoos-librechat__message nvoos-librechat__message--${ msg.role }` }
					>
						<div className="nvoos-librechat__message-content">
							{ msg.content || ( msg.role === 'assistant' && isStreaming ? '...' : '' ) }
						</div>
					</div>
				) ) }
				{ error && (
					<div className="nvoos-librechat__error" role="alert">
						{ error }
					</div>
				) }
				<div ref={ messagesEndRef } />
			</div>
			<div className="nvoos-librechat__input-area">
				<textarea
					className="nvoos-librechat__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ handleKeyDown }
					placeholder="Type a message..."
					rows={ 1 }
					disabled={ isStreaming }
					aria-label="Message input"
				/>
				<button
					className="nvoos-librechat__send"
					onClick={ sendMessage }
					disabled={ isStreaming || ! input.trim() }
					aria-label="Send message"
				>
					{ isStreaming ? '■' : '→' }
				</button>
			</div>
		</div>
	);
};

export default App;
