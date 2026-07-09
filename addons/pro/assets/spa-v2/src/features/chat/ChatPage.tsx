/**
 * ChatPage — Main chat feature page for the Pro SPA v2.
 *
 * Route: /chat
 *
 * Architecture: **Conversations-first** (matching chat-spa pattern).
 * The `useTranscripts` hook owns the chat session identity; `useChatSpoke`
 * binds to `sessionKey` so switching conversations cleanly rebinds.
 * Threads are a read-only browse view loaded via the sidebar.
 */

import { useCallback, useMemo, useRef, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { useBootstrap } from '../../hooks/useBootstrap';
import { useChatSpoke } from '../../hooks/useChatSpoke';
import { useTranscripts } from '../../hooks/useTranscripts';
import { useModelStore } from '../../stores/modelStore';
import { useAssistantStore } from '../../stores/assistantStore';
import { ThreadsClient } from '../../api/threads';
import { AgentPanel } from './AgentPanel';
import { MemoryDrawer, type MemoryTab } from '../../components/shared/MemoryDrawer';
import { HitlApprovalBar } from '../../components/shared/HitlApprovalBar';

export interface ChatPageProps {
	/** Transcript hook result lifted from Layout. */
	transcripts: ReturnType< typeof useTranscripts >;
}

export function ChatPage( props: ChatPageProps ): JSX.Element {
	const { transcripts } = props;

	const { loading: booting, error: bootError, runtime } = useBootstrap();

	const model = useModelStore( ( s ) => s.model );
	const profile = useModelStore( ( s ) => s.profile );
	const setModel = useModelStore( ( s ) => s.setModel );
	const setProfile = useModelStore( ( s ) => s.setProfile );
	const availableModels = useModelStore( ( s ) => s.availableModels );
	const availableProfiles = useModelStore( ( s ) => s.availableProfiles );

	// Deduplicate models by provider|model combo (backend may send duplicates).
	const uniqueModels = useMemo(
		() => {
			const seen = new Set< string >();
			return availableModels.filter( ( m ) => {
				const key = `${ m.provider }|${ m.model }`;
				if ( seen.has( key ) ) {
					return false;
				}
				seen.add( key );
				return true;
			} );
		},
		[ availableModels ]
	);

	// Use assistant store for dynamic selection (with runtime config fallback).
	const storedAssistantId = useAssistantStore( ( s ) => s.assistantId );
	const assistantId = storedAssistantId > 0 ? storedAssistantId : ( runtime?.config?.assistantId ?? runtime?.user?.assistant_id ?? 0 );
	const endpoints = runtime?.endpoints;
	const nonce = runtime?.nonce ?? '';

	// Map transcript messages to AI SDK Message shape (needs `id`).
	const initialMessages = useMemo(
		() =>
			transcripts.initialMessages.map( ( m, idx ) => ( {
				id: `${ transcripts.sessionKey }:${ idx }`,
				role: ( m.role === 'system' || m.role === 'tool' ? 'assistant' : m.role ) as
					| 'user'
					| 'assistant'
					| 'system'
					| 'data',
				content: typeof m.content === 'string' ? m.content : '',
			} ) ),
		[ transcripts.initialMessages, transcripts.sessionKey ]
	);

	// ---- Chat spoke (conversation-driven) ----
	// sessionKey owns the useChat `id` — switching conversations rebinds cleanly.
	const chatSpoke = useChatSpoke( {
		chatClientEndpoint: endpoints?.chatClient ?? '',
		nonce,
		assistantId,
		transcriptsEndpoint: endpoints?.transcripts ?? '',
		initialMessages,
		sessionKey: transcripts.sessionKey,
		model: model.model,
		provider: model.provider,
	} );

	const {
		messages,
		input,
		handleInputChange,
		handleSubmit,
		status,
		error: chatError,
		stop,
		reload,
		isStreaming,
		sendMessage,
	} = chatSpoke;

	// ---- Thread read‑only state (populated by ChatSidebar) ----
	// When the sidebar selects a thread, LayoutContent calls
	// chatSpoke.setMessages() with the thread's messages and sets
	// activeThreadId. Those messages are treated as a read‑only view;
	// the chat transport stays on /chat-client.

	const handleRegenerate = useCallback( () => {
		reload();
	}, [ reload ] );

	// ---- Memory drawer ----
	const [ memoryOpen, setMemoryOpen ] = useState< boolean >( false );
	const [ memoryTab, setMemoryTab ] = useState< MemoryTab >( 'memories' );
	const memoryToggleRef = useRef< HTMLButtonElement | null >( null );

	// ---- Feedback state ----
	const [ feedbackState, setFeedbackState ] = useState< Record< string, 'up' | 'down' > >( {} );

	const handleDeleteMessage = useCallback(
		( msgId: string ) => {
			chatSpoke.setMessages( messages.filter( ( m: Message ) => m.id !== msgId ) );
		},
		[ messages, chatSpoke ]
	);

	const handleFeedback = useCallback(
		( msgId: string, rating: 'up' | 'down' ) => {
			setFeedbackState( ( prev ) => {
				if ( prev[ msgId ] === rating ) {
					const next = { ...prev };
					delete next[ msgId ];
					return next;
				}
				return { ...prev, [ msgId ]: rating };
			} );
		},
		[]
	);

	const handleEditMessage = useCallback(
		( msgId: string ) => {
			const idx = messages.findIndex( ( m ) => m.id === msgId );
			if ( idx < 0 ) return;
			const msg = messages[ idx ];
			if ( msg.role !== 'user' ) return;
			chatSpoke.setMessages( messages.slice( 0, idx ) );
			const content = typeof msg.content === 'string' ? msg.content : '';
			// Fire a synthetic input event to update the composer.
			const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			)?.set;
			const inputEl = document.getElementById(
				'nvoos-pro-spa-composer-input'
			) as HTMLTextAreaElement | null;
			if ( inputEl && nativeInputValueSetter ) {
				nativeInputValueSetter.call( inputEl, content );
				inputEl.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				inputEl.focus();
			}
		},
		[ messages, chatSpoke ]
	);

	const hasMemory = typeof endpoints?.memory === 'string' && endpoints.memory.length > 0;
	const hasApprovals = typeof endpoints?.approvals === 'string' && endpoints.approvals.length > 0;

	// ---- Render ----

	if ( booting ) {
		return (
			<div
				className="nvoos-pro-spa-chat-page nvoos-pro-spa-chat-page--loading"
				role="status"
				aria-label={ __( 'Loading chat', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-chat-page__spinner" aria-hidden="true" />
				<p className="nvoos-pro-spa-chat-page__loading-text">
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( bootError ) {
		return (
			<div
				className="nvoos-pro-spa-chat-page nvoos-pro-spa-chat-page--error"
				role="alert"
			>
				<p className="nvoos-pro-spa-chat-page__error-text">
					{ sprintf(
						/* translators: %s: error message */
						__( 'Failed to initialize chat: %s', 'nvoos-pro-spa' ),
						bootError
					) }
				</p>
			</div>
		);
	}

	// Always render the chat surface — conversations own the transport.
	return (
		<div
			className="nvoos-pro-spa-chat-page nvoos-pro-spa-chat-page--active"
			role="main"
			aria-label={ __( 'Chat conversation', 'nvoos-pro-spa' ) }
		>
			<div className="nvoos-pro-spa-chat-page__toolbar">
				{/* Model selector */}
				{ uniqueModels.length > 0 && (
					<div className="nvoos-pro-spa-chat-page__model-select">
						<label
							htmlFor="nvoos-pro-spa-model-select"
							className="nvoos-pro-spa-chat-page__select-label"
						>
							{ __( 'Model', 'nvoos-pro-spa' ) }
						</label>
						<select
							id="nvoos-pro-spa-model-select"
							className="nvoos-pro-spa-chat-page__select"
							value={ `${ model.provider }|${ model.model }` }
							onChange={ ( e ) => {
								const [ provider, modelName ] = e.target.value.split( '|' );
								if ( provider && modelName ) {
									setModel( { provider, model: modelName } );
								}
							} }
						>
							{ uniqueModels.map( ( m ) => (
								<option
									key={ `${ m.provider }|${ m.model }` }
									value={ `${ m.provider }|${ m.model }` }
								>
									{ m.provider } / { m.model }
								</option>
							) ) }
						</select>
					</div>
				) }

				{/* Profile selector */}
				{ availableProfiles.length > 0 && (
					<div className="nvoos-pro-spa-chat-page__profile-select">
						<label
							htmlFor="nvoos-pro-spa-profile-select"
							className="nvoos-pro-spa-chat-page__select-label"
						>
							{ __( 'Profile', 'nvoos-pro-spa' ) }
						</label>
						<select
							id="nvoos-pro-spa-profile-select"
							className="nvoos-pro-spa-chat-page__select"
							value={ profile }
							onChange={ ( e ) => setProfile( e.target.value ) }
						>
							{ availableProfiles.map( ( p ) => (
								<option key={ p } value={ p }>
									{ p }
								</option>
							) ) }
						</select>
					</div>
				) }

				{/* Memory drawer toggle */}
				{ hasMemory && (
					<button
						type="button"
						ref={ memoryToggleRef }
						className="nvoos-pro-spa-chat-page__memory-btn nvoos-pro-spa-btn"
						onClick={ () => setMemoryOpen( ( prev ) => ! prev ) }
						aria-label={ __( 'Toggle memory drawer', 'nvoos-pro-spa' ) }
						aria-expanded={ memoryOpen }
					>
						{ __( 'Memory', 'nvoos-pro-spa' ) }
					</button>
				) }
			</div>

			{/* HITL approval bar */}
			{ hasApprovals && (
				<HitlApprovalBar
					endpoint={ endpoints!.approvals }
					nonce={ nonce }
					assistantId={ assistantId }
					sessionId={ transcripts.sessionKey }
					isStreaming={ isStreaming }
				/>
			) }

			<AgentPanel
			messages={ messages }
			input={ input }
			handleInputChange={ handleInputChange }
			handleSubmit={ handleSubmit }
			status={ status }
			error={ chatError }
			stop={ stop }
			reload={ reload }
			isStreaming={ isStreaming }
			sendMessage={ sendMessage }
			threadId={ 0 }
			threadTitle={ '' }
			onRegenerate={ handleRegenerate }
			onDeleteMessage={ handleDeleteMessage }
			onFeedback={ handleFeedback }
			onEditMessage={ handleEditMessage }
			feedbackState={ feedbackState }
			/>

			{/* Memory drawer */}
			{ hasMemory && (
				<MemoryDrawer
					endpoint={ endpoints!.memory }
					nonce={ nonce }
					assistantId={ assistantId }
					isOpen={ memoryOpen }
					activeTab={ memoryTab }
					onTabChange={ setMemoryTab }
					onClose={ () => setMemoryOpen( false ) }
					toggleRef={ memoryToggleRef }
				/>
			) }
		</div>
	);
}
