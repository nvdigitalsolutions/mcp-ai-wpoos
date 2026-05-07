/**
 * NV oOS Chat SPA — root component.
 *
 * Uses the Vercel AI SDK UI layer (@ai-sdk/react `useChat`) on the React side
 * only. The hook talks to the existing NV oOS REST chat endpoint
 * (`mcp-ai/v1/chat-client`) via a custom `fetch` that translates NV oOS's
 * native SSE frames into the AI SDK Data Stream Protocol (see
 * `./sse-adapter.ts`).
 *
 * As of v0.3.0 this component also owns the transcripts sidebar:
 *   - The active session key is managed by `useTranscriptSession`.
 *   - That key is fed into `useChat`'s `id` so switching sessions resets
 *     `useChat`'s internal message buffer cleanly.
 *   - When a session is selected, its messages are loaded via
 *     `GET /chat-transcripts/{key}` and passed as `initialMessages`.
 *   - When a turn completes (`onFinish`), the full conversation is saved
 *     back via `POST /chat-transcripts` so subsequent visits resume.
 *
 * As of v0.4.0 this component also owns the memory drawer:
 *   - A 🧠 toggle button sits above the composer.
 *   - The drawer shows the Memories / Scope / Audit tabs backed by the
 *     existing `mcp-ai/v1/chat-memory/*` REST namespace.
 *   - `memory_event` SSE annotations (arriving as `8:` frames) flash the
 *     toggle button so the user knows a memory operation just occurred.
 *   - The drawer is disabled for guest mounts and when
 *     `runtime.endpoints.memory` is absent.
 *
 * No Node server is introduced; the WordPress PHP layer remains the
 * orchestrator and AI provider gateway.
 */

import { useChat, type Message } from '@ai-sdk/react';
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { readChatSpaConfig, type ChatSpaConfig } from './api/config';
import { TranscriptsClient, type TranscriptMessage } from './api/transcripts';
import { createChatFetch } from './sse-adapter';
import { MessageView } from './components/MessageView';
import { MemoryDrawer, type MemoryTab } from './components/MemoryDrawer';
import { TranscriptsSidebar } from './components/TranscriptsSidebar';
import { HitlApprovalBar } from './components/HitlApprovalBar';
import { useTranscriptSession } from './hooks/useTranscriptSession';

interface AppProps {
	config: ChatSpaConfig;
}

const SIDEBAR_COLLAPSED_KEY = 'nvoos-chat-spa.sidebar-collapsed';

function readInitialSidebarCollapsed(): boolean {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	try {
		return window.localStorage.getItem( SIDEBAR_COLLAPSED_KEY ) === '1';
	} catch {
		return false;
	}
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
	const assistantId = config.assistantId ?? 0;
	const isGuest = !! config.guest;

	// Guest sessions can't list/save transcripts (the REST endpoint
	// requires an authenticated user), so we disable the feature there.
	const transcriptsDisabled = isGuest;

	const session = useTranscriptSession( {
		endpoint: runtime.endpoints.transcripts,
		nonce: runtime.nonce,
		assistantId,
		disabled: transcriptsDisabled,
	} );

	const transcriptsClient = useMemo(
		() =>
			new TranscriptsClient( {
				endpoint: runtime.endpoints.transcripts,
				nonce: runtime.nonce,
				assistantId,
			} ),
		[ runtime.endpoints.transcripts, runtime.nonce, assistantId ]
	);

	const customFetch = useMemo(
		() =>
			createChatFetch( {
				endpoint,
				nonce: runtime.nonce,
				assistantId,
				guest: isGuest,
			} ),
		[ endpoint, runtime.nonce, assistantId, isGuest ]
	);

	const { messages, input, handleInputChange, handleSubmit, status, error, stop } = useChat( {
		// `id` rebinds the hook's internal state to the active session, so
		// switching conversations does not bleed messages between them.
		id: session.sessionKey,
		api: endpoint,
		initialMessages: session.initialMessages.map( ( m, idx ) => ( {
			// `useChat` requires a stable id per message; transcripts don't carry one,
			// so synthesize a deterministic key per index within the session.
			id: `${ session.sessionKey }:${ idx }`,
			role: ( m.role === 'system' || m.role === 'tool' ? 'assistant' : m.role ) as
				| 'user'
				| 'assistant'
				| 'system'
				| 'data',
			content: typeof m.content === 'string' ? m.content : '',
		} ) ) as Message[],
		// `fetch` is exposed by `useChat` so callers can plug in custom transports.
		// We use it to bridge NV oOS's native SSE protocol into the AI SDK Data
		// Stream Protocol that `useChat` expects.
		fetch: customFetch as typeof globalThis.fetch,
		streamProtocol: 'data',
		onFinish: ( assistantMessage, { finishReason } ) => {
			// Persist the conversation after every completed turn. Save is
			// fire-and-forget — failures are surfaced via the sidebar's
			// error slot but do not block the chat surface.
			if ( transcriptsDisabled ) {
				return;
			}
			void persistFinishedTurn(
				transcriptsClient,
				session.sessionKey,
				messagesRef.current,
				assistantMessage,
				finishReason
			).then( () => {
				void session.refreshList();
			} );
		},
	} );

	// Keep a ref to the current message list so `onFinish` (which is
	// captured at construction time) can read the latest array.
	const messagesRef = useRef< Message[] >( messages );
	useEffect( () => {
		messagesRef.current = messages;
	}, [ messages ] );

	const [ sidebarCollapsed, setSidebarCollapsed ] = useState< boolean >(
		readInitialSidebarCollapsed
	);
	const toggleSidebar = useCallback( () => {
		setSidebarCollapsed( ( prev ) => {
			const next = ! prev;
			try {
				window.localStorage.setItem( SIDEBAR_COLLAPSED_KEY, next ? '1' : '0' );
			} catch {
				// Ignore storage failures.
			}
			return next;
		} );
	}, [] );

	// ── Memory drawer (Phase 4) ───────────────────────────────────────────────
	// Disabled for guests and when the memory endpoint is absent (site admin
	// has the memory surface turned off or the Pro addon isn't active).
	const memoryEndpoint = runtime.endpoints.memory;
	const memoryEnabled = ! isGuest && !! memoryEndpoint;

	const [ memoryOpen, setMemoryOpen ] = useState( false );
	const [ memoryTab, setMemoryTab ] = useState< MemoryTab >( 'memories' );
	// Flash indicator: set to `true` when a `memory_event` annotation arrives,
	// cleared automatically after 3 s.
	const [ memoryFlash, setMemoryFlash ] = useState( false );
	const memoryToggleRef = useRef< HTMLButtonElement | null >( null );

	// Watch the last message's annotations for `memory_event` frames so we
	// can flash the toggle button.  The annotation already renders as a pill
	// via `MessageView` — we just need the toggle-button flash here.
	useEffect( () => {
		const last = messages[ messages.length - 1 ];
		if ( ! last?.annotations ) return;
		const hasMemoryEvent = ( last.annotations as unknown[] ).some(
			( a ) =>
				a !== null &&
				typeof a === 'object' &&
				( a as Record< string, unknown > ).type === 'memory_event'
		);
		if ( ! hasMemoryEvent ) return;
		setMemoryFlash( true );
		const t = setTimeout( () => setMemoryFlash( false ), 3000 );
		return () => clearTimeout( t );
	}, [ messages ] );

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
			{ ! transcriptsDisabled && (
				<TranscriptsSidebar
					sessions={ session.sessions }
					activeSessionKey={ session.sessionKey }
					unavailableMessage={ session.unavailableMessage }
					error={ session.error }
					isCollapsed={ sidebarCollapsed }
					onToggleCollapsed={ toggleSidebar }
					onSelect={ ( key ) => void session.selectSession( key ) }
					onDelete={ ( key ) => void session.deleteSession( key ) }
					onNew={ session.startNewSession }
				/>
			) }
			<div className="nvoos-chat-spa-main">
				{ memoryEnabled && (
					<MemoryDrawer
						endpoint={ memoryEndpoint }
						nonce={ runtime.nonce }
						assistantId={ assistantId }
						isOpen={ memoryOpen }
						activeTab={ memoryTab }
						onTabChange={ setMemoryTab }
						onClose={ () => setMemoryOpen( false ) }
						toggleRef={ memoryToggleRef }
					/>
				) }
				<div className="nvoos-chat-spa-messages" role="log" aria-live="polite">
					{ session.isLoading && (
						<p className="nvoos-chat-spa-empty">
							{ __( 'Loading conversation…', 'nvoos-chat-spa' ) }
						</p>
					) }
					{ ! session.isLoading && messages.length === 0 && (
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
				{ runtime.endpoints.approvals && (
					<HitlApprovalBar
						endpoint={ runtime.endpoints.approvals }
						nonce={ runtime.nonce }
						assistantId={ assistantId }
						sessionId={ session.sessionKey ?? undefined }
						isStreaming={ isStreaming }
					/>
				) }
				<form className="nvoos-chat-spa-composer" onSubmit={ onSubmit }>
					{ memoryEnabled && (
						<button
							ref={ memoryToggleRef }
							type="button"
							className={ `nvoos-chat-spa-memory-toggle${ memoryFlash ? ' nvoos-chat-spa-memory-toggle--flash' : '' }` }
							aria-pressed={ memoryOpen }
							aria-label={ __( 'Toggle memory drawer', 'nvoos-chat-spa' ) }
							onClick={ () => setMemoryOpen( ( o ) => ! o ) }
						>
							🧠
						</button>
					) }
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
		</div>
	);
}

/**
 * Persist a completed turn to `POST /chat-transcripts`. Strips React/AI-SDK
 * fields that the server doesn't expect.
 */
async function persistFinishedTurn(
	client: TranscriptsClient,
	sessionKey: string,
	priorMessages: Message[],
	assistantMessage: Message,
	finishReason: unknown
): Promise< void > {
	const wireMessages: TranscriptMessage[] = [ ...priorMessages, assistantMessage ].map(
		( m ) => ( {
			role: m.role,
			content: typeof m.content === 'string' ? m.content : '',
		} )
	);
	try {
		await client.save( sessionKey, wireMessages, {
			finish_reason: typeof finishReason === 'string' ? finishReason : 'stop',
			source: 'chat-spa',
		} );
	} catch {
		// Soft-fail; the sidebar surfaces transcript errors on the next refresh.
	}
}
