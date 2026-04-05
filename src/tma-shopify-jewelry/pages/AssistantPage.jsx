/**
 * AssistantPage – AI Jewelry Concierge
 *
 * A full-screen chat interface for the AI jewelry assistant. The assistant
 * can recommend pieces, answer questions about materials, and help with
 * custom orders.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useRef, useEffect } from '@wordpress/element';
import DOMPurify from 'dompurify';
import { useAssistant } from '../hooks/useAssistant';
import { useTMA } from '../context/TMAContext';

/**
 * Sanitize and render basic markdown in assistant replies.
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
	const [ input, setInput ]   = useState( '' );
	const messagesEndRef        = useRef( null );

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

	const siteName = window.wpTmaJewelryConfig?.siteName || 'Jewelry';

	return (
		<div className="tma-jw-page tma-jw-assistant-page">
			<header className="tma-jw-page-header">
				<div className="tma-jw-page-header__avatar tma-jw-page-header__avatar--ai">💎</div>
				<div className="tma-jw-page-header__info">
					<div className="tma-jw-page-header__name">Jewelry Concierge</div>
					<div className="tma-jw-page-header__status">{ siteName }</div>
				</div>
				{ messages.length > 0 && (
					<button
						className="tma-jw-icon-btn"
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

			<div className="tma-jw-assistant-page__messages">
				{ messages.length === 0 && (
					<div className="tma-jw-assistant-page__welcome">
						<div className="tma-jw-assistant-page__welcome-icon">💍</div>
						{ user?.first_name && (
							<p>Hello { user.first_name }! 👋</p>
						) }
						<p>I&apos;m your personal jewelry concierge. Ask me about our collection, materials, sizing, or custom pieces.</p>
						<div className="tma-jw-assistant-page__suggestions">
							{ [
								'Show me engagement rings',
								'What are your bestsellers?',
								'Tell me about gold vs. platinum',
								'I need a gift under $500',
							].map( ( suggestion ) => (
								<button
									key={ suggestion }
									className="tma-jw-suggestion-chip"
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
						className={ `tma-jw-chat-msg tma-jw-chat-msg--${ msg.role }` }
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
					<div className="tma-jw-chat-msg tma-jw-chat-msg--assistant tma-jw-chat-msg--typing">
						<span className="tma-jw-typing-dot" />
						<span className="tma-jw-typing-dot" />
						<span className="tma-jw-typing-dot" />
					</div>
				) }

				<div ref={ messagesEndRef } />
			</div>

			<div className="tma-jw-assistant-page__input-bar">
				<textarea
					className="tma-jw-assistant-page__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ handleKeyDown }
					placeholder="Ask about our jewelry collection…"
					rows={ 1 }
					disabled={ loading }
				/>
				<button
					className="tma-jw-assistant-page__send-btn"
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
