/**
 * AssistantPage – Full-page AI Shopping Assistant
 *
 * A full-screen chat interface powered by the plugin's AI assistant. The
 * assistant is aware of the store context and can recommend products, answer
 * questions about orders, and help with navigation.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useRef, useEffect } from '@wordpress/element';
import DOMPurify from 'dompurify';
import { useAssistant } from '../hooks/useAssistant';
import { useTMA } from '../context/TMAContext';

/**
 * Sanitize and render basic markdown in assistant replies.
 * Uses DOMPurify to strip any malicious content before rendering.
 *
 * @param {string} text Raw assistant reply text.
 * @return {string}     Safe HTML string.
 */
function renderReply( text ) {
	const withMarkdown = text
		.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
		.replace( /\*(.+?)\*/g, '<em>$1</em>' )
		.replace( /\n/g, '<br>' );
	return DOMPurify.sanitize( withMarkdown, { ALLOWED_TAGS: [ 'strong', 'em', 'br' ] } );
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

	const siteName = window.wpTmaWooConfig?.siteName || 'Store';

	return (
		<div className="tma-woo-page tma-woo-assistant-page">
			{ /* Header */ }
			<header className="tma-woo-page-header">
				<div className="tma-woo-page-header__avatar tma-woo-page-header__avatar--ai">🤖</div>
				<div className="tma-woo-page-header__info">
					<div className="tma-woo-page-header__name">AI Shopping Assistant</div>
					<div className="tma-woo-page-header__status">{ siteName }</div>
				</div>
				{ messages.length > 0 && (
					<button
						className="tma-woo-icon-btn"
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
			<div className="tma-woo-assistant-page__messages">
				{ messages.length === 0 && (
					<div className="tma-woo-assistant-page__welcome">
						<div className="tma-woo-assistant-page__welcome-icon">🛍️</div>
						{ user?.first_name && (
							<p>Hi { user.first_name }! 👋</p>
						) }
						<p>I&apos;m your AI shopping assistant. Ask me about products, recommendations, or your orders.</p>
						<div className="tma-woo-assistant-page__suggestions">
							{ [
								'What are your bestsellers?',
								'Help me find a gift',
								'Track my last order',
							].map( ( suggestion ) => (
								<button
									key={ suggestion }
									className="tma-woo-suggestion-chip"
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
						className={ `tma-woo-chat-msg tma-woo-chat-msg--${ msg.role }` }
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
					<div className="tma-woo-chat-msg tma-woo-chat-msg--assistant tma-woo-chat-msg--typing">
						<span className="tma-woo-typing-dot" />
						<span className="tma-woo-typing-dot" />
						<span className="tma-woo-typing-dot" />
					</div>
				) }

				<div ref={ messagesEndRef } />
			</div>

			{ /* Input bar */ }
			<div className="tma-woo-assistant-page__input-bar">
				<textarea
					ref={ inputRef }
					className="tma-woo-assistant-page__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ handleKeyDown }
					placeholder="Ask about products or orders…"
					rows={ 1 }
					disabled={ loading }
				/>
				<button
					className="tma-woo-assistant-page__send-btn"
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
