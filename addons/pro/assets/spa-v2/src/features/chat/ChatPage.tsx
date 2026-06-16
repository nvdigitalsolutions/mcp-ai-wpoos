/**
 * ChatPage — Main chat feature page for the Pro SPA v2.
 *
 * Routes: /chat and /chat/:threadId
 *
 * Orchestrates the chat spoke, thread management, transcript
 * persistence, and the agent panel UI.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { __, sprintf } from '@wordpress/i18n';
import { type Message } from '@ai-sdk/react';

import { useBootstrap } from '../../hooks/useBootstrap';
import { useChatSpoke } from '../../hooks/useChatSpoke';
import { useTranscripts } from '../../hooks/useTranscripts';
import { useModelStore } from '../../stores/modelStore';
import { ThreadsClient } from '../../api/threads';
import { AgentPanel } from './AgentPanel';
import { MemoryDrawer, type MemoryTab } from '../../components/shared/MemoryDrawer';
import { HitlApprovalBar } from '../../components/shared/HitlApprovalBar';

function chatSpokeMessagesFromThread(
	raw: { role: string; content: string; id?: string | number }[]
): Message[] {
	return raw
		.filter( ( m ) => m.role === 'user' || m.role === 'assistant' )
		.map( ( m ) => ( {
			id: String( m.id ?? `${ m.role }-${ Date.now() }-${ Math.random() }` ),
			role: m.role as Message[ 'role' ],
			content: m.content,
		} ) );
}

export function ChatPage(): JSX.Element {
	const { threadId: threadIdParam } = useParams< { threadId?: string } >();
	const navigate = useNavigate();
	const { loading: booting, error: bootError, runtime } = useBootstrap();

	const model = useModelStore( ( s ) => s.model );
	const profile = useModelStore( ( s ) => s.profile );
	const setModel = useModelStore( ( s ) => s.setModel );
	const setProfile = useModelStore( ( s ) => s.setProfile );
	const availableModels = useModelStore( ( s ) => s.availableModels );
	const availableProfiles = useModelStore( ( s ) => s.availableProfiles );

	const assistantId = runtime?.config?.assistantId ?? 0;
	const endpoints = runtime?.endpoints;
	const nonce = runtime?.nonce ?? '';

	// ---- direct ThreadsClient (for getMessages / create, not list) ----
	const threadsClient = useMemo(
		() =>
			endpoints?.threads
				? new ThreadsClient( { endpoint: endpoints.threads, nonce } )
				: null,
		[ endpoints?.threads, nonce ]
	);

	// ---- active thread (URL param only) ----
	const activeThreadId = useMemo(
		() => ( threadIdParam ? parseInt( threadIdParam, 10 ) : null ),
		[ threadIdParam ]
	);

	// ---- local state ----
	const [ isCreating, setIsCreating ] = useState< boolean >( false );
	const [ threadTitle, setThreadTitle ] = useState< string >( '' );

	// ---- Hooks (conditionally enabled after bootstrap) ----

	const transcripts = useTranscripts( {
		endpoint: endpoints?.transcripts ?? '',
		nonce,
		assistantId,
		disabled: ! endpoints,
	} );

	const threadInitialMessages = useMemo< Message[] >( () => [], [] );

	const chatSpoke = useChatSpoke( {
		chatClientEndpoint: endpoints?.chatClient ?? '',
		nonce,
		assistantId,
		transcriptsEndpoint: endpoints?.transcripts ?? '',
		initialMessages: threadInitialMessages,
		sessionKey: transcripts.sessionKey,
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

	// ---- Load thread messages when threadId changes ----

	const [ threadMessagesLoaded, setThreadMessagesLoaded ] = useState< boolean >( false );

	useEffect( () => {
		if ( ! activeThreadId || ! threadsClient || threadMessagesLoaded ) {
			return;
		}
		let cancelled = false;
		void ( async () => {
			try {
				// Load messages and thread info in parallel.
				const [ msgsResult, listResult ] = await Promise.all( [
					threadsClient.getMessages( activeThreadId ),
					threadsClient.list(),
				] );
				if ( cancelled ) {
					return;
				}
				// Thread title from the list.
				const found = listResult.threads.find( ( t ) => t.id === activeThreadId );
				if ( found?.title ) {
					setThreadTitle( found.title );
				}
				if ( msgsResult.messages.length > 0 ) {
					const formatted = chatSpokeMessagesFromThread( msgsResult.messages );
					chatSpoke.setMessages( formatted );
				}
			} catch {
				// Silently ignore — messages / title just won't load.
			} finally {
				if ( ! cancelled ) {
					setThreadMessagesLoaded( true );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [ activeThreadId, threadMessagesLoaded, threadsClient, chatSpoke ] );

	// ---- Reset when threadId changes ----
	useEffect( () => {
		setThreadMessagesLoaded( false );
		setThreadTitle( '' );
	}, [ activeThreadId ] );

	// ---- Handlers ----

	const handleNewThread = useCallback( async () => {
		if ( ! threadsClient ) {
			return;
		}
		setIsCreating( true );
		try {
			const t = await threadsClient.create(
				assistantId,
				{ provider: model.provider, name: model.model },
				profile,
				{}
			);
			if ( t?.id ) {
				navigate( `/chat/${ t.id }` );
				setThreadMessagesLoaded( false );
				setThreadTitle( '' );
			}
		} finally {
			setIsCreating( false );
		}
	}, [ threadsClient, assistantId, model, profile, navigate ] );

	const handleRegenerate = useCallback( () => {
		reload();
	}, [ reload ] );

	// ---- Memory drawer ----
	const [ memoryOpen, setMemoryOpen ] = useState< boolean >( false );
	const [ memoryTab, setMemoryTab ] = useState< MemoryTab >( 'memories' );
	const memoryToggleRef = useRef< HTMLButtonElement | null >( null );

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

	// Welcome screen: no thread selected.
	if ( ! activeThreadId ) {
		return (
			<div
				className="nvoos-pro-spa-chat-page nvoos-pro-spa-chat-page--welcome"
				role="main"
				aria-label={ __( 'Welcome to Chat', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-chat-page__welcome">
					<h1 className="nvoos-pro-spa-chat-page__welcome-title">
						{ __( 'NV oOS Agent Chat', 'nvoos-pro-spa' ) }
					</h1>
					<p className="nvoos-pro-spa-chat-page__welcome-description">
						{ __(
							'Start a new conversation with your AI agent. Ask questions, run tools, and get things done.',
							'nvoos-pro-spa'
						) }
					</p>
					<button
						type="button"
						className="nvoos-pro-spa-chat-page__new-thread-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--primary"
						onClick={ handleNewThread }
						disabled={ isCreating }
					>
						{ isCreating
							? __( 'Creating…', 'nvoos-pro-spa' )
							: __( 'New Thread', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>
		);
	}

	// Active thread view.
	return (
		<div
			className="nvoos-pro-spa-chat-page nvoos-pro-spa-chat-page--active"
			role="main"
			aria-label={ sprintf(
				/* translators: %d: thread ID */
				__( 'Chat thread %d', 'nvoos-pro-spa' ),
				activeThreadId
			) }
		>
			<div className="nvoos-pro-spa-chat-page__toolbar">
				{/* Model selector */}
				{ availableModels.length > 0 && (
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
							{ availableModels.map( ( m ) => (
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
				threadId={ activeThreadId }
				threadTitle={ threadTitle }
				onRegenerate={ handleRegenerate }
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
