/**
 * AgentPanel — Core chat UI: message list + composer.
 *
 * Receives chat state from useChatSpoke (via ChatPage) and renders
 * the scrollable message list and the input composer area.
 */

import { useCallback, useEffect, useRef, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { type Message } from '@ai-sdk/react';
import { MessageView } from './MessageView';

export interface AgentPanelProps {
	/** Ordered list of chat messages from useChat. */
	messages: Message[];
	/** Current composer input value. */
	input: string;
	/** Handler for input changes (textarea or programmatic string). */
	handleInputChange: ( e: React.ChangeEvent< HTMLTextAreaElement > | string ) => void;
	/** Handler for form submission (send message). */
	handleSubmit: ( e?: { preventDefault?: () => void } ) => void;
	/** Current chat stream status. */
	status: 'submitted' | 'streaming' | 'ready' | 'error';
	/** Error object if the chat encountered an error. */
	error: Error | undefined;
	/** Stop the current generation. */
	stop: () => void;
	/** Reload / regenerate the last assistant response. */
	reload: () => void;
	/** Whether a response is actively streaming. */
	isStreaming: boolean;
	/** Programmatic send (bypasses current input). */
	sendMessage: ( content: string ) => void;
	/** Active thread ID (used for ARIA labels and context). */
	threadId: number;
	/** Display title for the active thread. */
	threadTitle: string;
	/** Callback to regenerate the last assistant message. */
	onRegenerate: () => void;
	/** Callback to delete a message by ID. */
	onDeleteMessage?: ( msgId: string ) => void;
	/** Callback for feedback on a message. */
	onFeedback?: ( msgId: string, rating: 'up' | 'down' ) => void;
	/** Callback for editing a user message. */
	onEditMessage?: ( msgId: string ) => void;
	/** Map of message ID → feedback rating. */
	feedbackState?: Record< string, 'up' | 'down' >;
}

export function AgentPanel( props: AgentPanelProps ): JSX.Element {
	const {
		messages,
		input,
		handleInputChange,
		handleSubmit,
		status,
		error,
		stop,
		isStreaming,
		sendMessage,
		threadId,
		threadTitle,
		onRegenerate,
		onDeleteMessage,
		onFeedback,
		onEditMessage,
		feedbackState = {},
	} = props;

	const messagesContainerRef = useRef< HTMLDivElement | null >( null );
	const composerRef = useRef< HTMLTextAreaElement | null >( null );

	// Track whether the user is at the bottom of the messages container.
	// Updated by the onScroll handler so we know their intent before
	// new content arrives — avoids the false-positive "user scrolled up"
	// when a large content chunk pushes scrollHeight past a static guard.
	const userAtBottomRef = useRef< boolean >( true );

	// Helper: scroll the messages container to the bottom.
	// Uses requestAnimationFrame to wait for any pending layout.
	const scrollToBottom = useCallback( () => {
		requestAnimationFrame( () => {
			const el = messagesContainerRef.current;
			if ( ! el ) {
				return;
			}
			el.scrollTop = el.scrollHeight;
		} );
	}, [] );

	// onScroll handler — records whether the user is at the very bottom.
	const handleMessagesScroll = useCallback( () => {
		const el = messagesContainerRef.current;
		if ( ! el ) {
			return;
		}
		// 2px tolerance to absorb sub-pixel rounding in various browsers.
		const distanceFromBottom =
			el.scrollHeight - el.scrollTop - el.clientHeight;
		userAtBottomRef.current = distanceFromBottom < 2;
	}, [] );

	// When the user submits, scroll to bottom unconditionally so the
	// composer stays out of the way and the response is visible.
	// Also scroll when streaming begins — this is when the assistant
	// message first appears in the DOM.
	useEffect( () => {
		if ( status === 'submitted' || status === 'streaming' ) {
			scrollToBottom();
		}
	}, [ status, scrollToBottom ] );

	// During streaming, keep the view pinned to the bottom as content
	// grows — but only when the user hasn't scrolled up to read earlier
	// messages.  The decision is based on the scroll position *before*
	// this render (captured by onScroll), so a large content chunk won't
	// fool the guard into thinking the user scrolled up.
	useEffect( () => {
		if ( ! isStreaming || ! userAtBottomRef.current ) {
			return;
		}
		const el = messagesContainerRef.current;
		if ( ! el ) {
			return;
		}
		requestAnimationFrame( () => {
			el.scrollTop = el.scrollHeight;
		} );
	}, [ messages, isStreaming ] );

	// Keyboard shortcut: Enter to send (Shift+Enter for newline).
	const handleKeyDown = useCallback(
		( e: React.KeyboardEvent< HTMLTextAreaElement > ) => {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				handleSubmit();
			}
		},
		[ handleSubmit ]
	);

	return (
		<div
			className="nvoos-pro-spa-agent-panel"
			role="region"
			aria-label={ __( 'Chat agent panel', 'nvoos-pro-spa' ) }
		>
			{/* Thread header */}
			{ threadTitle && (
				<div className="nvoos-pro-spa-agent-panel__header">
					<h2 className="nvoos-pro-spa-agent-panel__title">{ threadTitle }</h2>
				</div>
			) }

			{/* Message list */}
			<div
				ref={ messagesContainerRef }
				className="nvoos-pro-spa-agent-panel__messages"
				role="log"
				aria-live="polite"
				aria-label={ __( 'Chat messages', 'nvoos-pro-spa' ) }
				onScroll={ handleMessagesScroll }
			>
				{ messages.length === 0 && (
					<div className="nvoos-pro-spa-agent-panel__empty">
						<p className="nvoos-pro-spa-agent-panel__empty-text">
							{ __(
								'Send a message to start the conversation.',
								'nvoos-pro-spa'
							) }
						</p>
					</div>
				) }

				{ messages.map( ( message, index ) => (
				<MessageView
				key={ message.id ?? `msg-${ index }` }
				message={ message }
				index={ index }
				totalCount={ messages.length }
				isStreaming={ isStreaming }
				onRegenerate={ onRegenerate }
				 onDelete={ onDeleteMessage }
				  onFeedback={ onFeedback }
					onEdit={ onEditMessage }
					feedback={ feedbackState[ message.id ] ?? null }
				/>
			) ) }

				{/* Error display */}
				{ error && status === 'error' && (
					<div
						className="nvoos-pro-spa-agent-panel__error"
						role="alert"
					>
						<span className="nvoos-pro-spa-agent-panel__error-icon" aria-hidden="true">
							⚠
						</span>
						<span className="nvoos-pro-spa-agent-panel__error-text">
							{ error.message || __( 'An unknown error occurred.', 'nvoos-pro-spa' ) }
						</span>
					</div>
				) }


			</div>

			{/* Composer */}
			<div className="nvoos-pro-spa-agent-panel__composer">
				<form
					className="nvoos-pro-spa-agent-panel__composer-form"
					onSubmit={ ( e ) => {
						e.preventDefault();
						handleSubmit();
					} }
					aria-label={ __( 'Message composer', 'nvoos-pro-spa' ) }
				>
					<label htmlFor="nvoos-pro-spa-composer-input" className="nvoos-pro-spa-screen-reader-only">
						{ __( 'Type your message', 'nvoos-pro-spa' ) }
					</label>
					<textarea
						id="nvoos-pro-spa-composer-input"
						ref={ composerRef }
						className="nvoos-pro-spa-agent-panel__composer-input"
						value={ input }
						onChange={ handleInputChange }
						onKeyDown={ handleKeyDown }
						placeholder={ __( 'Type your message…', 'nvoos-pro-spa' ) }
						rows={ 1 }
						disabled={ isStreaming }
						aria-label={ __( 'Message input', 'nvoos-pro-spa' ) }
					/>
					<div className="nvoos-pro-spa-agent-panel__composer-actions">
						{ isStreaming ? (
							<button
								type="button"
								className="nvoos-pro-spa-agent-panel__stop-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--danger"
								onClick={ stop }
								aria-label={ __( 'Stop generating', 'nvoos-pro-spa' ) }
							>
								{ __( 'Stop', 'nvoos-pro-spa' ) }
							</button>
						) : (
							<button
								type="submit"
								className="nvoos-pro-spa-agent-panel__send-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--primary"
								disabled={ ! input.trim() }
								aria-label={ __( 'Send message', 'nvoos-pro-spa' ) }
							>
								{ __( 'Send', 'nvoos-pro-spa' ) }
							</button>
						) }
					</div>
				</form>
			</div>
		</div>
	);
}
