/**
 * AgentPanel — Core chat UI: message list + composer.
 *
 * Receives chat state from useChatSpoke (via ChatPage) and renders
 * the scrollable message list and the input composer area.
 */

import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { type Message } from '@ai-sdk/react';
import { MessageView } from './MessageView';
import { AudioRecorderButton } from '../../components/shared/AudioRecorderButton';
import { WorkflowTracker, type WorkflowState } from '../../components/shared/WorkflowTracker';
import { DelegationNotice, type DelegationData } from '../../components/shared/DelegationNotice';
import type { UsageData } from '../../components/shared/UsageBadges';
import type { SpeechState } from '../../components/shared/SpeechButton';
import type { JobRecord } from '../../components/shared/JobCard';
import { useAttachments, ACCEPT_ATTR } from '../../hooks/useAttachments';

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
	/** Usage data per message (v0.9.0). */
	usageMap?: Record< string, UsageData >;
	/** Speech playback (v0.9.0). */
	onSpeechPlay?: ( text: string ) => void;
	onSpeechStop?: () => void;
	speechStateFor?: ( text: string ) => SpeechState;
	/** Job system (v0.9.0). */
	jobs?: Record< string, JobRecord >;
	onCancelJob?: ( id: string ) => void;
	onRetryJob?: ( id: string ) => void;
	/** Workflow + delegation (v0.9.0). */
	workflow?: WorkflowState | null;
	delegations?: DelegationData[];
	/** Manual save conversation callback (v2.1.0). */
	onSaveConversation?: () => void;
	/** Audio recorder (v0.9.0). */
	toolsEndpoint?: string;
	uploadEndpoint?: string;
	nonce?: string;
	assistantId?: number;
	/** Submit with attachments (v0.9.0). */
	onSubmitWithAttachments?: ( attachments: Array< { name?: string; contentType?: string; url: string } > ) => void;
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
		usageMap,
		onSpeechPlay,
		onSpeechStop,
		speechStateFor,
		jobs,
		onCancelJob,
		onRetryJob,
		workflow,
		delegations,
		onSaveConversation,
		toolsEndpoint,
		uploadEndpoint,
		nonce,
		assistantId,
		onSubmitWithAttachments,
	} = props;

	const messagesContainerRef = useRef< HTMLDivElement | null >( null );
	const composerRef = useRef< HTMLTextAreaElement | null >( null );

	// ── Attachments (v0.9.0) ───────────────────────────────────────────
	const attachments = useAttachments( { uploadEndpoint: uploadEndpoint ?? '', nonce: nonce ?? '' } );
	const fileInputRef = useRef< HTMLInputElement | null >( null );

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

	// ── Save conversation handler (v2.1.0) ─────────────────────────────
	const [ savedIndicator, setSavedIndicator ] = useState< boolean >( false );
	const handleSaveClick = useCallback( () => {
		if ( ! onSaveConversation || isStreaming ) return;
		onSaveConversation();
		setSavedIndicator( true );
		setTimeout( () => setSavedIndicator( false ), 1500 );
	}, [ onSaveConversation, isStreaming ] );

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
					usage={ usageMap?.[ message.id ] ?? null }
					onSpeechPlay={ onSpeechPlay }
					onSpeechStop={ onSpeechStop }
					speechStateFor={ speechStateFor }
					jobs={ jobs }
					onCancelJob={ onCancelJob }
					onRetryJob={ onRetryJob }
					workflow={ index === messages.length - 1 ? workflow : null }
					delegations={ delegations }
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
							if ( ! input.trim() && attachments.files.length === 0 ) return;
							if ( attachments.files.length > 0 && onSubmitWithAttachments ) {
								void attachments.toPendingAttachments().then( ( atts ) => {
									onSubmitWithAttachments( atts );
									attachments.clear();
								} );
							} else {
								handleSubmit();
							}
						} }
						aria-label={ __( 'Message composer', 'nvoos-pro-spa' ) }
					>
					<label htmlFor="nvoos-pro-spa-composer-input" className="nvoos-pro-spa-screen-reader-only">
						{ __( 'Type your message', 'nvoos-pro-spa' ) }
					</label>
					{/* Audio recorder buttons (v0.9.0) */}
					{ toolsEndpoint && nonce && (
						<div className="nvoos-pro-spa-agent-panel__composer-toolbar">
							<AudioRecorderButton mode="transcribe" toolsEndpoint={ toolsEndpoint } uploadEndpoint={ uploadEndpoint ?? '' } nonce={ nonce } assistantId={ assistantId ?? 0 } disabled={ isStreaming }
								onTranscribed={ ( text ) => {
									const el = document.getElementById( 'nvoos-pro-spa-composer-input' ) as HTMLTextAreaElement | null;
									if ( el ) { const s = Object.getOwnPropertyDescriptor( window.HTMLTextAreaElement.prototype, 'value' )?.set; if ( s ) { s.call( el, text ); el.dispatchEvent( new Event( 'input', { bubbles: true } ) ); } }
								} } />
							<AudioRecorderButton mode="voice" toolsEndpoint={ toolsEndpoint } uploadEndpoint={ uploadEndpoint ?? '' } nonce={ nonce } assistantId={ assistantId ?? 0 } disabled={ isStreaming }
								onVoiceSubmit={ ( text ) => sendMessage( text ) } />
							{/* Attach file button (v0.9.0) */}
							<input ref={ fileInputRef } type="file" className="nvoos-pro-spa-screen-reader-only" multiple accept={ ACCEPT_ATTR }
								aria-hidden="true" tabIndex={ -1 }
								onChange={ ( e ) => { if ( e.target.files ) { attachments.attach( e.target.files ); e.target.value = ''; } } } />
							<button type="button" className="nvoos-pro-spa-attach-btn"
								aria-label={ __( 'Attach file', 'nvoos-pro-spa' ) } title={ __( 'Attach file', 'nvoos-pro-spa' ) }
								disabled={ isStreaming } onClick={ () => fileInputRef.current?.click() }>📎</button>
							{/* Save conversation button (v2.1.0) */}
							{ onSaveConversation && (
								<button type="button" className="nvoos-pro-spa-save-btn"
									aria-label={ __( 'Save conversation', 'nvoos-pro-spa' ) }
									title={ __( 'Save conversation', 'nvoos-pro-spa' ) }
									disabled={ isStreaming || messages.length === 0 }
									onClick={ handleSaveClick }>
									{ savedIndicator ? '✅' : '💾' }
								</button>
							) }
							</div>
					) }
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
					{/* Attachment strip (v0.9.0) */}
					{ attachments.files.length > 0 && (
						<ul className="nvoos-pro-spa-attachment-strip" aria-label={ __( 'Attachments', 'nvoos-pro-spa' ) }>
							{ attachments.files.map( ( pf ) => (
								<li key={ pf.key } className="nvoos-pro-spa-attachment-chip">
									{ pf.previewUrl ? <img src={ pf.previewUrl } alt={ pf.file.name } className="nvoos-pro-spa-attachment-thumb" /> : <span className="nvoos-pro-spa-attachment-icon" aria-hidden="true">📄</span> }
									<span className="nvoos-pro-spa-attachment-name">
										{ pf.uploading ? __( 'Uploading…', 'nvoos-pro-spa' ) :
										  pf.uploadError ? `${ pf.file.name } (${ __( 'failed', 'nvoos-pro-spa' ) })` :
										  pf.attachmentId ? `#${ pf.attachmentId }` : pf.file.name }
									</span>
									<button type="button" className="nvoos-pro-spa-attachment-remove" aria-label={ `${ __( 'Remove', 'nvoos-pro-spa' ) } ${ pf.file.name }` }
										onClick={ ( e ) => { e.preventDefault(); e.stopPropagation(); attachments.remove( pf.key ); } }>×</button>
								</li>
							) ) }
							<li className="nvoos-pro-spa-attachment-chip">
								<button type="button" className="nvoos-pro-spa-attachment-clear-all"
									onClick={ ( e ) => { e.preventDefault(); e.stopPropagation(); attachments.clear(); } }>
									{ __( 'Clear all', 'nvoos-pro-spa' ) }
								</button>
							</li>
						</ul>
					) }
					{ attachments.attachError && (
						<p className="nvoos-pro-spa-attachment-error" role="alert">{ attachments.attachError }</p>
					) }
					<div className="nvoos-pro-spa-agent-panel__composer-actions">
						{ isStreaming ? (
							<button type="button" className="nvoos-pro-spa-agent-panel__stop-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--danger"
								onClick={ stop } aria-label={ __( 'Stop generating', 'nvoos-pro-spa' ) }>{ __( 'Stop', 'nvoos-pro-spa' ) }</button>
						) : (
							<button type="submit" className="nvoos-pro-spa-agent-panel__send-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--primary"
								disabled={ ! input.trim() && attachments.files.length === 0 }
								aria-label={ __( 'Send message', 'nvoos-pro-spa' ) }>{ __( 'Send', 'nvoos-pro-spa' ) }</button>
						) }
					</div>
				</form>
			</div>
		</div>
	);
}
