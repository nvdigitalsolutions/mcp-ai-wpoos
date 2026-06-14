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
 * As of v0.5.0 this component also supports the Threads sidebar:
 *   - A "Threads" tab in the sidebar lists active threads from the thread
 *     manager (`mcp-ai/v1/threads`).
 *   - Clicking a thread loads its messages into the chat surface as
 *     `initialMessages` (read-only — the chat transport stays on /chat-client).
 *   - The "Conversations" tab still shows saved transcripts.
 *
 * No Node server is introduced; the WordPress PHP layer remains the
 * orchestrator and AI provider gateway.
 */

import { useChat, type Message } from '@ai-sdk/react';
import { __ } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	type FormEvent,
} from 'react';
import { readChatSpaConfig, type ChatSpaConfig } from './api/config';
import { TranscriptsClient, type TranscriptMessage } from './api/transcripts';
import { createChatFetch } from './sse-adapter';
import { MessageView } from './components/MessageView';
import { MemoryDrawer, type MemoryTab } from './components/MemoryDrawer';
import { TranscriptsSidebar } from './components/TranscriptsSidebar';
import { HitlApprovalBar } from './components/HitlApprovalBar';
import { useTranscriptSession } from './hooks/useTranscriptSession';
import { useThreadsSidebar } from './hooks/useThreadsSidebar';
import { useAttachments, ACCEPT_ATTR } from './hooks/useAttachments';

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

	// ── Threads sidebar (new) ────────────────────────────────────────────────
	// Threads are always disabled for guests (same as transcripts).
	const threadsDisabled = isGuest;

	const threads = useThreadsSidebar( {
		endpoint: runtime.endpoints.threads,
		nonce: runtime.nonce,
		disabled: threadsDisabled,
	} );

	// Sidebar tab state — "Conversations" or "Threads".
	type SidebarTab = 'transcripts' | 'threads';
	const [ sidebarTab, setSidebarTab ] = useState< SidebarTab >( 'transcripts' );

	// When switching to the transcripts tab, deselect any active thread.
	const handleTabChange = useCallback( ( tab: SidebarTab ) => {
		setSidebarTab( tab );
		if ( tab === 'transcripts' ) {
			threads.deselectThread();
		}
	}, [ threads ] );

	// When a transcript session is selected, deselect any active thread.
	const handleSelectSession = useCallback(
		( key: string ) => {
			threads.deselectThread();
			void session.selectSession( key );
		},
		[ session, threads ]
	);

	// Compute initialMessages: threads take priority when a thread is active.
	const mergedInitialMessages = useMemo( () => {
		if ( threads.threadInitialMessages ) {
			return threads.threadInitialMessages.map( ( m, idx ) => ( {
				id: m.id || `thread-msg-${ idx }`,
				role: ( m.role === 'system' || m.role === 'tool' ? 'assistant' : m.role ) as
					| 'user'
					| 'assistant'
					| 'system'
					| 'data',
				content: m.content,
			} ) ) as Message[];
		}
		return session.initialMessages.map( ( m, idx ) => ( {
			id: `${ session.sessionKey }:${ idx }`,
			role: ( m.role === 'system' || m.role === 'tool' ? 'assistant' : m.role ) as
				| 'user'
				| 'assistant'
				| 'system'
				| 'data',
			content: typeof m.content === 'string' ? m.content : '',
		} ) ) as Message[];
	}, [ threads.threadInitialMessages, session.initialMessages, session.sessionKey ] );

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

	const { messages, input, handleInputChange, handleSubmit, status, error, stop, reload, setMessages } = useChat( {
		// `id` rebinds the hook's internal state to the active session, so
		// switching conversations does not bleed messages between them.
		id: session.sessionKey,
		api: endpoint,
		initialMessages: mergedInitialMessages as Message[],
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
		if ( ! input.trim() && attachments.files.length === 0 ) {
			return;
		}
		if ( attachments.files.length > 0 ) {
			// Convert to Attachment[] asynchronously and submit.
			void attachments.toPendingAttachments().then( ( attached ) => {
				handleSubmit( e, { experimental_attachments: attached } );
				attachments.clear();
			} );
		} else {
			handleSubmit( e );
		}
	};

	const isStreaming = status === 'streaming' || status === 'submitted';

	// ── Attachment state ──────────────────────────────────────────────────────
	const attachments = useAttachments();
	const fileInputRef = useRef< HTMLInputElement | null >( null );

	const onFileChange = useCallback(
		( e: React.ChangeEvent< HTMLInputElement > ) => {
			if ( e.target.files ) {
				attachments.attach( e.target.files );
			}
			// Reset input value so the same file can be re-added after removal.
			e.target.value = '';
		},
		[ attachments ]
	);

	// ── Regenerate + Branching ────────────────────────────────────────────────
	// "Regenerate" re-sends the last user message to get a fresh assistant reply.
	const canRegenerate =
		! isStreaming &&
		messages.length >= 2 &&
		messages[ messages.length - 1 ].role === 'assistant';

	const handleRegenerate = useCallback( () => {
		void reload();
	}, [ reload ] );

	// "Edit + re-submit" — state stores the message being edited.
	const [ editingMsgId, setEditingMsgId ] = useState< string | null >( null );

	const handleStartEdit = useCallback(
		( msgId: string ) => {
			const idx = messages.findIndex( ( m ) => m.id === msgId );
			if ( idx < 0 ) return;
			const msg = messages[ idx ];
			if ( msg.role !== 'user' ) return;
			// Trim all messages after (and including) the edited one so the next
			// submit regenerates from that point.
			setMessages( messages.slice( 0, idx ) );
			// We can't set the input value directly — the composer's `handleInputChange`
			// is tied to `useChat`'s internal input state.  We fire a synthetic input
			// event to update it.
			const content = typeof msg.content === 'string' ? msg.content : '';
			const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			)?.set;
			const inputEl = document.getElementById(
				'nvoos-chat-spa-input'
			) as HTMLInputElement | null;
			if ( inputEl && nativeInputValueSetter ) {
				nativeInputValueSetter.call( inputEl, content );
				inputEl.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				inputEl.focus();
			}
			setEditingMsgId( msgId );
		},
		[ messages, setMessages ]
	);

	// Clear editing indicator when input is cleared or a new message arrives.
	useEffect( () => {
		if ( ! input ) {
			setEditingMsgId( null );
		}
	}, [ input ] );

	return (
		<div className="nvoos-chat-spa-app" data-theme={ config.theme ?? 'auto' }>
			{ ! transcriptsDisabled && (
				<TranscriptsSidebar
					sessions={ session.sessions }
					activeSessionKey={ session.sessionKey }
					unavailableMessage={ session.unavailableMessage }
					transcriptError={ session.error }
					isCollapsed={ sidebarCollapsed }
					onToggleCollapsed={ toggleSidebar }
					onSelect={ handleSelectSession }
					onDelete={ ( key ) => void session.deleteSession( key ) }
					onNew={ session.startNewSession }
					threads={ threads.threads }
					threadsLoading={ threads.isLoading }
					threadsError={ threads.error }
					threadsUnavailable={ threads.unavailable }
					activeThreadId={ threads.activeThreadId }
					activeTab={ sidebarTab }
					onTabChange={ handleTabChange }
					onSelectThread={ ( id ) => void threads.selectThread( id ) }
					onDeselectThread={ threads.deselectThread }
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
					{ ( session.isLoading || threads.isLoading ) && messages.length === 0 && (
						<p className="nvoos-chat-spa-empty">
							{ __( 'Loading conversation…', 'nvoos-chat-spa' ) }
						</p>
					) }
					{ ! session.isLoading && ! threads.isLoading && messages.length === 0 && (
						<p className="nvoos-chat-spa-empty">
							{ __( 'Start a conversation…', 'nvoos-chat-spa' ) }
						</p>
					) }
					{ messages.map( ( m, idx ) => (
						<div key={ m.id } className="nvoos-chat-spa-message-wrapper">
							<MessageView
								message={ m as Parameters< typeof MessageView >[ 0 ][ 'message' ] }
							/>
							{ ! isStreaming && m.role === 'user' && (
								<button
									type="button"
									className="nvoos-chat-spa-edit-btn"
									aria-label={ __( 'Edit this message and regenerate', 'nvoos-chat-spa' ) }
									onClick={ () => handleStartEdit( m.id ) }
								>
									✏
								</button>
							) }
							{ ! isStreaming &&
								m.role === 'assistant' &&
								idx === messages.length - 1 &&
								canRegenerate && (
									<button
										type="button"
										className="nvoos-chat-spa-regen-btn"
										aria-label={ __( 'Regenerate response', 'nvoos-chat-spa' ) }
										onClick={ handleRegenerate }
									>
										↺
									</button>
								) }
						</div>
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
					{ /* Hidden file input */ }
					<input
						ref={ fileInputRef }
						type="file"
						className="screen-reader-text"
						id="nvoos-chat-spa-file-input"
						multiple
						accept={ ACCEPT_ATTR }
						aria-hidden="true"
						tabIndex={ -1 }
						onChange={ onFileChange }
					/>
					<button
						type="button"
						className="nvoos-chat-spa-attach-btn"
						aria-label={ __( 'Attach file', 'nvoos-chat-spa' ) }
						title={ __( 'Attach file', 'nvoos-chat-spa' ) }
						disabled={ isStreaming }
						onClick={ () => fileInputRef.current?.click() }
					>
						📎
					</button>
					<label className="screen-reader-text" htmlFor="nvoos-chat-spa-input">
						{ __( 'Message', 'nvoos-chat-spa' ) }
					</label>
					<input
						id="nvoos-chat-spa-input"
						className={ `nvoos-chat-spa-input${ editingMsgId ? ' nvoos-chat-spa-input--editing' : '' }` }
						type="text"
						value={ input }
						onChange={ handleInputChange }
						placeholder={ editingMsgId
							? __( 'Edit message and press Send…', 'nvoos-chat-spa' )
							: __( 'Type a message…', 'nvoos-chat-spa' ) }
						disabled={ isStreaming }
						autoComplete="off"
					/>
					{ attachments.files.length > 0 && (
						<ul className="nvoos-chat-spa-attachment-strip" aria-label={ __( 'Attachments', 'nvoos-chat-spa' ) }>
							{ attachments.files.map( ( pf ) => (
								<li key={ pf.key } className="nvoos-chat-spa-attachment-chip">
									{ pf.previewUrl ? (
										<img
											src={ pf.previewUrl }
											alt={ pf.file.name }
											className="nvoos-chat-spa-attachment-thumb"
										/>
									) : (
										<span className="nvoos-chat-spa-attachment-icon" aria-hidden="true">📄</span>
									) }
									<span className="nvoos-chat-spa-attachment-name">{ pf.file.name }</span>
									<button
										type="button"
										className="nvoos-chat-spa-attachment-remove"
										aria-label={ `${ __( 'Remove', 'nvoos-chat-spa' ) } ${ pf.file.name }` }
										onClick={ () => attachments.remove( pf.key ) }
									>
										×
									</button>
								</li>
							) ) }
						</ul>
					) }
					{ attachments.attachError && (
						<p className="nvoos-chat-spa-attachment-error" role="alert">
							{ attachments.attachError }
						</p>
					) }
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
							disabled={ ! input.trim() && attachments.files.length === 0 }
						>
							{ editingMsgId
								? __( 'Update', 'nvoos-chat-spa' )
								: __( 'Send', 'nvoos-chat-spa' ) }
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
	// `priorMessages` (= `messagesRef.current`) is a ref snapshot that may or
	// may not include the just-completed assistant message yet, depending on
	// whether React has re-rendered and flushed the sync effect before
	// `onFinish` fires.  Guard against both duplication and omission:
	// if the last message already has the same id as `assistantMessage`, don't
	// append a second copy; otherwise append it so the turn is never lost.
	const last = priorMessages[ priorMessages.length - 1 ];
	const alreadyPresent =
		last && assistantMessage.id && last.id === assistantMessage.id;
	const wireMessages: TranscriptMessage[] = (
		alreadyPresent ? priorMessages : [ ...priorMessages, assistantMessage ]
	).map( ( m ) => ( {
		role: m.role,
		content: typeof m.content === 'string' ? m.content : '',
	} ) );
	try {
		await client.save( sessionKey, wireMessages, {
			finish_reason: typeof finishReason === 'string' ? finishReason : 'stop',
			source: 'chat-spa',
		} );
	} catch {
		// Soft-fail; the sidebar surfaces transcript errors on the next refresh.
	}
}
