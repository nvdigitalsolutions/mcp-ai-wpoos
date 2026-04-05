/**
 * AssistantPage – Full-page AI Shopping Assistant
 *
 * A full-screen chat interface powered by the plugin's AI assistant. The
 * assistant is aware of the Shopify store context and can recommend products,
 * answer questions about orders, and help with navigation.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useRef, useEffect } from 'react';
import { useAssistant } from '../hooks/useAssistant';
import { useTMA } from '../context/TMAContext';

/**
 * Sanitize and render basic markdown in assistant replies.
 *
 * Escapes HTML entities first so user-supplied content is safe, then applies
 * limited markdown formatting (bold, italic, line breaks). Only the
 * formatting tags we introduce are present in the output.
 *
 * @param {string} text Raw assistant reply text.
 * @return {string}     Safe HTML string containing only strong/em/br tags.
 */
function renderReply( text ) {
	const escaped = text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
	return escaped
		.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
		.replace( /\*(.+?)\*/g, '<em>$1</em>' )
		.replace( /\n/g, '<br>' );
}

/** @param {{ params:object }} props */
export default function AssistantPage() {
	const { messages, send, loading, clearHistory } = useAssistant();
	const { haptic, user } = useTMA();
	const [ input, setInput ] = useState( '' );
	const messagesEndRef = useRef( null );
	const inputRef = useRef( null );

	// Scroll to bottom whenever messages update.
	useEffect( () => {
		messagesEndRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ messages, loading ] );

	const handleSend = () => {
		const text = input.trim();
		if ( ! text || loading ) {
			return;
		}
		haptic( 'light' );
		setInput( '' );
		send( text );
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	};

	const siteName = window.wpTmaShopifyConfig?.siteName || 'Store';

	return (
		<div className="tma-shopify-page tma-shopify-assistant-page">
			{ /* Header */ }
			<header className="tma-shopify-page-header">
				<div className="tma-shopify-page-header__avatar tma-shopify-page-header__avatar--ai">🤖</div>
				<div className="tma-shopify-page-header__info">
					<div className="tma-shopify-page-header__name">AI Shopping Assistant</div>
					<div className="tma-shopify-page-header__status">{ siteName }</div>
				</div>
				{ messages.length > 0 && (
					<button
						className="tma-shopify-icon-btn"
						onClick={ () => {
							haptic( 'light' );
							clearHistory();
						} }
						aria-label="Clear chat"
						title="Clear chat"
					>
						🗑
					</button>
				) }
			</header>

			{ /* Messages */ }
			<div className="tma-shopify-assistant-page__messages">
				{ messages.length === 0 && (
					<div className="tma-shopify-assistant-page__welcome">
						<div className="tma-shopify-assistant-page__welcome-icon">🛍️</div>
						{ user?.first_name && (
							<p>Hi { user.first_name }! 👋</p>
						) }
						<p>I&apos;m your AI shopping assistant. Ask me about products, recommendations, or your orders.</p>
						<div className="tma-shopify-assistant-page__suggestions">
							{ [
								'What are your bestsellers?',
								'Help me find a gift',
								'Show me new arrivals',
							].map( ( suggestion ) => (
								<button
									key={ suggestion }
									className="tma-shopify-suggestion-chip"
									onClick={ () => {
										haptic( 'light' );
										send( suggestion );
									} }
								>
									{ suggestion }
								</button>
							) ) }
						</div>
					</div>
				) }

				{ messages.map( ( msg, idx ) => (
					<div
						key={ idx }
						className={ `tma-shopify-chat-msg tma-shopify-chat-msg--${ msg.role }` }
					>
						{ msg.role === 'assistant' ? (
							<div
								dangerouslySetInnerHTML={ { __html: renderReply( msg.content ) } }
							/>
						) : (
							msg.content
						) }
					</div>
				) ) }

				{ loading && (
					<div className="tma-shopify-chat-msg tma-shopify-chat-msg--assistant tma-shopify-chat-msg--typing">
						<span className="tma-shopify-typing-dot" />
						<span className="tma-shopify-typing-dot" />
						<span className="tma-shopify-typing-dot" />
					</div>
				) }

				<div ref={ messagesEndRef } />
			</div>

			{ /* Input bar */ }
			<div className="tma-shopify-assistant-page__input-bar">
				<textarea
					ref={ inputRef }
					className="tma-shopify-assistant-page__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ handleKeyDown }
					placeholder="Ask about products or orders…"
					rows={ 1 }
					disabled={ loading }
				/>
				<button
					className="tma-shopify-assistant-page__send-btn"
					onClick={ handleSend }
					disabled={ loading || ! input.trim() }
					aria-label="Send"
				>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
						<line x1="22" y1="2" x2="11" y2="13" />
						<polygon points="22 2 15 22 11 13 2 9 22 2" />
					</svg>
				</button>
			</div>
		</div>
	);
}
