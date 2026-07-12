/**
 * useChatSpoke — Wraps `useChat` from `@ai-sdk/react` with the pro SSE adapter.
 *
 * This is the core chat hook that replaces the legacy messagesStore + sse.js.
 *
 * Transcript auto-save happens client-side on each completed turn (onFinish).
 * This mirrors chat-spa's App.tsx persistFinishedTurn pattern so conversations
 * are visible across the Pro SPA, chat-spa, and legacy chat.js transcript lists.
 * The server-side WP_MCP_AI_Chat_Transcript_Recorder also records transcripts,
 * and sse-adapter.ts now forwards session_key so both pathways use the same key.
 */

import { useMemo, useCallback, useRef, useState, type RefObject } from 'react';
import { useChat, type Message } from '@ai-sdk/react';
import { createChatFetch } from '../sse-adapter';
import { TranscriptsClient } from '../api/transcripts';
import { useUIStore } from '../stores/uiStore';

export interface UseChatSpokeOptions {
	chatClientEndpoint: string;
	nonce: string;
	assistantId: number;
	initialMessages?: Message[];
	sessionKey?: string;
	/** Optional model override to send as options.provider / options.model. */
	model?: string;
	provider?: string;
	/** Transcripts REST endpoint for manual save (v2.1.0). */
	transcriptsEndpoint?: string;
}

export interface UseChatSpokeReturn {
	messages: Message[];
	input: string;
	handleInputChange: ( e: React.ChangeEvent< HTMLTextAreaElement > | string ) => void;
	handleSubmit: ( e?: { preventDefault?: () => void } ) => void;
	/** Submit with file attachments (v0.9.0). */
	handleSubmitWithAttachments: ( attachments: Array< { name?: string; contentType?: string; url: string } > ) => void;
	status: 'submitted' | 'streaming' | 'ready' | 'error';
	error: Error | undefined;
	stop: () => void;
	reload: () => void;
	setMessages: ( messages: Message[] ) => void;
	isStreaming: boolean;
	fileInputRef: RefObject< HTMLInputElement | null >;
	sendMessage: ( content: string ) => void;
	usageMap: Record< string, { promptTokens?: number; completionTokens?: number; totalTokens?: number; costUsd?: number; model?: string; provider?: string } >;
	/** Manually persist current session messages to the CCT transcripts store (v2.1.0). */
	saveConversation: () => Promise< void >;
}

export function useChatSpoke( options: UseChatSpokeOptions ): UseChatSpokeReturn {
	const {
		chatClientEndpoint,
		nonce,
		assistantId,
		initialMessages = [],
		sessionKey = '',
		model,
		provider,
		transcriptsEndpoint,
	} = options;

	const addToast = useUIStore( ( s ) => s.addToast );

	const customFetch = useMemo(
		() =>
			createChatFetch( {
				endpoint: chatClientEndpoint,
				nonce,
				assistantId,
				guest: false,
				model,
				provider,
				sessionKey,
			} ),
		[ chatClientEndpoint, nonce, assistantId, model, provider, sessionKey ]
	);

	const {
		messages,
		input,
		handleInputChange: chatHandleInputChange,
		handleSubmit: chatHandleSubmit,
		status,
		error,
		stop,
		reload,
		setMessages,
	} = useChat( {
		id: `pro-spa-${ assistantId }-${ sessionKey }`,
		api: chatClientEndpoint,
		fetch: customFetch,
		streamProtocol: 'data',
		initialMessages,
		onFinish: ( _message, { usage, finishReason } ) => {
			// Capture usage data (v0.9.0).
			// Guard against NaN token counts that some providers return in edge cases.
			if ( usage ) {
				const pt = typeof usage.promptTokens === 'number' && ! Number.isNaN( usage.promptTokens ) ? usage.promptTokens : undefined;
				const ct = typeof usage.completionTokens === 'number' && ! Number.isNaN( usage.completionTokens ) ? usage.completionTokens : undefined;
				const tt = typeof usage.totalTokens === 'number' && ! Number.isNaN( usage.totalTokens ) ? usage.totalTokens : undefined;
				setUsageMap( ( prev ) => ( { ...prev, [ _message.id ]: { promptTokens: pt, completionTokens: ct, totalTokens: tt } } ) );
			}
			// Persist model/provider so UsageBadges can show the model badge.
			if ( model || provider ) {
				setUsageMap( ( prev ) => {
					const existing = prev[ _message.id ] || {};
					return {
						...prev,
						[ _message.id ]: { ...existing, model, provider },
					};
				} );
			}

			// Auto-save transcript after each completed turn (v2.1.0).
			// Mirrors chat-spa's persistFinishedTurn pattern so conversations
			// appear in the transcript list across all clients.
			// Fire-and-forget — failures don't block the chat surface.
			if ( transcriptsClient && sessionKey ) {
				// Guard against duplication: if the last message already matches
				// the just-completed assistant message, use messages as-is;
				// otherwise append it (onFinish may fire before React
				// re-renders with the new message in state).
				const last = messages[ messages.length - 1 ];
				const alreadyPresent =
					last && _message.id && last.id === _message.id;
				const wireMessages = (
					alreadyPresent ? messages : [ ...messages, _message ]
				).map( ( m ) => ( {
					role: m.role as string,
					content: typeof m.content === 'string' ? m.content : '',
				} ) );
				void transcriptsClient
					.save( sessionKey, wireMessages, {
						finish_reason:
							typeof finishReason === 'string'
								? finishReason
								: 'stop',
						source: 'pro-spa-v2',
					} )
					.catch( () => {
						// Silent — matches chat-spa behaviour.
					} );
			}
		},
		onError: ( err ) => {
			addToast( err.message || 'Chat error', 'error' );
		},
	} );

	// Usage tracking (v0.9.0).
	const [ usageMap, setUsageMap ] = useState< Record< string, { promptTokens?: number; completionTokens?: number; totalTokens?: number; costUsd?: number; model?: string; provider?: string } > >( {} );

	const fileInputRef = useRef< HTMLInputElement | null >( null );

	const handleInputChange = useCallback(
		( e: React.ChangeEvent< HTMLTextAreaElement > | string ) => {
			if ( typeof e === 'string' ) {
				chatHandleInputChange( { target: { value: e } } as React.ChangeEvent< HTMLInputElement > );
			} else {
				chatHandleInputChange( e );
			}
		},
		[ chatHandleInputChange ]
	);

	const handleSubmit = useCallback(
		( e?: { preventDefault?: () => void } ) => {
			e?.preventDefault?.();
			chatHandleSubmit();
		},
		[ chatHandleSubmit ]
	);

	const sendMessage = useCallback(
		( content: string ) => {
			// Direct send: set input value and trigger submit.
			chatHandleInputChange( { target: { value: content } } as React.ChangeEvent< HTMLInputElement > );
			// Use setTimeout to ensure the input change has propagated.
			setTimeout( () => {
				chatHandleSubmit();
			}, 0 );
		},
		[ chatHandleInputChange, chatHandleSubmit ]
	);

	const handleSubmitWithAttachments = useCallback(
		( attachments: Array< { name?: string; contentType?: string; url: string } > ) => {
			chatHandleSubmit( undefined, { experimental_attachments: attachments } );
		},
		[ chatHandleSubmit ]
	);

	// ── Manual save callback (v2.1.0) ───────────────────────────────────
	// Auto-save runs in onFinish after every completed turn.  This
	// callback provides an explicit manual save for edge cases (e.g.
	// snapshotting the conversation before switching sessions).
	const transcriptsClient = useMemo(
		() =>
			transcriptsEndpoint
				? new TranscriptsClient( {
					endpoint: transcriptsEndpoint,
					nonce,
					assistantId,
				  } )
				: null,
		[ transcriptsEndpoint, nonce, assistantId ]
	);

	const saveConversation = useCallback( async () => {
		if ( ! transcriptsClient || ! sessionKey ) {
			return;
		}
		// Convert AI SDK Message[] to TranscriptMessage[] by stripping
		// non-serialisable fields (toolInvocations, annotations, parts).
		const transcriptMessages = messages.map( ( m ) => ( {
			role: m.role as string,
			content: typeof m.content === 'string' ? m.content : '',
		} ) );
		try {
			await transcriptsClient.save( sessionKey, transcriptMessages );
		} catch {
			// Silently ignore — server-side persistence covers the happy path.
		}
	}, [ transcriptsClient, sessionKey, messages ] );

	return {
		messages,
		input,
		handleInputChange,
		handleSubmit,
		handleSubmitWithAttachments,
		status,
		error,
		stop,
		reload,
		setMessages,
		isStreaming: status === 'streaming' || status === 'submitted',
		fileInputRef,
		sendMessage,
		usageMap,
		saveConversation,
	};
}
