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

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'react';
import { type Message } from '@ai-sdk/react';
import { __, sprintf } from '@wordpress/i18n';

import { useBootstrap } from '../../hooks/useBootstrap';
import { useChatSpoke } from '../../hooks/useChatSpoke';
import { useTranscripts } from '../../hooks/useTranscripts';
import { useKeyboardShortcuts } from '../../hooks/useKeyboardShortcuts';
import { useSpeechPlayback } from '../../hooks/useSpeechPlayback';
import { useJobBus } from '../../hooks/useJobBus';
import { useTabTitleBadge } from '../../hooks/useTabTitleBadge';
import { useModelStore } from '../../stores/modelStore';
import { useAssistantStore } from '../../stores/assistantStore';
import { useUIStore } from '../../stores/uiStore';
import { ThreadsClient } from '../../api/threads';
import { exportConversation } from '../../utils/export-conversation';
import { AgentPanel } from './AgentPanel';
import { MemoryDrawer, type MemoryTab } from '../../components/shared/MemoryDrawer';
import { HitlApprovalBar } from '../../components/shared/HitlApprovalBar';
import { ToolShortcutsDrawer } from './ToolShortcutsDrawer';
import { SlashCommandsDrawer } from './SlashCommandsDrawer';
import { KeyboardShortcutsHelp } from '../../components/shared/KeyboardShortcutsHelp';
import { SuggestedPrompts } from '../../components/shared/SuggestedPrompts';
import { TasksDrawer } from '../../components/shared/TasksDrawer';
import { useWorkflowState, useDelegationNotices } from '../../hooks/useAgentTeam';

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
		// Preserve the original role so tool messages are properly surfaced;
		// only system messages are re-mapped to assistant (the AI SDK does not
		// render system messages).  Spread the original message so annotations
		// and toolInvocations survive the mapping for CCT-loaded history.
		const initialMessages = useMemo(
			() =>
				transcripts.initialMessages.map( ( m, idx ) => ( {
					...m,
					id: `${ transcripts.sessionKey }:${ idx }`,
					role: ( m.role === 'system' ? 'assistant' : m.role ) as
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
		usageMap,
		handleSubmitWithAttachments,
	} = chatSpoke;

	// Enhance usageMap with cost/model data extracted from "data"
	// annotations that arrive via the SSE adapter's type-8 frames.
	const enhancedUsageMap = useMemo( () => {
		const map = { ...usageMap };
		for ( const msg of messages ) {
			const anns = Array.isArray( ( msg as Record< string, unknown > ).annotations )
				? ( msg as Record< string, unknown > ).annotations as Array< Record< string, unknown > >
				: [];
			for ( const ann of anns ) {
				if ( ann.type === 'data' && ann.data && typeof ann.data === 'object' ) {
					const d = ann.data as Record< string, unknown >;
					const cost = d.cost as Record< string, unknown > | undefined;
					const usage = d.usage as Record< string, unknown > | undefined;
					const existing = map[ msg.id ] || {};
					if ( ! existing.costUsd && cost && typeof cost.cost_usd === 'number' ) {
						existing.costUsd = cost.cost_usd as number;
					}
					if ( ! existing.totalTokens && usage && typeof usage.total_tokens === 'number' ) {
						existing.totalTokens = usage.total_tokens as number;
					}
					if ( ! existing.promptTokens && usage && typeof usage.prompt_tokens === 'number' ) {
						existing.promptTokens = usage.prompt_tokens as number;
					}
					if ( ! existing.completionTokens && usage && typeof usage.completion_tokens === 'number' ) {
						existing.completionTokens = usage.completion_tokens as number;
					}
					if ( ! existing.model && typeof d.model === 'string' && d.model ) {
						existing.model = d.model as string;
					}
					if ( ! existing.provider && typeof d.provider === 'string' && d.provider ) {
						existing.provider = d.provider as string;
					}
					map[ msg.id ] = existing;
				}
			}
		}
		return map;
	}, [ usageMap, messages ] );

	// Watch for media insert events from the sidebar Media tab.
	// When the user clicks "Insert into chat", append attachment ID
	// references to the composer so the image is easily referenced.
	const mediaInsert = useUIStore( ( s ) => s.mediaInsert );
	const clearMediaInsert = useUIStore( ( s ) => s.clearMediaInsert );
	useEffect( () => {
		if ( ! mediaInsert || mediaInsert.length === 0 ) {
			return;
		}
		const refs = mediaInsert.map( ( id ) => `[attachment:${ id }]` ).join( ' ' );

		// Programmatically set the composer value and fire an input event
		// so useChat/AI SDK's internal state stays in sync.
		const composer = document.getElementById( 'nvoos-pro-spa-composer-input' ) as HTMLTextAreaElement | null;
		if ( composer ) {
			const nativeSetter = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			)?.set;
			if ( nativeSetter ) {
				const current = composer.value || '';
				const newValue = current ? `${ current } ${ refs }` : refs;
				nativeSetter.call( composer, newValue );
				composer.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				composer.focus();
			}
		}

		clearMediaInsert();
	}, [ mediaInsert, clearMediaInsert ] );

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

	// ---- Tool Shortcuts drawer ----
	const [ toolsOpen, setToolsOpen ] = useState< boolean >( false );
	const toolsToggleRef = useRef< HTMLButtonElement | null >( null );

	// ---- Slash Commands drawer ----
	const [ commandsOpen, setCommandsOpen ] = useState< boolean >( false );
	const commandsToggleRef = useRef< HTMLButtonElement | null >( null );

	// Shared callback: close one drawer when another opens.
	const openDrawer = useCallback(
		( which: 'memory' | 'tools' | 'commands' ) => {
			setMemoryOpen( which === 'memory' );
			setToolsOpen( which === 'tools' );
			setCommandsOpen( which === 'commands' );
		},
		[]
	);

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
	const hasShortcuts = typeof endpoints?.shortcuts === 'string' && endpoints.shortcuts.length > 0;
	const hasSlashCommands = typeof endpoints?.slashCommands === 'string' && endpoints.slashCommands.length > 0;

	// ── Keyboard shortcuts (v0.9.0) ────────────────────────────────────────────
	const ks = useKeyboardShortcuts( {
		onExport: () => { if ( messages.length > 0 ) exportConversation( messages, 'json', assistantId, transcripts.sessionKey ); },
		onNewChat: () => transcripts.startNewSession(),
	} );

	// ── Speech playback (v0.9.0) ──────────────────────────────────────────────
	const speech = useSpeechPlayback( { toolsEndpoint: endpoints?.tools ?? '', nonce, assistantId } );

	// ── Job system (v0.9.0) ───────────────────────────────────────────────────
	const cronBase = ( endpoints?.chat ?? '' ).replace( /\/chat\/?$/, '' );
	const jobBus = useJobBus( cronBase, nonce );
	useTabTitleBadge( jobBus.runningCount );

	// ── Dark mode (v0.9.0) — uses existing uiStore ────────────────────────────
	const theme = useUIStore( ( s ) => s.theme );
	const setTheme = useUIStore( ( s ) => s.setTheme );

	// ── Workflow + delegation (v0.9.0) ──────────────────────────────────────
	const workflowState = useWorkflowState( messages );
	const delegationNotices = useDelegationNotices( messages );

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
						onClick={ () => openDrawer( 'memory' ) }
						aria-label={ __( 'Toggle memory drawer', 'nvoos-pro-spa' ) }
						aria-expanded={ memoryOpen }
					>
						{ __( 'Memory', 'nvoos-pro-spa' ) }
											</button>
										) }

										{/* Tool Shortcuts drawer toggle */}
										{ hasShortcuts && (
											<button
												type="button"
												ref={ toolsToggleRef }
												className="nvoos-pro-spa-chat-page__tools-btn nvoos-pro-spa-btn"
												onClick={ () => openDrawer( 'tools' ) }
												aria-label={ __( 'Toggle tool shortcuts drawer', 'nvoos-pro-spa' ) }
												aria-expanded={ toolsOpen }
											>
												{ __( 'Tools', 'nvoos-pro-spa' ) }
											</button>
										) }

										{/* Slash Commands drawer toggle */}
										{ hasSlashCommands && (
											<button
												type="button"
												ref={ commandsToggleRef }
												className="nvoos-pro-spa-chat-page__commands-btn nvoos-pro-spa-btn"
												onClick={ () => openDrawer( 'commands' ) }
												aria-label={ __( 'Toggle slash commands drawer', 'nvoos-pro-spa' ) }
												aria-expanded={ commandsOpen }
											>
												{ __( 'Commands', 'nvoos-pro-spa' ) }
											</button>
										) }
										{/* Theme toggle (v0.9.0) */}
										<button type="button" className="nvoos-pro-spa-btn"
											onClick={ () => setTheme( theme === 'dark' ? 'light' : 'dark' ) }
											aria-label={ theme === 'dark' ? __( 'Light mode', 'nvoos-pro-spa' ) : __( 'Dark mode', 'nvoos-pro-spa' ) }>
											{ theme === 'dark' ? '☀️' : '🌙' }
										</button>
										{/* Export (v0.9.0) */}
										<button type="button" className="nvoos-pro-spa-btn"
											disabled={ messages.length === 0 }
											onClick={ () => exportConversation( messages, 'json', assistantId, transcripts.sessionKey ) }
											aria-label={ __( 'Export conversation', 'nvoos-pro-spa' ) }>
											📥
										</button>
										{/* Keyboard shortcuts (v0.9.0) */}
										<button type="button" className="nvoos-pro-spa-btn"
											onClick={ ks.toggleHelp }
											aria-label={ __( 'Keyboard shortcuts', 'nvoos-pro-spa' ) }>
											⌨
										</button>
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

			{/* Suggested prompts (GAP-07: v0.9.0) */}
			<SuggestedPrompts
				prompts={ ( runtime?.config as Record< string, unknown > )?.suggestedPrompts as string[] | undefined }
				onSelect={ ( prompt ) => sendMessage( prompt ) }
			/>

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
				usageMap={ enhancedUsageMap }
				onSpeechPlay={ ( t ) => void speech.play( t ) }
				onSpeechStop={ speech.stop }
				speechStateFor={ speech.stateFor }
				jobs={ jobBus.jobs }
				onCancelJob={ ( id ) => void jobBus.cancelJob( id ) }
				onRetryJob={ ( id ) => void jobBus.retryJob( id ) }
				workflow={ workflowState }
				delegations={ delegationNotices }
				toolsEndpoint={ endpoints?.tools }
				uploadEndpoint={ endpoints?.upload }
				nonce={ nonce }
				assistantId={ assistantId }
				onSubmitWithAttachments={ ( atts ) => handleSubmitWithAttachments( atts ) }
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

			{/* Tool Shortcuts drawer */}
			{ hasShortcuts && (
				<ToolShortcutsDrawer
					endpoint={ endpoints!.shortcuts }
					nonce={ nonce }
					assistantId={ assistantId }
					isOpen={ toolsOpen }
					onClose={ () => setToolsOpen( false ) }
					onInsertPayload={ sendMessage }
					toggleRef={ toolsToggleRef }
				/>
			) }

			{/* Slash Commands drawer */}
			{ hasSlashCommands && (
				<SlashCommandsDrawer
					endpoint={ endpoints!.slashCommands }
					nonce={ nonce }
					isOpen={ commandsOpen }
					onClose={ () => setCommandsOpen( false ) }
					onInsertPayload={ sendMessage }
					toggleRef={ commandsToggleRef }
				/>
			) }

			{/* Tasks drawer (v0.9.0) */}
			<TasksDrawer
				jobs={ jobBus.jobs }
				runningCount={ jobBus.runningCount }
				onCancelJob={ jobBus.cancelJob }
				onRetryJob={ jobBus.retryJob }
				onDismissJob={ jobBus.dismissJob }
				onDismissAll={ jobBus.dismissAllTerminal }
			/>
			</div>
	);
	}
